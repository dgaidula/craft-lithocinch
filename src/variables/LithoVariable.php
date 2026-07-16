<?php

namespace iceboxind\lithocinch\variables;

use craft\elements\Asset;
use iceboxind\lithocinch\Plugin;
use Twig\Markup;

/**
 * Template API:
 *
 *   {# single best <img> src, auto format #}
 *   <img src="{{ craft.litho.url(asset, { width: 800, format: 'auto' }) }}">
 *
 *   {# full <picture>: avif/webp/fallback x widths, focal-point aware #}
 *   {{ craft.litho.picture(asset, {
 *        widths: [400, 800, 1200],
 *        formats: ['avif', 'webp', 'auto'],
 *        sizes: '(max-width: 800px) 100vw, 800px',
 *        alt: asset.title
 *   }) }}
 */
class LithoVariable
{
    public function url(Asset $asset, array $opts = []): ?string
    {
        return Plugin::$plugin->optimizer->url($asset, $opts);
    }

    public function picture(Asset $asset, array $opts = []): Markup
    {
        return Plugin::$plugin->optimizer->picture($asset, $opts);
    }
}
