<?php
/*
Plugin Name:  Easy SVG Support
Plugin URI:   https://wordpress.org/plugins/easy-svg/
Description:  Add SVG support for WordPress.
Version:      4.2
Author:       Benjamin Zekavica
Author URI:   https://www.benjamin-zekavica.de
Requires PHP: 8.0
Requires at least: 6.0
Text Domain:  easy-svg
Domain Path:  /languages
License:      GPL3

Easy SVG is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
any later version.

Easy SVG is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with Easy SVG. If not, see license.txt .

© 2017 - 2026 by Benjamin Zekavica. All rights reserved.
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Helper: Load Composer dependencies.
$composer_package = __DIR__ . '/vendor/autoload.php';

if ( file_exists( $composer_package ) ) {
    require $composer_package;
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
 * Bumped only when `easy_svg_sanitizer()` changes shape. Everything else in
 * this file is an internal detail and may be renamed, moved or deleted without
 * touching this number.
 */
define( 'EASY_SVG_API', 1 );

/**
 * A sanitiser configured the way THIS SITE sanitises. The whole public surface.
 *
 * ─── Why an add-on gets a function and not the classes ──────────────────────
 *
 * `esw_svg_tags` and `esw_svg_attributes` are internals. An add-on that reaches
 * for them by name pins every rename in this file, and the breakage is silent:
 * `class_exists()` goes false, the add-on decides the free plugin is not
 * installed, and it tells a paying customer to install something that is
 * already active. One documented function instead, and the rest is free to move.
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

    // A fresh instance per call. The old shared global was reconfigured on
    // every upload, so two callers meant whichever ran last decided what the
    // other one stripped.
    $sanitizer = new \enshrined\svgSanitize\Sanitizer();
    $sanitizer->setAllowedTags( new esw_svg_tags() );
    $sanitizer->setAllowedAttrs( new esw_svg_attributes() );

    return $sanitizer;
}

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

    $clean = $sanitizer->sanitize( $unclean );

    if ( false === $clean ) {
        return false;
    }

    // Save cleaned file.
    file_put_contents( $file, $clean );

    return true;
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

    $ext  = isset( $checked['ext'] )  ? $checked['ext']  : '';
    $type = isset( $checked['type'] ) ? $checked['type'] : '';

    // Fallback: check extension using pathinfo.
    $pathinfo_ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

    // Case 1: Genuine SVG (extension and mime type both match).
    if ( 'svg' === $ext && 'image/svg+xml' === $type ) {

        // Normalize mime type.
        $file['type'] = 'image/svg+xml';

        // Sanitize SVG content before it is stored.
        if ( ! esw_svg_file_checker( $file['tmp_name'] ) ) {
            $file['error'] = __( 'Sorry, please check your SVG file.', 'easy-svg' );
        }

        return $file;
    }

    // Case 2: File has .svg extension but mime type is not a valid SVG mime type -> reject.
    if ( 'svg' === $pathinfo_ext && 'image/svg+xml' !== $type ) {
        $file['error'] = __( 'Sorry, this SVG file is not allowed for security reasons.', 'easy-svg' );
        return $file;
    }

    // All other files pass through unchanged.
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

        if ( empty( $checked['type'] ) ) {
            $esw_upload_check = wp_check_filetype( $filename, $mimes );
            $ext              = $esw_upload_check['ext'];
            $type             = $esw_upload_check['type'];
            $proper_filename  = $filename;

            // Only allow valid image types and avoid mismatched image extensions.
            if ( $type && 0 === strpos( $type, 'image/' ) && 'svg' !== $ext ) {
                $ext  = false;
                $type = false;
            }

            $checked = compact( 'ext', 'type', 'proper_filename' );
        }

        return $checked;
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