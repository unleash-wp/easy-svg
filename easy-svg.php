<?php
/*
Plugin Name:  Easy SVG Support
Plugin URI:   https://wordpress.org/plugins/easy-svg/
Description:  Add SVG support for WordPress.
Version:      4.3
Author:       UnleashWP
Author URI:   https://www.unleash-wp.com
Requires PHP: 8.0
Requires at least: 6.0
Text Domain:  easy-svg
Domain Path:  /languages
License:      GPL-3.0-or-later
License URI:  https://www.gnu.org/licenses/gpl-3.0.html

Easy SVG is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

Easy SVG is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with Easy SVG. If not, see license.txt or
https://www.gnu.org/licenses/gpl-3.0.html.

Copyright (C) 2017-2026 Benjamin Zekavica.
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Composer's autoloader, for the sanitiser. Written without a variable: this
// file runs in the global scope, so a `$composer_package` here was a global
// with nobody's prefix on it.
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require __DIR__ . '/vendor/autoload.php';
}

/*
 * The `esw_` names below -- functions, the two allow-list classes and the
 * `esw_svg_allowed_tags` / `esw_svg_allowed_attributes` filters -- are older
 * than the `easy_svg_` prefix and stay as they are: sites hook those filters
 * and call those functions, and renaming them would break every one of them.
 */

/*
 * The sanitiser 4.1 kept in a global, kept for the snippets that configure it.
 *
 * 4.1 created `$sanitizer` here and cleaned every upload with it, so a site
 * could write `global $sanitizer; $sanitizer->removeRemoteReferences( true );`
 * and have that apply. Dropping the global turned such a snippet into a fatal
 * call on null -- or, where it built its own, into a setting silently ignored.
 *
 * It is no longer the object anybody sanitises WITH: `easy_svg_sanitizer()`
 * hands each caller a copy of it (see there for why a shared instance is
 * wrong). It is the template the copies start from.
 *
 * Only created when the name is free. 4.1 overwrote it unconditionally, which
 * clobbered any other plugin's `$sanitizer` -- a common enough name -- and a
 * Sanitizer some earlier code already put there is exactly what should be
 * honoured, not replaced.
 */
if ( class_exists( '\enshrined\svgSanitize\Sanitizer' ) && ! isset( $GLOBALS['sanitizer'] ) ) {
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- the 4.1 name, kept for site snippets.
    $GLOBALS['sanitizer'] = new \enshrined\svgSanitize\Sanitizer();
}

/**
 * SVG Sanitizer Allowed Tags Class.
 *
 * Custom class to filter allowed SVG tags using WordPress filters.
 */
class esw_svg_tags extends \enshrined\svgSanitize\data\AllowedTags {

    /**
     * Returns allowed SVG tags.
     *
     * @return array
     */
    public static function getTags() {
        return apply_filters( 'esw_svg_allowed_tags', parent::getTags() );
    }
}

/**
 * SVG Sanitizer Allowed Attributes Class.
 *
 * Custom class to filter allowed SVG attributes using WordPress filters.
 */
class esw_svg_attributes extends \enshrined\svgSanitize\data\AllowedAttributes {

    /**
     * Returns allowed SVG attributes.
     *
     * @return array
     */
    public static function getAttributes() {
        return apply_filters( 'esw_svg_allowed_attributes', parent::getAttributes() );
    }
}

/**
 * The version of the contract this plugin offers to add-ons.
 *
 * The surface it covers:
 *
 *     easy_svg_sanitizer()      a sanitiser with this site's allow-list
 *
 * Bumped only when that surface changes shape. Everything else in this plugin
 * is an internal detail and may be renamed, moved or deleted without touching
 * this number.
 *
 * 2 added a filter over how many icons a site may keep. 3 removes it again:
 * the icon manager has no limit, so there is nothing left to filter, and an
 * add-on must not build on that filter or expect it to change anything. A
 * number says that where a version string cannot -- "4.3" with the filter and
 * "4.3" without it look the same to a comparison somebody wrote in a hurry.
 */
