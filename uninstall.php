<?php
/**
 * What uninstalling removes: the icons, their cache and the plugin's own
 * options. Uploaded SVGs stay -- they are media items pages use, and deleting
 * a plugin must not take a site's images with it. The icons exist only for
 * this plugin's Icon block collection, so they go, in batches and per site.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/** Removes this plugin's data from the current site. */
function easy_svg_uninstall_site() {
    // Bounded: a delete that keeps failing must end the loop, not the request.
    for ( $round = 0; $round < 1000; $round++ ) {
        $ids = get_posts(
            array(
                'post_type'              => 'esw_icon',
                'post_status'            => 'any',
                'fields'                 => 'ids',
                'posts_per_page'         => 100,
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            )
        );

        if ( array() === $ids ) {
            break;
        }

        $deleted = 0;
        foreach ( $ids as $id ) {
            if ( wp_delete_post( (int) $id, true ) ) {
                $deleted++;
            }
        }

        if ( 0 === $deleted ) {
            break;
        }
    }

    delete_transient( 'easy_svg_icons' );
    delete_transient( 'easy_svg_5_notice' );
    delete_option( 'easy_svg_icons_version' );
    delete_option( 'easy_svg_settings' );
    delete_option( 'easy_svg_schema' );
}

if ( is_multisite() ) {
    foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $easy_svg_site_id ) {
        switch_to_blog( (int) $easy_svg_site_id );
        easy_svg_uninstall_site();
        restore_current_blog();
    }
} else {
    easy_svg_uninstall_site();
}
