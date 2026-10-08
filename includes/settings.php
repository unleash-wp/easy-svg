<?php
/**
 * One option, one page, for turning features off and sizing the parser.
 *
 * Everything here reads a single option so the gate below cannot drift from
 * what the screen shows. Defaults are on and usable: the free plugin is whole
 * with the settings untouched.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EASY_SVG_SETTINGS_OPTION = 'easy_svg_settings';

/**
 * The shape and the defaults.
 *
 * Features are OFF by default: a fresh install does nothing until the admin
 * turns on what they need, so the plugin adds exactly what was asked for and
 * no more. `max_mb` is NOT a feature -- it is the security cap on what the
 * sanitiser will parse, always enforced, never off; only its value is settable.
 */
function easy_svg_settings_defaults(): array {
	return array(
		'svg_upload' => false,
		'icons'      => false,
		'max_mb'     => 2,
	);
}

/**
 * Stored settings over the defaults.
 *
 * Not memoised: get_option() is already served from WordPress's options cache
 * (one query per request), and leaving it un-cached keeps the gate honest when
 * the option changes mid-request -- and testable without a static to reset.
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
 * There is no screen in this file any more.
 *
 * These three settings are edited in one place, the Icons panel's own Settings
 * tab, which writes them through the panel's REST route. A second form under
 * Settings -> Easy SVG offered the same three options over the same option, so
 * every future setting would have had to be built twice and kept in step -- and
 * a person had two places to look for one switch.
 *
 * What stays here is the data: the defaults, the reader, the feature test, and
 * the sanitiser the REST route calls on every write. `register_setting()` went
 * with the form it belonged to; nothing posts to `options.php` for this option
 * now.
 */

/**
 * Booleans are booleans; the size is an integer megabyte in 1..20.
 *
 * A missing key keeps its default (off): features are opt-in, so a saved form
 * with a box unchecked -- which sends no key -- reads as off, as it should.
 *
 * @param mixed $input
 * @return array
 */
function easy_svg_sanitize_settings( $input ): array {
	$input    = is_array( $input ) ? $input : array();
	$defaults = easy_svg_settings_defaults();

	$max_mb = isset( $input['max_mb'] ) ? (int) $input['max_mb'] : $defaults['max_mb'];
	$max_mb = max( 1, min( 20, $max_mb ) );

	return array(
		'svg_upload' => array_key_exists( 'svg_upload', $input ) ? (bool) $input['svg_upload'] : $defaults['svg_upload'],
		'icons'      => array_key_exists( 'icons', $input ) ? (bool) $input['icons'] : $defaults['icons'],
		'max_mb'     => $max_mb,
	);
}

/*
 * The 5.0 upgrade. Features are off by default now, but a site that updated from
 * 4.x was uploading SVGs all along, and turning that off under it on a security
 * release would break the media library and read as the plugin failing. So an
 * existing site keeps its uploads; a genuinely fresh install gets the opt-in
 * defaults. The icon library is new in 5.0, so it stays opt-in for everyone.
 */
const EASY_SVG_SCHEMA_OPTION  = 'easy_svg_schema';
const EASY_SVG_SCHEMA_VERSION = '5.0';

/**
 * What to seed on the first 5.0 run, or null to leave the defaults (off).
 *
 * Pure: the WordPress query and option writes are in easy_svg_run_migration().
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
	// Points at the panel. This used to link to Settings -> Easy SVG, which no
	// longer exists -- a welcome notice whose one link 404s is worse than none.
	// Built from the constant, never from a copy of the slug: one file registers
	// that page, and a test asserts the string appears in that file alone.
	$link = '<a href="' . esc_url( admin_url( 'admin.php?page=' . EASY_SVG_PANEL_SLUG ) ) . '">' . esc_html__( 'Icons', 'easy-svg' ) . '</a>';
	if ( 'kept' === $state ) {
		/* translators: %s: link to the Icons panel. */
		$msg = sprintf( __( 'Easy SVG 5.0: your SVG uploads stay on. New in 5.0 — an icon library and a settings panel. Manage both under %s.', 'easy-svg' ), $link );
	} else {
		/* translators: %s: link to the Icons panel. */
		$msg = sprintf( __( 'Easy SVG 5.0: features are opt-in. Turn on SVG uploads and the icon library under %s.', 'easy-svg' ), $link );
	}
	echo '<div class="notice notice-info is-dismissible"><p>' . wp_kses_post( $msg ) . '</p></div>';
}

add_action( 'admin_init', 'easy_svg_run_migration' );
add_action( 'admin_notices', 'easy_svg_migration_notice' );
