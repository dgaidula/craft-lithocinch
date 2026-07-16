<?php

namespace iceboxind\lithocinch\imagetransforms;

use craft\base\imagetransforms\EagerImageTransformerInterface;
use craft\base\imagetransforms\ImageTransformerInterface;
use craft\elements\Asset;
use craft\models\ImageTransform;
use iceboxind\lithocinch\Plugin;
use yii\base\Component;
use yii\base\NotSupportedException;

/**
 * Native Craft image transformer backed by the Lithocinch optimizer.
 *
 * Registering this as the default transformer (see Plugin::init + the
 * `useAsDefaultTransformer` setting) makes existing template calls like
 * `asset.getUrl({width: 800})` route through Lithocinch — perceptually-lossless,
 * best-format output — with ZERO template changes.
 *
 * Verified against Craft 5.10.8.1 (interface is `@since 4.0.0`, so this also
 * satisfies the 4.5+ target). The interface requires exactly two methods;
 * `EagerImageTransformerInterface` is implemented as a no-op because URL
 * generation is deferred to the optimizer's own cache.
 */
class LithoTransformer extends Component implements ImageTransformerInterface, EagerImageTransformerInterface
{
    /** Craft transform `mode` -> engine `fit` (sharp semantics; see engine config.js). */
    private const MODE_TO_FIT = [
        'crop' => 'cover',
        'fit' => 'inside',
        'stretch' => 'fill',
        'letterbox' => 'contain',
    ];

    /** Craft `position` -> sharp gravity. Only used when no focal point is set. */
    private const POSITION_TO_GRAVITY = [
        'top-left' => 'northwest', 'top-center' => 'north', 'top-right' => 'northeast',
        'center-left' => 'west', 'center-center' => 'centre', 'center-right' => 'east',
        'bottom-left' => 'southwest', 'bottom-center' => 'south', 'bottom-right' => 'southeast',
    ];

    /**
     * @throws NotSupportedException when Lithocinch can't (or shouldn't) handle the
     *   asset — Craft catches this and falls back to the untransformed URL.
     */
    public function getTransformUrl(Asset $asset, ImageTransform $imageTransform, bool $immediately): string
    {
        if ($asset->kind !== Asset::KIND_IMAGE) {
            throw new NotSupportedException('Lithocinch only transforms image assets.');
        }

        $url = Plugin::$plugin->optimizer->url($asset, $this->opts($asset, $imageTransform));
        if (!$url) {
            // No transform Fs configured, or the backend produced nothing usable:
            // let Craft serve the original rather than emit a broken <img src>.
            throw new NotSupportedException('Lithocinch produced no variant for this asset.');
        }
        return $url;
    }

    public function invalidateAssetTransforms(Asset $asset): void
    {
        // Variants are keyed by asset uid + dateModified + settings + schemaVersion
        // (see Optimizer::cacheKey), so a modified source naturally misses the cache
        // and regenerates. Nothing to purge eagerly in the skeleton.
    }

    public function eagerLoadTransforms(array $transforms, array $assets): void
    {
        // URL resolution is per-asset and cache-backed; no batch warmup needed here.
    }

    /** Map a Craft ImageTransform to the optimizer's opts array. */
    private function opts(Asset $asset, ImageTransform $t): array
    {
        $opts = ['fit' => self::MODE_TO_FIT[$t->mode] ?? 'cover'];
        if ($t->width) {
            $opts['width'] = (int)$t->width;
        }
        if ($t->height) {
            $opts['height'] = (int)$t->height;
        }
        if ($t->position) {
            $opts['position'] = self::POSITION_TO_GRAVITY[$t->position] ?? 'centre';
        }
        // Craft uses 'jpg'; the engine's format id is 'jpeg'. null/'' -> best format.
        $format = $t->format ?: 'auto';
        $opts['format'] = $format === 'jpg' ? 'jpeg' : $format;
        return $opts;
    }
}
