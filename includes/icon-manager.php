<?php
/**
 * The icons screen, and the WordPress adapters under it.
 *
 * Everything that decides anything is in `icons.php`, injected and checked
 * without WordPress. What is left here is the part that cannot be: a post type,
 * a query, and markup.
 *
 * ─── Capability ─────────────────────────────────────────────────────────────
 *
 * `edit_theme_options`. An icon applies site-wide, like a theme asset, and
 * appears in every editor for everyone. That is a design decision rather than
 * an upload, so it is not `upload_files`.
 *
 * ─── Absent-safe ────────────────────────────────────────────────────────────
 *
 * `wp_register_icon()` is `@since 7.1.0` and this plugin declares WordPress
 * 6.0. Nothing here may assume it exists. On an older site there is no menu
 * entry and nothing is registered; a fatal on 40,000 installs is not a trade
 * anybody would take.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/icons.php';

/** Who may manage icons. */
const EASY_SVG_ICON_CAP = 'edit_theme_options';

/** One nonce action for the screen. */
const EASY_SVG_ICON_NONCE = 'easy_svg_icons';

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
 * `show_ui` is false as well. The screen below is the interface; the generic
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
             * Every capability is the screen's own. The `post` defaults would
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
 * Hooked on `save_post_esw_icon` rather than called from the screen's
 * handlers alone: an icon written by WP-CLI, an importer or a REST client has
 * to show up just the same.
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

function easy_svg_icons_admin() {
    add_action( 'admin_menu', 'easy_svg_icons_menu' );
    add_action( 'admin_post_easy_svg_add_icon', 'easy_svg_handle_add_icon' );
    add_action( 'admin_post_easy_svg_delete_icon', 'easy_svg_handle_delete_icon' );
}

/**
 * Media -> SVG icons, on a WordPress that can hold icons, and nowhere else.
 *
 * Before 7.1 there is no icon registry and no Icon block, so there is nothing
 * to manage. The entry used to appear anyway and open onto a sentence saying
 * so -- on every one of the older sites, for a feature they cannot use.
 */
function easy_svg_icons_menu() {
    if ( ! easy_svg_icons_supported() ) {
        return;
    }

    add_media_page(
        __( 'SVG icons', 'easy-svg' ),
        __( 'SVG icons', 'easy-svg' ),
        EASY_SVG_ICON_CAP,
        'easy-svg-icons',
        'easy_svg_icons_screen'
    );
}

/** Back to the screen, with one word about what happened. */
function easy_svg_icons_redirect( $state ) {
    wp_safe_redirect(
        add_query_arg(
            'easy-svg-state',
            rawurlencode( (string) $state ),
            admin_url( 'upload.php?page=easy-svg-icons' )
        )
    );
    exit;
}

function easy_svg_handle_add_icon() {
    if ( ! current_user_can( EASY_SVG_ICON_CAP ) ) {
        wp_die( esc_html__( 'You are not allowed to do this.', 'easy-svg' ), '', array( 'response' => 403 ) );
    }
    check_admin_referer( EASY_SVG_ICON_NONCE );

    $label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';

    /*
     * The uploaded file is read, never moved or kept: its bytes go through the
     * sanitiser below and only the cleaned markup is stored. `is_uploaded_file()`
     * is the check that the path really is PHP's own temporary upload, which is
     * all a tmp_name needs -- there is nothing in it to sanitise as text.
     */
    $markup = '';
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a temporary path, verified by is_uploaded_file().
    $tmp_name = isset( $_FILES['icon']['tmp_name'] ) ? (string) $_FILES['icon']['tmp_name'] : '';
    if ( '' !== $tmp_name && is_uploaded_file( $tmp_name ) ) {
        $markup = (string) file_get_contents( $tmp_name );
    }

    easy_svg_icons_redirect( easy_svg_add_icon( $label, $markup ) );
}

/**
 * Store one icon, and answer with the word for what happened.
 *
 * Split from the handler so the answer can be checked: the handler ends in a
 * redirect and an `exit`, and a check cannot run past either.
 *
 * @param string $label  What the person typed, already sanitised as text.
 * @param string $markup The bytes they uploaded.
 * @return string A state `easy_svg_icon_message()` has a sentence for.
 */
