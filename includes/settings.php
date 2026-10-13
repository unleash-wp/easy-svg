<?php
/**
 * One option for the feature switches and the parser's size cap. Everything
 * reads it, so the gate cannot drift from what the panel's Settings tab shows.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EASY_SVG_SETTINGS_OPTION = 'easy_svg_settings';

/**
 * The shape and the defaults. Features are OFF on a fresh install: the plugin
 * adds what was asked for and no more. `max_mb` is NOT a feature -- it caps
 * what the sanitiser will parse, always enforced; only its value is settable.
 */
function easy_svg_settings_defaults(): array {
	return array(
		'svg_upload' => false,
		'icons'      => false,
		'max_mb'     => 2,
	);
}

/**
 * Stored settings over the defaults. Deliberately not memoised: get_option()
 * already comes from the options cache, and no static keeps the gate honest
 * when the option changes mid-request.
 */
function easy_svg_settings(): array {
	$stored = get_option( EASY_SVG_SETTINGS_OPTION, array() );
	return easy_svg_sanitize_settings( is_array( $stored ) ? $stored : array() );
}

/** Whether a feature is on. Unknown keys are off. */
function easy_svg_feature_enabled( string $key ): bool {
	$settings = easy_svg_settings();
	return ! empty( $settings[ $key ] );
}

/*
 * No screen in this file: these settings are edited only in the Icons panel's
 * Settings tab, which writes them through the panel's REST route. Nothing posts
 * to options.php for this option, so there is no register_setting() here.
 */

/**
 * The highest cap this site may set, in megabytes. 20 by default: past that a
 * single file keeps the sanitiser's DOM parser busy long enough to matter. The
 * filter is the one place the ceiling is decided.
 */
function easy_svg_max_mb_ceiling(): int {
	$ceiling = (int) apply_filters( 'easy_svg_max_mb_ceiling', 20 );
	return max( 1, $ceiling );
}

/**
 * Booleans are booleans; the size is an integer megabyte up to the ceiling. A
 * missing key keeps its default, so an unchecked box -- no key -- stays off.
 *
 * @param mixed $input
 * @return array
 */
function easy_svg_sanitize_settings( $input ): array {
	$input    = is_array( $input ) ? $input : array();
	$defaults = easy_svg_settings_defaults();

	$max_mb = isset( $input['max_mb'] ) ? (int) $input['max_mb'] : $defaults['max_mb'];
	$max_mb = max( 1, min( easy_svg_max_mb_ceiling(), $max_mb ) );

	return array(
		'svg_upload' => array_key_exists( 'svg_upload', $input ) ? (bool) $input['svg_upload'] : $defaults['svg_upload'],
		'icons'      => array_key_exists( 'icons', $input ) ? (bool) $input['icons'] : $defaults['icons'],
		'max_mb'     => $max_mb,
	);
}

/*
 * The 5.0 upgrade. A site that updated from 4.x was uploading SVGs all along,
 * so it keeps them; switching that off under it would break the media library.
 * The icon library is new in 5.0, so it stays opt-in for everyone.
 */
const EASY_SVG_SCHEMA_OPTION  = 'easy_svg_schema';
const EASY_SVG_SCHEMA_VERSION = '5.0';

/**
 * What to seed on the first 5.0 run, or null to leave the defaults (off). Pure:
 * the query and the option writes live in easy_svg_run_migration().
 *
 * @return array|null
 */
function easy_svg_migration_decision( bool $option_exists, bool $has_svgs ): ?array {
	if ( $option_exists ) {
		return null; // Already configured: never overwrite a choice.
	}
	if ( $has_svgs ) {
		return array(
			'svg_upload' => true,
			'icons'      => false,
			'max_mb'     => 2,
		);
	}
	return null; // Fresh: the opt-in defaults apply.
}

/** True when the media library already holds at least one SVG. */
function easy_svg_site_has_svgs(): bool {
	$ids = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image/svg+xml',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);
	return ! empty( $ids );
}

/** Run the one-time 5.0 migration, and arm the notice. */
function easy_svg_run_migration(): void {
	if ( EASY_SVG_SCHEMA_VERSION === get_option( EASY_SVG_SCHEMA_OPTION ) ) {
		return;
	}
	$option_exists = is_array( get_option( EASY_SVG_SETTINGS_OPTION, null ) );
	$seed          = easy_svg_migration_decision( $option_exists, easy_svg_site_has_svgs() );
	if ( null !== $seed ) {
		update_option( EASY_SVG_SETTINGS_OPTION, easy_svg_sanitize_settings( $seed ) );
		set_transient( 'easy_svg_5_notice', 'kept', WEEK_IN_SECONDS );
	} elseif ( ! $option_exists ) {
		set_transient( 'easy_svg_5_notice', 'fresh', WEEK_IN_SECONDS );
	}
	update_option( EASY_SVG_SCHEMA_OPTION, EASY_SVG_SCHEMA_VERSION );
}

/** One dismissible notice after the 5.0 update, pointing at the settings. */
function easy_svg_migration_notice(): void {
	$state = get_transient( 'easy_svg_5_notice' );
	if ( ! $state || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	delete_transient( 'easy_svg_5_notice' );
	// Built from the constant, never from a copy of the slug: one file registers
	// that page, and a test asserts the string appears in that file alone.
	$link = '<a href="' . esc_url( admin_url( 'admin.php?page=' . EASY_SVG_PANEL_SLUG ) ) . '">' . esc_html__( 'Icons', 'easy-svg' ) . '</a>';
	if ( 'kept' === $state ) {
		/* translators: %s: link to the Icons panel. */
		$msg = sprintf( __( 'Easy SVG 5.0: your SVG uploads stay on. New in 5.0: an icon library and a settings panel. Manage both under %s.', 'easy-svg' ), $link );
	} else {
		/* translators: %s: link to the Icons panel. */
		$msg = sprintf( __( 'Easy SVG 5.0: features are opt-in. Turn on SVG uploads and the icon library under %s.', 'easy-svg' ), $link );
	}
	echo '<div class="notice notice-info is-dismissible"><p>' . wp_kses_post( $msg ) . '</p></div>';
}

add_action( 'admin_init', 'easy_svg_run_migration' );
add_action( 'admin_notices', 'easy_svg_migration_notice' );
