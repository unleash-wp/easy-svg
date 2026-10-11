<?php
/**
 * Icons this site owns, offered to the core Icon block.
 *
 * WordPress 7.1's icon registry is `@since 7.1.0` and this plugin declares 6.6,
 * so everything here is absent-safe: on an older site the manager is not there.
 *
 * Names are validated HERE before core sees them. `WP_Icons_Registry::register()`
 * refuses a bad name through `_doing_it_wrong`, which on production means the
 * icon silently never appears.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The collection everything this plugin owns goes into. `core` is reserved. */
const EASY_SVG_ICON_COLLECTION = 'easy-svg';

/** The post type the icons live in. Private: never a URL, never a query. */
const EASY_SVG_ICON_POST_TYPE = 'esw_icon';

/**
 * Exactly what core will accept as the part after the slash.
 *
 * Duplicated from WP_Icons_Registry deliberately -- see the note above about
 * how core refuses.
 */
const EASY_SVG_ICON_NAME_PATTERN = '/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$/';

/**
 * A name core will accept, or '' when the label cannot make one.
 *
 * Core's pattern is matched here before core sees it, because its own refusal
 * is a `_doing_it_wrong` the customer never sees.
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
 * The argument array core accepts, and nothing besides.
 *
 * `WP_Icons_Registry::register()` refuses any key other than `label`,
 * `content` and `file_path` -- again through `_doing_it_wrong`, so an extra key
 * means the icon silently does not exist.
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
 * The stored markup for one icon slug, or '' when there is none.
 *
 * Reads the esw_icon post directly -- the content is the sanitiser's hardened
 * output, so it is safe to print, and this path does not need the 7.1 icon
 * registry, which is why the template tag works on older WordPress too.
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
     * Any collection the post type does not own can be supplied here, by an
     * add-on that hooks this filter to serve its own icons through the same
     * template tag. The post-type collection above always keeps its own name.
     *
     * The returned markup is PRINTED as-is. The post-type collection is the
     * sanitiser's hardened output; an add-on that supplies markup for another
     * collection is responsible for returning already-sanitised, print-safe
     * SVG -- it should run every icon through easy_svg_sanitizer() and
     * easy_svg_harden_icon_markup() before it reaches this filter.
     */
    return (string) apply_filters( 'easy_svg_icon_markup', $markup, $slug, (string) $collection );
}

/**
 * A human LABEL for an icon: its stored title, else its slug read back as words.
 *
 * Used only to name an icon a caller called meaningful without giving a label.
 * The esw_icon post's title is the name the inserter already shows, so it is
 * the name a reader would expect to hear. A slug is the last resort and still
 * beats an unnamed role="img".
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
 * Render a stored icon. Accepts 'slug' or 'easy-svg/slug'.
 *
 * Reads the esw_icon post directly, so it works on WordPress older than the
 * 7.1 registry. The markup is the sanitiser's hardened output.
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
         * Read as an image; focusable="false" keeps it off the tab order.
         *
         * role="img" without an accessible name is never emitted -- a screen
         * reader announces "graphic" and nothing else, WCAG 1.1.1 with the role
         * spelled out. Hiding it instead would contradict a caller who just said
         * it means something, so it is named: the label given, else the stored
         * title, else the slug as words. That lookup runs only here, and only
         * when no label came in.
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
     * `\1` as backreferences, and esc_attr() escapes neither `$` nor `\`, so a
     * label like "Price $20" was rewritten on its way in. The insertion point is
     * `<svg` followed by a non-word character, which is what `\b` meant.
     */
    $at = easy_svg_root_svg_offset( $markup );
    if ( null === $at ) {
        return $markup;
    }

    return substr( $markup, 0, $at + 4 ) . $attrs . substr( $markup, $at + 4 );
}

