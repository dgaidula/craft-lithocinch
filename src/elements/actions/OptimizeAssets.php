<?php

namespace iceboxind\lithocinch\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use iceboxind\lithocinch\jobs\BulkOptimizeJob;
use iceboxind\lithocinch\Plugin;

/**
 * "Optimize with Lithocinch" — the Assets-index bulk action (Pro).
 *
 * A scoped, ad-hoc alternative to the whole-library run in the Savings
 * utility: select some assets in the Assets index, pick this from the actions
 * menu, and only those go through Optimizer::bulkOptimize() — the exact same
 * driver + store() plumbing as url()/picture() and BulkOptimizeJob, so every
 * genuinely new variant lands in the savings ledger automatically. Originals
 * are never touched (that's the separate, opt-in on-upload layer).
 *
 * Sync-vs-queue: performAction() runs inside the CP request the editor is
 * actively waiting on, and each asset costs a full driver round-trip (source
 * read + engine transform + variant write) — seconds each, not milliseconds.
 * So small selections (≤ SYNC_LIMIT) run inline for immediate, concrete
 * feedback ("Optimized 3 of 3…"), while anything larger is pushed onto
 * Craft's queue as a BulkOptimizeJob scoped by assetIds — same UX as the
 * utility's whole-library runs, and no risk of a web-request timeout.
 *
 * Non-optimizable selections (non-images, unsupported extensions like SVG)
 * are skipped silently, mirroring BulkOptimizeJob; per-asset failures are
 * logged and skipped, never fatal to the rest of the selection.
 *
 * Registration is Pro-gated in Plugin::registerElementActions() (on Lite the
 * action never appears), and performAction() re-checks the edition as the
 * actual security boundary — a page loaded while Pro then downgraded must not
 * silently run Pro work.
 */
class OptimizeAssets extends ElementAction
{
    /**
     * Above this many eligible assets, the work is queued instead of run
     * inline. Each asset is a full engine round-trip (typically 1–3 s), so
     * five keeps the worst inline case comfortably inside normal web-request
     * budgets while still giving instant feedback for the common "fix these
     * couple of images" case.
     */
    private const SYNC_LIMIT = 5;

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('litho', 'Optimize with Lithocinch');
    }

    /**
     * @inheritdoc
     */
    public function performAction(ElementQueryInterface $query): bool
    {
        // Edition re-check at the execution boundary (defense in depth — the
        // listener registration gate only reflects the edition at page load).
        if (!Plugin::$plugin->isPro()) {
            $this->setMessage(Craft::t('litho', 'Optimizing assets requires Lithocinch Pro.'));
            return false;
        }

        /** @var Asset[] $selected */
        $selected = $query->all();
        $total = count($selected);

        // Silently drop what the raster pipeline can't handle (non-images,
        // SVG, etc.) — same skip list as BulkOptimizeJob.
        $eligible = array_values(array_filter(
            $selected,
            static fn(Asset $asset) => in_array(
                strtolower((string)$asset->getExtension()),
                BulkOptimizeJob::SUPPORTED_EXTENSIONS,
                true
            )
        ));

        if ($eligible === []) {
            $this->setMessage(Craft::t('litho', 'None of the selected assets are optimizable images.'));
            return true;
        }

        // Large selection → queue (see class doc for the sync-vs-queue call).
        if (count($eligible) > self::SYNC_LIMIT) {
            Craft::$app->getQueue()->push(new BulkOptimizeJob([
                'assetIds' => array_map(static fn(Asset $asset) => $asset->id, $eligible),
            ]));
            $this->setMessage(Craft::t('litho', 'Optimization of {count} assets queued.', [
                'count' => count($eligible),
            ]));
            return true;
        }

        // Small selection → run inline for concrete, immediate feedback.
        $optimizer = Plugin::$plugin->optimizer;
        $optimized = 0;
        $bytesSaved = 0;

        foreach ($eligible as $asset) {
            try {
                // store() (inside bulkOptimize) logs the savings-ledger row
                // itself; the return value is only used for the feedback line.
                $result = $optimizer->bulkOptimize($asset);
                if ($result !== null) {
                    $optimized++;
                    $bytesSaved += max(0, $result['bytesBefore'] - $result['bytesAfter']);
                }
            } catch (\Throwable $e) {
                Craft::warning(
                    "Optimize action failed for asset {$asset->id} ({$asset->filename}): {$e->getMessage()}",
                    'lithocinch'
                );
                continue;
            }
        }

        if ($optimized === 0) {
            $this->setMessage(Craft::t('litho', 'Couldn’t optimize the selected assets — check the Lithocinch logs.'));
            return false;
        }

        $this->setMessage($bytesSaved > 0
            ? Craft::t('litho', 'Optimized {optimized} of {total} selected assets ({saved} saved).', [
                'optimized' => $optimized,
                'total' => $total,
                'saved' => Craft::$app->getFormatter()->asShortSize($bytesSaved, 1),
            ])
            : Craft::t('litho', 'Optimized {optimized} of {total} selected assets.', [
                'optimized' => $optimized,
                'total' => $total,
            ]));

        return true;
    }
}
