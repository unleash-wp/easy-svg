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

/**
 * The "Icons" mark: a small set of tiles, one a circle, so it reads as a
 * collection of icons rather than a generic app grid. Monochrome on purpose --
 * WordPress tints the menu icon by state, and the panel header passes the brand
 * navy, so the shape takes a single $fill and is coloured by its context. The
 * forge radius matches the UnleashWP design system. Drop-in replaceable if the
 * official UnleashWP glyph is supplied: same signature, same square viewBox.
 */
function easy_svg_panel_icon_svg( string $fill ): string {
	return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><g fill="' . $fill . '">'
		. '<rect x="3" y="3" width="8" height="8" rx="2.4"/>'
		. '<circle cx="17" cy="7" r="4"/>'
		. '<rect x="3" y="13" width="8" height="8" rx="2.4"/>'
		. '<rect x="13" y="13" width="8" height="8" rx="2.4"/>'
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
	// wp-i18n ships the wp.i18n global the bundle externalises for __()/_x().
	wp_enqueue_script(
		'easy-svg-panel',
		plugins_url( 'build/panel.js', $main ),
		array( 'react', 'react-dom', 'react-jsx-runtime', 'wp-i18n' ),
		(string) filemtime( $build ),
		true
	);

	// Serve the panel's JS translations (languages/easy-svg-*-easy-svg-panel.json).
	wp_set_script_translations( 'easy-svg-panel', 'easy-svg' );

	$pro_active   = function_exists( 'easy_svg_pro_pro_enabled' );
	$pro_licensed = $pro_active && easy_svg_pro_pro_enabled();

	wp_localize_script(
		'easy-svg-panel',
		'EasySvgPanelData',
		array(
			'restRoot'     => esc_url_raw( trailingslashit( rest_url() ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'icon'         => easy_svg_panel_icon_uri( '#203159' ),
			'proActive'    => $pro_active,
			'proLicensed'  => $pro_licensed,
			'iconsEnabled' => function_exists( 'easy_svg_feature_enabled' ) && easy_svg_feature_enabled( 'icons' ),
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
	register_rest_route(
		'easy-svg/v1',
		'/library',
		array(
			'methods'             => 'GET',
			'permission_callback' => 'easy_svg_panel_rest_permission',
			'callback'            => 'easy_svg_panel_library_get',
		)
	);
	register_rest_route(
		'easy-svg/v1',
		'/library/add',
		array(
			'methods'             => 'POST',
			'permission_callback' => 'easy_svg_panel_rest_permission',
			'callback'            => 'easy_svg_panel_library_add',
		)
	);
	register_rest_route(
		'easy-svg/v1',
		'/library/delete',
		array(
			'methods'             => 'POST',
			'permission_callback' => 'easy_svg_panel_rest_permission',
			'callback'            => 'easy_svg_panel_library_delete',
		)
	);
}

/** GET /library -> the stored icons (id, slug, label, hardened markup). */
function easy_svg_panel_library_get( $request ) {
	if ( ! function_exists( 'easy_svg_icon_page' ) ) {
		return new WP_REST_Response( array() );
	}
	return new WP_REST_Response( easy_svg_icon_page( 1, 500 ) );
}

/** A user-readable reason for an add that the store refused. */
function easy_svg_panel_add_message( string $state ): string {
	switch ( $state ) {
		case 'no_sanitizer':
			return __( 'The sanitiser is unavailable, so nothing was stored.', 'easy-svg' );
		case 'not_saved':
		case 'empty':
			return __( 'That SVG has no drawing, or could not be stored.', 'easy-svg' );
		case 'not_svg':
			return __( 'That is not an SVG the sanitiser accepts.', 'easy-svg' );
		case 'bad_name':
			return __( 'That name cannot be turned into an icon name. Use letters and numbers.', 'easy-svg' );
		case 'too_large':
			return __( 'That SVG is larger than the size allowed in Settings.', 'easy-svg' );
		case 'too_complex':
			return __( 'That SVG has too many parts to be read safely.', 'easy-svg' );
		default:
			return __( 'The icon could not be added.', 'easy-svg' );
	}
}

/**
 * POST /library/add -> add one icon. The markup is sanitised AND hardened by
 * easy_svg_add_icon() before storage -- exactly the bar an upload meets.
 */
function easy_svg_panel_library_add( $request ) {
	if ( ! function_exists( 'easy_svg_add_icon' ) ) {
		// Deliberately the same sentence the Library tab shows (Library.jsx), so
		// the REST refusal and the UI that provoked it read identically -- and so
		// there is one string to translate rather than two spellings of one.
		return new WP_Error( 'easy_svg_no_store', __( 'The icon library is off. Switch it on under the Settings tab.', 'easy-svg' ), array( 'status' => 409 ) );
	}
	$body   = (array) $request->get_json_params();
	$label  = isset( $body['label'] ) ? sanitize_text_field( (string) $body['label'] ) : '';
	$markup = isset( $body['markup'] ) ? (string) $body['markup'] : '';
	$result = easy_svg_add_icon( $label, $markup );
	if ( 'added' !== $result ) {
		return new WP_Error( 'easy_svg_add_failed', easy_svg_panel_add_message( (string) $result ), array( 'status' => 422 ) );
	}
	return new WP_REST_Response( easy_svg_icon_page( 1, 500 ) );
}

/** POST /library/delete -> remove one icon by id (only an esw_icon post). */
function easy_svg_panel_library_delete( $request ) {
	$body = (array) $request->get_json_params();
	$id   = isset( $body['id'] ) ? max( 0, (int) $body['id'] ) : 0;
	$post = $id > 0 ? get_post( $id ) : null;
	if ( ! $post || ! defined( 'EASY_SVG_ICON_POST_TYPE' ) || EASY_SVG_ICON_POST_TYPE !== $post->post_type ) {
		return new WP_Error( 'easy_svg_no_icon', __( 'Icon not found.', 'easy-svg' ), array( 'status' => 404 ) );
	}
	wp_delete_post( $id, true );
	return new WP_REST_Response( function_exists( 'easy_svg_icon_page' ) ? easy_svg_icon_page( 1, 500 ) : array() );
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
