# craft-lithocinch — agent guide

Craft CMS plugin (working name **"Lithocinch"**, renameable) for perceptually-lossless,
content-aware image optimization + **best-format delivery**. Targets Craft
**4.5+ and 5.x**. It's the commercial product on top of the open-source engine at
`../lithocinch`.

> **Status: pre-release.** Twig-API (`url()` + `picture()`) + three drivers
> (cli/daemon/remote) + a native `ImageTransformer` + the Lite/Pro edition split +
> on-upload optimization + the bulk utility/savings dashboard + the Assets-index
> "Optimize" action. All Craft API calls **verified against Craft 5.10.8.1**
> vendored source. `php -l` clean. **Verified end-to-end 2026-07-20 and 2026-07-21**
> against `craft5-plugin-testbed` (Craft 5.10.11): native `ImageTransformer`,
> `url()`, and `picture()` produce real AVIF/WebP variants via the `daemon` driver;
> Lite's driver-fallback gate fires correctly; the on-upload destructive path
> (including its fail-safe skip and a SHA256-verified backup) and the savings
> ledger's atomic totals + GC pruning all verified live with real evidence — see
> `docs/DDEV-TESTING.md` and the "Live DDEV verification" sections of
> `../lithocinch-hq/strategy/craft-store-submission.md` for the full detail.
> Lite's `cli`-driver byte output still wasn't exercised (test container's
> Node/sharp arch mismatch — logic verified by code review, not by execution).

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
- `src/services/Optimizer.php` — asset+opts → cached variant URL(s); batches misses; builds `<picture>`/srcset; focal-point aware; `store()` logs every genuinely new variant via `logSavings()` (atomic increment on the totals table + an insert into the bounded detail ledger). **The heart.**
- `src/imagetransforms/LithoTransformer.php` — native Craft `ImageTransformer`; routes `asset.getUrl({width})` through Lithocinch. Opt-in via `useAsDefaultTransformer`.
- `src/variables/LithoVariable.php` — `craft.litho.url()` / `.picture()`.
- `src/utilities/SavingsUtility.php` — Pro CP utility (Utilities → Lithocinch Savings): reads the O(1) lifetime-totals table for headline numbers + a distinct-assets count from the (bounded) detail ledger, plus the bulk-optimize trigger. Registered in `Plugin::init()` (Pro-gated at registration).
- `src/jobs/BulkOptimizeJob.php` — queue job: image assets → `Optimizer::bulkOptimize()`, which itself logs via `store()`. The job doesn't write to the log directly. Scopeable by `volumeId` (utility) or `assetIds` (index action).
- `src/elements/actions/OptimizeAssets.php` — Pro "Optimize with Lithocinch" bulk action on the Assets index (`Asset::EVENT_REGISTER_ACTIONS`, Pro-gated at registration + re-checked in `performAction()`). ≤ 5 eligible assets run inline via `bulkOptimize()`; more get queued as an `assetIds`-scoped `BulkOptimizeJob`.
- `src/controllers/UtilityController.php` — `litho/utility/optimize` POST → queue push.
- `src/migrations/` — `Install.php` creates two tables: `litho_optimization_totals` (single-row lifetime totals, atomic-incremented, never pruned) and `litho_optimization_log` (bounded, append-only detail ledger, pruned via `Plugin::registerGc()` on Craft's own `Gc::EVENT_RUN` — see `savingsLogRetentionDays`). Numbered migrations pick up new tables on existing dev installs. Bump `$schemaVersion` when adding migrations.
- `src/templates/settings.twig`, `src/templates/utility.twig`, `config.example.php`.

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
- Dev: force an edition with `ddev craft project-config/set plugins.litho.edition pro`
  (no built-in `plugin/switch-edition` console command exists in Craft 5.10 — that's
  a CP-UI / `Plugins::switchEdition()` service action; project-config is the direct
  route for local testing without a Console license). Editions are declared in code +
  priced in Craft Console — nothing edition-related goes in `composer.json`.

## Next milestones (from SPEC §11)
1. ~~**Native `ImageTransformer`**~~ **[done, verified in-site 2026-07-20]** —
   `LithoTransformer` + `useAsDefaultTransformer`. See `docs/DDEV-TESTING.md`.
2. ~~**Lite/Pro edition split**~~ **[done]** — see above.
3. ~~**On-upload layer**~~ **[done, verified live 2026-07-21]** — caps + perceptually
   compresses the stored original (keeps format) for GCS savings on CAF/TLB.
   Backup-before-overwrite safety net SHA256-verified byte-identical; fail-safe
   skip path confirmed (bad backup config → upload proceeds untouched). Note:
   Craft's own post-upload sanitization re-encodes the file afterward, so the
   final on-disk size is a bit larger than what Lithocinch logs — see README.
4. ~~Bulk utility + queue jobs + savings dashboard~~ **[done, verified live
   2026-07-21]** — Savings utility + `BulkOptimizeJob` + the two-table
   totals/ledger design (atomic increment, GC-pruned detail ledger). Organic
   `url()`/`picture()` hits confirmed logging; repeat runs confirmed idempotent
   (no double-counting); GC pruning confirmed removing old detail rows without
   touching the lifetime totals. Fast-follow ~~element-index action~~
   **[done, `OptimizeAssets`]** also built. Remaining fast-follow: dashboard
   polish (per-volume breakdown, history) — not launch-blocking.

## Test target sites
DDEV Craft 5.10 + `craftcms/google-cloud`: `lunchbox-craft-cms-ddev`,
`chefann-craft-cms-ddev` (under `~/Resilio Sync/active projects/FVD/FVD-20-1111 Chef Ann Updates`).
