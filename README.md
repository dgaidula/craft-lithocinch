# Lithocinch (working name) — Craft CMS image optimization

Perceptually-lossless, content-aware image optimization and **best-format
delivery** for Craft CMS 4.5+ / 5, powered by the
[`lithocinch`](../lithocinch) (sharp) engine.

Unlike resize-only plugins, Lithocinch resizes **and** automatically returns the
smallest *perceptually-lossless* format per image (AVIF / WebP / JPEG / PNG),
with focal-point-aware crops and `<picture>`/`srcset` generation.

## Editions

Lithocinch ships in two editions. A fresh install runs **Lite**; **Pro** is
unlocked with a Plugin Store license.

| Capability | Lite (free) | Pro |
|---|:---:|:---:|
| Native `ImageTransformer` — existing `asset.getUrl({width})` calls auto-optimize, zero template changes | ✅ | ✅ |
| `craft.litho.url()` — single best-format `<img>` src | ✅ | ✅ |
| Best-format delivery (perceptual shootout) | WebP / JPEG / PNG | **+ AVIF** |
| Focal-point-aware cover crops | ✅ | ✅ |
| Key-based variant caching to your transform Fs (GCS/S3/local) | ✅ | ✅ |
| `craft.litho.picture()` — responsive `<picture>`/srcset (multi-format × multi-width) | — | ✅ |
| Backend drivers | `cli` | `cli` · `daemon` · `remote` |

**Graceful degradation, not lockout.** On Lite: AVIF is dropped from the format
pool (you still get the best of WebP/JPEG/PNG); `craft.litho.picture()` returns a
single optimized `<img>` at the largest requested width; and a `daemon`/`remote`
driver setting falls back to `cli` (logged), so nothing throws or renders broken.

> **Status: pre-release.** Twig API + native `ImageTransformer` + three drivers
> are built and `php -l` clean; all Craft API calls are verified against Craft
> 5.10.8.1 vendored source. Not yet run end-to-end in a live site — the install +
> render recipe is in `docs/DDEV-TESTING.md`. See
> [`../lithocinch/SPEC.md`](../lithocinch/SPEC.md) for the full design.

## How it works

```
Twig  ──▶  Lithocinch (PHP)  ──reads source via Craft Fs (GCS/S3/local)──▶  driver
                                                                              │
                          lithocinch engine (Node/sharp)  ◀──────────────────┘
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
php craft plugin/install litho
```

To exercise Pro behavior in local dev before a Store license exists, switch the
active edition:

```bash
php craft plugin/switch-edition litho pro   # or ...litho lite to go back
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

[Apache-2.0](./LICENSE) © 2026 Danniel T. Gaidula. Open source; the Pro edition and
support are sold via the Craft Plugin Store.
