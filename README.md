# Easy SVG Support

Upload SVG files to WordPress safely. Every SVG is sanitized on the way in with
the bundled [`enshrined/svg-sanitize`](https://github.com/darylldoyle/svg-sanitizer),
shown with its real dimensions in the media library, and — on WordPress 7.1+ —
usable as an icon in the core Icon block.

- **Live on wordpress.org:** https://wordpress.org/plugins/easy-svg
- **Requires:** PHP 8.0+, WordPress 6.6+ (icons need 7.1+)
- **License:** GPL-3.0-or-later

The user-facing readme that wordpress.org renders is [`readme.txt`](readme.txt).
This file is for anyone reading the source.

## What it does

- Accepts SVG uploads and **sanitizes every path that can store one** — the
  media uploader, `media_sideload_image()`, WP-CLI imports, importers and raw
  REST — not just the browser uploader.
- Decides a file is an SVG from **server-side** type detection
  (`wp_check_filetype_and_ext`) and its extension, never from the client's
  `Content-Type` header.
- Re-sanitizes on `add_attachment` as a second net: anything that reaches the
  uploads directory by any route is cleaned in place, or the attachment is
  removed if it cannot be.
- Renders SVGs correctly in the media library and the block editor.
- Adds a site-wide **icon library** in the **Icons** admin panel. Icons are
  stored as a private post type (`esw_icon`), registered with the core Icon
  block on 7.1+, and printable anywhere with `easy_svg_icon()` or the
  `[easy_svg_icon]` shortcode. Icon markup is sanitized with the site's own
  allow-list and then stripped of anything active.

Both features are switches under **Icons → Settings** and are off on a fresh
install; a site upgrading from 4.x that already has SVG attachments keeps SVG
uploads on. Sanitizing and the size cap are not switches: they always run.
No tracking, no outbound requests.

## For developers

Two filters widen the sanitizer's allow-list for a site that needs more than the
defaults. Add only what you need — every addition is something an uploaded file
may then contain, and the list decides which names may appear, not what their
attributes may do:

```php
add_filter( 'esw_svg_allowed_tags', function ( $tags ) {
    $tags[] = 'view';
    return $tags;
} );

add_filter( 'esw_svg_allowed_attributes', function ( $attributes ) {
    $attributes[] = 'focusable';
    return $attributes;
} );
```

External references (a stylesheet, image or font from another server) are
removed by default; `esw_svg_remove_remote_references` (`__return_false`)
restores them. Add-ons can call `easy_svg_sanitizer()` to clean markup exactly
as the site does; `EASY_SVG_API` is the integer that changes when that
function's shape does.

The `esw_` names (functions, the two allow-list classes, the filters above)
predate the `easy_svg_` prefix and are public API: they are never renamed.

Size and cost limits: `easy_svg_max_bytes` (the parse cap in bytes, from the
Settings value), `easy_svg_max_php_tags` and `easy_svg_max_use_tags` (refuse a
file whose structure would make the sanitizer slow before it is parsed).

Two more contracts, each with its own number, for add-ons that extend the
plugin (Easy SVG Pro is one):

- `EASY_SVG_PANEL_API`: the `easy_svg_panel_enqueue` action, the
  `easy_svg_panel_menu_tabs` and `easy_svg_max_mb_ceiling` filters, and the
  browser-side `window.EasySvgPanel` registry (`registerTab()`,
  `registerCollections()`).
- `EASY_SVG_ICONS_API`: the `easy_svg_registrable_icons` filter,
  `easy_svg_rename_icon()` and `easy_svg_update_icon_markup()`.

The panel talks to the REST namespace `easy-svg/v1` (`/settings`, `/library`
and its `add`, `media`, `rename`, `replace`, `delete` actions), all behind
`manage_options`. The full list, with error codes, is in
[`docs/wiki.md`](docs/wiki.md), which is for whoever works on this repository
and does not ship.

## Layout

```
easy-svg.php            Bootstrap, upload pipeline, media-library display
includes/
  settings.php          The one option behind the switches, the 5.0 migration
  icons.php             Icon core: slugs, hardening, acceptance, registration
  core-icon.php         Rewrites shapes and strokes into what core's icon registry keeps
  icon-manager.php      The icon store (esw_icon) and the WordPress adapters around the core
  icon-writes.php       Renaming an icon and replacing its markup
  panel.php             The Icons admin panel: menu, tabs, assets, REST routes
src/                    Panel sources (React, Chakra UI v3, Vite); not shipped
build/                  The built panel (committed; this is what ships)
languages/              POT plus de_DE, de_DE_formal, de_AT, de_CH, de_CH_informal
uninstall.php           Removes the plugin's icons and options
vendor/enshrined/       Bundled sanitizer (committed; no Composer step)
tests/                  Plain-PHP test suites, two Node checks
e2e/                    Playwright specs for the panel, run against wp-env
scripts/                test-all.sh, i18n build and check
```

## Tests

Plain PHP against a stubbed WordPress — no PHPUnit, no bootstrap, nothing to
install. Run each suite in its own process:

```bash
php tests/run.php           # upload pipeline, sanitizer, media display, release lists
php tests/icons.php         # icon names, storage, hardening
php tests/core-icon.php     # shapes and strokes for core's icon registry
php tests/panel-rest.php    # the panel's REST routes
php tests/panel-access.php  # who sees which tab
php tests/runner.php        # workflow guards
```

`scripts/test-all.sh` runs all of them plus the Node checks and the catalogue
check, and reports by exit code. The panel needs `npm ci` and `npm run build`
after any change in `src/`; CI fails when the committed `build/` is not what
the sources build. Static analysis (PHPStan, level 5), Plugin Check on the
shipped tree and the Playwright suite (`npx wp-env start`, then
`npm run test:e2e`) run in CI.

## Releasing

A numeric tag (for example `4.3`) that matches the `Version:` header and the
readme `Stable tag:` triggers `.github/workflows/deploy.yml`, which publishes
to the wordpress.org SVN repository. The workflow refuses to deploy a tag whose
commit is not on `master` or whose versions disagree, and runs the tests and
the catalogue check first. See [`RELEASING.md`](RELEASING.md). `.distignore` lists what
stays out of the published package.
