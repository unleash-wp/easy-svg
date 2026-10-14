<?php
/**
 * The icon store, and the WordPress adapters over it. Everything that decides
 * anything is in `icons.php`, injected and checked without WordPress.
 *
 * `EASY_SVG_ICON_CAP` is `edit_theme_options` and belongs to the POST TYPE, so
 * every route into the store clears it -- an icon is site-wide, which is a
 * design decision rather than `upload_files`. `wp_register_icon()` is 7.1 and
 * this plugin declares 6.6, so nothing here may assume it exists.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/core-icon.php';

/** Who may manage icons. */
const EASY_SVG_ICON_CAP = 'edit_theme_options';

/** How many icons one query reads. Bounded, so no single query is "everything". */
const EASY_SVG_ICON_PAGE_SIZE = 100;

/** Where the list of icons is kept between requests. */
const EASY_SVG_ICON_CACHE = 'easy_svg_icons';

/** Raised on every change to the icons; a cached list from before is stale. */
const EASY_SVG_ICON_VERSION = 'easy_svg_icons_version';

/**
 * The store. Private in every direction -- no archive, no single view, not
 * queryable, not searchable: an icon is editor markup, not a page anybody
 * should open. `show_ui` is false because the generic post list would offer a
 * content editor for SVG markup; the Icons panel is the interface.
 */
function easy_svg_register_icon_store() {
    // Nothing to store before 7.1, which has no icon registry to hand icons
    // to. Uninstalling does not need the type registered: it deletes by
    // post type straight from the table.
    if ( ! easy_svg_icons_supported() ) {
        return;
    }

    register_post_type(
        EASY_SVG_ICON_POST_TYPE,
        array(
            'labels'              => array( 'name' => __( 'SVG icons', 'easy-svg' ) ),
            'public'              => false,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_ui'             => false,
            'show_in_menu'        => false,
            'show_in_rest'        => false,
            'has_archive'         => false,
            'rewrite'             => false,
            'query_var'           => false,
            'supports'            => array( 'title', 'editor' ),
            /*
             * Every capability is this plugin's own. The `post` defaults would let
             * an Author create an icon and an Editor change anybody's through any
             * route that asks the post type -- XML-RPC, a generic post editor.
             * `map_meta_cap` off, so these are checked as written.
             */
            'capabilities'        => array(
                'edit_post'              => EASY_SVG_ICON_CAP,
                'read_post'              => EASY_SVG_ICON_CAP,
                'delete_post'            => EASY_SVG_ICON_CAP,
                'edit_posts'             => EASY_SVG_ICON_CAP,
                'edit_others_posts'      => EASY_SVG_ICON_CAP,
                'delete_posts'           => EASY_SVG_ICON_CAP,
                'publish_posts'          => EASY_SVG_ICON_CAP,
                'read_private_posts'     => EASY_SVG_ICON_CAP,
                'create_posts'           => EASY_SVG_ICON_CAP,
                'read'                   => EASY_SVG_ICON_CAP,
                'delete_private_posts'   => EASY_SVG_ICON_CAP,
                'delete_published_posts' => EASY_SVG_ICON_CAP,
                'delete_others_posts'    => EASY_SVG_ICON_CAP,
                'edit_private_posts'     => EASY_SVG_ICON_CAP,
                'edit_published_posts'   => EASY_SVG_ICON_CAP,
            ),
            'map_meta_cap'        => false,
        )
    );
}

/**
 * Every stored icon, oldest first, so the picker does not reshuffle.
 *
 * Cached because core registers its icon registry on every `init`, which would
 * otherwise be a full query with markup on every request of every site. The
 * expiry keeps it out of the autoload and heals a write that bypassed us.
 *
 * @return array<int, array{id:int, slug:string, label:string, content:string}>
 */
function easy_svg_stored_icons() {
    /*
     * The version is read BEFORE the icons: a miss takes a moment to read them,
     * and an icon added meanwhile makes the list it is about to store stale. The
     * version stored with that list is then the lower one, so the next request
     * ignores it instead of trusting it for a day.
     */
    $version = (int) get_option( EASY_SVG_ICON_VERSION, 0 );
    $cached  = get_transient( EASY_SVG_ICON_CACHE );

    // Anything that is not a list of the current version is a miss. Trusting
    // a stray value here would hand core garbage on every request until it
    // expired.
    if ( is_array( $cached ) && isset( $cached['version'], $cached['icons'] ) && $version === $cached['version'] && is_array( $cached['icons'] ) ) {
        return $cached['icons'];
    }

    $icons = easy_svg_collect_icons( 'easy_svg_icon_page', EASY_SVG_ICON_PAGE_SIZE );

    set_transient(
        EASY_SVG_ICON_CACHE,
        array(
            'version' => $version,
            'icons'   => $icons,
        ),
        DAY_IN_SECONDS
    );

    return $icons;
}

