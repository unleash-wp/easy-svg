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
 * here has to be absent-safe: on an older site the manager hides itself and
 * says why. A fatal on 40,000 installs is not a trade anybody would take.
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
 * How many icons a site may keep.
 *
 * Enough to be a complete thing rather than a demonstration, and the number a
 * paid add-on lifts. Read through `easy_svg_icon_limit()`, never directly, so
 * the filter is the only way in.
 */
const EASY_SVG_ICON_LIMIT = 5;

/**
 * Exactly what core will accept as the part after the slash.
 *
 * Duplicated from WP_Icons_Registry deliberately -- see the note above about
 * how core refuses.
 */
const EASY_SVG_ICON_NAME_PATTERN = '/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$/';

/**
 * How many icons this site may keep.
 *
 * @return int A number, never below zero.
 */
function easy_svg_icon_limit() {
    /**
     * Filters how many icons this site may keep.
     *
     * Part of the contract an add-on may rely on. Easy SVG Pro raises it; that
     * is the whole of what the paid plugin does to this feature.
     *
     * @since 4.3
     *
     * @param int $limit The default limit.
     */
    $limit = (int) apply_filters( 'easy_svg_icon_limit', EASY_SVG_ICON_LIMIT );

    // A negative limit would read as "none allowed" in one place and "no limit"
    // in another, depending on who compared what.
    return max( 0, $limit );
}

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
 * Whether one more icon may be added.
 *
 * Asked when an icon is CREATED, and never when one is rendered. A site that
 * drops below a paid limit keeps showing every icon it already has; only the
 * next one is refused.
 *
 * A paywall that blanks published pages is the fastest route to an uninstall,
 * and it would break the rule this plugin already follows: never take the base
 * function away from somebody who is using it.
 *
 * @param int $existing How many icons the site already has.
 * @param int $limit    What it is allowed.
 * @return bool
 */
function easy_svg_icon_may_add( $existing, $limit ) {
    return (int) $existing < (int) $limit;
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
 * the limit, the name, and what the sanitiser does to the markup. The
 * WordPress side below only carries the answer out.
 *
 * The states are separate words because they need separate sentences. "You
 * have five icons already" and "that file is not an SVG" send a person to two
 * different places, and one message covering both sends half of them wrong.
 *
 * @param string   $label    What the person typed.
 * @param string   $markup   The bytes they uploaded.
 * @param callable $sanitize Cleans SVG markup, or returns false.
 * @param int      $existing How many icons the site already has.
 * @param int      $limit    How many it may have.
 * @param callable|null $slugger Turns the label into a slug.
 * @return array{state: string, slug?: string, content?: string}
 */
function easy_svg_accept_icon( $label, $markup, $sanitize, $existing, $limit, $slugger = null ) {
    // Asked FIRST, so a site at its limit is told that rather than being walked
    // through a validation it was never going to pass.
    if ( ! easy_svg_icon_may_add( $existing, $limit ) ) {
        return array( 'state' => 'limit_reached' );
    }

    $slug = easy_svg_icon_slug( $label, $slugger );
    if ( '' === $slug ) {
        return array( 'state' => 'bad_name' );
    }

    if ( '' === trim( (string) $markup ) ) {
        return array( 'state' => 'empty' );
    }

    $clean = call_user_func( $sanitize, (string) $markup );

    // The sanitiser refusing means it could not read the file. Storing whatever
    // came back would put bytes nobody understood into every page that uses the
    // icon.
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
