<?php

namespace iceboxind\lithocinch;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\models\ImageTransform;
use craft\services\ImageTransforms;
use craft\web\twig\variables\CraftVariable;
use iceboxind\lithocinch\imagetransforms\LithoTransformer;
use iceboxind\lithocinch\models\Settings;
use iceboxind\lithocinch\services\Optimizer;
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
    public string $schemaVersion = '0.1.0';
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

        // TODO (layer A): on-upload source optimization — hook
        // craft\services\Assets::EVENT_BEFORE_REPLACE_ASSET / Elements save to cap
        // + perceptually compress the stored original (keep its format). Toggleable.
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
