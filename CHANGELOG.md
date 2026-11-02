# Changelog

## 0.1.0 - 2026-11-02

Initial release. Two editions (Lite / Pro).

- **License:** the plugin is now under the Craft License (`LICENSE.md`; `composer.json`
  `license` is `proprietary`), the Plugin Store’s convention for commercial plugins.
  Commits published before this change remain available under the Apache-2.0 terms
  they were released with. The Lithocinch engine itself stays Apache-2.0.

- **Lite / Pro edition split** (`Plugin::editions()` → `['lite', 'pro']`).
  - Lite: native `ImageTransformer` drop-in, `craft.litho.url()`, best non-AVIF
    format (WebP/JPEG/PNG perceptual pick), focal-aware crops, `cli` driver.
  - Pro: AVIF in the format shootout, `craft.litho.picture()` responsive
    `<picture>`/srcset, and the `daemon` + `remote` drivers.
  - Pro-only behavior degrades gracefully on Lite (AVIF dropped from the pool,
    `picture()` → single optimized `<img>`, daemon/remote → `cli`), never throws.
- `craft.litho.url()` and `craft.litho.picture()` Twig API.
- Native `ImageTransformer` so existing `asset.getUrl({width})` calls
  auto-optimize with no template changes (opt-in via `useAsDefaultTransformer`).
- Three backend drivers (cli / daemon / remote) over the lithocinch transform
  service; bytes-in/bytes-out, storage via Craft Fs (GCS-safe).
- Key-based variant caching written to a configurable transform filesystem.
- **On-upload source optimization (Pro)** — cap + perceptually compress the
  stored original on upload/replace, keeping its format (never converts; format
  conversion stays at the delivery layer). Hooks `Asset::EVENT_BEFORE_HANDLE_FILE`
  and rewrites the temp file before Craft copies it into the volume.
  - Off by default (`onUploadOptimize`); silent no-op on Lite.
  - Pristine-original backups on by default (`onUploadKeepBackup`): bytes are
    copied to the backup Fs (`onUploadBackupFsHandle` + subpath) or to local
    `storage/lithocinch/originals/` **before** anything is overwritten; if the
    backup can't be secured, the optimization is skipped.
  - A failed/unreachable engine, a format mismatch, or a zero-savings result
    leaves the upload untouched — never blocks or corrupts an upload.
  - Only JPEG/PNG/WebP are rewritten; GIF/SVG/TIFF/AVIF and non-images skipped.
- **Bulk optimization utility + savings dashboard (Pro)** — Utilities →
  “Lithocinch Savings”: a running total of real, ongoing savings — every
  variant Lithocinch generates, from normal `url()`/`picture()` template hits
  *and* bulk runs alike — plus a bulk (re)optimization trigger for existing
  libraries (all volumes or one).
  - `Optimizer::store()` logs every genuinely new variant it writes — so the
    dashboard reflects real bytes saved across actual site traffic, not a
    one-time bulk-run snapshot. The bulk job just drives the same pipeline
    for existing assets; it doesn’t log separately.
  - **Two-table design so the numbers stay fast and honest as the log grows**:
    a single-row lifetime-totals table (`litho_optimization_totals`,
    maintained by atomic increment) backs the dashboard’s headline numbers —
    an O(1) read regardless of site history — while the detail activity
    ledger (`litho_optimization_log`) is a *bounded* recent-activity window,
    pruned by Craft’s own garbage collection (new `savingsLogRetentionDays`
    setting, default 90; `0` disables pruning). Pruning the detail ledger
    never touches the lifetime totals. Originals are never modified by any
    of this — that’s the separate on-upload layer.
  - v1 dashboard is deliberately minimal — a few stat rows, no charts or
    history. Per-asset failures during a bulk run are logged and skipped,
    never fatal.
  - First install migration (`migrations/Install.php`); schema version 0.3.0
    (existing dev installs pick up new tables via numbered migrations).
- **“Optimize with Lithocinch” Assets-index action (Pro)** — select assets in
  the Assets index and optimize just those from the bulk-actions menu, a
  scoped ad-hoc alternative to the whole-library run. Same
  `Optimizer::bulkOptimize()` pipeline (variants only — originals untouched;
  savings land on the dashboard automatically). Small selections (≤ 5
  eligible images) run inline with concrete feedback (“Optimized 3 of 3
  selected assets (1.2 MB saved)”); larger ones are queued as an
  asset-ID-scoped `BulkOptimizeJob`. Non-images/unsupported formats are
  skipped silently; per-asset failures never kill the rest of the selection.
  Hidden entirely on Lite.
- Settings model + CP settings + `config/litho.php` overrides.

### Fixed
- `$ENV_VAR` values in the API key, daemon URL and remote API URL settings are
  now resolved (`App::parseEnv()`) when the driver is built. Before, the CP
  offered env vars for these fields but sent the literal `$NAME` as the URL and
  the Bearer token.
- The plugin icon moved from the repo root to `src/icon.svg`, the plugin’s base
  path, where Craft and the Plugin Store read it; the CP showed the default icon.
- This heading now uses the `## X.Y.Z - YYYY-MM-DD` form; the Plugin Store
  ignores any other version heading (it read `## 0.1.0 — unreleased`).
- `composer.json` no longer carries a `version` field: Packagist takes versions
  from git tags, and `composer validate` warned about it.

### Next
- Savings dashboard polish (per-volume breakdown, history) — fast-follow, not
  launch-blocking. (The element-index “Optimize” action from this list is
  built — see above.)