define( 'EASY_SVG_API', 3 );

/**
 * A sanitiser configured the way THIS SITE sanitises. The whole public surface.
 *
 * ─── Why an add-on gets a function and not the classes ──────────────────────
 *
 * `esw_svg_tags` and `esw_svg_attributes` are internals. An add-on that reaches
 * for them by name pins every rename in this file, and the breakage is silent:
 * `class_exists()` goes false, the add-on decides this plugin is not
 * installed, and it tells somebody to install something that is already
 * active. One documented function instead, and the rest is free to move.
 *
 * ─── Why this plugin uses it too ────────────────────────────────────────────
 *
 * Because otherwise it is a second path. The allow-list here is not the
 * library's default -- it goes through `esw_svg_allowed_tags`, which sites
 * widen for `style` or for animation elements -- and an add-on configured any
 * other way would report and remove things this site never would. One
 * function, used by both, is the only version of that which cannot drift.
 *
 * @return \enshrined\svgSanitize\Sanitizer|null Null when the library is absent.
 */
function easy_svg_sanitizer() {
    if ( ! class_exists( '\enshrined\svgSanitize\Sanitizer' ) ) {
        return null;
    }

    /*
     * One instance per call. The 4.1 global was reconfigured on every upload,
     * so two callers meant whichever ran last decided what the other one
     * stripped.
     *
     * A COPY of that global when it holds a Sanitizer, so whatever a site
     * snippet set on it -- remote references, minifying, the XML declaration,
     * nesting limits -- still applies, as it did in 4.1. A clone is safe to
     * hand out: the library builds a new DOMDocument on every sanitize() call,
     * so nothing a caller does reaches the template. The allow-lists are set on
     * the copy below and replace anything set on the global; 4.1 did exactly
     * that on every upload, so a snippet never could set them there.
     *
     * Anything else under that name is somebody else's variable and is left
     * alone.
     */
    $legacy    = isset( $GLOBALS['sanitizer'] ) ? $GLOBALS['sanitizer'] : null;
    $sanitizer = $legacy instanceof \enshrined\svgSanitize\Sanitizer
        ? clone $legacy
        : new \enshrined\svgSanitize\Sanitizer();

    $sanitizer->setAllowedTags( new esw_svg_tags() );
    $sanitizer->setAllowedAttrs( new esw_svg_attributes() );

    return $sanitizer;
}

/**
 * Whether the sanitiser PHP will use is the version this plugin ships.
 *
 * Composer classes are global, and the first autoloader to answer for a class
 * wins. A plugin that bundles an older copy of this library and loads first
 * puts ITS Sanitizer behind every upload here. `removeDoctype()` exists only
 * from 1.0.0 on -- it is the fix for the most serious of the advisories that
 * release closed -- so its presence is what tells the two apart.
 *
 * @param string $class The sanitiser class; injectable so it can be checked.
 * @return bool
 */
function easy_svg_sanitizer_is_current( $class = '\\enshrined\\svgSanitize\\Sanitizer' ) {
    return class_exists( $class ) && method_exists( $class, 'removeDoctype' );
}

/**
 * The sentence for an outdated sanitiser, or '' when there is nothing to say.
 *
 * Names the file the class came from, which is the quickest way for an
 * administrator to find the plugin responsible.
 *
 * @param string $class The sanitiser class.
 * @return string
 */
