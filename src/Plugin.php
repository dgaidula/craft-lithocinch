<?php

namespace iceboxind\lithocinch;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\Asset;
use craft\events\AssetEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterElementActionsEvent;
use craft\models\ImageTransform;
use craft\services\Gc;
use craft\services\ImageTransforms;
use craft\services\Utilities;
use craft\web\twig\variables\CraftVariable;
use iceboxind\lithocinch\elements\actions\OptimizeAssets;
use iceboxind\lithocinch\imagetransforms\LithoTransformer;
use iceboxind\lithocinch\migrations\Install;
use iceboxind\lithocinch\models\Settings;
use iceboxind\lithocinch\services\Optimizer;
use iceboxind\lithocinch\utilities\SavingsUtility;
use iceboxind\lithocinch\variables\LithoVariable;
use yii\base\Event;

/**
 * Lithocinch — perceptually-lossless, content-aware image optimization for Craft.
 *
 * This skeleton uses the Twig-API + `cli` driver path (version-robust across
 * Craft 4.5+/5). Registering a native ImageTransformer (so existing
 * `asset.getUrl({width})` calls auto-optimize) is the documented next step.
 *
 * @property-read Optimizer $optimizer
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '0.3.0';
    public bool $hasCpSettings = true;

    public static Plugin $plugin;

    /**
     * Two editions, low-to-high. A fresh install defaults to the first (Lite),
     * so installing the plugin never silently unlocks paid behavior. Pro is
     * activated by a Store license (or, in dev, `craft project-config/set
     * plugins.litho.edition pro`). The `is()` / `isPro()` gates below key off this list.
     *
     * Split:
     *  - Lite (free): native ImageTransformer drop-in + craft.litho.url(),
     *    best non-AVIF format (WebP/JPEG/PNG perceptual pick), focal-aware
     *    crops, cli driver.
     *  - Pro (paid): AVIF in the format shootout, craft.litho.picture()
     *    responsive <picture>/srcset, and the daemon + remote drivers.
     */
    public static function editions(): array
    {
        return ['lite', 'pro'];
    }

    /**
     * True when the active edition is Pro (or higher). Written with the `>=`
     * operator so it stays correct if a third tier is ever added, and so a
     * stale/legacy `edition` value (e.g. 'standard' from a pre-editions install)
     * resolves to false — a safe Lite default rather than a thrown error.
     */
    public function isPro(): bool
    {
        return $this->is('pro', '>=');
    }

    public static function config(): array
    {
        return [
            'components' => [
                'optimizer' => Optimizer::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        // craft.litho.* Twig API
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function (Event $event) {
            /** @var CraftVariable $variable */
            $variable = $event->sender;
            $variable->set('litho', LithoVariable::class);
        });

        $this->registerImageTransformer();
        $this->registerOnUploadOptimization();
        $this->registerSavingsUtility();
        $this->registerElementActions();
        $this->registerGc();
    }

    /**
     * Register LithoTransformer so Craft knows about it (invalidation / eager-load
     * bookkeeping), and — when opted in — make it the DEFAULT transformer so that
     * existing `asset.getUrl({width})` calls route through Lithocinch with no template
     * changes. The default is set via a DI container definition, which covers both
     * ad-hoc transform arrays and CP-saved named transforms (the transformer class
     * is not persisted per-transform, so the container default always applies).
     */
    private function registerImageTransformer(): void
    {
        Event::on(
            ImageTransforms::class,
            ImageTransforms::EVENT_REGISTER_IMAGE_TRANSFORMERS,
            static function (RegisterComponentTypesEvent $event) {
                $event->types[] = LithoTransformer::class;
            }
        );

        if ($this->getSettings()->useAsDefaultTransformer) {
            Craft::$container->set(ImageTransform::class, [
                'transformer' => LithoTransformer::class,
            ]);
        }
    }

    /**
     * Layer A (Pro): on-upload source optimization — cap + perceptually compress
     * the stored ORIGINAL on upload/replace, keeping its format.
     *
     * Hook: `Asset::EVENT_BEFORE_HANDLE_FILE`, fired inside `Asset::beforeSave()`
     * whenever a file operation is coming (new upload, replacement, or move) —
     * verified against Craft 5.10 vendored source. For uploads/replacements,
     * `$asset->tempFilePath` is the local file Craft will copy into the volume
     * in `afterSave()` → `_relocateFile()` (which streams it fresh from disk and
     * recomputes width/height/size afterwards), so rewriting it in place here is
     * honored end-to-end. The handler itself skips moves (no tempFilePath).
     *
     * The listener is only attached when the (config-overridable) toggle is on,
     * so the feature is zero-cost for everyone else; the edition gate and all
     * per-file checks live in Optimizer::optimizeUpload(). The outer try/catch
     * is belt-and-braces for the safety contract: a failed optimization must
     * never block or corrupt an upload.
     */
    private function registerOnUploadOptimization(): void
    {
        if (!$this->getSettings()->onUploadOptimize) {
            return;
        }

        Event::on(
            Asset::class,
            Asset::EVENT_BEFORE_HANDLE_FILE,
            function (AssetEvent $event) {
                try {
                    $this->optimizer->optimizeUpload($event->asset);
                } catch (\Throwable $e) {
                    Craft::warning('On-upload optimize failed (upload proceeds with original bytes): ' . $e->getMessage(), 'lithocinch');
                }
            }
        );
    }

    /**
     * Register the Savings utility (Utilities → Lithocinch Savings): the bulk
     * (re)optimization trigger + total-bytes-saved dashboard (SPEC §4 item 4).
     *
     * Pro-gated at REGISTRATION rather than inside contentHtml(): on Lite the
     * utility simply doesn't appear, which keeps the CP uncluttered and avoids
     * a dead nav item + a `utility:litho-savings` user-permission entry for a
     * feature Lite can't run. The upsell surface for Lite is the Store listing
     * and the plugin settings page, not a locked utility screen. (The trigger
     * controller and the queue job re-check isPro() independently, so this gate
     * is presentation, not the security boundary.)
     */
    private function registerSavingsUtility(): void
    {
        if (!$this->isPro()) {
            return;
        }

        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
            static function (RegisterComponentTypesEvent $event) {
                $event->types[] = SavingsUtility::class;
            }
        );
    }

    /**
     * Register the "Optimize with Lithocinch" bulk action on the Assets index
     * (the fast-follow to the Savings utility — SPEC §4 item 4): select some
     * assets, run just those through the same Optimizer::bulkOptimize()
     * pipeline the whole-library job uses. Small selections run inline;
     * larger ones are pushed as an assetIds-scoped BulkOptimizeJob — see
     * OptimizeAssets for the cutoff rationale.
     *
     * Same Pro-gating shape as registerSavingsUtility(): gated at REGISTRATION
     * so on Lite the action simply never appears in the dropdown (no dead
     * upsell surface in the actions menu), while OptimizeAssets::performAction()
     * re-checks isPro() itself as the actual security boundary — a CP page
     * loaded while Pro then downgraded can still POST the trigger.
     */
    private function registerElementActions(): void
    {
        if (!$this->isPro()) {
            return;
        }

        Event::on(
            Asset::class,
            Asset::EVENT_REGISTER_ACTIONS,
            static function (RegisterElementActionsEvent $event) {
                $event->actions[] = OptimizeAssets::class;
            }
        );
    }

    /**
     * Prune the Savings dashboard's detail ledger (`litho_optimization_log`)
     * on Craft's own garbage-collection pass — piggybacking on Craft's
     * existing `gc/run` cron/CLI mechanism rather than the plugin inventing
     * its own scheduler. Deletes rows older than `savingsLogRetentionDays`
     * (`0` = keep everything, never prune).
     *
     * This ONLY touches the detail ledger. The dashboard's lifetime totals
     * (`litho_optimization_totals`, maintained by atomic increment in
     * Optimizer::logSavings()) are never pruned — that's the whole point of
     * the two-table split: the headline "bytes saved" number survives GC
     * forever, even as the detail rows behind it get cleaned up.
     */
    private function registerGc(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function (Event $event) {
            $days = $this->getSettings()->savingsLogRetentionDays;
            if ($days <= 0) {
                return; // pruning disabled — keep every detail row forever
            }

            try {
                if (!Craft::$app->getDb()->tableExists(Install::TABLE)) {
                    return;
                }
                $cutoff = (new \DateTime())->modify("-{$days} days");
                Craft::$app->getDb()->createCommand()
                    ->delete(Install::TABLE, ['<', 'dateCreated', $cutoff->format('Y-m-d H:i:s')])
                    ->execute();
            } catch (\Throwable $e) {
                Craft::warning("Lithocinch savings-log GC prune failed: {$e->getMessage()}", 'lithocinch');
            }
        });
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('litho/settings', [
            'settings' => $this->getSettings(),
        ]);
    }
}
