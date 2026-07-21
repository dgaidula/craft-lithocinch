# Testing Lithocinch in the lunchbox DDEV site (Craft 5.10)

End-to-end validation recipe for the `lunchbox` DDEV site (Craft 5.10.8.1 +
`craftcms/google-cloud`). Everything here is **reversible** (teardown at the
bottom). ⚠️ This site lives under **Resilio Sync**, so composer/project-config
changes propagate to other machines — do the install with intent and revert when
done, or keep it on a branch.

> **Driver choice for DDEV:** use the **`daemon`** driver, not `cli`. The `cli`
> driver shells out to Node *inside the PHP container*, but the engine lives on the
> host. Running the engine's HTTP daemon on the host and pointing the plugin at
> `host.docker.internal` avoids installing Node + the tool into the container.

## 0. Prereqs
- `lunchbox` DDEV project running (`ddev list` shows it green).
- Engine deps installed: `(cd ~/sw/github-public/lithocinch && npm ci)`.

## 1. Start the engine daemon on the host
```bash
cd ~/sw/github-public/lithocinch
node src/cli.js serve --port 4111        # keep this running in its own terminal
```
Sanity check from inside the container (host.docker.internal is the host):
```bash
ddev exec curl -s http://host.docker.internal:4111/health   # -> {"ok":true}
```

## 2. Add the plugin as a path-repo (mirrors the site's existing `plugins/gated-content`)
From the site root:
```bash
SITE=~/"Resilio Sync/active projects/FVD/FVD-20-1111 Chef Ann Updates/lunchbox-craft-cms-ddev"
cd "$SITE"
mkdir -p plugins
ln -s ~/sw/github-public/craft-lithocinch plugins/craft-lithocinch   # symlinked local dev copy
```
Add to the site's `composer.json` `repositories` array (next to the gated-content entry):
```json
{ "type": "path", "url": "plugins/craft-lithocinch", "options": { "symlink": true } }
```
Then, **inside the container** (so it writes container-side lockfile state):
```bash
ddev composer require iceboxind/craft-lithocinch:@dev
ddev craft plugin/install litho
```

## 3. Configure Lithocinch — `config/litho.php`
Create it in the site's `config/` dir:
```php
<?php
return [
    'driver'    => 'daemon',
    'daemonUrl' => 'http://host.docker.internal:4111',

    // Where generated variants are written + served from. For a first test, a
    // LOCAL filesystem handle is simplest; switch to the GCS handle for the real
    // GCS round-trip. Find handles in CP → Settings → Filesystems (or
    //   ddev craft on   # then look at project config config/project/fs/ )
    'transformFsHandle' => 'localTransforms',   // <-- set to a real Fs handle
    'transformSubpath'  => '_litho',

    // The drop-in demo: route existing asset.getUrl({width}) through Lithocinch.
    'useAsDefaultTransformer' => true,
];
```
> The transform Fs must have **`hasUrls = true`** (a public base URL) or
> `getRootUrl()` returns null and Lithocinch yields no URL (the transformer then throws
> `NotSupportedException` and Craft falls back to the original — safe, but you won't
> see optimization). A local "Web-accessible" Fs or the public GCS bucket both work.

## 4. Exercise it
Pick any image asset id (find one: `ddev craft` isn't a REPL, so use a template or
the CP). Easiest: add a throwaway line to a template that renders an image, e.g.
```twig
{% set a = craft.assets.kind('image').one() %}
<img src="{{ a.getUrl({ width: 800 }) }}">   {# should now be a Lithocinch _litho/... URL #}
{{ craft.litho.picture(a, { widths: [400, 800, 1200] }) }}  {# the Twig API path #}
```
Load the page and verify:
- [ ] The `<img src>` points at a `_litho/<assetId>/...` path (not Craft's normal
      `/transformed/` path) → the ImageTransformer is in the loop.
- [ ] The generated file exists in the transform Fs (local dir or GCS bucket).
- [ ] The daemon terminal logs a transform request; the output format is AVIF/WebP
      per the format pool, smaller than the source.
- [ ] With `useAsDefaultTransformer => false`, `getUrl({width})` reverts to stock
      Craft behavior (confirms the opt-in gate works).

## 5. Teardown / revert (leave the site as found)
```bash
ddev craft plugin/uninstall litho
ddev composer remove iceboxind/craft-lithocinch
cd "$SITE"
rm plugins/craft-lithocinch                      # remove the symlink
git checkout composer.json composer.lock    # drop the repo + require entries
rm config/litho.php
# stop the host daemon (Ctrl-C in its terminal)
```
Because the plugin install writes to **project config** (`config/project/`), review
`git status` there and `git checkout` any Lithocinch-related project-config changes too,
so nothing Lithocinch-related syncs to the other machines.

## Gotchas
- **`host.docker.internal` unreachable** → on Linux DDEV needs
  `ddev config global --xdebug-ide-location=…`; on macOS it works out of the box.
  Fallback: `ddev exec getent hosts host.docker.internal`.
- **No optimization, no error** → almost always the transform Fs has `hasUrls=false`
  (no public URL) so `getRootUrl()` is null. Use a web-accessible Fs.
- **Original URL served** → the transformer threw `NotSupportedException` (non-image
  asset, or the daemon was down). Check the daemon is up and the asset is an image.
- **Symlinked path-repo shows as a broken symlink inside the container (Mutagen sites
  only)** → if the DDEV project uses Mutagen sync (check `ddev describe`), a host-side
  `ln -s` under `plugins/` gets synced *as a symlink* into the container, which Composer
  can't resolve (`the url ... does not exist`), and even after `composer require`
  succeeds once, Mutagen will keep re-syncing the symlink over any fix. The reliable
  fix: skip the host symlink entirely and bind-mount the plugin repo directly via a
  `.ddev/docker-compose.<plugin>.yaml` override:
  ```yaml
  services:
    web:
      volumes:
        - "/abs/host/path/to/craft-lithocinch:/var/www/html/plugins/craft-lithocinch"
  ```
  then `ddev restart`. See `craft5-plugin-testbed/.ddev/docker-compose.lithocinch.yaml`
  for the working example (verified 2026-07-20 against `craft5-plugin-testbed`, a
  non-Mutagen-conflicting alternative to `lunchbox` for this exact reason — consider
  testing there instead).

## Verified runs
- **2026-07-20** — full Pro-edition round trip verified end-to-end against
  `~/sw/github-private/craft5-plugin-testbed` (Craft 5.10.11) instead of `lunchbox`:
  native `ImageTransformer` drop-in, `craft.litho.url()`, and `craft.litho.picture()`
  all produced real AVIF/WebP variants via the `daemon` driver, written to the `local`
  Fs, ~99% smaller than the source. Lite edition's driver-fallback gate (non-`cli`
  driver → forced to `cli` + logged warning) also confirmed firing correctly via
  `plugins.litho.edition` project-config. Lite's `cli`-driver byte-level output was
  **not** exercised (the container's Node is linux-arm64 and the engine's `sharp`
  native binary wasn't rebuilt for it — a testbed environment gap, not a plugin issue;
  the format/pool-gating logic itself was verified by code review).