function easy_svg_outdated_sanitizer_message( $class = '\\enshrined\\svgSanitize\\Sanitizer' ) {
    if ( ! class_exists( $class ) || easy_svg_sanitizer_is_current( $class ) ) {
        return '';
    }

    $reflection = new ReflectionClass( $class );
    $source     = (string) $reflection->getFileName();
    if ( defined( 'WP_CONTENT_DIR' ) && 0 === strpos( $source, WP_CONTENT_DIR ) ) {
        $source = 'wp-content' . substr( $source, strlen( WP_CONTENT_DIR ) );
    }

    return sprintf(
        /* translators: %s: path of the file the older sanitizer was loaded from */
        __( 'Easy SVG Support: another plugin has loaded an older version of the SVG sanitizer (%s) before this plugin could load its own, so SVG uploads are being sanitized with that older version. Please update or deactivate the plugin it belongs to.', 'easy-svg' ),
        $source
    );
}

/**
 * Shown to administrators, on the screens where they would act on it.
 *
 * Once per request, on the dashboard, the plugins screen and the media
 * library -- not on every admin page, where it would be noise.
 */
function easy_svg_outdated_sanitizer_notice() {
    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }

    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins', 'upload' ), true ) ) {
        return;
    }

    $message = easy_svg_outdated_sanitizer_message();
    if ( '' !== $message ) {
        echo '<div class="notice notice-warning"><p>' . esc_html( $message ) . '</p></div>';
    }
}
add_action( 'admin_notices', 'easy_svg_outdated_sanitizer_notice' );

/**
 * Check and sanitize SVG file content.
 *
 * @param string $file Path to the file.
 * @return bool Returns true if file was sanitized successfully.
 */
function esw_svg_file_checker( $file ) {
    $sanitizer = easy_svg_sanitizer();

    if ( null === $sanitizer ) {
        return false;
    }

    $unclean = file_get_contents( $file );

    if ( false === $unclean ) {
        return false;
    }

    /*
     * Caught, and answered like any other file the sanitiser refuses. The
     * library throws a LogicException for well-formed XML without exactly one
     * <svg> root, where it returns false for markup it cannot parse; both mean
     * "not a usable SVG", and the caller turns false into an upload error the
     * person can read. Uncaught, the same file was a fatal error.
     */
    try {
        $clean = $sanitizer->sanitize( $unclean );
    } catch ( \Throwable $e ) {
        return false;
    }

    if ( false === $clean ) {
        return false;
    }

    // The cleaned bytes ARE the check. If they cannot be written back, the
    // file on disk is still the one that came in, and it must not be stored.
    if ( false === file_put_contents( $file, $clean ) ) {
        return false;
    }

    return true;
}

/**
 * Whether a filename names an SVG.
 *
 * File extensions are case-insensitive, so the comparison is too.
 *
 * @param string $name A filename or path.
 * @return bool
 */
function easy_svg_is_svg_name( $name ) {
    return 'svg' === strtolower( (string) pathinfo( (string) $name, PATHINFO_EXTENSION ) );
}

/**
 * Filter and sanitize uploaded SVG files using trusted file detection.
 *
 * This function does NOT rely on the user-controlled MIME header.
 * It uses wp_check_filetype_and_ext() and the file extension to reliably
 * detect SVG uploads and sanitize them. Inconsistent SVG uploads are rejected.
 *
 * @param array $file Array containing file details before upload.
 * @return array Modified file array or error message if invalid.
 */
function esw_svg_upload_filter_check_init( $file ) {

    // Bail if required keys are missing.
    if ( empty( $file['tmp_name'] ) || empty( $file['name'] ) ) {
        return $file;
    }

    // Server-side detection of extension and mime type.
    $checked = wp_check_filetype_and_ext(
        $file['tmp_name'],
        $file['name'],
        get_allowed_mime_types()
    );

    $type = isset( $checked['type'] ) ? strtolower( (string) $checked['type'] ) : '';

    /*
     * One rule for what is an SVG: its lower-cased extension says so, or the
     * type WordPress settled on does. Either way it is sanitised or refused --
     * never stored as it came.
     */
    $name_is_svg = easy_svg_is_svg_name( $file['name'] );
    $type_is_svg = 'image/svg+xml' === $type;

    if ( ! $name_is_svg && ! $type_is_svg ) {
        // Not an SVG. Nothing for this plugin to decide.
        return $file;
    }

    // Named like an SVG, but WordPress would not accept it as one (SVG not
    // allowed on this site, or the check refused the bytes).
    if ( ! $type_is_svg ) {
        $file['error'] = __( 'Sorry, this SVG file is not allowed for security reasons.', 'easy-svg' );
        return $file;
    }

    $file['type'] = 'image/svg+xml';

    // Sanitize SVG content before it is stored.
    if ( ! esw_svg_file_checker( $file['tmp_name'] ) ) {
        $file['error'] = __( 'Sorry, please check your SVG file.', 'easy-svg' );
    }

    return $file;
}
add_filter( 'wp_handle_upload_prefilter', 'esw_svg_upload_filter_check_init' );

