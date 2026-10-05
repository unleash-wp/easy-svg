<?php
/**
 * Icons this site owns, offered to the core Icon block.
 *
 * ─── What this hangs on ─────────────────────────────────────────────────────
 *
 * WordPress 7.1 added an icons registry. A plugin registers a collection and
 * then icons inside it, and the editor discovers them over `wp/v2/icons` -- so
 * an icon registered here appears in the `core/icon` block's inserter with no
 * editor JavaScript of ours involved at all.
 *
 *     wp_register_icon_collection( 'easy-svg', [ 'label' => ... ] );
 *     wp_register_icon( 'easy-svg/arrow-left', [ 'label' => ..., 'content' => '<svg...>' ] );
 *
 * Both are `@since 7.1.0`. This plugin declares WordPress 6.0, so everything
 * here has to be absent-safe: on an older site the manager simply is not
 * there. A fatal on 40,000 installs is not a trade anybody would take.
 *
 * ─── The rules core enforces, checked here on purpose ───────────────────────
 *
 * `WP_Icons_Registry::register()` refuses a name that is not
 * `collection/icon-name`, that contains an uppercase letter, or that does not
 * match `^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$`. It refuses through
 * `_doing_it_wrong`, which on a production site means the icon simply never
 * appears and nothing says so.
 *
 * So a name is checked HERE, before core is asked. The pattern is duplicated
 * knowingly: the alternative is finding out from a customer that their icon is
 * missing.
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
 * The sanitiser is injected so this is testable without WordPress, and it
 * defaults to `sanitize_title()` -- which is what every WordPress developer
 * expects and what handles accents by locale.
 *
 * Whatever it returns is then checked against the pattern core actually
 * applies. That is the point of the function: not to reproduce
 * `sanitize_title`, but to guarantee nothing leaves here which core would
 * refuse in silence.
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
 * Whether a submitted icon may be stored, and in what shape.
 *
 * Every decision about accepting an icon lives here, injected and testable:
 * the name, and what the sanitiser does to the markup. The WordPress side
 * below only carries the answer out.
 *
 * There is deliberately no count among the inputs. A site keeps as many icons
 * as it likes; nothing here may refuse an icon for being one too many.
 *
 * The states are separate words because they need separate sentences. "That
 * name makes no icon name" and "that file is not an SVG" send a person to two
 * different places, and one message covering both sends half of them wrong.
 *
 * @param string        $label    What the person typed.
 * @param string        $markup   The bytes they uploaded.
 * @param callable      $sanitize Cleans SVG markup, or returns false.
 * @param callable|null $slugger  Turns the label into a slug.
 * @return array{state: string, slug?: string, content?: string}
 */
function easy_svg_accept_icon( $label, $markup, $sanitize, $slugger = null ) {
    $slug = easy_svg_icon_slug( $label, $slugger );
    if ( '' === $slug ) {
        return array( 'state' => 'bad_name' );
    }

    if ( '' === trim( (string) $markup ) ) {
        return array( 'state' => 'empty' );
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
     * The sanitiser refusing means it could not read the file. Storing whatever
     * came back would put bytes nobody understood into every page that uses the
     * icon.
     *
     * A probe says this line changes nothing today: `false` coerces to `''` on
     * the way into `stripos()` below, which then refuses it anyway. It stays
     * because that equivalence is a property of this file having no
     * `declare(strict_types=1)`. Add one -- and every other file in this
     * project has one -- and the coercion becomes a TypeError, so a clean
     * refusal turns into a fatal on somebody's upload screen.
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

    return array(
        'state'   => 'ok',
        'slug'    => $slug,
        // The CLEANED markup is what gets stored. The whole reason this plugin
        // is the right home for an icon manager is that the thing on the page
        // has been through this site's allow-list.
        'content' => $clean,
    );
}

/**
 * Every icon, read a page at a time until a page comes back short.
 *
 * There is no product limit, so there is no natural size for this list, and
 * one query for "all of them" is the kind a host's slow-query log remembers.
 * Pages keep each query bounded; the loop decides when it is done.
 *
 * The fetcher is injected so the loop is checkable without WordPress, and the
 * loop is where the mistakes live: stopping at a fixed number (the old list
 * stopped at 200 while the add handler counted everything, so icon 201 was
 * stored and then shown nowhere), or never stopping at all.
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
    // The collection first. `WP_Icons_Registry` refuses an icon whose
    // collection is not registered, so the order is not a style choice.
    $ready = call_user_func(
        $register_collection,
        EASY_SVG_ICON_COLLECTION,
        array( 'label' => __( 'Easy SVG', 'easy-svg' ) )
    );

    if ( ! $ready ) {
        return 0;
    }

    $taken = 0;

    foreach ( (array) $icons as $icon ) {
        $name = easy_svg_icon_name( isset( $icon['slug'] ) ? $icon['slug'] : '' );

        // Checked here rather than left to core, which refuses through
        // _doing_it_wrong: on a production site that means the icon quietly
        // does not exist.
        if ( '' === $name ) {
            continue;
        }

        $args = easy_svg_icon_args(
            isset( $icon['label'] ) ? $icon['label'] : '',
            isset( $icon['content'] ) ? $icon['content'] : ''
        );

        // Counted from what core ANSWERED, not from what we sent. A screen that
        // says "5 icons" about icons core refused is worse than no screen.
        if ( call_user_func( $register_icon, $name, $args ) ) {
            $taken++;
        }
    }

    return $taken;
}
