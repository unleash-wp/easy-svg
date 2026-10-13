<?php
/**
 * Icons this site owns, offered to the core Icon block. The icon registry is
 * 7.1 and this plugin declares 6.6, so everything here is absent-safe. Names
 * are validated HERE because `WP_Icons_Registry::register()` refuses a bad one
 * through `_doing_it_wrong` -- on production the icon never appears at all.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The collection everything this plugin owns goes into. `core` is reserved. */
const EASY_SVG_ICON_COLLECTION = 'easy-svg';

/** The post type the icons live in. Private: never a URL, never a query. */
const EASY_SVG_ICON_POST_TYPE = 'esw_icon';

/**
 * Exactly what core will accept as the part after the slash. Duplicated from
 * WP_Icons_Registry deliberately -- see the note above on how core refuses.
 */
const EASY_SVG_ICON_NAME_PATTERN = '/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$/';

/**
 * A name core will accept, or '' when the label cannot make one.
 *
 * @param string        $label    A human-readable label.
 * @param callable|null $sanitize Turns a label into a slug.
 * @return string A valid unqualified icon name, or ''.
 */
function easy_svg_icon_slug( $label, $sanitize = null ) {
    if ( null === $sanitize ) {
        $sanitize = function_exists( 'sanitize_title' ) ? 'sanitize_title' : 'strtolower';
    }

    $slug = (string) call_user_func( $sanitize, (string) $label );

    // Folded rather than rejected: a sanitiser that leaves capitals is a
    // configuration problem, not the user's mistake.
    $slug = strtolower( $slug );

    return 1 === preg_match( EASY_SVG_ICON_NAME_PATTERN, $slug ) ? $slug : '';
}

/**
 * The namespaced name core wants.
 *
 * @param string $slug An unqualified name.
 * @return string `collection/name`, or '' when the slug is not usable.
 */
function easy_svg_icon_name( $slug ) {
    $slug = (string) $slug;

    if ( 1 !== preg_match( EASY_SVG_ICON_NAME_PATTERN, $slug ) ) {
        return '';
    }

    return EASY_SVG_ICON_COLLECTION . '/' . $slug;
}

/**
 * The argument array core accepts, and nothing besides: `register()` refuses
 * any key other than `label`, `content` and `file_path`, again through
 * `_doing_it_wrong`, so an extra key means the icon silently does not exist.
 *
 * @param string $label   Human-readable label.
 * @param string $content SVG markup.
 * @return array
 */
function easy_svg_icon_args( $label, $content ) {
    return array(
        'label'   => (string) $label,
        'content' => (string) $content,
    );
}

/** Whether this WordPress can hold icons at all. */
function easy_svg_icons_supported() {
    return function_exists( 'wp_register_icon' ) && function_exists( 'wp_register_icon_collection' );
}

/**
 * The editor-only notice for an Icon block whose icon is gone.
 *
 * Enqueued on the block editor alone, and only where there are icons to miss:
 * a WordPress without the icon registry has no Icon block for the filter to
 * wrap. The front end is left silent on purpose -- a reader is not the person
 * who can fix a reference, and saying it there publishes an internal problem.
 */
function easy_svg_enqueue_icon_block_notice() {
    if ( ! easy_svg_icons_supported() ) {
        return;
    }

    $main = dirname( __DIR__ ) . '/easy-svg.php';
    $file = dirname( __DIR__ ) . '/assets/icon-block-notice.js';
    if ( ! is_readable( $file ) ) {
        return;
    }

    wp_enqueue_script(
        'easy-svg-icon-block-notice',
        plugins_url( 'assets/icon-block-notice.js', $main ),
        array( 'wp-hooks', 'wp-element', 'wp-data', 'wp-i18n', 'wp-compose', 'wp-block-editor' ),
        (string) filemtime( $file ),
        true
    );
    // The path, for the catalogue this plugin bundles -- the same reason
    // panel.php passes one.
    wp_set_script_translations( 'easy-svg-icon-block-notice', 'easy-svg', dirname( __DIR__ ) . '/languages' );
}
// Hooked in easy-svg.php beside the plugin's other hooks: this file is pure
// enough to be read by the suite without a WordPress around it.

