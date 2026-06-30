# craft-crisp — agent guide

Craft CMS plugin (working name **"Crisp"**, renameable) for perceptually-lossless,
content-aware image optimization + **best-format delivery**. Targets Craft
**4.5+ and 5.x**. It's the commercial product on top of the open-source engine at
`../process-images`.

> **Status: scaffold.** Twig-API + `cli` driver path only. `php -l` clean; not yet
> run inside a real Craft site. Several Craft API calls are flagged `// VERIFY`.

## Architecture
PHP plugin → reads source bytes via Craft's `Fs` (GCS/S3/local) → a **driver**
(cli / daemon / remote) calls the Node engine's transform API → optimized bytes
back → written to the transform `Fs`, cached by key. Bytes-in/bytes-out, so the
engine needs **zero storage integration** (works with `craftcms/google-cloud`).

The full design + decisions + roadmap is in **`../process-images/SPEC.md`** —
read it first.

## Map
- `src/Plugin.php` — registers `craft.crisp.*` + settings.
- `src/models/Settings.php` — driver, endpoints, transform Fs handle, perceptual target, responsive defaults.
- `src/drivers/` — `DriverInterface`, `CliDriver` (Symfony Process), `HttpDriver` (Guzzle; daemon+remote), `Outputs` decoder.
- `src/services/Optimizer.php` — asset+opts → cached variant URL(s); batches misses; builds `<picture>`/srcset; focal-point aware. **The heart.**
- `src/variables/CrispVariable.php` — `craft.crisp.url()` / `.picture()`.
- `src/templates/settings.twig`, `config.example.php`.

## Conventions
- PSR-4 `iceboxind\crisp\` → `src/`. `php -l` before commit (no PHP runtime here
  to test against Craft; verify in a DDEV site).
- Commit attribution per global `~/.claude/CLAUDE.md`.

## Known `// VERIFY` items (confirm in Craft 5.10 / DDEV)
- `Asset::getContents()` (read bytes), `getFocalPoint()` (→ {x,y}).
- `Craft::$app->getFs()->getFilesystemByHandle($h)`; `$fs->write/fileExists/getRootUrl`.
- CP form macro field names in `settings.twig`.

## Next milestones (from SPEC §11)
1. **Native `ImageTransformer`** (Craft 4.4+/5) so existing `asset.getUrl({width})`
   auto-optimizes — the drop-in demo. Mind the 4-vs-5 interface delta.
2. **On-upload layer**: cap + perceptually compress the stored original (keep
   format) for GCS savings on CAF/TLB.
3. Bulk utility + queue jobs + savings dashboard; editions/licensing.

## Test target sites
DDEV Craft 5.10 + `craftcms/google-cloud`: `lunchbox-craft-cms-ddev`,
`chefann-craft-cms-ddev` (under `~/Resilio Sync/active projects/FVD/FVD-20-1111 Chef Ann Updates`).