function easy_svg_add_icon( $label, $markup ) {
    $sanitizer = easy_svg_sanitizer();
    if ( null === $sanitizer ) {
        return 'no_sanitizer';
    }

    $decision = easy_svg_accept_icon(
        $label,
        $markup,
        array( $sanitizer, 'sanitize' )
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

function easy_svg_handle_delete_icon() {
    if ( ! current_user_can( EASY_SVG_ICON_CAP ) ) {
        wp_die( esc_html__( 'You are not allowed to do this.', 'easy-svg' ), '', array( 'response' => 403 ) );
    }
    check_admin_referer( EASY_SVG_ICON_NONCE );

    $id   = isset( $_POST['icon'] ) ? absint( wp_unslash( $_POST['icon'] ) ) : 0;
    $post = $id > 0 ? get_post( $id ) : null;

    // Checked before deleting: the id comes from a form, and a delete handler
    // that trusts it will happily remove a page.
    if ( $post && EASY_SVG_ICON_POST_TYPE === $post->post_type ) {
        wp_delete_post( $id, true );
    }

    easy_svg_icons_redirect( 'deleted' );
}

/**
 * The line above the table.
 *
 * Its own function rather than a sprintf inside the markup, because the screen
 * needs a WordPress that a plain-PHP suite does not have, and this sentence is
 * the part worth checking: it names how many icons there are and nothing to
 * measure them against. There is no limit, so "3 of 5" would advertise one.
 *
 * @param int $counted How many icons this site has.
 * @return string
 */
function easy_svg_icon_count_message( $counted ) {
    $counted = (int) $counted;

    return sprintf(
        /* translators: %d: how many icons this site has */
        _n( '%d icon. They appear in the Icon block.', '%d icons. They appear in the Icon block.', $counted, 'easy-svg' ),
        $counted
    );
}

/** The sentence for each state. */
function easy_svg_icon_message( $state ) {
    $messages = array(
        'added'        => __( 'Icon added.', 'easy-svg' ),
        'deleted'      => __( 'Icon removed. Pages already using it will show nothing where it was.', 'easy-svg' ),
        'bad_name'     => __( 'That name cannot be turned into an icon name. Use letters and numbers.', 'easy-svg' ),
        'empty'        => __( 'No file was uploaded.', 'easy-svg' ),
        'not_svg'      => __( 'That file could not be read as an SVG, so nothing was stored.', 'easy-svg' ),
        'no_sanitizer' => __( 'The SVG sanitiser did not load, so nothing was checked and nothing was stored.', 'easy-svg' ),
        'not_saved'    => __( 'WordPress could not save the icon, so nothing was stored. Please try again.', 'easy-svg' ),
    );

    return isset( $messages[ $state ] ) ? $messages[ $state ] : '';
}

/**
 * Elements no preview may contain, whatever a site's allow-list says.
 *
 * Everything here can run code or pull in a document: `script`; `foreignObject`
 * (it carries HTML, iframes included); the SVG Tiny `handler` and `listener`;
 * the animation elements, which can rewrite an `href` to `javascript:`
 * after the markup has been checked; and `style`, whose rules would apply to
 * the whole admin page, not to the icon. A thumbnail needs none of it.
 */
const EASY_SVG_PREVIEW_NEVER = array(
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
 * The wp_kses allow-list for the icon previews.
 *
 * This site's own SVG allow-list -- the one the sanitiser applied when the icon
 * was stored -- so the preview shows what the Icon block will show. Then
 * everything that can execute is taken out again, because that allow-list is
 * a filter (`esw_svg_allowed_tags`, `esw_svg_allowed_attributes`) and a site
 * may have widened it to `script` or `onload` for its own reasons. Storing such
 * markup is that site's call; running it in an administrator's browser the
 * moment this screen opens is not.
 *
 * Lower case throughout, because wp_kses looks names up in lower case.
 *
 * @return array<string, array<string, bool>>
 */
function easy_svg_icon_preview_allowed_html() {
    $attributes = array();
    foreach ( (array) esw_svg_attributes::getAttributes() as $attribute ) {
        $attribute = strtolower( (string) $attribute );

        // Event handlers, whatever the allow-list says. `on` is the whole
        // namespace of them, including ones no list has heard of yet.
        if ( '' === $attribute || 0 === strpos( $attribute, 'on' ) ) {
            continue;
        }

        $attributes[ $attribute ] = true;
    }

    $allowed = array();
    foreach ( (array) esw_svg_tags::getTags() as $tag ) {
        $tag = strtolower( (string) $tag );

        if ( '' === $tag || in_array( $tag, EASY_SVG_PREVIEW_NEVER, true ) ) {
            continue;
        }

        // The sanitiser's model: one attribute list, valid on every element.
        $allowed[ $tag ] = $attributes;
    }

    return $allowed;
}

/**
 * One stored icon, made safe to print as a drawing.
 *
 * Not esc_html: that would show the source instead of the picture. Two passes:
 *
 * 1. As XML, which stored icons are (they are the sanitiser's output): the
 *    never-elements go WITH their content -- wp_kses would remove the tag and
 *    leave a script's source standing as text -- and every `href` /
 *    `xlink:href` that does not point at a part of the same drawing (`#id`)
 *    goes. A thumbnail has no reason to link anywhere, and wp_kses checks
 *    neither `xlink:href` nor what a value means.
 * 2. wp_kses with the SVG allow-list above, which is what makes the output
 *    safe to print, whatever pass 1 did or did not catch.
 *
 * Markup that is not XML previews as nothing rather than as a guess.
 *
 * @param string $content Stored SVG markup.
 * @return string
 */
function easy_svg_icon_preview( $content ) {
    $doc      = new DOMDocument();
    $internal = libxml_use_internal_errors( true );
    // No entity substitution and no network access while reading.
    $loaded = $doc->loadXML( (string) $content, LIBXML_NONET );
    libxml_clear_errors();
    libxml_use_internal_errors( $internal );

    if ( ! $loaded || null === $doc->documentElement ) {
        return '';
    }

    $xpath = new DOMXPath( $doc );

    // Snapshot first: removing nodes while walking a live list skips some.
    $doomed = array();
    foreach ( $xpath->query( '//*' ) as $element ) {
        if ( in_array( strtolower( $element->localName ), EASY_SVG_PREVIEW_NEVER, true ) ) {
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
            $is_href = 'href' === strtolower( $attribute->localName );
            if ( $is_href && 0 !== strpos( ltrim( (string) $attribute->nodeValue ), '#' ) ) {
                $drop[] = $attribute;
            }
        }
        foreach ( $drop as $attribute ) {
            $element->removeAttributeNode( $attribute );
        }
    }

    $markup = $doc->saveXML( $doc->documentElement );

    return wp_kses( (string) $markup, easy_svg_icon_preview_allowed_html() );
}

function easy_svg_icons_screen() {
    if ( ! current_user_can( EASY_SVG_ICON_CAP ) ) {
        return;
    }

    // Unreachable through the menu, which is not registered without the icon
    // API. Kept because a screen callback can be called by other means.
    if ( ! easy_svg_icons_supported() ) {
        return;
    }

    echo '<div class="wrap"><h1>' . esc_html__( 'SVG icons', 'easy-svg' ) . '</h1>';

    /*
     * Read only for display: it picks which of a fixed set of sentences to
     * show, and an unknown word shows none. Nothing is changed or decided from
     * it, so there is nothing for a nonce to protect -- the handlers that DO
     * change something check theirs before redirecting here.
     */
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only, see above.
    $state   = isset( $_GET['easy-svg-state'] ) ? sanitize_key( wp_unslash( $_GET['easy-svg-state'] ) ) : '';
    $message = easy_svg_icon_message( $state );

    if ( '' !== $message ) {
        $kind = in_array( $state, array( 'added', 'deleted' ), true ) ? 'success' : 'warning';
        echo '<div class="notice notice-' . esc_attr( $kind ) . '"><p>' . esc_html( $message ) . '</p></div>';
    }

    $icons = easy_svg_stored_icons();

    echo '<p>' . esc_html( easy_svg_icon_count_message( count( $icons ) ) ) . '</p>';

    echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
    wp_nonce_field( EASY_SVG_ICON_NONCE );
    echo '<input type="hidden" name="action" value="easy_svg_add_icon" />';
    echo '<p><label>' . esc_html__( 'Name', 'easy-svg' ) . ' <input type="text" name="label" required /></label> ';
    echo '<input type="file" name="icon" accept=".svg,image/svg+xml" required /> ';
    echo '<button type="submit" class="button button-primary">' . esc_html__( 'Add icon', 'easy-svg' ) . '</button></p>';
    echo '</form>';

    if ( array() === $icons ) {
        echo '</div>';
        return;
    }

    /*
     * An SVG with no width or height attribute is a replaced element with no
     * intrinsic size, so a browser gives it the CSS default: 300x150, or 300x300
     * with a square viewBox. Measured in a real browser -- every icon in this
     * table rendered at 300 pixels and the rows were as tall as the screen.
     *
     * `width` on the cell does not help: it sizes the COLUMN, and the graphic
     * overflows it. The size has to be on a box around the graphic, with the
     * graphic told to fill that box.
     *
     * Inline in the page rather than an enqueued stylesheet: it is four
     * declarations that exist for one table, and a file would be a request and
     * a version to keep in step for that.
     */
    echo '<style>'
        . '.easy-svg-icon-preview{display:inline-block;width:2.5rem;height:2.5rem;line-height:0}'
        . '.easy-svg-icon-preview svg{width:100%;height:100%;display:block}'
        . '</style>';

    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>' . esc_html__( 'Icon', 'easy-svg' ) . '</th>';
    echo '<th>' . esc_html__( 'Name', 'easy-svg' ) . '</th>';
    echo '<th>' . esc_html__( 'Used as', 'easy-svg' ) . '</th>';
    echo '<th></th></tr></thead><tbody>';

    foreach ( $icons as $icon ) {
        echo '<tr><td style="width:4rem"><span class="easy-svg-icon-preview">';
        // Escaped by wp_kses inside easy_svg_icon_preview(); see there for why
        // that, and not esc_html, is the right escape for a drawing.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo easy_svg_icon_preview( $icon['content'] );
        echo '</span></td><td>' . esc_html( $icon['label'] ) . '</td>';
        echo '<td><code>' . esc_html( easy_svg_icon_name( $icon['slug'] ) ) . '</code></td>';
        echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( EASY_SVG_ICON_NONCE );
        echo '<input type="hidden" name="action" value="easy_svg_delete_icon" />';
        echo '<input type="hidden" name="icon" value="' . esc_attr( (string) $icon['id'] ) . '" />';
        echo '<button type="submit" class="button">' . esc_html__( 'Remove', 'easy-svg' ) . '</button>';
        echo '</form></td></tr>';
    }

    echo '</tbody></table></div>';
}
