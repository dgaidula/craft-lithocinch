<?php

namespace iceboxind\lithocinch\jobs;

use Craft;
use craft\elements\Asset;
use craft\queue\BaseJob;
use iceboxind\lithocinch\Plugin;

/**
 * Bulk (re)optimization queue job (Pro), pushed from the Savings utility.
 *
 * Iterates image assets (optionally scoped to one volume) and runs each
 * through Optimizer::bulkOptimize() — the same driver/store plumbing as
 * url()/picture(), pre-warming the default delivery variant. Optimizer's
 * store() logs every genuinely new variant to the savings ledger on its own
 * (the same hook organic url()/picture() template hits use), so this job does
 * NOT write to the log itself — it just drives the iteration and progress bar.
 *
 * Per-asset failures are logged and skipped, never fatal: one unreadable or
 * undecodable file must not kill a whole library pass.
 */
class BulkOptimizeJob extends BaseJob
{
    /**
     * Extensions the engine's decode path handles. kind('image') also matches
     * things like SVG, which the raster pipeline shouldn't touch — skip those.
     */
    private const SUPPORTED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'tif', 'tiff', 'avif'];

    /** Optional volume scope; null = all volumes. */
    public ?int $volumeId = null;

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        // Re-check the edition at run time (it could change between the queue
        // push and the worker picking this up). Silent skip, per the plugin's
        // degrade-gracefully convention.
        if (!Plugin::$plugin->isPro()) {
            Craft::warning('Bulk optimization is a Pro feature; job skipped.', 'lithocinch');
            return;
        }

        $query = Asset::find()->kind('image');
        if ($this->volumeId) {
            $query->volumeId($this->volumeId);
        }

        $total = (int)$query->count();
        if ($total === 0) {
            return;
        }

        $optimizer = Plugin::$plugin->optimizer;
        $i = 0;

        foreach ($query->each() as $asset) {
            /** @var Asset $asset */
            $i++;
            $this->setProgress($queue, $i / $total, sprintf('%d of %d', $i, $total));

            if (!in_array(strtolower((string)$asset->getExtension()), self::SUPPORTED_EXTENSIONS, true)) {
                continue;
            }

            try {
                // Return value intentionally unused: bulkOptimize()'s own call
                // to store() already logs this to the savings ledger when it's
                // a genuinely new variant — nothing left for this job to do
                // with the per-asset result. (A future progress label like
                // "N bytes saved so far" could read it without changing this
                // job's persistence story.)
                $optimizer->bulkOptimize($asset);
            } catch (\Throwable $e) {
                Craft::warning(
                    "Bulk optimize failed for asset {$asset->id} ({$asset->filename}): {$e->getMessage()}",
                    'lithocinch'
                );
                continue;
            }
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('litho', 'Optimizing image assets');
    }
}
