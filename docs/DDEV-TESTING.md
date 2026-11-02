# Testing Lithocinch in a DDEV Craft site (Craft 5)

End-to-end validation recipe for a DDEV Craft 5 site. Use Dan’s own testbed,
`~/sw/github-private/craft5-plugin-testbed` (its own site and database,
reinstallable from `config/` at any time), never a client site. Everything here
is **reversible** (teardown at the bottom).

> **Driver choice for DDEV:** use the **`daemon`** driver, not `cli`. The `cli`
> driver shells out to Node *inside the PHP container*, but the engine lives on the
> host. Running the engine’s HTTP daemon on the host and pointing the plugin at
> `host.docker.internal` avoids installing Node + the tool into the container.

## 0. Prereqs
- The testbed’s DDEV project running (`ddev list` shows it green).
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

## 2. Mount the plugin
The testbed bind-mounts plugin repos through a `.ddev/docker-compose.<plugin>.yaml`
override (see `craft5-plugin-testbed/.ddev/docker-compose.lithocinch.yaml`), so the
plugin is already at `plugins/craft-lithocinch` inside the container. Then, **inside
the container** (so it writes container-side lockfile state):
```bash
ddev composer require iceboxind/craft-lithocinch:@dev
ddev craft plugin/install litho
```
For another DDEV site, add the plugin as a Composer path repository
(`{ "type": "path", "url": "plugins/craft-lithocinch", "options": { "symlink": true } }`)
and mount it the same way; see the Mutagen gotcha below before using a host symlink.

## 3. Configure Lithocinch — `config/litho.php`
Create it in the site’s `config/` dir:
```php
<?php
return [
    'driver'    => 'daemon',
    'daemonUrl' => 'http://host.docker.internal:4111',

    // Where generated variants are written + served from. For a first test, a
    // LOCAL filesystem handle is simplest; switch to a cloud Fs handle for the real
    // round-trip. Find handles in CP → Settings → Filesystems (or
    //   ddev craft on   # then look at project config config/project/fs/ )
    'transformFsHandle' => 'localTransforms',   // <-- set to a real Fs handle
    'transformSubpath'  => '_litho',

    // The drop-in demo: route existing asset.getUrl({width}) through Lithocinch.
    'useAsDefaultTransformer' => true,
];
```
> The transform Fs must have **`hasUrls = true`** (a public base URL) or
> `getRootUrl()` returns null and Lithocinch yields no URL (the transformer then throws
> `NotSupportedException` and Craft falls back to the original — safe, but you won’t
> see optimization). A local “Web-accessible” Fs or a public bucket both work.

## 4. Exercise it
Pick any image asset id (find one: `ddev craft` isn’t a REPL, so use a template or
the CP). Easiest: add a throwaway line to a template that renders an image, e.g.
```twig
{% set a = craft.assets.kind('image').one() %}
<img src="{{ a.getUrl({ width: 800 }) }}">   {# should now be a Lithocinch _litho/... URL #}
{{ craft.litho.picture(a, { widths: [400, 800, 1200] }) }}  {# the Twig API path #}
```
Load the page and verify:
- [ ] The `<img src>` points at a `_litho/<assetId>/...` path (not Craft’s normal
      `/transformed/` path) → the ImageTransformer is in the loop.
- [ ] The generated file exists in the transform Fs (local dir or bucket).
- [ ] The daemon terminal logs a transform request; the output format is AVIF/WebP
      per the format pool, smaller than the source.
- [ ] With `useAsDefaultTransformer => false`, `getUrl({width})` reverts to stock
      Craft behavior (confirms the opt-in gate works).

## 5. Teardown / revert (leave the site as found)
```bash
ddev craft plugin/uninstall litho
ddev composer remove iceboxind/craft-lithocinch
git checkout composer.json composer.lock    # drop the require entry
rm config/litho.php
# stop the host daemon (Ctrl-C in its terminal)
```
Because the plugin install writes to **project config** (`config/project/`), review
`git status` there and `git checkout` any Lithocinch-related project-config changes too.

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
  can’t resolve (`the url ... does not exist`), and Mutagen keeps re-syncing the symlink
  over any fix. The reliable fix: skip the host symlink and bind-mount the plugin repo
  directly via a `.ddev/docker-compose.<plugin>.yaml` override:
  ```yaml
  services:
    web:
      volumes:
        - "/abs/host/path/to/craft-lithocinch:/var/www/html/plugins/craft-lithocinch"
  ```
  then `ddev restart`. The testbed’s `.ddev/docker-compose.lithocinch.yaml` is the
  working example (verified 2026-07-20).

## Verified runs
- **2026-07-20** — full Pro-edition round trip verified end-to-end against
  `~/sw/github-private/craft5-plugin-testbed` (Craft 5.10.11):
  native `ImageTransformer` drop-in, `craft.litho.url()`, and `craft.litho.picture()`
  all produced real AVIF/WebP variants via the `daemon` driver, written to the `local`
  Fs, ~99% smaller than the source. Lite edition’s driver-fallback gate (non-`cli`
  driver → forced to `cli` + logged warning) also confirmed firing correctly via
  `plugins.litho.edition` project-config. Lite’s `cli`-driver byte-level output was
  **not** exercised (the container’s Node is linux-arm64 and the engine’s `sharp`
  native binary wasn’t rebuilt for it — a testbed environment gap, not a plugin issue;
  the format/pool-gating logic itself was verified by code review).
