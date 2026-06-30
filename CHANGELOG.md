# Changelog

## 0.1.0 — unreleased

Initial scaffold.

- `craft.crisp.url()` and `craft.crisp.picture()` Twig API.
- Three backend drivers (cli / daemon / remote) over the process-images transform
  service; bytes-in/bytes-out, storage via Craft Fs (GCS-safe).
- Key-based variant caching written to a configurable transform filesystem.
- Settings model + CP settings + `config/crisp.php` overrides.

### Next
- Native `ImageTransformer` so existing `asset.getUrl({width})` auto-optimizes.
- On-upload source optimization (cap + perceptual, keep format) for GCS savings.
- Bulk utility + queue jobs + savings dashboard; editions/licensing.