/**
 * The stored markup for one icon slug, or '' when there is none. Reads the
 * esw_icon post directly: the content is the sanitiser's hardened output, so it
 * is safe to print, and it needs no 7.1 registry, so this works on 6.6 too.
 *
 * @param string $slug
 * @return string
 */
function easy_svg_icon_markup( $slug, $collection = EASY_SVG_ICON_COLLECTION ) {
    $slug   = (string) $slug;
    $markup = '';
    if ( EASY_SVG_ICON_COLLECTION === $collection ) {
        $post = function_exists( 'get_page_by_path' ) ? get_page_by_path( $slug, OBJECT, EASY_SVG_ICON_POST_TYPE ) : null;
        if ( is_object( $post ) && isset( $post->post_type ) && EASY_SVG_ICON_POST_TYPE === $post->post_type ) {
            $markup = (string) $post->post_content;
        }
    }

    /*
     * An add-on can serve a collection the post type does not own through this
     * filter; our own collection always keeps its name. The returned markup is
     * PRINTED as-is, so an add-on owes already-hardened SVG -- run it through
     * easy_svg_sanitizer() and easy_svg_harden_icon_markup() first.
     */
    return (string) apply_filters( 'easy_svg_icon_markup', $markup, $slug, (string) $collection );
}

/**
 * A human LABEL for an icon: its stored title, else its slug as words. Used
 * only when a caller called an icon meaningful without naming it -- the title
 * is what the inserter shows, and a slug still beats an unnamed role="img".
 *
 * @param string $slug
 * @param string $collection
 * @return string
 */
function easy_svg_icon_label( $slug, $collection = EASY_SVG_ICON_COLLECTION ) {
    $slug = (string) $slug;
    if ( EASY_SVG_ICON_COLLECTION === $collection && function_exists( 'get_page_by_path' ) ) {
        $post = get_page_by_path( $slug, OBJECT, EASY_SVG_ICON_POST_TYPE );
        if ( is_object( $post ) && isset( $post->post_title ) && '' !== trim( (string) $post->post_title ) ) {
            return (string) $post->post_title;
        }
    }
    $words = trim( str_replace( array( '-', '_' ), ' ', $slug ) );
    return '' === $words ? $slug : ucfirst( $words );
}

/**
 * Render a stored icon. Accepts 'slug' or 'easy-svg/slug'. Reads the esw_icon
 * post directly, so it works on WordPress older than the 7.1 registry.
 *
 * @param string $name Icon name, bare slug or collection-qualified.
 * @param array  $args Optional. 'class' (string). 'label' (string) names the
 *                     icon so it reads as content: role="img" + aria-label.
 *                     'decorative' (bool) overrides -- true hides it from
 *                     assistive tech, false keeps it meaningful. With neither,
 *                     the icon is decorative. A meaningful one is always named:
 *                     'label', else the stored title, else the slug as words.
 * @return string SVG markup ready to echo, or '' when the icon is unknown.
 */
