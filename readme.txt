=== Easy SVG Support ===
Contributors: Benjamin_Zekavica
Tags: svg, svg upload, sanitize, icons, media
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 5.4.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Upload SVG files safely. Every SVG is sanitized automatically, shown in your media library, and usable as an icon in WordPress 7.1.

== Description ==

Easy SVG Support lets you upload SVG files to WordPress and use them like any other image: in the block editor, as a featured image, in galleries and in custom fields. You choose what runs: turn on SVG uploads and the icon library under Icons → Settings, and the plugin adds exactly what you switch on and nothing else.

SVG is not a picture format like PNG or JPEG. It is XML, and an SVG file can carry scripts, event handlers and references to other files or websites. That is why WordPress does not allow SVG uploads on its own. Easy SVG Support cleans every SVG with the maintained [enshrined/svg-sanitize](https://github.com/darylldoyle/svg-sanitizer) library before the file is stored, and refuses files it cannot clean.

The plugin deliberately stays small and quiet: one Icons screen, no tracking, no external requests and no ads. Features are off until you turn them on, so a fresh install changes nothing you did not ask for.

= Features =

* **Everything is opt-in.** Turn SVG uploads and the icon library on or off, and set the parse-size limit, under Icons → Settings. Sanitizing and the size limit are always on: they are the safety, not features you switch off.
* **SVG uploads** in the media library and the block editor.
* **Automatic sanitizing** before an SVG is stored, on the usual ways a file arrives: the media uploader, the REST API, WP-CLI `wp media import` and `media_sideload_image()`.
* **A safety net for everything else.** Every new SVG attachment is checked once more when it is created, for example by an importer. A file that cannot be cleaned is removed again.
* **XML-RPC uploads** are accepted only when the SVG is already clean.
* **SVG previews** in the media library.
* **SVG icons for the Icon block** (WordPress 7.1 and newer). Keep your own icons under Icons → Icon library and use them in the Icon block. Each icon is sanitized with the same rules as your uploads.
* **Developer filters** to allow additional SVG tags and attributes.
* **Lightweight.** No scripts or stylesheets on your front end.
* **Translation-ready.**

= For developers =

The sanitizer removes every tag and attribute that is not on its allow-list. Two filters let you extend that list for your site. Only add what you need: every addition is something an uploaded file may then contain.

Allow additional tags with `esw_svg_allowed_tags`:

`add_filter( 'esw_svg_allowed_tags', function ( $tags ) {
    $tags[] = 'view';
    return $tags;
} );`

Most drawing elements are already on the list, so you rarely need this. Never add `script`, `style`, `foreignObject`, or the animation elements (`animate`, `set`, `animateTransform`, `animateMotion`): the list only decides which names may appear, it does not check what their attributes point at, so those elements can put back the very things the sanitizer is there to remove.

Allow additional attributes with `esw_svg_allowed_attributes`:

`add_filter( 'esw_svg_allowed_attributes', function ( $attributes ) {
    $attributes[] = 'focusable';
    return $attributes;
} );`

External references, for example a stylesheet, image or font loaded from another server, are removed by default. A site that needs them can keep them with `esw_svg_remove_remote_references`:

`add_filter( 'esw_svg_remove_remote_references', '__return_false' );`

Add-ons can call `easy_svg_sanitizer()` to clean markup exactly the way the site does. See the FAQ for details.

== Installation ==

1. In your WordPress admin, go to Plugins → Add New Plugin.
2. Search for "Easy SVG Support".
3. Click Install Now, then Activate.
4. Go to Icons → Settings and turn on SVG uploads (and the icon library, if you want it).
5. Upload SVG files in the media library as usual.

You can also download the plugin as a zip file and upload it under Plugins → Add New Plugin → Upload Plugin.

Features are off until you switch them on under Icons → Settings, so a fresh install adds nothing you did not ask for. A site that was already using SVG uploads keeps them when it updates.

== Frequently Asked Questions ==

= Is it safe to allow SVG uploads? =

An SVG file can contain code, so allowing uploads without checks is not safe. Easy SVG Support sanitizes every SVG before it is stored: scripts, event handlers and other unsafe content are removed, and a file that cannot be cleaned is refused. Uploading still requires the same permission as any other media file.

= Why was my SVG rejected? =

The sanitizer refuses files it cannot read as a valid SVG, for example a file with broken XML or one that is not an SVG at all. Some older Adobe Illustrator exports use custom DTD entities, which are refused as well. Export the file again as a plain SVG, without those entities, and upload it again.

= What happens to SVGs already in my media library? =

Sanitizing happens when a file is added, so files already in your library are not changed or re-checked when you update the plugin. If some of them were uploaded before this version, or added outside the media uploader, re-upload anything you are unsure about so it passes through the current sanitizer.

= How do I allow additional tags or attributes? =

Use the `esw_svg_allowed_tags` and `esw_svg_allowed_attributes` filters, for example in a small custom plugin. The Description shows an example of each.

= How do I use the SVG icons? =

Go to Icons → Icon library. Give the icon a name, choose an SVG file and add it. On WordPress 7.1 or newer the icon then appears in the Icon block under "Easy SVG". You need administrator rights, because an icon is available site-wide. There is no limit on how many icons you keep.

On older WordPress versions the Icon block does not exist, so the icons do not appear in the editor; rendering them with `easy_svg_icon()` works there too.

Where you cannot write PHP, use the shortcode: `[easy_svg_icon name="easy-svg/heart"]`. It takes `class` and `label` like the PHP function does, and renders the same thing. Each icon's copy button in the library offers all three forms: PHP, shortcode and block. The Icon block only draws paths and polygons, so for an icon drawn with other shapes (a rectangle, a circle) the block form is not offered.

= For add-on authors: what may an add-on rely on? =

Exactly one function. Everything else in this plugin is an internal detail that may be renamed or removed.

`easy_svg_sanitizer()` returns a sanitizer configured with this site's allowed tags and attributes, or null when the sanitizing library is not loaded. Use it rather than the classes behind it, so an add-on cleans files exactly the way the site does.

`EASY_SVG_API` is an integer that changes only when that surface changes shape. It is 3. Compare against it rather than against the plugin version.

`EASY_SVG_PANEL_API` is a second integer, for the Icons panel's extension points: `easy_svg_panel_enqueue`, `easy_svg_panel_menu_tabs` and `easy_svg_max_mb_ceiling`. It is 3. Version 2 added the five labelled areas a tab names with `area`, a tab's own drawing through `icon`, and a capability per tab, which `easy_svg_panel_menu_tabs` now carries as `array( 'label' => …, 'capability' => … )` beside the plain label it still accepts; 3 added `registerCollections()`, through which an add-on lists its icon collections inside the Icon library tab. An add-on written for 1 or 2 keeps working on 3. Its own number because the sanitizer surface and the panel surface move for different reasons, and a filter cannot be asked about (nothing registers one), so an add-on that contributes a menu entry or raises the ceiling has no way to tell an older plugin apart except by this.

The hooks below are part of that surface too, because this plugin's own add-on already depends on two of them. Everything not named here or in those two answers is an internal detail that may be renamed or removed.

= For add-on authors: which hooks are there? =

`easy_svg_icon_markup` filters the markup of one icon on its way out of `easy_svg_icon()`, as `( string $markup, string $slug, string $collection )`. That covers the template tag and everything that renders through it, a page builder's icon element included. The Icon block does not: WordPress holds the markup it was handed when the collection was registered, and draws that. `easy_svg_registrable_icons` below is the hook for that half. Add a class or a `<title>` with this. Return your own string and you own the escaping.

`easy_svg_update_icon_markup( int $id, string $markup )` replaces one stored icon's drawing and answers with a word: `updated`, `not_found`, `no_markup`, `refused` or `failed`. The bytes go through this site's sanitizer and the same size and complexity bounds an upload meets, so a repaired icon is not the one way into the store that skips them. Neither the slug nor the title moves: this changes the drawing, not which icon it is.

`easy_svg_rename_icon( int $id, string $label )` changes one stored icon's visible name and answers with a word: `renamed`, `not_found`, `no_label` or `failed`. Use it rather than writing the post yourself: `wp_update_post()` re-saves the row whole, so the markup passes kses again and the icon is quietly emptied for anybody without `unfiltered_html`, and the cached icon list has to be dropped afterwards or the editor offers the old name for up to a day. The slug is never touched: it is the icon's identity, and a saved Icon block carries it.

`easy_svg_registrable_icons` filters the whole library on its way to WordPress's icon registry, as one list of `{ slug, label, content }`. This is the only place the Icon block's markup can be changed: core's registry keeps a short allow-list of its own and offers no filter, so sizing or recoloring an icon has to happen before it is registered. Drop an entry and that icon is not offered at all. Answer with something that is not a list and nothing is registered, which is the safe reading rather than a fatal. `EASY_SVG_ICONS_API` is defined when this hook exists, because a filter cannot be probed for.

`easy_svg_panel_enqueue` fires once the Icons panel's script is registered, with that script's handle as its only argument. Make it a dependency of your own bundle and yours loads after ours, into the same panel.

`easy_svg_pro_url` filters the address behind the add-on link in this plugin's row on the Plugins screen. The link is only there when the add-on is not installed; return your own address to point it somewhere else.

`easy_svg_panel_menu_tabs` filters which tabs get their own entry in the Icons menu, as `tab id => label`. An add-on that registers a tab adds its entry here, and the entry opens the panel with that tab already open.

`easy_svg_max_mb_ceiling` (20) is the highest cap the Icons screen lets a site set, in megabytes. Raising it widens the field and the slider; the stored value is clamped to it on save.

`easy_svg_max_bytes` is the largest SVG the uploader accepts, in bytes. It defaults to the setting on the Icons screen. A file over it is refused, never truncated.

`easy_svg_max_php_tags` (10) and `easy_svg_max_use_tags` (1000) are two structural bounds checked before sanitizing. They are not about size: the sanitizer rescans the whole document once per nested PHP processing instruction, and once per `<use>` for every element with an id, so a file well under the byte cap can hold a worker for minutes. Raising them raises that ceiling. No icon and no ordinary drawing comes near either.

`easy_svg_respect_network_filetypes` (true) decides whether a multisite network's own upload-filetype setting is honoured. Left alone, a network that does not permit SVG keeps not permitting it whatever a single site says. Return false to let a site decide for itself.

= For add-on authors: which hooks loosen a security decision? =

Three, and what they cost is worth stating. Nothing in this plugin's settings can switch sanitizing off, so reaching these is a deliberate act of code on a site whose owner accepted the trade.

`esw_svg_allowed_tags` and `esw_svg_allowed_attributes` are the sanitizer's allow-list of elements and of attributes. Adding to either lets markup through that is refused today. `<script>`, `on*` handlers and `javascript:` URLs are refused for the ordinary reason, and admitting them makes an uploaded file able to run code in your admins' browsers. Admitting a drawing element that an icon library needs is the case these exist for.

`esw_svg_remove_remote_references` (true) decides whether references to other hosts are stripped out of an uploaded SVG. Left alone, an SVG cannot pull anything from somewhere else. Turned off, an uploaded file can fetch from a third-party host on your visitors' behalf, a request that leaves your site and can be logged by whoever receives it.

= Where can I get help? =

Please ask in the [support forum](https://wordpress.org/support/plugin/easy-svg/).

= Where is the source code? =

On GitHub: [github.com/unleash-wp/easy-svg](https://github.com/unleash-wp/easy-svg).

== Screenshots ==

1. An SVG in an Image block in the block editor.
2. Upload SVG files in the media library like any other image.
3. An SVG as the featured image of a post.

== Changelog ==

= 5.4.0 =
* New: the panel says which halves are installed: a badge beside the product name when the add-on is licensed, and both version numbers in the footer. Until now the only place that named the add-on's version was its own license screen.
* Change: the Icons entry now sits at the bottom of the admin menu and carries the UnleashWP mark instead of a generic glyph.
* New: the plugin's row on the Plugins screen links straight to the Icons screen, and offers the paid add-on to a site that does not have it. The offer disappears the moment that add-on is installed.
* New: for add-on authors, `easy_svg_update_icon_markup()` replaces an icon's drawing through the sanitizer and the bounds an upload meets, leaving its name and slug alone, so an add-on can repair a broken SVG without becoming the way into the store that skips those checks. `EASY_SVG_ICONS_API` is 3.
* New: for add-on authors, `easy_svg_rename_icon()` renames one stored icon, carrying the kses handling and the cache bust that writing the post by hand would have to repeat. Getting either wrong empties the icon or shows the old name for a day. `EASY_SVG_ICONS_API` is 2.
* New: for add-on authors, `easy_svg_registrable_icons` filters the whole library on its way to WordPress's icon registry. That is the only place the Icon block's markup can be changed (core's registry keeps an allow-list of its own and offers no filter), so an add-on can size or recolor an icon there. `EASY_SVG_ICONS_API` says the hook is present, because a filter cannot be probed for.
* Fix: icons drawn with strokes (most outline sets) showed as an empty box in the Icon block and anywhere a template calls `wp_get_icon()`. WordPress's icon registry keeps only paths and polygons and drops `stroke` entirely; the icon now carries its stroke as a class that a small stylesheet restores, and circles, rects, ellipses, lines and polylines are handed over as the paths they are. Nothing changes for icons that are plain filled paths.
* New: the editor says so when an Icon block points at an icon that is no longer here. A collection leaves when a theme is switched, a plugin is deactivated or a folder is renamed, and until now the block simply drew nothing, on the page as well, where the first person to notice was a visitor.
* Fix: German, formal. One sentence addressed the reader informally on a site set to "Sie": the one shown when the sanitizer is missing. The tool that builds the formal catalog did not recognize the verb, so it was copied through unchanged and nothing said otherwise.

= 5.3.2 =
* Security: an SVG could keep an animation that runs on load by writing the element behind a namespace prefix. The neutraliser reads the element's own name now, prefix or not.
* Fix: the German translation this plugin ships was read by nobody. WordPress stops looking as soon as it has a language pack, so what the download carries was never used; it is loaded underneath the pack now, which means a string translators have not reached yet is no longer English.
* Fix: German arrives on sites set to "Sie", to Austrian or to Swiss German. WordPress looks for the exact locale, and only one German catalog shipped.
* Fix: the upload size cap could not be set above 20 MB however high an add-on raised the ceiling, and the field kept showing the number it was not going to save.
* Fix: searching the icon library said the library held as many icons as the search found, so a search that matched nothing reported an empty library.
* Fix: the search field's magnifier sat on the first letters of what you typed.
* Fix: the icon grid worked out its columns from the window rather than from the space it has, so icon names broke across two lines on a narrow screen.
* Change: the panel self-hosts Ubuntu again. It was removed in 5.1.0; the panel now ships the two weight files beside its own license text, and no request leaves your site for them.
* Fix: the documented `easy_svg_icon_markup` filter said it reached the Icon block. It does not. The readme now says which paths it covers.

= 5.3.1 =
* Fix: uninstalling now removes the plugin's settings and schema marker as well, on every site of a network.
* Fix: the readme described menus that no longer exist. Everything lives under Icons.
* Change: only the German translation ships with the plugin. The bundled empty translation files for 14 other languages blocked future community translations from wordpress.org; those now load as soon as they exist.

= 5.3.0 =
* New: the Icons screen has a header, a footer and a sidebar you can collapse, with an icon for each section.
* Fix: screen readers now read the description of each setting, not only its name.
* Fix: text fields show the plugin's own focus ring instead of the WordPress default.
* Fix: an empty SVG and an SVG that could not be saved now get two different messages, each saying what happened.

= 5.2.1 =
* Housekeeping: three developer scripts were being packaged with the plugin. They never ran on your site and are gone from the download. Nothing else changed.

= 5.2.0 =
* New: add icons from the media library. The SVGs you have already uploaded are two clicks from being icons. Pick several at once, and each one is sanitized and hardened on the way in, exactly as an upload is.
* New: drop SVG files onto the library to add them. The drop area sits where the icons are, instead of being a tile the size of a postage stamp.
* Fix: a file dropped beside the drop area used to open in the browser and take the Icons screen with it, unsaved settings included. It is caught now, with a line saying where the files go.
* Fix: saving one setting over the REST API no longer switches off the settings that were not part of the request. The Icons screen was never affected; a script or another plugin writing a single value was.
* Fix: new text reaches the translation files again. Strings added since 5.0 were English in every language, German included.

= 5.1.0 =
* New: one Icons screen. SVG uploads, the icon library and everything else now live together under Icons, instead of being split across Settings and Media. The two older screens are gone; nothing you had is lost and no setting changed its meaning.
* New: the icon library is a grid you can search, and an icon can be renamed without losing the slug a template already writes.
* New: the hooks this plugin fires are documented, under "For add-on authors" in the FAQ. Nine of them, with what three of them cost.
* Fix: every path that adds an icon now has the same size and complexity limits as an upload. A file that is too large, or shaped to make the sanitizer expensive, is refused rather than stored.
* Fix: an icon rendered by `easy_svg_icon()` carries the right accessibility role: decorative unless you give it a label.
* Fix: a library with no icons no longer offers an empty collection to the Icon block.
* Fix: German and every other translation now reaches the screen's JavaScript, not just its PHP.
* Fix: an icon you call meaningful is always named. `easy_svg_icon( 'x', [ 'decorative' => false ] )` without a label used to render an image with no accessible name at all; it now falls back to the icon's own title, then to its slug read back as words.
* Change: no font file ships with this plugin any more. The panel used to self-host Ubuntu; it now starts from the type your system already has, which is what it fell back to whenever that download failed anyway.

= 5.0.0 =
* Security: the bundled sanitizer (enshrined/svg-sanitize) is updated from 0.22.0 to 1.0.0. This fixes four published advisories: GHSA-9rjx-3jch-6vjf, GHSA-m9xh-6747-9r6f, GHSA-v383-3rw5-q8rf and GHSA-qhmf-972w-m957. SVGs that rely on custom DTD entities, as some older Illustrator exports do, are now refused.
* Security: SVGs added outside the media uploader, for example through WP-CLI `wp media import`, `media_sideload_image()`, importers or raw REST uploads, are now sanitized too, or refused.
* Security hardening of the upload checks.
* Note: updating does not re-check SVGs already in your media library; sanitizing happens when a file is added. Re-upload anything added before this version, or outside the media uploader, if you are unsure about it.
* External references in uploaded SVGs are now removed by default; filter `esw_svg_remove_remote_references` restores the old behavior.
* On multisite, SVG is offered only where the network's "Upload file types" includes it; filter `easy_svg_respect_network_filetypes` turns this off.
* New: SVG icons for the Icon block under Media → SVG icons (WordPress 7.1 and newer). Each icon is sanitized with this site's allowed tags and attributes before it is stored.
* New: `easy_svg_sanitizer()` and `EASY_SVG_API` for add-on authors.
* Fix: a file the sanitizer cannot read now gets a clear upload error.
* Fix: the global `$sanitizer` from 4.1 is back, and site snippets that configure it work again.
* Removed an unused AJAX endpoint.
* Uninstalling removes the plugin's SVG icons. Uploaded SVG files stay in the media library.
* License: GPL-3.0-or-later, stated the same way everywhere, with the full license text included.
* Tested up to WordPress 7.1.

= 4.1 =
* Security: server-side SVG file type detection; every genuine SVG upload is sanitized, and spoofed uploads are rejected.
* Updated SVG sanitizer.
* Code quality improvements for the WordPress plugin guidelines.

= 4.0 =
* Updated SVG sanitizer.
* Compatibility with the current WordPress version.

= 3.9 =
* Updated SVG sanitizer and license.
* Compatibility with WordPress 6.8.

= 3.8 =
* Security fix for the image uploader. Props to Francesco Carlucci and Wordfence.
* Requires PHP 8.0 and WordPress 6.0 or newer.

Older versions: see the [tags on GitHub](https://github.com/unleash-wp/easy-svg/tags).

== Upgrade Notice ==

= 5.4.0 =
Security: an SVG could keep an animation that runs on load by hiding the element
behind a namespace prefix. The German translation this plugin ships is now read
on formal, Austrian and Swiss sites too. Three new hooks reach the icon library.
No setting changes on update.

= 5.3.1 =
Housekeeping: a complete uninstall, corrected menu paths in the readme, and room for community translations. No behavior changed on a running site.

= 5.3.0 =
A new layout for the Icons screen and clearer messages when an SVG cannot be added. No settings change on update.

= 5.2.1 =
Packaging only: three developer files no longer ship with the plugin. No behavior changed.

= 5.2.0 =
Add icons straight from the media library, and drop SVG files onto the library to add them. A dropped file that misses no longer navigates away and loses what you had open. Translations cover the newer strings again.

= 5.1.0 =
SVG uploads and the icon library now share one screen under Icons. Nothing is lost in the move and no setting changed its meaning. Every path that adds an icon is now bounded the way an upload is. The panel no longer ships a webfont and uses your system type instead.

= 5.0.0 =
Security: fixes four published security advisories in the bundled sanitizer, and cleans SVGs from `wp media import`, importers or REST. SVGs with custom DTD entities are refused. Features are now opt-in under Settings → Easy SVG; sites already using uploads keep them. Re-upload older files.
