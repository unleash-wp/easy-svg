<?php
/*
Plugin Name:  Easy SVG Support
Plugin URI:   https://wordpress.org/plugins/easy-svg/
Description:  Add SVG support for WordPress.
Version:      5.3.2
Author:       UnleashWP
Author URI:   https://www.unleash-wp.com
Requires PHP: 8.0
Requires at least: 6.6
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
 * The `esw_` names below -- the functions, the two allow-list classes and the
 * `esw_svg_allowed_*` filters -- predate the `easy_svg_` prefix. Sites hook and
 * call them, so renaming them would break every one of those sites.
 */

/*
 * The sanitiser 4.1 kept in a global. Site snippets still call
 * `$sanitizer->removeRemoteReferences()` on it, so dropping it is a fatal on
 * null. It is only the template `easy_svg_sanitizer()` copies, and it is
 * created only when the name is free -- an existing one is somebody else's.
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
 * The surface is `easy_svg_sanitizer()`, a sanitiser carrying this site's
 * allow-list; bumped only when that changes shape, so everything else here is
 * free to move. A number of its own, because two plugin versions with and
 * without a given hook look the same to a hasty comparison.
 */
define( 'EASY_SVG_API', 3 );

/**
 * The panel contract: `easy_svg_panel_enqueue`, `easy_svg_panel_menu_tabs` and
 * `easy_svg_max_mb_ceiling`. Its own number because EASY_SVG_API covers the
 * sanitiser alone, and because a filter cannot be probed for.
 */
define( 'EASY_SVG_PANEL_API', 1 );

/**
 * The icon contract: `easy_svg_registrable_icons` and `easy_svg_rename_icon()`.
 *
 * Its own number for the reason the panel contract states -- a filter cannot be
 * probed for, and two versions with and without one look the same. Core's icon
 * registry keeps a short allow-list and offers no filter, so an add-on that
 * sizes or recolours an icon has to change the markup handed over at
 * registration; that is what the filter is for.
 *
 * 3 adds `easy_svg_update_icon_markup( $id, $markup )`, which writes new bytes
 * for an existing icon through the same sanitiser and the same bounds an upload
 * meets -- so a repaired icon is not the one way in that skips them.
 *
 * 2 adds `easy_svg_rename_icon( $id, $label )`. An add-on renaming an icon has
 * to turn kses off for that one write -- `wp_update_post()` re-saves the row
 * whole, and kses knows no SVG, so without it renaming empties the icon for any
 * administrator without `unfiltered_html` -- and has to drop the cached icon
 * list afterwards. Two traps nobody should meet twice.
 */
define( 'EASY_SVG_ICONS_API', 3 );

// Settings: one option behind every feature toggle and the parse-size ceiling.
require_once __DIR__ . '/includes/settings.php';

/**
 * The largest SVG this plugin will parse, in bytes.
 *
 * It bounds the input, not the WORK -- easy_svg_svg_too_complex() does that.
 * Two megabytes is far above any icon or ordinary drawing.
 *
 * @return int
 */
function easy_svg_max_bytes() {
    $mb = easy_svg_settings()['max_mb'];
    return (int) apply_filters( 'easy_svg_max_bytes', $mb * MB_IN_BYTES );
}

/**
 * Whether an SVG is too structurally complex to sanitise within a bounded cost.
 *
 * The library rescans the whole string once per nested PHP processing
 * instruction and walks every id'd element once per `<use>`, so a file far
 * under the byte cap can tie up a worker for minutes. These two counts are
 * linear and refuse those shapes before the parser sees them.
 *
 * @param string $markup
 * @return bool
 */
function easy_svg_svg_too_complex( $markup ) {
    $markup   = (string) $markup;
    $php_tags = substr_count( $markup, '<' . '?' );
    $use_tags = substr_count( strtolower( $markup ), '<use' );
    $max_php  = (int) apply_filters( 'easy_svg_max_php_tags', 10 );
    $max_use  = (int) apply_filters( 'easy_svg_max_use_tags', 1000 );
    return $php_tags > $max_php || $use_tags > $max_use;
}

/**
 * The width and height of an SVG on disk, or [] when it cannot be read safely.
 *
 * Bounded by easy_svg_max_bytes(), warnings silenced, every failure swallowed:
 * this runs in media listings and in attachment metadata, where a fatal would
 * break the screen for everyone.
 *
 * @param string $path
 * @return array{width:int,height:int}|array{}
 */
