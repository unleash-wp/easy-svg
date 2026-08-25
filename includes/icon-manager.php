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
 * 6.0. Nothing here may assume it exists. On an older site the screen says so
 * in a sentence and stops; a fatal on 40,000 installs is not a trade anybody
 * would take.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/icons.php';

/** Who may manage icons. */
const EASY_SVG_ICON_CAP = 'edit_theme_options';

/** One nonce action for the screen. */
const EASY_SVG_ICON_NONCE = 'easy_svg_icons';

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
            'capability_type'     => 'post',
        )
    );
}

/**
 * Every stored icon, oldest first.
 *
 * Oldest first so the picker does not reshuffle itself when somebody adds one.
 *
 * @return array<int, array{id:int, slug:string, label:string, content:string}>
 */
function easy_svg_stored_icons() {
    $posts = get_posts(
        array(
            'post_type'      => EASY_SVG_ICON_POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'orderby'        => 'ID',
            'order'          => 'ASC',
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

/** How many icons this site has. Counted, never guessed from a list length. */
function easy_svg_icon_count() {
    $counts = wp_count_posts( EASY_SVG_ICON_POST_TYPE );

    return isset( $counts->publish ) ? (int) $counts->publish : 0;
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

function easy_svg_icons_menu() {
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

    $markup = '';
    if ( isset( $_FILES['icon']['tmp_name'] ) && is_uploaded_file( $_FILES['icon']['tmp_name'] ) ) {
        $markup = (string) file_get_contents( $_FILES['icon']['tmp_name'] );
    }

    $sanitizer = easy_svg_sanitizer();
    if ( null === $sanitizer ) {
        easy_svg_icons_redirect( 'no_sanitizer' );
    }

    $decision = easy_svg_accept_icon(
        $label,
        $markup,
        array( $sanitizer, 'sanitize' ),
        easy_svg_icon_count(),
        easy_svg_icon_limit()
    );

    if ( 'ok' !== $decision['state'] ) {
        easy_svg_icons_redirect( $decision['state'] );
    }

    /*
     * `post_name` is left to WordPress, which makes it unique against what is
     * already there. Two icons called "Arrow" then become `arrow` and `arrow-2`
     * rather than one silently replacing the other -- and the second name is
     * still one core accepts, because `wp_unique_post_slug` only ever appends
     * `-<number>`.
     */
    wp_insert_post(
        array(
            'post_type'    => EASY_SVG_ICON_POST_TYPE,
            'post_status'  => 'publish',
            'post_title'   => $label,
            'post_name'    => $decision['slug'],
            'post_content' => $decision['content'],
        )
    );

    easy_svg_icons_redirect( 'added' );
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

/** The sentence for each state. */
function easy_svg_icon_message( $state ) {
    $limit = easy_svg_icon_limit();

    $messages = array(
        'added'         => __( 'Icon added.', 'easy-svg' ),
        'deleted'       => __( 'Icon removed. Pages already using it will show nothing where it was.', 'easy-svg' ),
        'limit_reached' => sprintf(
            /* translators: %d: how many icons this site may keep */
            __( 'This site already has its %d icons. Remove one to add another.', 'easy-svg' ),
            $limit
        ),
        'bad_name'      => __( 'That name cannot be turned into an icon name. Use letters and numbers.', 'easy-svg' ),
        'empty'         => __( 'No file was uploaded.', 'easy-svg' ),
        'not_svg'       => __( 'That file could not be read as an SVG, so nothing was stored.', 'easy-svg' ),
        'no_sanitizer'  => __( 'The SVG sanitiser did not load, so nothing was checked and nothing was stored.', 'easy-svg' ),
    );

    return isset( $messages[ $state ] ) ? $messages[ $state ] : '';
}

function easy_svg_icons_screen() {
    if ( ! current_user_can( EASY_SVG_ICON_CAP ) ) {
        return;
    }

    echo '<div class="wrap"><h1>' . esc_html__( 'SVG icons', 'easy-svg' ) . '</h1>';

    if ( ! easy_svg_icons_supported() ) {
        echo '<div class="notice notice-warning"><p>' . esc_html__(
            'Icons need WordPress 7.1 or newer, which is where the icon block and its registry arrived. Everything else in this plugin works as before.',
            'easy-svg'
        ) . '</p></div></div>';
        return;
    }

    // Read only for display. Nothing is decided from it.
    $state   = isset( $_GET['easy-svg-state'] ) ? sanitize_key( wp_unslash( $_GET['easy-svg-state'] ) ) : '';
    $message = easy_svg_icon_message( $state );

    if ( '' !== $message ) {
        $kind = in_array( $state, array( 'added', 'deleted' ), true ) ? 'success' : 'warning';
        echo '<div class="notice notice-' . esc_attr( $kind ) . '"><p>' . esc_html( $message ) . '</p></div>';
    }

    $icons = easy_svg_stored_icons();
    $limit = easy_svg_icon_limit();

    echo '<p>' . esc_html(
        sprintf(
            /* translators: 1: icons stored, 2: how many are allowed */
            __( '%1$d of %2$d icons. They appear in the Icon block.', 'easy-svg' ),
            count( $icons ),
            $limit
        )
    ) . '</p>';

    if ( easy_svg_icon_may_add( count( $icons ), $limit ) ) {
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( EASY_SVG_ICON_NONCE );
        echo '<input type="hidden" name="action" value="easy_svg_add_icon" />';
        echo '<p><label>' . esc_html__( 'Name', 'easy-svg' ) . ' <input type="text" name="label" required /></label> ';
        echo '<input type="file" name="icon" accept=".svg,image/svg+xml" required /> ';
        echo '<button type="submit" class="button button-primary">' . esc_html__( 'Add icon', 'easy-svg' ) . '</button></p>';
        echo '</form>';
    }

    if ( array() === $icons ) {
        echo '</div>';
        return;
    }

    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>' . esc_html__( 'Icon', 'easy-svg' ) . '</th>';
    echo '<th>' . esc_html__( 'Name', 'easy-svg' ) . '</th>';
    echo '<th>' . esc_html__( 'Used as', 'easy-svg' ) . '</th>';
    echo '<th></th></tr></thead><tbody>';

    foreach ( $icons as $icon ) {
        echo '<tr><td style="width:3rem">';
        /*
         * Printed unescaped, and that is the one place in this file where that
         * is true. It is SVG markup and escaping it would show source code
         * instead of a drawing.
         *
         * What makes it safe is not this line, it is that nothing reaches
         * `post_content` without going through the sanitiser first -- see
         * `easy_svg_accept_icon()`, which stores the CLEANED markup and refuses
         * anything the sanitiser could not read.
         */
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $icon['content'];
        echo '</td><td>' . esc_html( $icon['label'] ) . '</td>';
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