/*
 * The same check for files that do not come from the media uploader.
 *
 * WordPress builds this hook name from the action:
 *
 *     $file = apply_filters( "{$action}_prefilter", $file );   wp-admin/includes/file.php
 *
 * and `$action` is `wp_handle_upload` OR `wp_handle_sideload`. Listening to the
 * first one only covered a person choosing a file in the media library, and
 * nothing else. Everything that sideloads went in unchecked:
 *
 *   - `media_sideload_image()`, which themes and page builders use to pull in
 *     remote assets
 *   - WP-CLI `wp media import`
 *   - importers, and anything else that hands WordPress a file it already has
 *
 * Safe on this path: for a local file WP-CLI copies to a temporary first
 * (`make_copy()` -> `copy()`), and a remote one arrives through `download_url()`,
 * so `tmp_name` is always a temporary copy. Sanitising in place never touches
 * the file somebody passed in.
 */
add_filter( 'wp_handle_sideload_prefilter', 'esw_svg_upload_filter_check_init' );

/**
 * Whether SVG markup is already clean: the sanitiser would remove nothing.
 *
 * Compared by structure, not by bytes -- the sanitiser re-serialises every
 * file, so its output never equals the input byte for byte, even when it took
 * nothing out. Elements, attributes, text and processing instructions are
 * compared; whitespace, the XML declaration and comments are not.
 *
 * @param string $markup SVG markup.
 * @return bool False when the sanitiser refuses it or would change it.
 */
function easy_svg_markup_is_clean( $markup ) {
    $sanitizer = easy_svg_sanitizer();
    if ( null === $sanitizer ) {
        return false;
    }

    try {
        $clean = $sanitizer->sanitize( (string) $markup );
    } catch ( \Throwable $e ) {
        return false;
    }

    if ( ! is_string( $clean ) || '' === trim( $clean ) ) {
        return false;
    }

    $fingerprint = static function ( $xml ) {
        $doc      = new DOMDocument();
        $internal = libxml_use_internal_errors( true );
        // No entity substitution and no network: only the markup as written.
        $loaded = $doc->loadXML( $xml, LIBXML_NONET );
        libxml_clear_errors();
        libxml_use_internal_errors( $internal );
        if ( ! $loaded || null === $doc->documentElement ) {
            return null;
        }
        $parts = array();
        $xpath = new DOMXPath( $doc );
        foreach ( $xpath->query( '//*' ) as $element ) {
            $attrs = array();
            foreach ( $element->attributes as $attr ) {
                $attrs[] = $attr->nodeName . '=' . $attr->nodeValue;
            }
            sort( $attrs );
            $parts[] = $element->nodeName . '[' . implode( ' ', $attrs ) . ']';
        }
        $parts[] = 'pi:' . $xpath->query( '//processing-instruction()' )->length;
        $parts[] = 'text:' . preg_replace( '/\s+/', ' ', trim( $doc->documentElement->textContent ) );
        return implode( "\n", $parts );
    };

    $before = $fingerprint( (string) $markup );

    return null !== $before && $before === $fingerprint( $clean );
}