/**
 * Where the root `<svg` tag starts, or null when there is none.
 *
 * `<svg` has to be followed by something that is not a word character -- a
 * space, `>`, a newline -- so `<svgfoo` is not an svg root. That is exactly
 * what `\b` meant in the pattern this replaced; it is spelled out here because
 * the splice no longer runs a regular expression over attacker-influenced text.
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
 *
 * An icon is inlined into the page by the core Icon block, so it runs in the
 * document's own context, not isolated the way an <img> is. `style` would then
 * style the whole page; `script`, `foreignObject`, `iframe`, `embed`, `object`,
 * the SVG Tiny `handler`/`listener`, and the animation elements (which can
 * rewrite an href to javascript: after the sanitiser has checked it) can run
 * code or pull in a document. None of it belongs in a drawing.
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
 * every href that does not point inside the same drawing (`#id`).
 *
 * A second, fixed layer on the sanitiser's output, so it does not depend on how
 * a site configured its allow-list. Parsed as XML, no network, no entities.
 * Answers '' when nothing drawable survives, and the caller refuses it.
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
 * Whether a submitted icon may be stored, and in what shape.
 *
 * A pure decision: the bounds are injected, not read, so a test or a CLI import
 * can ask for none. They are the same two the media-upload path applies -- how
 * many icons a site keeps is unbounded, how much WORK one may cost is not. Each
 * refusal is its own word, because "that name makes no icon name" and "that
 * file is not an SVG" send a person somewhere different.
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
     * parser: refusing afterwards would already have spent what this is here to
     * save. Size alone does not bound the work -- a small file with a
     * thousand <use> references is cheap to send and expensive to parse -- so
     * the complexity guard is asked as well.
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
     * an HTML page or an XML export saved as .svg -- where it returns false for
     * markup it cannot parse at all. Both mean the same thing to the person
     * uploading, and an uncaught throw here is a white screen on the form.
     */
    try {
        $clean = call_user_func( $sanitize, (string) $markup );
    } catch ( \Throwable $e ) {
        return array( 'state' => 'not_svg' );
    }

    /*
     * The sanitiser refusing means it could not read the file, and storing what
     * came back would put bytes nobody understood into every page using the icon.
     * The explicit check is redundant only while this file has no
     * `declare(strict_types=1)`: add one, as every other file here has, and the
     * `false`-to-`''` coercion below becomes a TypeError on somebody's upload.
     */
    if ( ! is_string( $clean ) || '' === trim( $clean ) ) {
        return array( 'state' => 'not_svg' );
    }

    /*
     * An `<svg` root is required of the CLEANED markup, not the submitted
     * markup. Somebody pasting a whole HTML document gets a sanitiser result
     * that is technically a string and contains no drawing, and storing that
     * would put an empty icon in the picker with no explanation.
     */
    if ( false === stripos( $clean, '<svg' ) ) {
        return array( 'state' => 'not_svg' );
    }

    /*
     * One fixed layer on top of the site's allow-list: an icon is inlined by
     * the core Icon block, so it must carry nothing active even if the site
     * widened its allow-list to style or animation. Refuse if this leaves no
     * drawing (unparseable, or nothing but the stripped elements).
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
 * Every icon, read a page at a time until a page comes back short.
 *
 * There is no product limit, so one query for "all of them" is unbounded.
 * Pages keep each query small; the loop decides when it is done. The fetcher is
 * injected because the loop is where the mistakes live -- stopping at a fixed
 * number, or never stopping.
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
 * Hand every icon to core, and answer how many were taken.
 *
 * Injected rather than reaching for globals, so the loop can be checked without
 * WordPress -- and the loop is where the interesting mistakes live: registering
 * before the collection exists, letting one bad icon stop the rest, or counting
 * icons core actually refused.
 *
 * @param array    $icons               List of [ 'slug' => , 'label' => , 'content' => ].
 * @param callable $register_collection ( string $slug, array $args ) => bool
 * @param callable $register_icon       ( string $name, array $args ) => bool
 * @return int How many icons core accepted.
 */
function easy_svg_register_icons( $icons, $register_collection, $register_icon ) {
    /*
     * What is registrable is worked out BEFORE a collection is announced.
     *
     * The collection still has to go in first -- `WP_Icons_Registry` refuses an
     * icon whose collection it does not know -- but doing that unconditionally
     * put an "Easy SVG" entry in the editor's icon picker on every site with
     * the feature on and nothing in it yet, and clicking it showed a blank
     * panel. An empty collection reads as a broken plugin rather than an empty
     * one, so nothing is offered until there is something to put in it.
     */
    $ready_icons = array();

    foreach ( (array) $icons as $icon ) {
        $name = easy_svg_icon_name( isset( $icon['slug'] ) ? $icon['slug'] : '' );

        // Checked here rather than left to core, which refuses through
        // _doing_it_wrong: on a production site that means the icon quietly
        // does not exist.
        if ( '' === $name ) {
            continue;
        }

        $ready_icons[] = array(
            'name' => $name,
            'args' => easy_svg_icon_args(
                isset( $icon['label'] ) ? $icon['label'] : '',
                isset( $icon['content'] ) ? $icon['content'] : ''
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

    return $taken;
}
