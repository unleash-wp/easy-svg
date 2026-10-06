=== Easy SVG Support ===
Contributors: Benjamin_Zekavica
Tags: svg, svg upload, sanitize, icons, media
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 4.3
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Upload SVG files safely. Every SVG is sanitized automatically, shown in your media library, and usable as an icon in WordPress 7.1.

== Description ==

Easy SVG Support lets you upload SVG files to WordPress and use them like any other image: in the block editor, as a featured image, in galleries and in custom fields. Activate it and you are done.

SVG is not a picture format like PNG or JPEG. It is XML, and an SVG file can carry scripts, event handlers and references to other files or websites. That is why WordPress does not allow SVG uploads on its own. Easy SVG Support cleans every SVG with the maintained [enshrined/svg-sanitize](https://github.com/darylldoyle/svg-sanitizer) library before the file is stored, and refuses files it cannot clean.

The plugin deliberately stays small. There is no settings page, no tracking, no external requests and no ads.

= Features =

* **SVG uploads** in the media library and the block editor.
* **Automatic sanitizing** before an SVG is stored, on the usual ways a file arrives: the media uploader, the REST API, WP-CLI `wp media import` and `media_sideload_image()`.
* **A safety net for everything else.** Every new SVG attachment is checked once more when it is created, for example by an importer. A file that cannot be cleaned is removed again.
* **XML-RPC uploads** are accepted only when the SVG is already clean.
* **SVG previews** in the media library.
* **SVG icons for the Icon block** (WordPress 7.1 and newer). Keep your own icons under Media → SVG icons and use them in the Icon block. Each icon is sanitized with the same rules as your uploads.
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
4. Upload SVG files in the media library as usual.

You can also download the plugin as a zip file and upload it under Plugins → Add New Plugin → Upload Plugin.

No configuration is needed.

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

On WordPress 7.1 or newer, go to Media → SVG icons. Give the icon a name, choose an SVG file and add it. The icon then appears in the Icon block under "Easy SVG". You need the capability to edit theme options, because an icon is available site-wide. There is no limit on how many icons you keep.

On older WordPress versions the SVG icons screen does not appear, and everything else works as before.

= For add-on authors: what may an add-on rely on? =

Exactly one function. Everything else in this plugin is an internal detail that may be renamed or removed.

`easy_svg_sanitizer()` returns a sanitizer configured with this site's allowed tags and attributes, or null when the sanitizing library is not loaded. Use it rather than the classes behind it, so an add-on cleans files exactly the way the site does.

`EASY_SVG_API` is an integer that changes only when that function changes shape. It is 3. Compare against it rather than against the plugin version.

= Where can I get help? =

Please ask in the [support forum](https://wordpress.org/support/plugin/easy-svg/).

= Where is the source code? =

On GitHub: [github.com/unleash-wp/easy-svg](https://github.com/unleash-wp/easy-svg).

== Screenshots ==

1. An SVG in an Image block in the block editor.
2. Upload SVG files in the media library like any other image.
3. An SVG as the featured image of a post.

== Changelog ==

= 4.3 =
* Security: the bundled sanitizer (enshrined/svg-sanitize) is updated from 0.22.0 to 1.0.0. This fixes four published advisories: GHSA-9rjx-3jch-6vjf, GHSA-m9xh-6747-9r6f, GHSA-v383-3rw5-q8rf and GHSA-qhmf-972w-m957. SVGs that rely on custom DTD entities, as some older Illustrator exports do, are now refused.
* Security: SVGs added outside the media uploader, for example through WP-CLI `wp media import`, `media_sideload_image()`, importers or raw REST uploads, are now sanitized too, or refused.
* Security hardening of the upload checks.
* Note: updating does not re-check SVGs already in your media library; sanitizing happens when a file is added. Re-upload anything added before this version, or outside the media uploader, if you are unsure about it.
* External references in uploaded SVGs are now removed by default; filter `esw_svg_remove_remote_references` restores the old behaviour.
* New: SVG icons for the Icon block under Media → SVG icons (WordPress 7.1 and newer). Each icon is sanitized with this site's allowed tags and attributes before it is stored.
* New: `easy_svg_sanitizer()` and `EASY_SVG_API` for add-on authors.
* Fix: a file the sanitizer cannot read now gets a clear upload error.
* Fix: the global `$sanitizer` from 4.1 is back, and site snippets that configure it work again.
* Removed an unused AJAX endpoint.
* Uninstalling removes the plugin's SVG icons. Uploaded SVG files stay in the media library.
* Licence: GPL-3.0-or-later, stated the same way everywhere, with the full licence text included.
* Tested up to WordPress 7.1.

= 4.1 =
* Security: server-side SVG file type detection; every genuine SVG upload is sanitized, and spoofed uploads are rejected.
* Updated SVG sanitizer.
* Code quality improvements for the WordPress plugin guidelines.

= 4.0 =
* Updated SVG sanitizer.
* Compatibility with the current WordPress version.

= 3.9 =
* Updated SVG sanitizer and licence.
* Compatibility with WordPress 6.8.

= 3.8 =
* Security fix for the image uploader. Props to Francesco Carlucci and Wordfence.
* Requires PHP 8.0 and WordPress 6.0 or newer.

Older versions: see the [tags on GitHub](https://github.com/unleash-wp/easy-svg/tags).

== Upgrade Notice ==

= 4.3 =
Security: fixes four published security advisories in the bundled sanitizer, and sanitizes SVGs added via `wp media import`, importers or raw REST. SVGs with custom DTD entities are refused. Files already in your library are not re-checked — re-upload older ones. New: SVG icons on WP 7.1.
