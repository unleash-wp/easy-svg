=== Easy SVG Support ===
Contributors: Benjamin_Zekavica
Tags: svg, svg support, upload svg, svg media, icons
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 4.3
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

This Plugin allows you to upload SVG Files into your Media library.

== Description ==

= Direct Upload SVG Files into WordPress  =

EASY SVG Support is a Plugin which allows you to upload SVG Files into your Media library. This plugin was created for persons, who don’t need much options for SVG.

Every SVG is sanitized before it is stored, whichever way it arrives: the media uploader, REST uploads, WP-CLI `wp media import`, `media_sideload_image()`, importers and XML-RPC media uploads. Every new SVG attachment is checked once more when it is created. A file that cannot be sanitized is rejected; XML-RPC uploads are accepted only when they are already clean.

= SVG icons for the Icon block (WordPress 7.1 and newer) =

Under Media → SVG icons you can keep your own SVG icons, as many as you like, and use them in the Icon block. Each icon goes through the same sanitizer and the same allowed tags and attributes as your uploads, and what is stored is the cleaned markup. Icons are not media files: they have no URL of their own and do not appear in the media library.

On WordPress versions before 7.1, which have no icon registry, the SVG icons screen does not appear and everything else works as before.

= Features of the plugin include: =

* Uploading SVG Support for WordPress
* Easy installation
* Display SVG Files in the Media Library
* SVG files are sanitized directly, on every upload path
* SVG Sanitize – Custom Hooks for Tags and Attributes
* SVG icons for the Icon block, unlimited (WordPress 7.1+)
* Updated for the WordPress block editor
* Requires PHP 8.0 or newer


= Documentation & Support =

Got a problem or need help with Easy SVG Support? Than you can write me an e-mail:

info@benjamin-zekavica.de or you can ask your question in the forums section.

== Installation ==

1. Activate the plugin.
2. Go to the Media Library and Upload your SVG Files.
3. Upload now your SVG Files.
4. Go to the Page or ACF and choose your File and save changes.


== Frequently Asked Questions ==

= SVG Sanitize – Allow Tags & Attributes Hooks =

**Hook: esw_svg_allowed_tags**



        // XML TAGS
        add_filter( 'esw_svg_allowed_tags', function ($tags) {
            $tags[] = 'p';
            $tags[] = 'info';
            
            return $tags;
        } );


**Hook: esw_svg_allowed_attributes**

        // XML attributes
        add_filter( 'esw_svg_allowed_attributes', function ( $attributes ) {
            $attributes[] = 'src';
            
            return $attributes;
        } );

= Where do I manage SVG icons? =

Under Media → SVG icons, on WordPress 7.1 or newer. Give the icon a name, choose an SVG file and add it; it then appears in the Icon block under "Easy SVG". You need the capability to edit theme options, because an icon is available site-wide. There is no limit on how many icons you keep.

= Do you need a Source Code? =

Please check out the repository on GitHub:

