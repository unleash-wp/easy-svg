# Easy SVG Support

Upload SVG files to WordPress safely. Every SVG is sanitized on the way in with
the bundled [`enshrined/svg-sanitize`](https://github.com/darylldoyle/svg-sanitizer),
shown with its real dimensions in the media library, and — on WordPress 7.1+ —
usable as an icon in the core Icon block.

- **Live on wordpress.org:** https://wordpress.org/plugins/easy-svg
- **Requires:** PHP 8.0+, WordPress 6.0+ (icons need 7.1+)
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
- Adds a site-wide **icon manager** (Media → SVG icons) whose icons are stored
  as a private post type and registered with the core Icon block. Icon markup is
  sanitized with the site's own allow-list and then stripped of anything active.

No settings page, no tracking, no outbound requests.

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

## Layout

```
easy-svg.php            Bootstrap, upload pipeline, media-library display
includes/
  icon-manager.php      Media → SVG icons screen, admin-post handlers
  icons.php             Icon storage, hardening, registration with core
uninstall.php           Removes the plugin's icons and options
vendor/enshrined/       Bundled sanitizer (committed; no build step)
tests/                  Plain-PHP test suites
```

## Tests

Plain PHP against a stubbed WordPress — no PHPUnit, no bootstrap, nothing to
install. Run each suite in its own process:

```bash
php tests/run.php       # upload pipeline, sanitizer, media display
php tests/icons.php     # icon names, storage, hardening
php tests/runner.php    # workflow guards
```

## Releasing

A numeric tag (for example `4.3`) that matches the `Version:` header and the
readme `Stable tag:` triggers `.github/workflows/deploy.yml`, which publishes
to the wordpress.org SVN repository. The workflow refuses to deploy a tag whose
commit is not on `master` and whose versions disagree. `.distignore` lists what
stays out of the published package.
