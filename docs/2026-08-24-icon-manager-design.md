# Easy SVG Icons — design, 2026-08-24

## What is being built

An icon manager in the FREE plugin, capped at five icons. Pro lifts the cap.

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
be identical on every page. Five icons is enough for a blog and enough for
nobody with a styleguide.

The one thing a generic icon plugin cannot offer: every uploaded icon goes
through `easy_svg_sanitizer()`, which is THIS SITE's allow-list, not a library
default.

## Where the line runs

| Free | Pro |
|---|---|
| SVG upload, sanitized, on every path | |
| Icon manager, **5 icons**, one collection | cap removed |
| | several named collections |
| | multi-file upload |

Five icons is a complete thing, not crippleware. That is the condition for the
40,000 existing installs to like the feature rather than feel sold to.

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

**The cap** is checked when an icon is CREATED. Never when one is rendered.

## The contract between the two plugins

Pro still knows nothing of Free's internals. The contract grows by exactly one
filter, and `EASY_SVG_API` goes to **2**:

```php
apply_filters( 'easy_svg_icon_limit', 5 );
```

Pro filters it. That is the entire unlock.

## What must never happen

The cap applies to adding, never to output. A lapsed licence on a site with 40
icons still renders all 40; only the 41st is refused.

A paywall that blanks published pages is the fastest route to an uninstall and a
one-star review, and it would break the rule this system already follows: the
plugin never takes the base function away from a customer.

## Risks

**The API is two versions old.** `@since 7.1.0`, and the free plugin declares
6.0. The manager must hide itself on older WordPress with a sentence rather than
a fatal. For 40,000 installs that is not optional.

**It may still change.** New in core means going through the documented
functions and never touching the registry.

**The price was set for a different promise.** 99 and 199 USD a year, tiered by
site count, come from the agency-security idea. Whether an icon manager carries
that is a product question, and it is recorded here rather than assumed away.

## Not in 1.0

The library audit (moves to 1.1, already built and tested) · importing whole
icon sets · syncing icons between sites · any central surface · anything going
to wordpress.org.