[GitHub Repository](https://github.com/unleash-wp/easy-svg)

== Screenshots ==
1. Easy SVG Support in Gutenberg
2. Upload direct into your WordPress Media
3. An SVG as the featured image in the block editor


== For add-on authors ==

This plugin offers exactly one function, and everything else in it is an
internal detail that may be renamed or removed:

`easy_svg_sanitizer()` returns a sanitizer configured with THIS SITE'S
allowed tags and attributes, or null when the sanitizing library is not
loaded.

`EASY_SVG_API` is an integer that changes only when that function changes
shape. It is 3. Compare against it rather than against a version string:
a release number moves for reasons that have nothing to do with this.

API 3 means the `easy_svg_icon_limit` filter no longer exists. Version 2
introduced it while icons were capped; the icon manager now has no limit,
so nothing reads that filter and an add-on must not rely on it.

Use the function rather than the classes behind it. A site widens the allow-list
through the `esw_svg_allowed_tags` and `esw_svg_allowed_attributes` filters,
so an add-on configured any other way removes things this site never would,
and reports problems that do not exist.

This plugin updates through wordpress.org and carries no update mechanism of
its own.

== Changelog ==
= 4.3 =
* Security: the bundled SVG sanitizer (enshrined/svg-sanitize) is updated from 0.22.0 to 1.0.0, which fixes four published advisories: GHSA-9rjx-3jch-6vjf (stored XSS through a DTD entity named like an HTML character reference), GHSA-m9xh-6747-9r6f (mixed-case xlink:href escaping the nested-`<use>` check), GHSA-v383-3rw5-q8rf (denial of service through a DTD attribute declaration) and GHSA-qhmf-972w-m957 (CSS injection and remote-reference bypass). Note: an SVG that relies on custom DTD entities, as some older Adobe Illustrator exports do, is now refused; export it again without them.
* Security: SVGs that do not arrive through the media uploader are now sanitized too, or rejected. WordPress builds the filter name from the action, so listening only to wp_handle_upload_prefilter left WP-CLI `wp media import`, media_sideload_image(), importers and REST uploads sent as a raw file body unchecked.
* Security: an SVG file the sanitizer cannot use (for example an HTML page saved as .svg) is now refused with an upload error instead of causing a fatal error.
* Security hardening of the upload checks.
* New: SVG icons for the Icon block, under Media → SVG icons. Needs WordPress 7.1, which is where the icon registry arrived; on older versions the screen does not appear and everything else works as before. As many icons as you like, each sanitized with this site's own allowed tags and attributes before it is stored.
* The icons screen shows previews that can never run scripts, even on a site that allows extra SVG tags or attributes.
* The icon list is cached and read only when it changes, so sites pay nothing on every request for it.
* Compatibility: the global `$sanitizer` from 4.1 exists again, and settings a site snippet makes on it are applied, as they were in 4.1.
* Removed an AJAX endpoint that could never fire. It was registered under a hook name WordPress does not build, so it had not worked in any released version, and nothing called it.
* For add-on authors: easy_svg_sanitizer() and EASY_SVG_API (3), so an add-on cleans files exactly the way this site does. See "For add-on authors".
* Licence: GPL-3.0-or-later, stated the same way everywhere, with the full licence text included.
* Tested with WordPress 7.1.

= 4.1: November 14, 2025 =
* Support for new WordPress version
* Support Gutenberg Version
* Updated SVG Sanitizer Package
* Security: Implemented trusted server-side SVG filetype detection using wp_check_filetype_and_ext().
* Security: Sanitizing is now enforced for all genuine SVG uploads.
* Security: Rejecting spoofed or inconsistent SVG uploads.
* Security: Hardened AJAX handler with capability checks and nonce verification.
* Improved code quality to match WordPress Plugin Guidelines.

= 4.0: September 2, 2025 =
* Support for new WordPress version
* Support Gutenberg Version
* Updated SVG Sanitizer Package

= 3.9: 1st of April, 2025 =
* Support for new WordPress version 6.8
* Support Gutenberg Version
* Updated SVG Sanitizer Package
* Updated License
* Code Optimizing

= 3.8: 4th of November, 2024 =
* Security Fix for Image Uploader | Props to Francesco Carlucci & Wordfence
* Support for new WordPress version 6.7
* Support Gutenberg Version
* Updated SVG Sanitizer Package
* Remove Support for 7.4 - Now it's imporant to use 8.0
* Remove WP Support for 5x - Now it's imporant to use 6.0
* Code Optimizing

= 3.7: 21th of June, 2024 =
* Support for new WordPress version 6.6
* Support Gutenberg Version
* Updated SVG Sanitizer Package
* Code Optimizing

= 3.6: 3rd of March, 2024 =
* Support for new WordPress version 6.5
* Support Gutenberg Version
* Updated SVG Sanitizer Package
* Code Optimizing

= 3.5: 5th of November, 2023 =
* Support for new WordPress version 6.4
* Support Gutenberg Version
* Updated Translation
* Better Support for PHP 8.2

= 3.4: 19th of June, 2023 =
* Support for new WordPress version 6.3
* Support Gutenberg Version
* Updated SVG svg-sanitize
* Better Support for PHP 8.2

= 3.3.1: 13th of March, 2023 = 
* Support for new WordPress version 6.2
* Support Gutenberg Version 

= 3.3.0: 29th of May, 2022 =

* Support for new WordPress version 6.0
* Support Gutenberg Version 
* SVG Sanitize Files direcly 
* Security Update
* New & updated POT-File for Translation
* SVG Sanitize – Custom Hooks for Tags and Attributes


= 3.2.0: 26th of January, 2022 =

* Support for new WordPress version 5.9
* Support Gutenberg Version 

= 3.1.0: 21th of July, 2021 =

* Support for new WordPress version 5.8
* Support Gutenberg Version 


= 3.0.0: 26th of May, 2021 =

* Support for new WordPress version

= 2.9.1: 28th of January, 2021 =

* Add PHP 8.0 support
* Support for new version of the Gutenberg Editor
* Support for new WordPress version

= 2.9: 28th of October, 2020 =

* Security Fixes
* Support for new WordPress version

= 2.8: 02th of July, 2020 =

* Security Fixes
* Updated Language files
* Support for new WordPress version

= 2.7: 24th of January, 2020 =

* Add Support for WordPress 5.3.2
* Gutenberg Editor Post Image Size  
* Security Fixes

= 2.6: 21th of September, 2019 =

* Add Support for WordPress 5.2.3
* Fixes

= 2.5.1: 12th of June, 2019 =

* Add Support for WordPress 5.2.1

= 2.5: 31th of March, 2019 =

* Add SVG Performance Update
* Add Security Update 
* Add Support for WordPress 5.1.1
* Add PHP 7.3 Support Update
* Remove external CSS Stylesheet -> Better Backend Performance (Write Less CSS Code in Style Tag into the Header)
* Some Changes and Fixes

= 2.4: 12th of December, 2018 =

* Higher Code Quality
* Security Update 
* Full Gutenberg Support in Backend


= 2.3: 8th of August, 2018 =

* (NEW Full WordPress 5.0 Support inc. Gutenberg Support
* (NEW) Now you can see all SVG Files in the Backend (ACF Support) and for Galleries 
* (REMOVE) Removing JavaScript File (The Plugin is now faster and easier)
* (CHANGE) Edit Language Files 


= 2.2.2: 27th of May, 2018 =

* Add correction of the new versions number


= 2.0.3: 27th of Febuary, 2018 =

* Add better security with index.php

= 2.0.2: 10th of Febuary, 2018 =

* Add new Versions Number

= 2.0.1: 10th of Febuary, 2018 =

* Add JQuery Function (Please Update now!)


= 2.0: 10th of Febuary, 2018 =

* Add better Security SVG Support(XML)
* Add better Code Quality and more code comments for WordPress Developers
* Add A Dashboard Widget to remember you for Easy SVG
* Display SVG Files into WordPress Media Libary
* Add Translation Files (Template)
* New Translation FIles for (EN, US, DE, DE Formal, HR)
* Add JavaScript to Backend
* Add CSS for Display SVG Files into the Backend

= 1.1: 29th of November, 2017 =

* Add a smole Alert message for users.

= 1.0.1: 28th of November, 2017 =

* Edit new Text


= 1.0.0: 28th of November, 2017 =

* Initial Release

== Upgrade Notice ==

= 4.3 =
Security: SVG sanitizer 1.0.0 (fixes GHSA-9rjx-3jch-6vjf, GHSA-m9xh-6747-9r6f, GHSA-v383-3rw5-q8rf, GHSA-qhmf-972w-m957). SVGs added via WP-CLI `wp media import`, media_sideload_image(), importers or raw REST uploads are now sanitized. New: SVG icons on WP 7.1+.
