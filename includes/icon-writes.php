<?php
/**
 * The icon writes an add-on has to be able to make, and must not reimplement.
 *
 * Its own file so a test can load the write without the whole store: the suite
 * that drives it stubs `easy_svg_icon_page()`, which icon-manager.php also
 * declares, and two declarations of one name is a fatal.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Rename one stored icon, and answer with the word for what happened.
 *
 * Here rather than in the REST handler because two things in it are easy to get
 * wrong and expensive to get wrong twice: `wp_update_post()` re-saves the row
 * whole, so the markup passes `content_save_pre` again and kses would strip the
 * SVG for any administrator without `unfiltered_html` -- renaming would quietly
 * empty the icon. And the cached icon list has to be dropped, or the editor
 * offers the old name for up to a day.
 *
 * `post_name` is NOT touched. It is the icon's identity: a theme and a saved
 * Icon block both carry the slug, so moving it would point every page using the
 * icon at a name nothing answers to -- and silently, because core renders a
 * missing icon as nothing at all.
 *
 * @param int    $id    The icon post's id.
 * @param string $label The new visible name, already sanitised as text.
 * @return string `renamed`, `not_found`, `no_label` or `failed`.
 */
function easy_svg_rename_icon( $id, $label ) {
    $id   = max( 0, (int) $id );
    $post = $id > 0 ? get_post( $id ) : null;
    if ( ! $post || ! defined( 'EASY_SVG_ICON_POST_TYPE' ) || EASY_SVG_ICON_POST_TYPE !== $post->post_type ) {
        return 'not_found';
    }

    $label = (string) $label;
    if ( '' === $label ) {
        return 'no_label';
    }

    $kses_was_on = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
    if ( $kses_was_on ) {
        kses_remove_filters();
    }

    try {
        $updated = wp_update_post(
            array(
                'ID'         => $id,
                'post_title' => wp_slash( $label ),
            ),
            true
        );
    } finally {
        if ( $kses_was_on ) {
            kses_init_filters();
        }
    }

    if ( is_wp_error( $updated ) || ! $updated ) {
        return 'failed';
    }

    /*
     * The `save_post_esw_icon` hook that drops the cache is registered only
     * while the icons feature is on, and an icon post outlives the switch -- so
     * it is called here as well, where a second call is a no-op.
     */
    if ( function_exists( 'easy_svg_forget_icons' ) ) {
        easy_svg_forget_icons();
    }

    return 'renamed';
}
