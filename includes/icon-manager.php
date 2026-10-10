<?php
/**
 * The icon store, and the WordPress adapters over it.
 *
 * Everything that decides anything is in `icons.php`, injected and checked
 * without WordPress. What is left here is the part that cannot be: a post type,
 * a query, and the one write that stores an icon.
 *
 * There is no screen in this file. The product's interface is the Icons panel
 * (`panel.php`), which owns the menu entry, the Library tab and the REST routes
 * that add and remove icons. This file is what that panel writes through.
 *
 * ─── Capability ─────────────────────────────────────────────────────────────
 *
 * Two levels, and they are not the same question.
 *
 * `EASY_SVG_ICON_CAP` is `edit_theme_options` and belongs to the POST TYPE: it
 * is what every other route into the store -- XML-RPC's `wp.newPost`, a
 * plugin's generic post editor, REST if it were ever switched on -- has to
 * clear. An icon applies site-wide, like a theme asset, and appears in every
 * editor for everyone, so it is a design decision rather than an upload and
 * not `upload_files`.
 *
 * The panel itself asks for `manage_options`, as an options screen does. That
 * is the stricter of the two, so it does not widen anything here.
 *
 * ─── Absent-safe ────────────────────────────────────────────────────────────
 *
 * `wp_register_icon()` is `@since 7.1.0` and this plugin declares WordPress
 * 6.6. Nothing here may assume it exists. On an older site nothing is
 * registered at all; a fatal on 40,000 installs is not a trade anybody would
 * take.
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
 * Private in every direction: no archive, no single view, not queryable, not in
 * search. An icon is markup that belongs to the editor, not a page somebody
 * should be able to open.
 *
 * `show_ui` is false as well. The Icons panel is the interface; the generic
 * post list would offer a content editor for SVG markup, which is a text area
 * somebody would paste anything into.
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
             * Every capability is this plugin's own. The `post` defaults would
             * let an Author create an icon and an Editor change anybody's
             * through any route that asks the post type -- XML-RPC, REST if it
             * were ever switched on, a plugin's generic post editor. An icon
             * appears in every editor on the site; who may add one is the
             * same question as who may change the theme.
             *
             * `map_meta_cap` off, so `edit_post` and friends are checked as
             * written here instead of being mapped back to post-author rules.
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
 * Every stored icon, oldest first.
 *
 * Oldest first so the picker does not reshuffle itself when somebody adds one.
 *
 * ─── Why it is cached ───────────────────────────────────────────────────────
 *
 * Core has no lazy hook for its icon registry: it registers its own icons on
 * every `init`, and so must we, because the Icon block is rendered on the
 * server for every visitor. Reading the list from the posts table there meant
 * a full query, markup included, on every request of every 7.1 site -- most of
 * which have no icons at all -- for a list that changes only when somebody adds
 * or removes one.
 *
 * So the list lives in a transient, dropped whenever an icon is saved or
 * deleted (see `easy_svg_forget_icons()`; hooked on the post type, so WP-CLI
 * and anything else that writes icons drops it too). It is given an expiry for
 * two reasons: a transient with one is not autoloaded, so a site with many
 * icons does not carry their markup through every request's options; and a
 * write that bypassed WordPress entirely heals by itself within a day.
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
function easy_svg_icon_page( $page, $per_page ) {
    $posts = get_posts(
        array(
            'post_type'              => EASY_SVG_ICON_POST_TYPE,
            'post_status'            => 'publish',
            'posts_per_page'         => (int) $per_page,
            'paged'                  => (int) $page,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        )
    );

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
function easy_svg_add_icon( $label, $markup ) {
    $sanitizer = easy_svg_sanitizer();
    if ( null === $sanitizer ) {
        return 'no_sanitizer';
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
        return $decision['state'];
    }

    /*
     * `post_name` is left to WordPress, which makes it unique against what is
     * already there. Two icons called "Arrow" then become `arrow` and `arrow-2`
     * rather than one silently replacing the other -- and the second name is
     * still one core accepts, because `wp_unique_post_slug` only ever appends
     * `-<number>`.
     *
     * Asked for a WP_Error, and the answer is checked. "Icon added." above a
     * table without the icon -- a full disk, a read-only database, a plugin
     * vetoing the insert -- sends a person looking for a bug in the wrong place.
     */
    /*
     * kses is switched off for this one insert, and only if it was on.
     * wp_insert_post() runs content through wp_filter_post_kses for anybody
     * without unfiltered_html -- every administrator of a multisite sub-site,
     * every site with DISALLOW_UNFILTERED_HTML -- and kses knows no SVG, so the
     * icon arrived empty. The markup has just been through this site's SVG
     * sanitiser, which is the check that understands it. Restored in `finally`,
     * so neither an error return nor an exception leaves kses off for the
     * rest of the request.
     *
     * Slashed, because wp_insert_post() unslashes what it is given; without
     * it a backslash in the markup is lost.
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
        return 'not_saved';
    }

    // Whatever else may sit on the way in, what was STORED is what the block
    // will show. An icon that arrived without a drawing is not kept.
    $stored = get_post( $id );
    if ( ! $stored || false === stripos( (string) $stored->post_content, '<svg' ) ) {
        wp_delete_post( $id, true );
        return 'not_saved';
    }

    return 'added';
}