/**
 * Icon ids whose SLUG contains a term. Its own query because WP_Query cannot
 * ask: `search_columns` takes only post_title, post_excerpt and post_content,
 * and `name` is exact.
 *
 * @param string $term
 * @return array<int,int>
 */
function easy_svg_icon_ids_by_slug_like( $term ) {
    global $wpdb;
    $term = trim( (string) $term );
    if ( '' === $term || ! isset( $wpdb ) ) {
        return array();
    }

    return array_map(
        'intval',
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WP_Query cannot ask this (see above), and the answer changes on every icon added or renamed.
        (array) $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_name LIKE %s",
                EASY_SVG_ICON_POST_TYPE,
                '%' . $wpdb->esc_like( $term ) . '%'
            )
        )
    );
}

/**
 * One page of stored icons, straight from the posts table. Only the posts
 * themselves are wanted: no found-rows count, no meta or term caches.
 *
 * @param int    $page     1-based page number.
 * @param int    $per_page How many icons a page holds.
 * @param string $search   Term to match against name and slug, or ''.
 * @return array<int, array{id:int, slug:string, label:string, content:string}>
 */
function easy_svg_icon_page( $page, $per_page, $search = '' ) {
    $args = array(
        'post_type'              => EASY_SVG_ICON_POST_TYPE,
        'post_status'            => 'publish',
        'posts_per_page'         => (int) $per_page,
        'paged'                  => (int) $page,
        'orderby'                => 'ID',
        'order'                  => 'ASC',
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    );

    /*
     * The server searches, because a library past one page cannot be filtered
     * by what one response carried. Title here plus a separate slug query:
     * core narrows `search_columns` to title, excerpt and content, which drops
     * post_name -- and is why an icon's SVG markup is never searched either.
     */
    $search = trim( (string) $search );
    if ( '' !== $search ) {
        $args['s']              = $search;
        $args['search_columns'] = array( 'post_title' );

        $by_slug = easy_svg_icon_ids_by_slug_like( $search );
        if ( ! empty( $by_slug ) ) {
            // Both sets of ids, paged together: a page of results has to be
            // one page of the whole answer, not one page of half of it.
            $by_title = get_posts( array_merge( $args, array( 'fields' => 'ids', 'posts_per_page' => -1, 'paged' => 1 ) ) );
            $ids      = array_values( array_unique( array_map( 'intval', array_merge( (array) $by_title, $by_slug ) ) ) );

            unset( $args['s'], $args['search_columns'] );
            $args['post__in'] = $ids;
        }
    }

    $posts = get_posts( $args );

    $icons = array();

    foreach ( $posts as $post ) {
        $icons[] = array(
            'id'      => (int) $post->ID,
            'slug'    => (string) $post->post_name,
            'label'   => (string) $post->post_title,
            'content' => (string) $post->post_content,
        );
    }

    return $icons;
}

/**
 * Drop the cached list, so the next request reads the icons again. Hooked on
 * `save_post_esw_icon` rather than the panel's REST handlers alone: an icon
 * written by WP-CLI or an importer has to show up just the same.
 */
function easy_svg_forget_icons() {
    // A small autoloaded number: reading it costs nothing on any request.
    update_option( EASY_SVG_ICON_VERSION, (int) get_option( EASY_SVG_ICON_VERSION, 0 ) + 1, true );
    delete_transient( EASY_SVG_ICON_CACHE );
}

/**
 * The same, after a delete -- but only when what was deleted was an icon.
 *
 * `deleted_post` fires for every post on the site, and throwing the icon list
 * away each time somebody empties the trash would undo the cache.
 *
 * @param int          $post_id The deleted post's ID.
 * @param WP_Post|null $post    The deleted post.
 */
function easy_svg_forget_deleted_icon( $post_id, $post = null ) {
    if ( is_object( $post ) && isset( $post->post_type ) && EASY_SVG_ICON_POST_TYPE === $post->post_type ) {
        easy_svg_forget_icons();
    }
}

/**
 * Offer everything to core.
 *
 * On `init` at 10, after core registers its own collections at 0.
 */
function easy_svg_boot_icons() {
    if ( ! easy_svg_icons_supported() ) {
        return;
    }

    /*
     * Offered to add-ons before core sees them. Core's registry keeps a short
     * allow-list and no filter of its own, so sizing or recolouring an icon has
     * to happen to the markup handed over here; `easy_svg_icon_markup` is the
     * same thing for the template tag's read path. Cast, because a filter that
     * answers with a non-array must mean "nothing" rather than a fatal.
     */
    $icons = (array) apply_filters( 'easy_svg_registrable_icons', easy_svg_stored_icons() );

    easy_svg_register_icons(
        $icons,
        'wp_register_icon_collection',
        'wp_register_icon'
    );
}

