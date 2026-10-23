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
| On-upload source optimization — cap + compress the stored original, keep its format | — | ✅ |
| Bulk (re)optimization utility + bytes-saved dashboard | — | ✅ |
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

- **Delivery (the Twig API + transformer above):** AVIF/WebP, right-sized, per
  template — the PageSpeed win.
- **On-upload source optimization (Pro):** cap + perceptually compress the
  stored original (keep its format) to save storage — separate, toggleable,
  documented below.

## On-upload source optimization (Pro)

Designers upload 6000–8000px originals; capping them to ~4K and perceptually
compressing them **in their own format** (JPEG stays JPEG, PNG stays PNG — never
converted) is a large storage saving, and every later transform gets faster
because there's less to decode. This is a separate layer from delivery: format
conversion still happens only at transform time.

**Off by default.** Installing the plugin never silently rewrites uploads.
Enable it in the CP settings or `config/litho.php`:

```php
'onUploadOptimize' => true,   // Pro; off by default
'onUploadKeepBackup' => true, // strongly recommended — see below
'onUploadMaxSize' => 3840,    // cap for the stored original (separate from the delivery cap)
'onUploadBackupFsHandle' => 'gcsColdStorage', // optional; blank = local storage dir
```

It hooks `Asset::EVENT_BEFORE_HANDLE_FILE` and rewrites the uploaded temp file
before Craft copies it into your volume, so the asset's recorded width/height/
size automatically reflect the optimized file. Only JPEG/PNG/WebP uploads are
touched; GIF/SVG/TIFF/AVIF and non-images are skipped. If the engine is
unreachable, the result would change format, or there are no byte savings, the
upload proceeds **untouched** — a failed optimization never blocks or corrupts
an upload.

> **Honest note on the final size:** the number Lithocinch logs is what its own
> engine produced. Craft runs its own image-sanitization pass
> (`Image::cleanImageByPath()`) on the file immediately afterward — a security
> measure, not something this plugin should (or can) skip — which re-encodes it
> again at Craft's own default quality. The file that actually lands in your
> volume is usually a bit larger than Lithocinch's logged number as a result
> (verified live: a 2.66MB upload logged at 166KB landed at 261KB — still a
> ~90% reduction, just not the exact logged figure). Don't quote the log line
> as the final on-disk size; check the asset's actual `size` instead.

### Backups & recovery

Rewriting an original is a one-way door, so the backup toggle is **on by
default**: before anything is overwritten, the pristine bytes are copied to

- `<backup Fs>/<onUploadBackupSubpath>/YYYY-MM-DD/<unique>-<filename>` when
  `onUploadBackupFsHandle` points at a Craft filesystem (e.g. a cold-storage
  bucket), or
- `storage/lithocinch/originals/YYYY-MM-DD/<unique>-<filename>` on the web
  server otherwise (fine for a single server; use a bucket for real durability).

If the backup cannot be verifiably written, the optimization is **skipped** and
the upload keeps its original bytes — the pristine file is never at risk. To
recover an original: find it by filename + upload date in the backup location,
then use the CP's **Replace file** on the asset (or re-upload it). Backups are
never deleted by the plugin; prune them on your own schedule. Only disable
`onUploadKeepBackup` if you truly never need the originals back.

## Bulk optimization + savings dashboard (Pro)

**Utilities → Lithocinch Savings** tracks real, ongoing savings — every
variant Lithocinch generates, whether from a normal page render calling
`url()`/`picture()` or from an explicit bulk run, adds to the tally. It's a
live number tied to your actual traffic, not a one-time snapshot.

The same utility also lets you (re)optimize your existing library in one
pass: pick a scope (everything, or a single volume), queue the run, and
Craft's queue works through your image assets in the background — each one
goes through the same delivery pipeline as `url()`/`picture()` (auto
format shootout at your default settings), pre-generating the best-format
variant.

The dashboard shows: variants generated, distinct assets touched, bytes
before/after, and bytes/percent saved. Numbers are stored in the database
(an append-only ledger), so they survive cache clears and re-running with
unchanged settings is a safe no-op — it never double-counts a variant that
already exists. **Originals are never modified** by any of this — that's the
separate, opt-in on-upload layer above.

Prefer a scoped, ad-hoc run? Select assets in the **Assets index** (the
checkboxes in the file browser) and pick **“Optimize with Lithocinch”** from
the bulk-actions menu — only the selected assets go through the same
pipeline. A handful runs immediately with concrete feedback (“Optimized 3 of
3 selected assets (1.2 MB saved)”); larger selections are queued in the
background, same as a utility run. Non-images and unsupported formats are
skipped silently. Pro-only — on Lite the action doesn’t appear.

Honest scoping note: this is a v1 dashboard — a few totals, not an analytics
product. Per-volume breakdowns and history are planned fast-follows.

## License

[The Craft License](./LICENSE.md) © Danniel T. Gaidula. The plugin is source-available
and commercial: the Lite edition is free, and the Pro edition and support are sold
via the Craft Plugin Store. The [Lithocinch engine](https://github.com/dgaidula/lithocinch)
it drives stays open source under Apache-2.0.
