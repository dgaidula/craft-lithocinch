# Lithocinch (working name) — Craft CMS image optimization

Perceptually-lossless, content-aware image optimization and **best-format
delivery** for Craft CMS 4.5+ / 5, powered by the
[`lithocinch`](../lithocinch) (sharp) engine.

Unlike resize-only plugins, Crisp resizes **and** automatically returns the
smallest *perceptually-lossless* format per image (AVIF / WebP / JPEG / PNG),
with focal-point-aware crops and `<picture>`/`srcset` generation.

> **Status: scaffold.** This is a working skeleton (Twig API + `cli` driver) to
> wire into a real Craft site and iterate. Calls marked `// VERIFY` in the code
> need confirming against your exact Craft 4.5.x / 5.x. The native
> `ImageTransformer` integration (so existing `asset.getUrl({width})` calls
> auto-optimize) is the next milestone — see [`../lithocinch/SPEC.md`](../lithocinch/SPEC.md).

## How it works

```
Twig  ──▶  Crisp (PHP)  ──reads source via Craft Fs (GCS/S3/local)──▶  driver
                                                                         │
                          lithocinch engine (Node/sharp)  ◀─────────┘
                          resize + perceptual + auto-format
                                 │  optimized bytes back
                          written to the transform Fs (GCS) + cached by key
```

Bytes in, bytes out — the engine never touches storage, so this works with the
`craftcms/google-cloud` filesystem (verified target) with no extra integration.

## Requirements

- Craft CMS 4.5+ or 5.x
- The `lithocinch` engine reachable via one of:
  - **cli**: Node 20+ and the tool on the server (`npm i -g lithocinch`)
  - **daemon**: `lithocinch serve` running locally
  - **remote**: a hosted Lambda (no Node needed on the web host)

## Install (local dev, path repo)

In your Craft project's `composer.json`:

```jsonc
"repositories": [{ "type": "path", "url": "../../sw/github/craft-lithocinch" }]
```

```bash
composer require iceboxind/craft-lithocinch:@dev
php craft plugin/install crisp
```

Then copy `config.example.php` → `config/litho.php` and set the driver +
`transformFsHandle` (your GCS bucket).

## Template usage

```twig
{# single best <img> src, format auto-picked #}
<img src="{{ craft.litho.url(hero, { width: 1200, format: 'auto' }) }}" alt="">

{# full <picture>: avif + webp sources + fallback, responsive, focal-aware #}
{{ craft.litho.picture(hero, {
     widths: [600, 1000, 1600],
     formats: ['avif', 'webp', 'auto'],
     sizes: '(max-width: 1000px) 100vw, 1000px',
     alt: hero.title
}) }}
```

Variants are generated on first use, written to the configured filesystem, and
cached by key (source mtime + transform params + settings) — so subsequent
requests are a cache hit, and editing/replacing the asset invalidates them.

## Two layers (see SPEC §3)

- **Delivery (this plugin's Twig API):** AVIF/WebP, right-sized, per template —
  the PageSpeed win.
- **On-upload source optimization (planned):** cap + perceptually compress the
  stored original (keep its format) to save GCS — separate, toggleable.

## License

Proprietary (commercial plugin). The underlying engine is MIT.