/**
 * Print the stroke rule, where core prints the icons it was registered with.
 *
 * Front end and editor both: `wp_get_icon()` is called from templates as well
 * as from the Icon block, and a rule that only reached one of them would make
 * the editor and the page disagree about what an icon looks like.
 */
function easy_svg_print_stroke_css() {
    if ( ! easy_svg_stroke_stylesheet_needed() ) {
        return;
    }

    $handle = 'easy-svg-stroke-icons';
    // No file to version against: the rule is inline and changes with the
    // plugin, so the file this function lives in is the honest answer.
    wp_register_style( $handle, false, array(), (string) filemtime( __FILE__ ) );
    wp_enqueue_style( $handle );
    wp_add_inline_style( $handle, easy_svg_stroke_css() );
}

/**
 * Store one icon, and answer with the word for what happened. A word rather
 * than a sentence, and a return rather than an exit, so every caller can phrase
 * it itself: the REST add makes a message and a status of it, WP-CLI does not.
 *
 * @param string $label  What the person typed, already sanitised as text.
 * @param string $markup The bytes they uploaded.
 * @return string A state `easy_svg_panel_add_message()` has a sentence for.
 */
/**
 * What this cannot say is which name the icon ENDED UP with, so a caller that
 * needs that asks easy_svg_add_icon_result() instead.
 */
function easy_svg_add_icon( $label, $markup ) {
    return easy_svg_add_icon_result( $label, $markup )['state'];
}

/**
 * The same write, answering with the icon it created. `post_name` is decided
 * inside wp_insert_post(), where wp_unique_post_slug() appends `-2` when the
 * name is taken: a caller that derives the slug from the label is right until
 * the first collision, then hands back somebody else's icon.
 *
 * @return array{state:string, id:int, slug:string}
 */
function easy_svg_add_icon_result( $label, $markup ) {
    $refused = static function ( string $state ): array {
        return array(
            'state' => $state,
            'id'    => 0,
            'slug'  => '',
        );
    };

    $sanitizer = easy_svg_sanitizer();
    if ( null === $sanitizer ) {
        return $refused( 'no_sanitizer' );
    }

    /*
     * The same two bounds the media uploader applies, so every way into the icon
     * store costs a request the same at most. `max_mb` is the site's own
     * setting, so the bound on the Settings tab is the bound an icon meets.
     */
    $decision = easy_svg_accept_icon(
        $label,
        $markup,
        array( $sanitizer, 'sanitize' ),
        null,
        easy_svg_max_bytes(),
        'easy_svg_svg_too_complex'
    );

    if ( 'ok' !== $decision['state'] ) {
        return $refused( $decision['state'] );
    }

    /*
     * `post_name` is left to WordPress, which makes it unique: two icons called
     * "Arrow" become `arrow` and `arrow-2`, and the second is still a name core
     * accepts because `wp_unique_post_slug` only appends `-<number>`. A WP_Error
     * is asked for and checked, so "Icon added." is never said about no icon.
     */
    /*
     * kses off for this one insert, and only if it was on: wp_filter_post_kses
     * knows no SVG, so for anybody without unfiltered_html the icon arrived
     * empty. What the markup has been through instead is this site's own SVG
     * sanitiser. Slashed, because wp_insert_post() unslashes what it is given.
     */
    $kses_was_on = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
    if ( $kses_was_on ) {
        kses_remove_filters();
    }

    try {
        $id = wp_insert_post(
            wp_slash(
                array(
                    'post_type'    => EASY_SVG_ICON_POST_TYPE,
                    'post_status'  => 'publish',
                    'post_title'   => $label,
                    'post_name'    => $decision['slug'],
                    'post_content' => $decision['content'],
                )
            ),
            true
        );
    } finally {
        if ( $kses_was_on ) {
            kses_init_filters();
        }
    }

    if ( is_wp_error( $id ) || ! $id ) {
        return $refused( 'not_saved' );
    }

    // Whatever else may sit on the way in, what was STORED is what the block
    // will show. An icon that arrived without a drawing is not kept.
    $stored = get_post( $id );
    if ( ! $stored || false === stripos( (string) $stored->post_content, '<svg' ) ) {
        wp_delete_post( $id, true );
        return $refused( 'not_saved' );
    }

    return array(
        'state' => 'added',
        'id'    => (int) $id,
        'slug'  => (string) $stored->post_name,
    );
}