function easy_svg_icon( $name, $args = array() ) {
    $name       = (string) $name;
    $collection = EASY_SVG_ICON_COLLECTION;
    $slug       = $name;
    if ( false !== strpos( $name, '/' ) ) {
        list( $collection, $slug ) = explode( '/', $name, 2 );
    }

    $markup = easy_svg_icon_markup( $slug, $collection );
    if ( '' === $markup ) {
        return '';
    }

    // An inlined icon is decoration unless a label gives it a name; an explicit
    // 'decorative' argument overrides that default in either direction.
    $decorative = isset( $args['decorative'] ) ? (bool) $args['decorative'] : empty( $args['label'] );

    $attrs = '';
    if ( ! empty( $args['class'] ) ) {
        $attrs .= ' class="' . esc_attr( (string) $args['class'] ) . '"';
    }
    if ( $decorative ) {
        // Hidden from a screen reader, and never a tab stop in IE / old Edge.
        $attrs .= ' aria-hidden="true" focusable="false"';
    } else {
        /*
         * role="img" is never emitted without a name -- a screen reader then
         * announces "graphic" and nothing else, WCAG 1.1.1. Hiding it would
         * contradict a caller who said it means something, so it is named: the
         * label given, else the stored title, else the slug as words.
         */
        $attrs .= ' role="img" focusable="false"';
        $label  = (string) ( $args['label'] ?? '' );
        if ( '' === trim( $label ) ) {
            $label = easy_svg_icon_label( $slug, $collection );
        }
        $attrs .= ' aria-label="' . esc_attr( $label ) . '"';
    }

    /*
     * Spliced, not preg_replace'd: a replacement string reads `$1`, `${1}` and
     * `\1` as backreferences and esc_attr() escapes neither `$` nor `\`, so a
     * label like "Price $20" came out rewritten.
     */
    $at = easy_svg_root_svg_offset( $markup );
    if ( null === $at ) {
        return $markup;
    }

    return substr( $markup, 0, $at + 4 ) . $attrs . substr( $markup, $at + 4 );
}

/**
 * Where the root `<svg` tag starts, or null when there is none. `<svg` has to
 * be followed by a non-word character -- a space, `>`, a newline -- so
 * `<svgfoo` is not an svg root.
 *
 * @param string $markup
 * @return int|null Byte offset of the `<` , or null.
 */
function easy_svg_root_svg_offset( $markup ) {
    $markup = (string) $markup;
    $at     = 0;
    while ( true ) {
        $at = stripos( $markup, '<svg', $at );
        if ( false === $at ) {
            return null;
        }
        $next = substr( $markup, $at + 4, 1 );
        if ( '' === $next || 1 !== preg_match( '/\w/', $next ) ) {
            return $at;
        }
        $at += 4;
    }
}

/**
 * Elements an icon may never carry, whatever a site's SVG allow-list permits.
 * The core Icon block inlines an icon into the document, so it is not isolated
 * the way an <img> is: `style` would style the whole page, and the animation
 * elements can rewrite an href to javascript: after the sanitiser checked it.
 */
const EASY_SVG_ICON_UNSAFE_ELEMENTS = array(
    'script',
    'style',
    'foreignobject',
    'iframe',
    'embed',
    'object',
    'handler',
    'listener',
    'set',
    'animate',
    'animatecolor',
    'animatemotion',
    'animatetransform',
    'discard',
);

/**
 * Icon markup with everything active taken out: the unsafe elements above, and
 * every href that does not point inside the same drawing (`#id`). A second,
 * fixed layer on the sanitiser's output, so it does not depend on how a site
 * configured its allow-list. '' when nothing drawable survives.
 *
 * @param string $markup Cleaned SVG markup.
 * @return string Hardened markup, or '' when there is no drawing left to store.
 */