function easy_svg_read_svg_dimensions( $path ) {
    if ( ! is_string( $path ) || '' === $path || ! is_file( $path ) || ! class_exists( 'SimpleXMLElement' ) ) {
        return array();
    }
    if ( filesize( $path ) > easy_svg_max_bytes() ) {
        return array();
    }
    $internal = libxml_use_internal_errors( true );
    try {
        $svg = new SimpleXMLElement( (string) file_get_contents( $path ) );
        return array( 'width' => (int) $svg['width'], 'height' => (int) $svg['height'] );
    } catch ( \Throwable $e ) {
        return array();
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors( $internal );
    }
}

/**
 * Remove any animation element that drives an `href` or an `on*` handler.
 *
 * A no-op by default -- the allow-list has no animation elements. A site that
 * widens `esw_svg_allowed_tags` to `animate`/`set` keeps them, and the
 * sanitiser checks attribute NAMES, never `attributeName`, so `<animate
 * attributeName="href" values="javascript:...">` sets an href it approved.
 *
 * @param string $markup Cleaned SVG markup.
 * @return string Markup without href-driving animation, or '' when unparseable.
 */
function easy_svg_neutralize_href_animation( $markup ) {
    static $animation = array( 'animate', 'set', 'animatecolor', 'animatemotion', 'animatetransform' );

    $doc      = new DOMDocument();
    $internal = libxml_use_internal_errors( true );
    $loaded   = $doc->loadXML( (string) $markup, LIBXML_NONET );
    libxml_clear_errors();
    libxml_use_internal_errors( $internal );

    if ( ! $loaded || null === $doc->documentElement ) {
        return '';
    }

    $xpath  = new DOMXPath( $doc );
    $doomed = array();
    foreach ( $xpath->query( '//*' ) as $element ) {
        if ( ! in_array( strtolower( $element->localName ), $animation, true ) ) {
            continue;
        }
        $target = strtolower( trim( (string) $element->getAttribute( 'attributeName' ) ) );
        if ( 0 === strpos( $target, 'xlink:' ) ) {
            $target = substr( $target, 6 );
        }
        if ( 'href' === $target || 0 === strpos( $target, 'on' ) ) {
            $doomed[] = $element;
        }
    }
    foreach ( $doomed as $element ) {
        if ( $element->parentNode ) {
            $element->parentNode->removeChild( $element );
        }
    }

    return (string) $doc->saveXML( $doc->documentElement );
}

/**
 * A sanitiser configured the way THIS SITE sanitises. The whole public surface.
 *
 * Add-ons get this function, never the `esw_svg_*` classes: those are
 * internals, and a rename leaves `class_exists()` false, which an add-on reads
 * as "not installed". The allow-list runs through `esw_svg_allowed_tags`, which
 * sites widen, so one built any other way removes what this site keeps.
 *
 * @return \enshrined\svgSanitize\Sanitizer|null Null when the library is absent.
 */
function easy_svg_sanitizer() {
    if ( ! class_exists( '\enshrined\svgSanitize\Sanitizer' ) ) {
        return null;
    }

    /*
     * One instance per call: a shared one gets reconfigured, and then whichever
     * caller ran last decides what the others strip. It is a COPY of the 4.1
     * global when that holds a Sanitizer, so a site snippet's settings still
     * apply; cloning is safe, the library builds a DOMDocument per sanitize().
     */
    $legacy    = isset( $GLOBALS['sanitizer'] ) ? $GLOBALS['sanitizer'] : null;
    $sanitizer = $legacy instanceof \enshrined\svgSanitize\Sanitizer
        ? clone $legacy
        : new \enshrined\svgSanitize\Sanitizer();

    $sanitizer->setAllowedTags( new esw_svg_tags() );
    $sanitizer->setAllowedAttrs( new esw_svg_attributes() );

    /*
     * An SVG that loads a stylesheet, image or font from another server makes
     * every visitor's browser ask that server; `#id` and local paths stay. The
     * filter decides here rather than the copied global, because the library
     * offers no way to tell a deliberate false from its own default.
     */
    $sanitizer->removeRemoteReferences(
        (bool) apply_filters( 'esw_svg_remove_remote_references', true )
    );

    return $sanitizer;
}

