# Easy SVG Icons — design, 2026-08-24

> **Changed before release (4.3, 2026-10-05).** This design capped the free
> plugin at five icons and let a paid add-on lift the cap through a filter.
> WordPress.org guideline 5 forbids exactly that: functionality in a hosted
> plugin that is locked until somebody pays. The cap, the `easy_svg_icon_limit`
> filter and everything around them were removed; the icon manager in the free
> plugin has no limit, and `EASY_SVG_API` went to 3 to say the filter is gone.
> A paid add-on may only sell code that lives in the add-on. The sections below
> are edited to match; nothing here describes a limit any more.

## What is being built

An icon manager in the free plugin, with no limit on how many icons a site
keeps.

Registered icons appear in the core `core/icon` block's inserter without any
editor JavaScript, because WordPress exposes them over `wp/v2/icons`.

Measured before designing, not remembered:

| | |
|---|---|
| Current WordPress | 7.1 |
| The API | `wp_register_icon_collection()`, `wp_register_icon()`, `@since 7.1.0` |
| Name shape | `collection/icon-name`; `core` is reserved |
| Icon content | `content` (markup) or `file_path` |
| Discovery | `WP_REST_Icons_Controller`, `wp/v2/icons` and `wp/v2/icons/{collection}` |
| Core registers on | `init`, priority 0 |
| The block | `core/icon`, since 7.0, attribute `icon` is a string resolved by `wp_get_icon()` |

## Who it is for

Somebody with a real icon system: twenty symbols in a corporate design that must
be identical on every page.

The one thing a generic icon plugin cannot offer: every uploaded icon goes
through `easy_svg_sanitizer()`, which is THIS SITE's allow-list, not a library
default.

## Architecture

**Storage:** a private custom post type `esw_icon`. Markup in `post_content`,
label in `post_title`, slug in `post_name`.

Not attachments, for two reasons. An icon is not a media file — no alt text, no
sizes, no thumbnails — and putting them among the photos makes the media library
worse. More importantly, as `post_content` there is **no file and therefore no
URL**: an icon is never directly fetchable, not even before somebody uses it.

**Registration:** on `init` at priority 10, after core's own collections at 0.
Collection slug `easy-svg`, icons as `easy-svg/arrow-left`.

**Capability:** `edit_theme_options`. An icon applies site-wide like a theme
asset, not like an upload.

## The contract an add-on may rely on

Still exactly one function, `easy_svg_sanitizer()`, and the number that says
what shape it has, `EASY_SVG_API`. The icon manager adds nothing to it: there
is no filter over how many icons a site may keep, and `EASY_SVG_API` is **3**
because the filter that 2 introduced was removed again before release.

## Risks

**The API is two versions old.** `@since 7.1.0`, and the free plugin declares
6.0. On older WordPress the manager is simply absent -- no menu entry, nothing
registered -- rather than a fatal. For 40,000 installs that is not optional.

**It may still change.** New in core means going through the documented
functions and never touching the registry.

## Not in the first version

Importing whole icon sets · syncing icons between sites · any central surface.