function easy_svg_harden_icon_markup( $markup ) {
    $doc      = new DOMDocument();
    $internal = libxml_use_internal_errors( true );
    $loaded   = $doc->loadXML( (string) $markup, LIBXML_NONET );
    libxml_clear_errors();
    libxml_use_internal_errors( $internal );

    if ( ! $loaded || null === $doc->documentElement ) {
        return '';
    }

    $xpath = new DOMXPath( $doc );

    // Snapshot first: removing nodes while walking a live list skips some.
    $doomed = array();
    foreach ( $xpath->query( '//*' ) as $element ) {
        if ( in_array( strtolower( $element->localName ), EASY_SVG_ICON_UNSAFE_ELEMENTS, true ) ) {
            $doomed[] = $element;
        }
    }
    foreach ( $doomed as $element ) {
        if ( $element->parentNode ) {
            $element->parentNode->removeChild( $element );
        }
    }

    foreach ( $xpath->query( '//*' ) as $element ) {
        $drop = array();
        foreach ( $element->attributes as $attribute ) {
            $name = strtolower( $attribute->localName );

            // Event handlers, the whole `on` namespace -- the same rule the
            // preview applies. The sanitiser removes these first unless the site
            // widened its allow-list to one; the hardener must not rely on that.
            if ( 0 === strpos( $name, 'on' ) ) {
                $drop[] = $attribute;
                continue;
            }
            if ( 'href' === $name && 0 !== strpos( ltrim( (string) $attribute->nodeValue ), '#' ) ) {
                $drop[] = $attribute;
            }
        }
        foreach ( $drop as $attribute ) {
            $element->removeAttributeNode( $attribute );
        }
    }

    // Nothing but the stripped elements leaves a root with no drawing in it.
    // Refuse that rather than store a blank icon: a real icon keeps at least
    // one element under the root.
    $has_drawing = false;
    foreach ( $doc->documentElement->childNodes as $node ) {
        if ( XML_ELEMENT_NODE === $node->nodeType ) {
            $has_drawing = true;
            break;
        }
    }
    if ( ! $has_drawing ) {
        return '';
    }

    return (string) $doc->saveXML( $doc->documentElement );
}

/**
 * Whether a submitted icon may be stored, and in what shape. The bounds are
 * injected, not read, so a CLI import can ask for none: how many icons a site
 * keeps is unbounded, how much WORK one may cost is not. Each refusal is its
 * own word, because a bad name and a bad file send a person somewhere else.
 *
 * @param string        $label       What the person typed.
 * @param string        $markup      The bytes they uploaded.
 * @param callable      $sanitize    Cleans SVG markup, or returns false.
 * @param callable|null $slugger     Turns the label into a slug.
 * @param int           $max_bytes   Largest markup accepted; 0 for no bound.
 * @param callable|null $too_complex Returns true for markup too costly to parse.
 * @return array{state: string, slug?: string, content?: string}
 */
function easy_svg_accept_icon( $label, $markup, $sanitize, $slugger = null, $max_bytes = 0, $too_complex = null ) {
    $slug = easy_svg_icon_slug( $label, $slugger );
    if ( '' === $slug ) {
        return array( 'state' => 'bad_name' );
    }

    if ( '' === trim( (string) $markup ) ) {
        return array( 'state' => 'empty' );
    }

    /*
     * Both bounds BEFORE the sanitiser, which is what hands the bytes to a DOM
     * parser: refusing afterwards has already spent what this saves. Size alone
     * does not bound the work -- a small file with a thousand <use> references
     * is cheap to send and expensive to parse -- so complexity is asked too.
     */
    $max_bytes = (int) $max_bytes;
    if ( $max_bytes > 0 && strlen( (string) $markup ) > $max_bytes ) {
        return array( 'state' => 'too_large' );
    }

    if ( null !== $too_complex && call_user_func( $too_complex, (string) $markup ) ) {
        return array( 'state' => 'too_complex' );
    }

    /*
     * The library THROWS for well-formed XML without exactly one <svg> root --
     * an HTML page saved as .svg -- where it returns false for markup it cannot
     * parse at all. An uncaught throw here is a white screen on the form.
     */
    try {
        $clean = call_user_func( $sanitize, (string) $markup );
    } catch ( \Throwable $e ) {
        return array( 'state' => 'not_svg' );
    }

    /*
     * A refusal means the sanitiser could not read the file, and storing what
     * came back would put bytes nobody understood on every page using the icon.
     * The explicit check looks redundant only while this file has no
     * `declare(strict_types=1)`: add one and the coercion becomes a TypeError.
     */
    if ( ! is_string( $clean ) || '' === trim( $clean ) ) {
        return array( 'state' => 'not_svg' );
    }

    /*
     * The `<svg` root is required of the CLEANED markup: a pasted HTML document
     * sanitises to a string with no drawing in it, and storing that would put an
     * empty icon in the picker with no explanation.
     */
    if ( false === stripos( $clean, '<svg' ) ) {
        return array( 'state' => 'not_svg' );
    }

    /*
     * One fixed layer on top of the site's allow-list, because the block inlines
     * the icon. No drawing left means refuse.
     */
    $clean = easy_svg_harden_icon_markup( $clean );
    if ( '' === trim( $clean ) || false === stripos( $clean, '<svg' ) ) {
        return array( 'state' => 'not_svg' );
    }

    return array(
        'state'   => 'ok',
        'slug'    => $slug,
        // The CLEANED, hardened markup is what gets stored. The whole reason
        // this plugin is the right home for an icon manager is that the thing
        // on the page has been through this site's allow-list -- and then had
        // everything active taken out, because the block inlines it.
        'content' => $clean,
    );
}

