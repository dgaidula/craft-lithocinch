# craft-lithocinch — agent guide

Craft CMS plugin (working name **"Lithocinch"**, renameable) for perceptually-lossless,
content-aware image optimization + **best-format delivery**. Targets Craft
**4.5+ and 5.x**. It's the commercial product on top of the open-source engine at
`../lithocinch`.

> **Status: pre-release.** Twig-API (`url()` + `picture()`) + three drivers
> (cli/daemon/remote) + a native `ImageTransformer` + the Lite/Pro edition split.
> All Craft API calls **verified against Craft 5.10.8.1** vendored source (the
> `// VERIFY` markers are resolved). `php -l` clean. **Not yet run end-to-end in a
> real Craft site** — the install + live-render test recipe is in
> `docs/DDEV-TESTING.md`.

## Architecture
PHP plugin → reads source bytes via Craft's `Fs` (GCS/S3/local) → a **driver**
(cli / daemon / remote) calls the Node engine's transform API → optimized bytes
back → written to the transform `Fs`, cached by key. Bytes-in/bytes-out, so the
engine needs **zero storage integration** (works with `craftcms/google-cloud`).

The full design + decisions + roadmap is in **`../lithocinch/SPEC.md`** —
read it first.

## Map
- `src/Plugin.php` — registers `craft.litho.*` + settings.
- `src/models/Settings.php` — driver, endpoints, transform Fs handle, perceptual target, responsive defaults.
- `src/drivers/` — `DriverInterface`, `CliDriver` (Symfony Process), `HttpDriver` (Guzzle; daemon+remote), `Outputs` decoder.
- `src/services/Optimizer.php` — asset+opts → cached variant URL(s); batches misses; builds `<picture>`/srcset; focal-point aware. **The heart.**
- `src/imagetransforms/LithoTransformer.php` — native Craft `ImageTransformer`; routes `asset.getUrl({width})` through Lithocinch. Opt-in via `useAsDefaultTransformer`.
- `src/variables/LithoVariable.php` — `craft.litho.url()` / `.picture()`.
- `src/templates/settings.twig`, `config.example.php`.

## Conventions
- PSR-4 `iceboxind\lithocinch\` → `src/`. `php -l` before commit (no PHP runtime here
  to test against Craft; verify in a DDEV site).
- Commit attribution per global `~/.claude/CLAUDE.md`.

## API verification (done — Craft 5.10.8.1 vendored source)
Confirmed correct as written; interfaces are all `@since 4.0.0`, so the 4.5+ target holds:
- `Asset::getContents(): string` — routes through the volume's Fs (GCS-safe).
- `getFocalPoint(): array|string|null` — returns `['x'=>0.5,'y'=>0.5]` **defaults** for
  images (null only for non-image kinds); gate on `getHasFocalPoint(): bool` to detect an
  explicitly-set point (Optimizer now does this).
- `Craft::$app->getFs()->getFilesystemByHandle($h): ?FsInterface` (nullable — null-check).
- `BaseFsInterface::write(string $path, string $contents, array $config=[]): void`,
  `fileExists(string): bool`, `getRootUrl(): ?string` (nullable when `hasUrls=false`).
- ImageTransformer: implement `craft\base\imagetransforms\ImageTransformerInterface`
  (`getTransformUrl`, `invalidateAssetTransforms`); make default via a DI container def
  `Craft::$container->set(ImageTransform::class, ['transformer'=>LithoTransformer::class])`
  (covers ad-hoc AND named CP transforms — transformer isn't persisted per-transform).
- Still unverified in a running site: CP form macro field names in `settings.twig`.

## Editions (Lite / Pro) — [done]
`Plugin::editions()` → `['lite','pro']`; a fresh install defaults to Lite. Gating
is centralized in `Optimizer` via `Plugin::$plugin->isPro()` (`is('pro','>=')`):
- **Lite:** native transformer + `craft.litho.url()`, best non-AVIF format
  (AVIF stripped from `formatPool`), focal crops, `cli` driver only.
- **Pro:** AVIF in the shootout, `craft.litho.picture()` responsive markup,
  `daemon`/`remote` drivers.
- Lite degrades gracefully (never throws): `editionFormatPool()` drops AVIF,
  `editionFormat()` coerces explicit `avif` → `auto`, `litePicture()` returns a
  single optimized `<img>`, `driver()` falls back to `cli` (logged).
- Dev: force Pro with `php craft plugin/switch-edition litho pro`. Editions are
  declared in code + priced in Craft Console — nothing edition-related goes in
  `composer.json`.

## Next milestones (from SPEC §11)
1. ~~**Native `ImageTransformer`**~~ **[done, untested in-site]** — `LithoTransformer`
   + `useAsDefaultTransformer`. Needs the live DDEV render test (`docs/DDEV-TESTING.md`).
2. ~~**Lite/Pro edition split**~~ **[done]** — see above.
3. **On-upload layer**: cap + perceptually compress the stored original (keep
   format) for GCS savings on CAF/TLB.
4. Bulk utility + queue jobs + savings dashboard (Pro point-release).

## Test target sites
DDEV Craft 5.10 + `craftcms/google-cloud`: `lunchbox-craft-cms-ddev`,
`chefann-craft-cms-ddev` (under `~/Resilio Sync/active projects/FVD/FVD-20-1111 Chef Ann Updates`).
