# Changelog

## 0.1.0 — unreleased

Initial release. Two editions (Lite / Pro).

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
- Settings model + CP settings + `config/litho.php` overrides.

### Next
- On-upload source optimization (cap + perceptual, keep format) for GCS savings.
- Bulk utility + queue jobs + savings dashboard (Pro point-release).