/**
 * Every icon, read a page at a time until a page comes back short. Nothing
 * bounds how many a site keeps, so one query for "all of them" is unbounded.
 * The fetcher is injected because the loop is where the mistakes live --
 * stopping at a fixed number, or never stopping.
 *
 * @param callable $fetch_page ( int $page, int $per_page ) => list of icons with an 'id'.
 * @param int      $per_page   How many one page holds.
 * @return array
 */
function easy_svg_collect_icons( $fetch_page, $per_page ) {
    $per_page = max( 1, (int) $per_page );
    $icons    = array();
    $seen     = array();
    $page     = 1;

    do {
        $batch = array_values( (array) call_user_func( $fetch_page, $page, $per_page ) );

        /*
         * A query that ignores `paged` -- a filter on the query, a caching
         * layer -- answers every page with the first one. Without this the
         * loop would ask for ever and take the request down with it.
         */
        if ( array() !== $batch && isset( $batch[0]['id'], $seen[ $batch[0]['id'] ] ) ) {
            break;
        }

        foreach ( $batch as $icon ) {
            if ( isset( $icon['id'] ) ) {
                $seen[ $icon['id'] ] = true;
            }
            $icons[] = $icon;
        }

        $page++;
    } while ( count( $batch ) === $per_page );

    return $icons;
}

/**
 * Hand every icon to core, and answer how many were taken. Injected rather than
 * reaching for globals, so the loop can be checked without WordPress -- and the
 * loop is where the mistakes live: registering before the collection exists,
 * letting one bad icon stop the rest, counting icons core refused.
 *
 * @param array    $icons               List of [ 'slug' => , 'label' => , 'content' => ].
 * @param callable $register_collection ( string $slug, array $args ) => bool
 * @param callable $register_icon       ( string $name, array $args ) => bool
 * @return int How many icons core accepted.
 */
function easy_svg_register_icons( $icons, $register_collection, $register_icon ) {
    /*
     * What is registrable is worked out BEFORE the collection is announced. The
     * collection still has to go in first -- `WP_Icons_Registry` refuses an icon
     * whose collection it does not know -- but announcing it unconditionally
     * puts an empty entry in the editor's icon picker, which reads as broken.
     */
    $ready_icons      = array();
    $needs_stylesheet = false;

    foreach ( (array) $icons as $icon ) {
        $name = easy_svg_icon_name( isset( $icon['slug'] ) ? $icon['slug'] : '' );

        // Checked here rather than left to core, which refuses through
        // _doing_it_wrong: on a production site that means the icon quietly
        // does not exist.
        if ( '' === $name ) {
            continue;
        }

        /*
         * Rewritten for what core's registry keeps: shapes become paths,
         * groups are flattened, and a stroked icon carries its stroke as a
         * class the stylesheet restores. Without this an outline set -- the
         * usual thing an agency ships -- reaches the Icon block as paths with
         * no stroke and no fill, which draws nothing at all.
         */
        $for_core = easy_svg_core_icon( isset( $icon['content'] ) ? $icon['content'] : '' );
        $content  = '' !== $for_core['content'] ? $for_core['content'] : (string) ( $icon['content'] ?? '' );
        if ( array() !== $for_core['classes'] ) {
            $needs_stylesheet = true;
        }

        $ready_icons[] = array(
            'name' => $name,
            'args' => easy_svg_icon_args(
                isset( $icon['label'] ) ? $icon['label'] : '',
                $content
            ),
        );
    }

    if ( array() === $ready_icons ) {
        return 0;
    }

    $ready = call_user_func(
        $register_collection,
        EASY_SVG_ICON_COLLECTION,
        array( 'label' => __( 'Easy SVG', 'easy-svg' ) )
    );

    if ( ! $ready ) {
        return 0;
    }

    $taken = 0;

    foreach ( $ready_icons as $icon ) {
        // Counted from what core ANSWERED, not from what we sent. A screen that
        // says "5 icons" about icons core refused is worse than no screen.
        if ( call_user_func( $register_icon, $icon['name'], $icon['args'] ) ) {
            $taken++;
        }
    }

    // Only when something actually needs it: a site whose icons are all filled
    // paths loads no stylesheet for a rule it would never use.
    if ( $needs_stylesheet && $taken > 0 ) {
        easy_svg_stroke_stylesheet_needed( true );
    }

    return $taken;
}