/**
 * The check for `wp_upload_bits()`, which writes files without any upload
 * filter. XML-RPC media uploads go through it.
 *
 * Its filter cannot replace the bytes, only refuse them -- so an SVG passes
 * only when the sanitiser would leave it exactly as it is. Anything else is
 * refused with a sentence; the media library, which cleans files, is the way
 * to upload it.
 *
 * Empty bits pass: the WordPress importer reserves a file that way and fills
 * it afterwards, and the attachment check below sees the finished file.
 *
 * @param array $upload { name, bits, time }.
 * @return array|string The upload unchanged, or an error message.
 */
function easy_svg_upload_bits_check( $upload ) {
    if ( ! is_array( $upload ) || empty( $upload['name'] ) || ! easy_svg_is_svg_name( $upload['name'] ) ) {
        return $upload;
    }

    if ( ! isset( $upload['bits'] ) || '' === (string) $upload['bits'] ) {
        return $upload;
    }

    if ( easy_svg_markup_is_clean( (string) $upload['bits'] ) ) {
        return $upload;
    }

    return __( 'This SVG file contains content this site does not allow. Please upload it through the media library, which cleans it.', 'easy-svg' );
}
add_filter( 'wp_upload_bits', 'easy_svg_upload_bits_check' );

/**
 * The last check: every new SVG attachment, however its file got there.
 *
 * Importers copy files into place themselves, and other plugins write files
 * and then register them; none of that passes an upload filter. So when an
 * attachment that is an SVG is created, its file is sanitised in place with
 * the same checker the uploads use. If the sanitiser cannot use it, the
 * attachment and its file are deleted -- a file nobody could clean must not
 * stay reachable under the uploads URL.
 *
 * For a file that already went through the upload filter this is a second,
 * harmless pass. An attachment with no local file (offloaded media, an
 * attachment that only points somewhere) is left alone: there is nothing here
 * to check, and deleting it would lose somebody's library entry.
 *
 * @param int $attachment_id The new attachment.
 */
function easy_svg_check_new_attachment( $attachment_id ) {
    $path = get_attached_file( $attachment_id );
    $mime = strtolower( (string) get_post_mime_type( $attachment_id ) );

    if ( ! is_string( $path ) || '' === $path ) {
        return;
    }

    if ( 'image/svg+xml' !== $mime && ! easy_svg_is_svg_name( $path ) ) {
        return;
    }

    if ( ! is_file( $path ) || ! is_readable( $path ) ) {
        return;
    }

    if ( ! esw_svg_file_checker( $path ) ) {
        wp_delete_attachment( $attachment_id, true );
    }
}
add_action( 'add_attachment', 'easy_svg_check_new_attachment' );

/*
 * The icon manager.
 *
 * Required unconditionally, and registered here. Not behind `is_admin()`: the
 * icons have to be registered on the front end too, because the Icon block is
 * SERVER-rendered and `wp_get_icon()` resolves the name when the page is built.
 * An admin-only registration would show every icon in the editor and nothing at
 * all to a visitor.
 *
 * Priority 5 for the store and 10 for the icons, both after core's own
 * collections at 0. The order between the two is not a preference: the icons
 * are read out of the post type.
 */
require_once __DIR__ . '/includes/icon-manager.php';

add_action( 'init', 'easy_svg_register_icon_store', 5 );
add_action( 'init', 'easy_svg_boot_icons', 10 );

/*
 * The icon list is cached between requests (see `easy_svg_stored_icons()`), and
 * these drop it. On the post type rather than in the screen's handlers, and on
 * every request rather than in wp-admin only: WP-CLI, importers and REST
 * clients write icons too, and their icons must appear just the same.
 */
add_action( 'save_post_' . EASY_SVG_ICON_POST_TYPE, 'easy_svg_forget_icons' );
add_action( 'deleted_post', 'easy_svg_forget_deleted_icon', 10, 2 );

easy_svg_icons_admin();

