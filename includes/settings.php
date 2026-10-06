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

add_action( 'admin_menu', 'easy_svg_settings_menu' );
add_action( 'admin_init', 'easy_svg_settings_register' );

/** Settings -> Easy SVG. */
function easy_svg_settings_menu(): void {
	add_options_page(
		__( 'Easy SVG', 'easy-svg' ),
		__( 'Easy SVG', 'easy-svg' ),
		'manage_options',
		'easy-svg',
		'easy_svg_settings_render'
	);
}

/** Register the option, its sanitiser, and the fields. */
function easy_svg_settings_register(): void {
	register_setting(
		'easy_svg',
		EASY_SVG_SETTINGS_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'easy_svg_sanitize_settings',
			'default'           => easy_svg_settings_defaults(),
		)
	);
	add_settings_section( 'easy_svg_main', '', '__return_null', 'easy-svg' );
	add_settings_field( 'svg_upload', __( 'Allow SVG uploads', 'easy-svg' ), 'easy_svg_field_toggle', 'easy-svg', 'easy_svg_main', array( 'key' => 'svg_upload' ) );
	add_settings_field( 'icons', __( 'Icon manager', 'easy-svg' ), 'easy_svg_field_toggle', 'easy-svg', 'easy_svg_main', array( 'key' => 'icons' ) );
	add_settings_field( 'max_mb', __( 'Maximum SVG size (MB)', 'easy-svg' ), 'easy_svg_field_max_mb', 'easy-svg', 'easy_svg_main' );
}

/** A single on/off checkbox bound to one settings key. */
function easy_svg_field_toggle( array $args ): void {
	$key      = (string) ( $args['key'] ?? '' );
	$settings = easy_svg_settings();
	$on       = ! empty( $settings[ $key ] );
	printf(
		'<label><input type="checkbox" name="%1$s[%2$s]" value="1"%3$s> %4$s</label>',
		esc_attr( EASY_SVG_SETTINGS_OPTION ),
		esc_attr( $key ),
		checked( $on, true, false ),
		esc_html__( 'Enabled', 'easy-svg' )
	);
}

/** The megabyte ceiling, 1..20. */
function easy_svg_field_max_mb(): void {
	$settings = easy_svg_settings();
	printf(
		'<input type="number" min="1" max="20" step="1" name="%1$s[max_mb]" value="%2$d"> %3$s',
		esc_attr( EASY_SVG_SETTINGS_OPTION ),
		(int) $settings['max_mb'],
		esc_html__( 'MB', 'easy-svg' )
	);
}

/** The page shell. */
function easy_svg_settings_render(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	echo '<div class="wrap"><h1>' . esc_html__( 'Easy SVG', 'easy-svg' ) . '</h1><form action="options.php" method="post">';
	settings_fields( 'easy_svg' );
	do_settings_sections( 'easy-svg' );
	submit_button();
	echo '</form></div>';
}

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
	$link = '<a href="' . esc_url( admin_url( 'options-general.php?page=easy-svg' ) ) . '">' . esc_html__( 'Settings → Easy SVG', 'easy-svg' ) . '</a>';
	if ( 'kept' === $state ) {
		/* translators: %s: link to the settings page. */
		$msg = sprintf( __( 'Easy SVG 5.0: your SVG uploads stay on. New in 5.0 — an icon library and a settings page. Manage both under %s.', 'easy-svg' ), $link );
	} else {
		/* translators: %s: link to the settings page. */
		$msg = sprintf( __( 'Easy SVG 5.0: features are opt-in. Turn on SVG uploads and the icon library under %s.', 'easy-svg' ), $link );
	}
	echo '<div class="notice notice-info is-dismissible"><p>' . wp_kses_post( $msg ) . '</p></div>';
}

add_action( 'admin_init', 'easy_svg_run_migration' );
add_action( 'admin_notices', 'easy_svg_migration_notice' );