/**
 * The rule that gives a stroked icon its stroke back.
 *
 * Core keeps `class` on the root and nothing else that matters, so this is the
 * other half of easy_svg_core_icon(): the classes it sets mean nothing until
 * something declares them. Printed inline -- six short rules are not worth a
 * request, and there is no file to link to anyway.
 */
function easy_svg_stroke_css() {
    /*
     * Aimed at the SHAPES, not at the root.
     *
     * Core's own block sheet carries `.wp-block-icon svg{fill:currentcolor}`,
     * and a `fill` on our root would only be inherited by the paths -- an
     * inherited value loses to any rule that matches the element itself, so
     * the first version of this drew filled black lines. A rule on the paths
     * wins that by construction rather than by specificity, which is what
     * makes it hold inside a host whose selectors we do not know.
     *
     * No `!important`: a theme that deliberately restyles an icon should still
     * be able to.
     */
    $shapes = '.esvg-stroke path,.esvg-stroke polygon';

    return $shapes . '{fill:none;stroke:currentColor;stroke-width:1}'
        . '.esvg-stroke.esvg-sw-1 path,.esvg-stroke.esvg-sw-1 polygon{stroke-width:1}'
        . '.esvg-stroke.esvg-sw-125 path,.esvg-stroke.esvg-sw-125 polygon{stroke-width:1.25}'
        . '.esvg-stroke.esvg-sw-15 path,.esvg-stroke.esvg-sw-15 polygon{stroke-width:1.5}'
        . '.esvg-stroke.esvg-sw-175 path,.esvg-stroke.esvg-sw-175 polygon{stroke-width:1.75}'
        . '.esvg-stroke.esvg-sw-2 path,.esvg-stroke.esvg-sw-2 polygon{stroke-width:2}'
        . '.esvg-stroke.esvg-sw-25 path,.esvg-stroke.esvg-sw-25 polygon{stroke-width:2.5}'
        . '.esvg-stroke.esvg-sw-3 path,.esvg-stroke.esvg-sw-3 polygon{stroke-width:3}'
        . '.esvg-stroke.esvg-cap-round path,.esvg-stroke.esvg-cap-round polygon{stroke-linecap:round}'
        . '.esvg-stroke.esvg-join-round path,.esvg-stroke.esvg-join-round polygon{stroke-linejoin:round}';
}

/**
 * Whether anything registered this request needs the stroke stylesheet.
 *
 * A latch rather than an option: registration happens on every request, so the
 * answer is current by construction, and a site that removes its last stroked
 * icon stops loading the rule the moment it does.
 *
 * @param bool|null $set Pass true to latch it; null to read.
 * @return bool
 */
function easy_svg_stroke_stylesheet_needed( $set = null ) {
    static $needed = false;
    if ( null !== $set ) {
        $needed = (bool) $set;
    }
    return $needed;
}
