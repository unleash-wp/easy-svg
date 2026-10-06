<?php
/**
 * The "Icons" admin panel: one branded top-level menu that hosts a React/Chakra
 * dashboard (UnleashWP look). Free owns it; Pro extends it through the JS
 * registry window.EasySvgPanel, injecting its own tabs.
 *
 * React and ReactDOM are NOT bundled -- WordPress ships them (script handles
 * `react` / `react-dom`), so the panel bundle is enqueued with them as
 * dependencies. The bundle loads only on this screen; the front end stays 0-JS.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EASY_SVG_PANEL_SLUG = 'easy-svg-icons';

/** A placeholder 2x2 icon-grid mark until the real square UnleashWP icon lands. */
function easy_svg_panel_icon_svg( string $fill ): string {
	return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><g fill="' . $fill . '">'
		. '<rect x="2" y="2" width="7" height="7" rx="1.6"/><rect x="11" y="2" width="7" height="7" rx="1.6"/>'
		. '<rect x="2" y="11" width="7" height="7" rx="1.6"/><rect x="11" y="11" width="7" height="7" rx="1.6"/>'
		. '</g></svg>';
}
function easy_svg_panel_icon_uri( string $fill ): string {
	return 'data:image/svg+xml;base64,' . base64_encode( easy_svg_panel_icon_svg( $fill ) );
}

add_action( 'admin_menu', 'easy_svg_panel_menu' );
add_action( 'admin_enqueue_scripts', 'easy_svg_panel_assets' );
add_action( 'rest_api_init', 'easy_svg_panel_rest' );

function easy_svg_panel_menu(): void {
	add_menu_page(
		__( 'Icons', 'easy-svg' ),
		__( 'Icons', 'easy-svg' ),
		'manage_options',
		EASY_SVG_PANEL_SLUG,
		'easy_svg_panel_render',
		easy_svg_panel_icon_uri( '#a7aaad' ),
		58
	);
}

function easy_svg_panel_render(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	echo '<div class="wrap"><div id="easy-svg-panel"></div></div>';
}

function easy_svg_panel_assets( $hook ): void {
	if ( 'toplevel_page_' . EASY_SVG_PANEL_SLUG !== $hook ) {
		return;
	}
	$main  = dirname( __DIR__ ) . '/easy-svg.php';
	$build = dirname( __DIR__ ) . '/build/panel.js';
	if ( ! is_readable( $build ) ) {
		return;
	}

	$fonts = dirname( __DIR__ ) . '/assets/panel-fonts.css';
	if ( is_readable( $fonts ) ) {
		wp_enqueue_style(
			'easy-svg-panel-fonts',
			plugins_url( 'assets/panel-fonts.css', $main ),
			array(),
			(string) filemtime( $fonts )
		);
	}

	// React, ReactDOM and the JSX runtime come from WordPress, not the bundle.
	wp_enqueue_script(
		'easy-svg-panel',
		plugins_url( 'build/panel.js', $main ),
		array( 'react', 'react-dom', 'react-jsx-runtime' ),
		(string) filemtime( $build ),
		true
	);

	$pro_active   = function_exists( 'easy_svg_pro_pro_enabled' );
	$pro_licensed = $pro_active && easy_svg_pro_pro_enabled();

	wp_localize_script(
		'easy-svg-panel',
		'EasySvgPanelData',
		array(
			'restRoot'    => esc_url_raw( trailingslashit( rest_url() ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'icon'        => easy_svg_panel_icon_uri( '#203159' ),
			'proActive'   => $pro_active,
			'proLicensed' => $pro_licensed,
		)
	);

	// Let Pro enqueue its extension bundle with ours as a dependency.
	do_action( 'easy_svg_panel_enqueue', 'easy-svg-panel' );
}

function easy_svg_panel_rest(): void {
	register_rest_route(
		'easy-svg/v1',
		'/settings',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => 'easy_svg_panel_rest_permission',
				'callback'            => 'easy_svg_panel_settings_get',
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => 'easy_svg_panel_rest_permission',
				'callback'            => 'easy_svg_panel_settings_post',
			),
		)
	);
}

function easy_svg_panel_rest_permission(): bool {
	return current_user_can( 'manage_options' );
}

/** GET /easy-svg/v1/settings -> the free settings (svg_upload, icons, max_mb). */
function easy_svg_panel_settings_get( $request ) {
	return new WP_REST_Response( easy_svg_settings() );
}

/**
 * POST /easy-svg/v1/settings -> store the free settings.
 *
 * The body runs through easy_svg_sanitize_settings() exactly as the Settings-API
 * form it replaces did: booleans coerced, max_mb clamped. Security (sanitise +
 * cap) stays always-on and is not a toggle here.
 */
function easy_svg_panel_settings_post( $request ) {
	$body = (array) $request->get_json_params();
	update_option( EASY_SVG_SETTINGS_OPTION, easy_svg_sanitize_settings( $body ) );
	return new WP_REST_Response( easy_svg_settings() );
}
