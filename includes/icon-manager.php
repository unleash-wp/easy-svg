<?php
/**
 * The icon store, and the WordPress adapters over it.
 *
 * Everything that decides anything is in `icons.php`, injected and checked
 * without WordPress. What is left here is a post type, a query, and the one
 * write that stores an icon. There is no screen: the Icons panel writes
 * through this file.
 *
 * Two capabilities, two questions. `EASY_SVG_ICON_CAP` is
 * `edit_theme_options` and belongs to the POST TYPE -- what every other route
 * into the store must clear, XML-RPC and a generic post editor included. An
 * icon applies site-wide and shows up in every editor, so it is a design
 * decision, not `upload_files`. The panel asks for `manage_options`, which is
 * stricter and widens nothing.
 *
 * `wp_register_icon()` is `@since 7.1.0` and this plugin declares 6.6, so
 * nothing here may assume it exists; on an older site nothing registers.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/icons.php';

/** Who may manage icons. */
const EASY_SVG_ICON_CAP = 'edit_theme_options';

/** How many icons one query reads. Bounded, so no single query is "everything". */
const EASY_SVG_ICON_PAGE_SIZE = 100;

/** Where the list of icons is kept between requests. */
const EASY_SVG_ICON_CACHE = 'easy_svg_icons';

/** Raised on every change to the icons; a cached list from before is stale. */
const EASY_SVG_ICON_VERSION = 'easy_svg_icons_version';

/**
 * The store.
 *
 * Private in every direction -- no archive, no single view, not queryable,
 * not searchable: an icon is editor markup, not a page anybody should open.
 * `show_ui` is false too, because the generic post list would offer a content
 * editor for SVG markup; the Icons panel is the interface.
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
             * Every capability is this plugin's own. The `post` defaults would let an
             * Author create an icon and an Editor change anybody's through any route
             * that asks the post type -- XML-RPC, REST if it were ever switched on, a
             * plugin's generic post editor. Who may add one is the same question as who
             * may change the theme. `map_meta_cap` off, so these are checked as written
             * instead of mapped back to post-author rules.
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
 * Every stored icon, oldest first, so the picker does not reshuffle when
 * somebody adds one.
 *
 * Cached because core has no lazy hook for its icon registry: it registers on
 * every `init` and so must we, the Icon block rendering server-side for every
 * visitor. Uncached, that is a full query with markup on every request of
 * every 7.1 site, most of which have no icons.
 *
 * Dropped whenever an icon is saved or deleted, hooked on the post type so
 * WP-CLI drops it too. The expiry earns its keep twice: a transient with one
 * is not autoloaded, so the markup is not carried through every request; and
 * a write that bypassed WordPress heals within a day.
 *
 * @return array<int, array{id:int, slug:string, label:string, content:string}>
 */
function easy_svg_stored_icons() {
    /*
     * The version is read BEFORE the icons. A request that misses the cache
     * can take a moment to read them, and if somebody adds or removes an icon
     * meanwhile, the list it is about to store is already out of date. The
     * version it stores with that list is then lower than the current one, so
     * the next request ignores it instead of trusting it for a day.
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
 * One page of stored icons, straight from the posts table.
 *
 * Only the posts themselves are wanted: no found-rows count, no meta or term
 * caches primed for posts that have neither.
 *
 * @param int $page     1-based page number.
 * @param int $per_page How many icons a page holds.
 * @return array<int, array{id:int, slug:string, label:string, content:string}>
 */
/**
 * Icon ids whose SLUG contains a term.
 *
 * Its own query because WP_Query cannot ask for it: `search_columns` supports
 * only post_title, post_excerpt and post_content, and `name` is exact. LIKE
 * through esc_like() in a prepared statement.
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
        (array) $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_name LIKE %s",
                EASY_SVG_ICON_POST_TYPE,
                '%' . $wpdb->esc_like( $term ) . '%'
            )
        )
    );
}

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
     * Searching asks the database, not the browser: a library past one page
     * cannot be filtered by what happens to have been sent. Title and slug
     * only -- an icon's content is its SVG markup, and a default search would
     * match every file that happens to contain the letters somebody typed.
     *
     * The slug needs its own query. Core intersects `search_columns` with
     * post_title, post_excerpt and post_content and drops whatever else is in
     * it, so `post_name` there did nothing: measured on a renamed icon, the
     * list found it by title and never by the slug it shows in its own tile.
     * That intersection is also why post_content is genuinely never searched.
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
 * Drop the cached list, so the next request reads the icons again.
 *
 * Hooked on `save_post_esw_icon` rather than called from the panel's REST
 * handlers alone: an icon written by WP-CLI, an importer or another REST
 * client has to show up just the same.
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

    easy_svg_register_icons(
        easy_svg_stored_icons(),
        'wp_register_icon_collection',
        'wp_register_icon'
    );
}

/**
 * Store one icon, and answer with the word for what happened.
 *
 * A word rather than a sentence, and a return rather than an exit, so every
 * caller can check the answer and phrase it for itself: the panel's REST add
 * turns the word into a message (`easy_svg_panel_add_message()`) and an HTTP
 * status, where WP-CLI or an importer wants neither.
 *
 * @param string $label  What the person typed, already sanitised as text.
 * @param string $markup The bytes they uploaded.
 * @return string A state `easy_svg_panel_add_message()` has a sentence for.
 */
/**
 * Store one icon and answer with the word for what happened.
 *
 * Kept as the whole contract it has always been, because every caller reads it
 * that way. What it cannot say is which name the icon ENDED UP with, so a
 * caller that needs that asks easy_svg_add_icon_result() instead.
 */
function easy_svg_add_icon( $label, $markup ) {
    return easy_svg_add_icon_result( $label, $markup )['state'];
}

/**
 * The same write, answering with the icon it created.
 *
 * `post_name` is decided inside wp_insert_post(): wp_unique_post_slug() appends
 * `-2` when the name is taken, so the slug an icon ends up with is knowable
 * only after the insert. A caller that derives it from the label instead is
 * right until the first collision and silently wrong afterwards -- it hands
 * back a name that resolves to somebody else's icon.
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
     * The same two bounds the media uploader applies, so every way into the
     * icon store costs a request the same amount at most. Without them the
     * panel's REST add handed whatever arrived straight to the sanitiser's DOM
     * parser; `max_mb` is the site's own setting, so the bound a person sees on
     * the Settings tab is the bound an icon meets.
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
     * `post_name` is left to WordPress, which makes it unique against what is
     * already there: two icons called "Arrow" become `arrow` and `arrow-2`
     * rather than one replacing the other, and the second is still a name core
     * accepts, since `wp_unique_post_slug` only appends `-<number>`.
     *
     * A WP_Error is asked for and checked. "Icon added." above a table without
     * the icon sends a person looking for a bug in the wrong place.
     */
    /*
     * kses is switched off for this one insert, and only if it was on.
     * wp_insert_post() runs content through wp_filter_post_kses for anybody
     * without unfiltered_html -- every administrator of a multisite sub-site,
     * every site with DISALLOW_UNFILTERED_HTML -- and kses knows no SVG, so the
     * icon arrived empty. The markup has just been through this site's SVG
     * sanitiser, which is the check that understands it. Restored in `finally`,
     * so no path leaves kses off for the rest of the request. Slashed, because
     * wp_insert_post() unslashes what it is given.
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