/**
 * Whether the sanitiser PHP will use is the version this plugin ships.
 *
 * Composer classes are global and the first autoloader to answer wins, so a
 * plugin bundling an older copy of this library can put ITS Sanitizer behind
 * every upload here. `removeDoctype()` exists only from 1.0.0 on, which is
 * what tells the two apart.
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
 * Shown to administrators, on the screens where they would act on it: the
 * dashboard, the plugins screen and the media library, not every admin page.
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

    // On disk BEFORE reading it, as easy_svg_read_svg_dimensions() already does:
    // the cap is what stops one file tying up a request, and reading 100 MB to
    // decide it is too big is the cost the cap exists to refuse.
    if ( is_file( $file ) && filesize( $file ) > easy_svg_max_bytes() ) {
        return false;
    }

    $unclean = file_get_contents( $file );

    if ( false === $unclean ) {
        return false;
    }

    // And again on what was actually read, which a stream wrapper can make
    // differ from what the filesystem reported.
    if ( strlen( $unclean ) > easy_svg_max_bytes() ) {
        return false;
    }

    // Size is not cost: a sub-cap file with a pathological structure can still
    // drive the sanitiser's quadratic paths for minutes. Refused before parsing.
    if ( easy_svg_svg_too_complex( $unclean ) ) {
        return false;
    }

    /*
     * The library throws a LogicException for well-formed XML without exactly
     * one <svg> root, where it returns false for markup it cannot parse. Both
     * mean "not a usable SVG"; uncaught, the same file was a fatal error.
     */
    try {
        $clean = $sanitizer->sanitize( $unclean );
    } catch ( \Throwable $e ) {
        return false;
    }

    // A refusal, and so is a result of nothing: the library returns '' for
    // input PHP empty() treats as empty (a file whose only content is "0"
    // among them), and an empty .svg is not what the person meant to store.
    // The other two sanitise paths already refuse a trim()-empty result.
    if ( ! is_string( $clean ) || '' === trim( $clean ) ) {
        return false;
    }

    // Only when a site has widened the allow-list to animation elements is
    // there anything to do; the quick check keeps the ordinary upload off the
    // DOM round-trip. A href-driving animation is taken out; a parse failure
    // leaves the cleaned bytes as they are.
    //
    // A namespace prefix is allowed for, because the function this guards
    // compares localName and would have defused `<s:animate` -- it was only
    // this trigger, a raw string match, that never let it look. Measured: with
    // the element on the allow-list, `<s:animate attributeName="href">` passed
    // through untouched.
    if ( preg_match( '/<(?:[a-z][\w.-]*:)?(?:animate[a-z]*|set)[\s\/>]/i', $clean ) ) {
        $neutralized = easy_svg_neutralize_href_animation( $clean );
        if ( '' !== trim( $neutralized ) ) {
            $clean = $neutralized;
        }
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
 * @param string $name A filename or path.
 * @return bool
 */
function easy_svg_is_svg_name( $name ) {
    return 'svg' === strtolower( (string) pathinfo( (string) $name, PATHINFO_EXTENSION ) );
}

/**
 * Filter and sanitize uploaded SVG files using trusted file detection.
 *
 * The user-controlled MIME header is never trusted: the extension and
 * wp_check_filetype_and_ext() decide, and an inconsistent SVG is refused.
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
if ( easy_svg_feature_enabled( 'svg_upload' ) ) {
    add_filter( 'wp_handle_upload_prefilter', 'esw_svg_upload_filter_check_init' );
}

/*
 * The same check for files that do not come from the media uploader:
 * `wp_handle_sideload` is what `media_sideload_image()`, WP-CLI and importers
 * use. Sanitising in place is safe here, because `tmp_name` is always a
 * temporary copy -- of a local file, or of what `download_url()` fetched.
 */
if ( easy_svg_feature_enabled( 'svg_upload' ) ) {
    add_filter( 'wp_handle_sideload_prefilter', 'esw_svg_upload_filter_check_init' );
}

/**
 * Whether SVG markup is already clean: the sanitiser would remove nothing.
 *
 * Compared by structure, not by bytes: the sanitiser re-serialises every file,
 * so its output never equals the input byte for byte even when it removed
 * nothing. Whitespace, the XML declaration and comments are not compared.
 *
 * @param string $markup SVG markup.
 * @return bool False when the sanitiser refuses it or would change it.
 */
function easy_svg_markup_is_clean( $markup ) {
    $markup = (string) $markup;

    if ( strlen( $markup ) > easy_svg_max_bytes() ) {
        return false;
    }

    /*
     * Three things a structural comparison cannot see: a doctype, whose
     * internal subset can declare attribute defaults the sanitiser then drops;
     * control characters, which a parser may stop at, leaving the rest stored
     * but unparsed; and anything but whitespace after the root's closing `>`.
     */
    if ( false !== stripos( $markup, '<!DOCTYPE' ) ) {
        return false;
    }
    if ( 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $markup ) ) {
        return false;
    }
    if ( '>' !== substr( rtrim( $markup ), -1 ) ) {
        return false;
    }

    $sanitizer = easy_svg_sanitizer();
    if ( null === $sanitizer ) {
        return false;
    }

    // Same cost guard as the upload path: reject a too-complex document before
    // handing it to the sanitiser (this path is reached from XML-RPC uploads).
    if ( easy_svg_svg_too_complex( $markup ) ) {
        return false;
    }

    try {
        $clean = $sanitizer->sanitize( $markup );
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
        if ( ! $loaded || null === $doc->documentElement || null !== $doc->doctype ) {
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

    $before = $fingerprint( $markup );

    return null !== $before && $before === $fingerprint( $clean );
}

/**
 * The check on `wp_upload_bits()`, which XML-RPC uploads and plugins write to.
 *
 * Its filter can only refuse bytes, never replace them, so an SVG passes only
 * when the sanitiser would leave it exactly as it is. Empty bits pass: an
 * importer reserves a file that way and fills it afterwards, and the
 * attachment check below sees the finished file.
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
if ( easy_svg_feature_enabled( 'svg_upload' ) ) {
    add_filter( 'wp_upload_bits', 'easy_svg_upload_bits_check' );
}

/**
 * The last check: every new SVG attachment, however its file got there.
 *
 * If the sanitiser cannot use the file, the attachment and the file are
 * deleted: a file nobody could clean must not stay reachable under the uploads
 * URL. Files outside the uploads directory and attachments with no local file
 * are left alone, because offloaded media has nothing here to clean.
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

    /*
     * A plugin may register an SVG it ships in its own folder; that file is
     * part of the plugin, not an upload, and not this check's to rewrite or
     * delete. Resolved paths, so `../` cannot make an outside file look inside.
     */
    $uploads = wp_upload_dir( null, false );
    $base    = empty( $uploads['basedir'] ) ? false : realpath( $uploads['basedir'] );
    $real    = realpath( $path );
    if ( false === $base || false === $real || 0 !== strpos( $real, rtrim( $base, '/\\' ) . DIRECTORY_SEPARATOR ) ) {
        return;
    }

    if ( ! esw_svg_file_checker( $path ) ) {
        wp_delete_attachment( $attachment_id, true );
    }
}
// Ungated on purpose, unlike the upload prefilters above: this is the safety
// net. If any plugin -- not only this one -- lets an SVG into the library, the
// net cleans it or deletes the ones it cannot, and it is a no-op on everything
// else. Tying it to our own upload toggle would leave a hole exactly when a
// second plugin is the one allowing SVGs.
add_action( 'add_attachment', 'easy_svg_check_new_attachment' );

/**
 * Give an SVG attachment width/height so the Image block can size the <img>.
 *
 * Core computes no dimensions for an SVG (getimagesize does not read them), so
 * the Image block renders without width/height and jumps on load. We read them
 * once, at upload, from the already-sanitised file.
 *
 * @param array $metadata      The generated metadata.
 * @param int   $attachment_id The attachment.
 * @return array
 */
function easy_svg_svg_attachment_metadata( $metadata, $attachment_id ) {
    $mime = strtolower( (string) get_post_mime_type( $attachment_id ) );
    $path = get_attached_file( $attachment_id );

    if ( 'image/svg+xml' !== $mime && ! easy_svg_is_svg_name( (string) $path ) ) {
        return $metadata;
    }

    $dim = easy_svg_read_svg_dimensions( $path );
    if ( array() !== $dim ) {
        $metadata           = is_array( $metadata ) ? $metadata : array();
        $metadata['width']  = $dim['width'];
        $metadata['height'] = $dim['height'];
    }

    return $metadata;
}
if ( easy_svg_feature_enabled( 'svg_upload' ) ) {
    add_filter( 'wp_generate_attachment_metadata', 'easy_svg_svg_attachment_metadata', 10, 2 );
}

/*
 * The icon manager. Not behind `is_admin()`: the Icon block is SERVER-rendered,
 * so an admin-only registration would show every icon in the editor and none
 * to a visitor. Priority 5 then 10 -- the icons are read out of the post type.
 */
// The icon writes an add-on reaches for, before the store that also uses them.
require_once __DIR__ . '/includes/icon-writes.php';
require_once __DIR__ . '/includes/icon-manager.php';

// The "Icons" admin panel (React/Chakra), which an add-on can extend through
// window.EasySvgPanel. Loaded last so the functions it wraps are defined.
require_once __DIR__ . '/includes/panel.php';

/**
 * The catalogue that ships with this plugin.
 *
 * WordPress loads a plugin's translation on its own, but only from
 * WP_LANG_DIR/plugins -- which is where wordpress.org puts what translators
 * contribute. The /languages folder in the download is reached by nothing
 * unless this is called, so the German catalogue we ship was never read on any
 * site: measured, the panel was German (its JS catalogue is registered with an
 * explicit path) while the admin menu beside it and every PHP string were not.
 *
 * On `init` rather than earlier, which is where WordPress wants a textdomain
 * loaded, and still before the menu is built on `admin_menu`.
 *
 * Two calls, and the order is the point. load_plugin_textdomain() returns as
 * soon as a wordpress.org pack loads and never looks inside the plugin, so on
 * every site that has a pack -- which is most German sites -- what we ship was
 * still read by nothing: measured on a de_DE_formal site, the menu said "Icon
 * library" while the pack it loaded held four header strings and nothing else.
 * Loading ours afterwards layers it UNDER the pack: a string translators have
 * wins, and one they have not yet seen is no longer English.
 */
function easy_svg_load_textdomain(): void {
    load_plugin_textdomain( 'easy-svg', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    $locale = determine_locale();
    $mofile = __DIR__ . '/languages/easy-svg-' . $locale . '.mo';
    if ( is_readable( $mofile ) ) {
        load_textdomain( 'easy-svg', $mofile, $locale );
    }
}
add_action( 'init', 'easy_svg_load_textdomain', 1 );

// The editor-only notice for an Icon block whose icon is no longer registered.
add_action( 'enqueue_block_editor_assets', 'easy_svg_enqueue_icon_block_notice' );

/*
 * The stroke rule, wherever core renders an icon it was given: the page, the
 * block editor, and the site editor. Late, because the icons register on init
 * and nothing can know whether the rule is needed before that.
 */
add_action( 'wp_enqueue_scripts', 'easy_svg_print_stroke_css', 20 );
add_action( 'enqueue_block_editor_assets', 'easy_svg_print_stroke_css', 20 );

if ( easy_svg_feature_enabled( 'icons' ) ) {
    add_action( 'init', 'easy_svg_register_icon_store', 5 );
    add_action( 'init', 'easy_svg_boot_icons', 10 );
}

/*
 * The icon list is cached between requests (see `easy_svg_stored_icons()`).
 * Hooked on the post type and on every request, not in the screen's handlers:
 * WP-CLI, importers and REST clients write icons too.
 */
if ( easy_svg_feature_enabled( 'icons' ) ) {
    add_action( 'save_post_' . EASY_SVG_ICON_POST_TYPE, 'easy_svg_forget_icons' );
    add_action( 'deleted_post', 'easy_svg_forget_deleted_icon', 10, 2 );
}

/**
 * Add support for SVG file uploads by modifying MIME types.
 *
 * @param array $mimes File type associations.
 * @return array Modified MIME types with SVG support.
 */
if ( ! function_exists( 'esw_add_support' ) ) {
    function esw_add_support( $mimes ) {
        /*
         * The network admin's "Upload file types" (`upload_filetypes`) is
         * policy. Adding svg unconditionally would override an admin who left
         * it out, and would make this plugin's own "does this site allow SVG"
         * checks meaningless, because svg would always be in the list.
         */
        if (
            is_multisite() &&
            apply_filters( 'easy_svg_respect_network_filetypes', true ) &&
            ! in_array(
                'svg',
                preg_split( '/\s+/', strtolower( (string) get_site_option( 'upload_filetypes' ) ), -1, PREG_SPLIT_NO_EMPTY ),
                true
            )
        ) {
            return $mimes;
        }

        $mimes['svg'] = 'image/svg+xml';
        return $mimes;
    }
    if ( easy_svg_feature_enabled( 'svg_upload' ) ) {
        add_filter( 'upload_mimes', 'esw_add_support' );
    }
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
         * fileinfo often cannot name an SVG -- one without an XML declaration
         * reads as text -- and core then drops its type. This gives the type
         * back so the upload filter can sanitise the file.
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
    if ( easy_svg_feature_enabled( 'svg_upload' ) ) {
        add_filter( 'wp_check_filetype_and_ext', 'esw_upload_check', 10, 4 );
    }
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
            // One shared reader, bounded and fail-safe (see
            // easy_svg_read_svg_dimensions). An oversized or unreadable file
            // yields nothing and the default response stands.
            $dim = easy_svg_read_svg_dimensions( get_attached_file( $attachment->ID ) );
            if ( array() !== $dim ) {
                $src    = $response['url'];
                $width  = $dim['width'];
                $height = $dim['height'];

                $response['image'] = compact( 'src', 'width', 'height' );
                $response['thumb'] = compact( 'src', 'width', 'height' );

                $response['sizes']['full'] = array(
                    'height'      => $height,
                    'width'       => $width,
                    'url'         => $src,
                    'orientation' => ( $height > $width ) ? 'portrait' : 'landscape',
                );
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