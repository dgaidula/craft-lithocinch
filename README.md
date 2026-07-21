# Lithocinch (working name) — Craft CMS image optimization

Perceptually-lossless, content-aware image optimization and **best-format
delivery** for Craft CMS 4.5+ / 5, powered by the
[`lithocinch`](../lithocinch) (sharp) engine. Unlike resize-only plugins, it
resizes **and** returns the smallest *perceptually-lossless* format per image
(AVIF / WebP / JPEG / PNG), with focal-point-aware crops and `<picture>` / srcset
generation.

**The win:** on a live Craft 5.10 site backed by Google Cloud Storage,
Lithocinch cut delivered image weight by **−99.2%** end-to-end — the same visible
image, a fraction of the bytes over the wire. It gets there with a per-image
*perceptual shootout*: encode candidate formats, measure fidelity (SSIM), return
the smallest output that still clears the perceptual bar. No quality-slider
guessing, no one-format-fits-all.

## Quick start

```twig
{# Drop-in: your existing transforms just start emitting optimized,
   modern-format images — nothing in your templates changes. #}
<img src="{{ asset.getUrl({ width: 1200 }) }}" alt="">

{# Or call it explicitly for single best-format delivery: #}
<img src="{{ craft.litho.url(hero, { width: 1200, format: 'auto' }) }}" alt="">
```

> **Status: pre-release (0.1.0, unreleased).** **Verified end-to-end 2026-07-20**
> against a live Craft 5.10.11 site: the native `ImageTransformer` drop-in,
> `craft.litho.url()`, and `craft.litho.picture()` all produced real AVIF/WebP
> variants via the `daemon` driver, written to a configured Fs. (That pass used a
> synthetic test image for functional proof; the −99.2% above is from separate
> real-world verification on a production site.) All Craft API calls are verified
> against Craft 5.10 vendored source and `php -l` clean. See the full design in
> [`../lithocinch/SPEC.md`](../lithocinch/SPEC.md).

## Editions

**Lite is free** and a real optimizer on its own: the drop-in transformer plus
best-format WebP/JPEG/PNG delivery, with zero template changes. **Pro** unlocks
the biggest byte win (**AVIF** in the shootout), the marquee developer feature
(**`craft.litho.picture()`** responsive markup), and the **`daemon` / `remote`**
scale drivers. Pro is sold per-site through the **Craft Plugin Store**.

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

## Requirements

- Craft CMS 4.5+ or 5.x
- The `lithocinch` engine reachable via one of:
  - **cli**: Node 20+ and the tool on the server (`npm i -g lithocinch`)
  - **daemon**: `lithocinch serve` running locally
  - **remote**: a hosted Lambda (no Node needed on the web host)

> **Node-hosting requirement, stated plainly.** The engine is Node/sharp. This
> works on VPS and most managed Craft hosting where you can run Node (`cli` or
> `daemon`). It does **not yet** work on PHP-only managed hosts (Fortrabbit,
> Servd, Craft Cloud), which have no Node — a hosted `remote` driver that removes
> the requirement is planned but not available at launch.

## Install (local dev, path repo)

In your Craft project's `composer.json`:

```jsonc
"repositories": [{ "type": "path", "url": "../../sw/github/craft-lithocinch" }]
```

```bash
composer require iceboxind/craft-lithocinch:@dev
php craft plugin/install litho
```

To exercise Pro behavior in local dev before a Store license exists, set the
active edition via project config (there's no `plugin/switch-edition` console
command in Craft 5.10):

```bash
php craft project-config/set plugins.litho.edition pro   # or ...edition lite to go back
```

Then copy `config.example.php` → `config/litho.php` and set the driver +
`transformFsHandle` (your GCS bucket).

## Template usage

```twig
{# single best <img> src, format auto-picked #}
<img src="{{ craft.litho.url(hero, { width: 1200, format: 'auto' }) }}" alt="">

{# full <picture> (Pro): avif + webp sources + fallback, responsive, focal-aware #}
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

## Two layers (see SPEC §3)

- **Delivery (this plugin's Twig API):** AVIF/WebP, right-sized, per template —
  the PageSpeed win.
- **On-upload source optimization (planned):** cap + perceptually compress the
  stored original (keep its format) to save GCS — separate, toggleable.

## License

[Apache-2.0](./LICENSE) © 2026 Danniel T. Gaidula. Open source; the Pro edition and
support are sold via the Craft Plugin Store.