/**
 * Add support for SVG file uploads by modifying MIME types.
 *
 * @param array $mimes File type associations.
 * @return array Modified MIME types with SVG support.
 */
if ( ! function_exists( 'esw_add_support' ) ) {
    function esw_add_support( $mimes ) {
        $mimes['svg'] = 'image/svg+xml';
        return $mimes;
    }
    add_filter( 'upload_mimes', 'esw_add_support' );
}

/**
 * Validate uploaded image files and ensure proper file extension and MIME type.
 *
 * @param array  $checked  File check results.
 * @param string $file     Path to the uploaded file.
 * @param string $filename The file name.
 * @param array  $mimes    Allowed MIME types.
 * @return array Checked results including extension, type, and filename.
 */
if ( ! function_exists( 'esw_upload_check' ) ) {

    function esw_upload_check( $checked, $file, $filename, $mimes ) {

        /*
         * Only ever about SVGs. fileinfo often cannot name an SVG (one without
         * an XML declaration reads as text), and core then drops its type; this
         * gives the type back so the upload filter can SANITISE the file rather
         * than have it refused. Every other file keeps core's verdict exactly
         * as core gave it.
         */
        if ( ! easy_svg_is_svg_name( $filename ) || ! empty( $checked['type'] ) ) {
            return $checked;
        }

        // Only where this site allows SVG at all.
        $by_name = wp_check_filetype( $filename, $mimes );
        if ( 'image/svg+xml' !== strtolower( (string) $by_name['type'] ) ) {
            return $checked;
        }

        return array(
            'ext'             => 'svg',
            'type'            => 'image/svg+xml',
            'proper_filename' => isset( $checked['proper_filename'] ) ? $checked['proper_filename'] : false,
        );
    }
    add_filter( 'wp_check_filetype_and_ext', 'esw_upload_check', 10, 4 );
}

/**
 * Display SVG files properly in the media library.
 *
 * @param array  $response   File response array.
 * @param object $attachment Attachment object.
 * @param array  $meta       File metadata.
 * @return array Modified response with SVG dimensions.
 */
if ( ! function_exists( 'esw_display_svg_media' ) ) {

    function esw_display_svg_media( $response, $attachment, $meta ) {

        if (
            isset( $response['type'], $response['subtype'] ) &&
            'image' === $response['type'] &&
            'svg+xml' === $response['subtype'] &&
            class_exists( 'SimpleXMLElement' )
        ) {
            try {
                $path = get_attached_file( $attachment->ID );
                if ( file_exists( $path ) ) {
                    $svg    = new SimpleXMLElement( file_get_contents( $path ) );
                    $src    = $response['url'];
                    $width  = (int) $svg['width'];
                    $height = (int) $svg['height'];

                    $response['image'] = compact( 'src', 'width', 'height' );
                    $response['thumb'] = compact( 'src', 'width', 'height' );

                    $response['sizes']['full'] = array(
                        'height'      => $height,
                        'width'       => $width,
                        'url'         => $src,
                        'orientation' => ( $height > $width ) ? 'portrait' : 'landscape',
                    );
                }
            } catch ( Exception $e ) {
                // Fail silently, keep default response if SVG parsing fails.
            }
        }

        return $response;
    }
    add_filter( 'wp_prepare_attachment_for_js', 'esw_display_svg_media', 10, 3 );
}

/**
 * Add styles for SVG files in the media library and Gutenberg editor.
 */
if ( ! function_exists( 'esw_svg_styles' ) ) {
    function esw_svg_styles() {
        echo "<style>
                /* Media Library SVG styles */
                table.media .column-title .media-icon img[src*='.svg'] {
                    width: 100%;
                    height: auto;
                }

                /* Gutenberg editor SVG styles */
                .components-responsive-wrapper__content[src*='.svg'] {
                    position: relative;
                }
            </style>";
    }
    add_action( 'admin_head', 'esw_svg_styles' );
}