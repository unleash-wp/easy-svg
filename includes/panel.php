<?php
/**
 * The "Icons" admin panel: one branded top-level menu hosting a React/Chakra
 * dashboard. Free owns it; Pro extends it through window.EasySvgPanel. React
 * and ReactDOM are NOT bundled -- WordPress ships them, so the bundle takes
 * `react` / `react-dom` as dependencies and the front end stays 0-JS.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EASY_SVG_PANEL_SLUG = 'easy-svg-icons';

/**
 * The "Icons" mark: tiles with one circle, so it reads as a collection of icons
 * rather than a generic app grid. Monochrome on purpose -- WordPress tints the
 * menu icon by state, so the shape takes a single $fill from its context.
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

/*
 * Priority 9, before core's `_add_post_type_submenus` at the default 10. Pro
 * nests its icon-set post type here, and WordPress points a top-level link at
 * whichever submenu entry was registered FIRST -- at 10, clicking "Icons" opens
 * a post list instead of the panel, and plugin load order decides it.
 */
add_action( 'admin_menu', 'easy_svg_panel_menu', 9 );
add_filter( 'submenu_file', 'easy_svg_panel_current_submenu' );
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

	/*
	 * The panel's own entry, not decoration: add_menu_page() registers no
	 * submenu item, so as soon as anything nests here WordPress would point the
	 * parent link at that first entry and "Icons" would open a post list. Same
	 * slug and callback as the parent is what makes it the self-entry.
	 */
	add_submenu_page(
		EASY_SVG_PANEL_SLUG,
		__( 'Icons', 'easy-svg' ),
		__( 'Icons', 'easy-svg' ),
		'manage_options',
		EASY_SVG_PANEL_SLUG,
		'easy_svg_panel_render'
	);

	/*
	 * One entry per tab: the panel is a single page, and each entry is that
	 * page with `?tab=` in the slug, so WordPress builds the URL and marks the
	 * entry current by itself. Pro contributes its own through the filter.
	 */
	foreach ( easy_svg_panel_menu_tabs() as $tab => $label ) {
		add_submenu_page(
			EASY_SVG_PANEL_SLUG,
			$label,
			$label,
			'manage_options',
			EASY_SVG_PANEL_SLUG . '&tab=' . $tab,
			'easy_svg_panel_render'
		);
	}
}

/**
 * Mark the entry for the tab that is actually open. WordPress decides which
 * item is current from `$plugin_page` alone, which here is always this page,
 * so without this the parent entry stays highlighted for every tab.
 *
 * @param string|null $submenu_file
 * @return string|null
 */
function easy_svg_panel_current_submenu( $submenu_file ) {
	if ( ! isset( $_GET['page'], $_GET['tab'] ) || EASY_SVG_PANEL_SLUG !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return $submenu_file;
	}
	$tab = sanitize_key( wp_unslash( $_GET['tab'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! isset( easy_svg_panel_menu_tabs()[ $tab ] ) ) {
		return $submenu_file;
	}
	return EASY_SVG_PANEL_SLUG . '&tab=' . $tab;
}

/**
 * The tabs that get their own entry in the menu, as slug => label.
 *
 * @return array<string,string>
 */
function easy_svg_panel_menu_tabs(): array {
	return (array) apply_filters(
		'easy_svg_panel_menu_tabs',
		array(
			'settings' => __( 'Settings', 'easy-svg' ),
			'library'  => __( 'Icon library', 'easy-svg' ),
		)
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

	/*
	 * No font stylesheet is enqueued: the two bundled Ubuntu faces are
	 * @font-face'd in the inline block below, beside the rules that need them.
	 */

	// The media frame, for picking SVGs that are already in the library. Only on
	// this screen -- media-views is a large dependency to hand every admin page.
	wp_enqueue_media();

	// React, ReactDOM and the JSX runtime come from WordPress, not the bundle.
	// wp-i18n ships the wp.i18n global the bundle externalises for __()/_x().
	wp_enqueue_script(
		'easy-svg-panel',
		plugins_url( 'build/panel.js', $main ),
		array( 'react', 'react-dom', 'react-jsx-runtime', 'wp-i18n' ),
		(string) filemtime( $build ),
		true
	);

	/*
	 * The path argument is required for the catalogue this plugin BUNDLES:
	 * without it WordPress looks only in WP_LANG_DIR/plugins, plus the 6.7+
	 * textdomain registry. Measured on 7.1.3: found either way; on the 6.6 floor
	 * only with the path. The file is named after md5() of `build/panel.js`.
	 */
	wp_set_script_translations( 'easy-svg-panel', 'easy-svg', dirname( __DIR__ ) . '/languages' );

	$pro_active   = function_exists( 'easy_svg_pro_pro_enabled' );
	$pro_licensed = $pro_active && easy_svg_pro_pro_enabled();

	wp_localize_script(
		'easy-svg-panel',
		'EasySvgPanelData',
		array(
			'restRoot'     => esc_url_raw( trailingslashit( rest_url() ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'icon'         => easy_svg_panel_icon_uri( '#203159' ),
			// Shown in the panel's footer, the way Forge shows its own. Read from
			// the plugin header rather than a constant, because the header is the
			// single version source this plugin has.
			'version'      => (string) ( get_file_data( $main, array( 'Version' => 'Version' ) )['Version'] ?? '' ),
			'proActive'    => $pro_active,
			'proLicensed'  => $pro_licensed,
			'iconsEnabled' => function_exists( 'easy_svg_feature_enabled' ) && easy_svg_feature_enabled( 'icons' ),
			// The cap's ceiling, so the field and the slider end where the
			// server actually clamps -- Pro raises it, and the panel must not
			// show a range the save will refuse.
			'maxMbCeiling' => function_exists( 'easy_svg_max_mb_ceiling' ) ? easy_svg_max_mb_ceiling() : 20,
		)
	);

	/*
	 * Core styles `.wrap h1` unlayered, and a layered Chakra rule loses to any
	 * unlayered one however specific -- so the design system's h1 token lost
	 * (measured: 23px/400 inside .wrap, 24px/700 without it). These rules are
	 * unlayered and carry an id. The numbers repeat src/theme.js; change both.
	 */
	wp_register_style( 'easy-svg-panel', false, array(), (string) filemtime( $build ) );
	wp_enqueue_style( 'easy-svg-panel' );
	wp_add_inline_style(
		'easy-svg-panel',
		// The panel paints its own canvas. wp-admin's body is #f0f0f0, a neutral
		// grey; ours is the cool ui.bg the rest of UnleashWP uses, so the white
		// header, rail and cards sit on the same ground they do in the account app
		// and in Forge. Scoped to this screen's body class, so no other admin page
		// changes colour.
		'body.toplevel_page_' . EASY_SVG_PANEL_SLUG . '{background:#eef1f6}'
		// Ubuntu, bundled (UFL 1.0, licence text beside the files in
		// build/fonts) -- the same two faces Pro's set editor loads, so the
		// whole product renders one brand type. The theme's heading stack
		// names the family and falls back to system type where it is not
		// loaded.
		. '@font-face{font-family:Ubuntu;font-style:normal;font-weight:400;font-display:swap;'
		. 'src:url(' . esc_url( plugins_url( 'build/fonts/ubuntu-v21-latin-400-normal.woff2', $main ) ) . ') format("woff2")}'
		. '@font-face{font-family:Ubuntu;font-style:normal;font-weight:700;font-display:swap;'
		. 'src:url(' . esc_url( plugins_url( 'build/fonts/ubuntu-v21-latin-700-normal.woff2', $main ) ) . ') format("woff2")}'
		// The whole panel, unlayered: core sets its own font on body and the
		// form elements, and a layered Chakra rule loses to an unlayered one
		// however specific. `inherit` on the controls because a button, input
		// or select takes the browser's font, not the document's.
		. '.wrap #easy-svg-panel,.wrap #easy-svg-panel :is(div,p,span,h1,h2,h3,h4,label,a,li,td,th,strong,em,small)'
		. '{font-family:Ubuntu,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}'
		. '.wrap #easy-svg-panel :is(button,input,textarea,select){font-family:inherit}'
		. '.wrap #easy-svg-panel h1{font-size:28px;font-weight:700;margin:0;padding:0}'
		// Same reason as the heading: core's forms.css is unlayered and beats
		// any layered Chakra rule, so without these the fields sit in raw
		// wp-admin grey. The values repeat ui.sunk, ui.border, the forge radius,
		// ui.primary and ui.ring from src/theme.js; change them in both.
		. '.wrap #easy-svg-panel :is(input[type=text],input[type=search],input[type=number],input[type=email],input[type=url],textarea,select)'
		. '{background:#f5f7fa;border:1px solid #e3e7f0;border-radius:5px;padding:8px 12px}'
		. '.wrap #easy-svg-panel :is(input[type=text],input[type=search],input[type=number],input[type=email],input[type=url],select){min-height:40px}'
		. '.wrap #easy-svg-panel :is(input,textarea,select):is(:focus,:focus-visible){background:#fff;border-color:#203159;box-shadow:0 0 0 3px rgba(32,49,89,.26);outline:none}'
		// A field with an icon in front of it needs room for the icon. The rule
		// above sets one padding for every field and, being unlayered, beats the
		// per-field value the component asks for -- so the magnifier landed on
		// the first letters of the placeholder.
		. '.wrap #easy-svg-panel [data-scope=input-group] input{padding-inline-start:36px}'
		// The copy field shows a call to paste into a template, and it reads as
		// code. It is an <input>, so the `inherit` rule above takes it back to
		// the brand face whatever the component asks for -- this is the only
		// place that can put it back.
		. '.wrap #easy-svg-panel [data-scope=clipboard] input{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}'
	);

	// Let Pro enqueue its extension bundle with ours as a dependency.
	do_action( 'easy_svg_panel_enqueue', 'easy-svg-panel' );
}

function easy_svg_panel_rest(): void {
	$perm = 'easy_svg_panel_rest_permission';

	/*
	 * Declared arguments AND hand-checked ones. Every handler re-reads and
	 * re-sanitises what it is given, which is what protects a caller that is not
	 * the REST layer -- WP-CLI, another plugin. The schema adds what a
	 * hand-check cannot: a wrong type refused before the callback, and OPTIONS.
	 */
	$id_arg = array(
		'type'        => 'integer',
		'required'    => true,
		'minimum'     => 1,
		'description' => __( 'The icon post id, as the library listing reports it.', 'easy-svg' ),
	);

	register_rest_route(
		'easy-svg/v1',
		'/settings',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => $perm,
				'callback'            => 'easy_svg_panel_settings_get',
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => 'easy_svg_panel_settings_post',
				// None required: a key that is absent keeps its stored value, which
				// is the whole contract of this route. See easy_svg_panel_settings_post().
				'args'                => array(
					'svg_upload' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether editors may upload .svg files.', 'easy-svg' ),
					),
					'icons'      => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the icon library and the Icon block are registered.', 'easy-svg' ),
					),
					'max_mb'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 20,
						'description' => __( 'The largest SVG the sanitiser will parse, in megabytes. Always enforced; only its value is settable.', 'easy-svg' ),
					),
				),
			),
		)
	);
	register_rest_route(
		'easy-svg/v1',
		'/library',
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => 'easy_svg_panel_library_get',
			'args'                => array(
				'search' => array(
					'type'        => 'string',
					'required'    => false,
					'description' => __( 'Narrow the list to icons whose name or slug contains this.', 'easy-svg' ),
				),
			),
		)
	);
	register_rest_route(
		'easy-svg/v1',
		'/library/add',
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => 'easy_svg_panel_library_add',
			'args'                => array(
				'markup' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => __( 'The SVG markup. Sanitised and hardened on the way in, whatever is sent.', 'easy-svg' ),
				),
				'label'  => array(
					'type'        => 'string',
					'description' => __( 'The icon name a person reads. The slug is derived from it and never moves afterwards.', 'easy-svg' ),
				),
			),
		)
	);
	register_rest_route(
		'easy-svg/v1',
		'/library/media',
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => 'easy_svg_panel_library_media',
			'args'                => array(
				'ids' => array(
					'type'        => 'array',
					'required'    => true,
					'items'       => array( 'type' => 'integer' ),
					'minItems'    => 1,
					// The same bound the handler enforces, said where a client can
					// read it. The panel chunks at this number rather than finding
					// out by being refused.
					'maxItems'    => EASY_SVG_MEDIA_MAX_IDS,
					'description' => __( 'Attachment ids of SVGs already in the media library.', 'easy-svg' ),
				),
			),
		)
	);
	register_rest_route(
		'easy-svg/v1',
		'/library/rename',
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => 'easy_svg_panel_library_rename',
			'args'                => array(
				'id'    => $id_arg,
				'label' => array(
					'type'        => 'string',
					'required'    => true,
					'description' => __( 'The new visible name. The slug is not touched.', 'easy-svg' ),
				),
			),
		)
	);
	register_rest_route(
		'easy-svg/v1',
		'/library/delete',
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => 'easy_svg_panel_library_delete',
			'args'                => array( 'id' => $id_arg ),
		)
	);
}

/**
 * How many icons one library response carries. A bound, not a page size: the
 * response holds every icon's markup, so it is the payload that must stay sane.
 */
const EASY_SVG_PANEL_LIBRARY_MAX = 500;

/**
 * The library response: the icons, and how many there really are, because the
 * cap can stay but being silent about it cannot. `wp_count_posts()` rather than
 * a query of our own: WordPress caches it per post type and busts it on save.
 *
 * @return array{items:array,total:int,truncated:bool}
 */
function easy_svg_panel_library_payload( string $search = '' ) {
	if ( ! function_exists( 'easy_svg_icon_page' ) ) {
		return array(
			'items'     => array(),
			'total'     => 0,
			'truncated' => false,
		);
	}

	$items = array_values( (array) easy_svg_icon_page( 1, EASY_SVG_PANEL_LIBRARY_MAX, $search ) );
	$total = count( $items );
	// A search reports what it found. Only the unfiltered list can say how many
	// icons the site has, and "3 of 400" under a search would describe the
	// library while pretending to describe the search.
	if ( '' === $search && function_exists( 'wp_count_posts' ) ) {
		$counts = wp_count_posts( EASY_SVG_ICON_POST_TYPE );
		$total  = max( $total, (int) ( $counts->publish ?? 0 ) );
	}

	return array(
		'items'     => $items,
		'total'     => $total,
		// Under a search the total IS the match count, so the unfiltered
		// comparison can never be true there: a full page is then the only
		// signal left that the cap cut the matches short.
		'truncated' => '' === $search
			? $total > count( $items )
			: count( $items ) >= EASY_SVG_PANEL_LIBRARY_MAX,
	);
}

/** GET /library -> the stored icons (id, slug, label, hardened markup). */
function easy_svg_panel_library_get( $request ) {
	$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
	return new WP_REST_Response( easy_svg_panel_library_payload( $search ) );
}

/** A user-readable reason for an add that the store refused. */
function easy_svg_panel_add_message( string $state ): string {
	switch ( $state ) {
		case 'no_sanitizer':
			return __( 'The sanitiser is unavailable, so nothing was stored. Reinstall the plugin to restore it.', 'easy-svg' );
		case 'empty':
			return __( 'That SVG is empty, so there was nothing to store.', 'easy-svg' );
		case 'not_saved':
			return __( 'That SVG could not be stored: it arrived without a drawing, or the write was refused.', 'easy-svg' );
		case 'added':
			return __( 'Icon added.', 'easy-svg' );
		case 'not_found':
			return __( 'That file is no longer in the media library.', 'easy-svg' );
		case 'no_file':
			return __( 'That file is not stored on this server, so it could not be read.', 'easy-svg' );
		case 'not_svg':
			return __( 'That is not an SVG the sanitiser accepts.', 'easy-svg' );
		case 'bad_name':
			return __( 'That name cannot be turned into an icon name. Use letters and numbers.', 'easy-svg' );
		case 'too_large':
			return __( 'That SVG is larger than the size allowed in Settings.', 'easy-svg' );
		case 'too_complex':
			return __( 'That SVG has too many parts to be read safely.', 'easy-svg' );
		default:
			// The catch-all, and the one case with no cause to name. Saying so and
			// offering a move beats a sentence that ends the conversation.
			return __( 'The icon could not be added, for a reason this screen has no name for. Try again, or try a different file.', 'easy-svg' );
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
	return new WP_REST_Response( easy_svg_panel_library_payload() );
}

/**
 * How many attachments one media request may carry. The server reads and
 * sanitises every file, so one click must not ask for unbounded work; the
 * picker sends more in chunks.
 */
const EASY_SVG_MEDIA_MAX_IDS = 25;

/**
 * POST /library/media -> store the chosen media-library SVGs as icons.
 *
 * Attachment ids, never bytes -- an offloaded library serves from another origin
 * and the browser would hit CORS. Every file goes through easy_svg_add_icon(),
 * because an SVG that predates this plugin may never have been cleaned.
 */
function easy_svg_panel_library_media( $request ) {
	if ( ! function_exists( 'easy_svg_add_icon' ) ) {
		return new WP_Error( 'easy_svg_no_store', __( 'The icon library is off. Switch it on under the Settings tab.', 'easy-svg' ), array( 'status' => 409 ) );
	}
	$body = (array) $request->get_json_params();
	$ids  = isset( $body['ids'] ) && is_array( $body['ids'] ) ? array_values( $body['ids'] ) : array();
	if ( array() === $ids ) {
		return new WP_Error( 'easy_svg_no_ids', __( 'Choose at least one SVG from the media library.', 'easy-svg' ), array( 'status' => 400 ) );
	}
	if ( count( $ids ) > EASY_SVG_MEDIA_MAX_IDS ) {
		return new WP_Error(
			'easy_svg_too_many',
			sprintf(
				/* translators: %d: how many media files one request may carry. */
				__( 'Choose at most %d files at a time.', 'easy-svg' ),
				EASY_SVG_MEDIA_MAX_IDS
			),
			array( 'status' => 400 )
		);
	}

	$report = array();
	foreach ( $ids as $raw ) {
		$id       = max( 0, (int) $raw );
		$state    = easy_svg_panel_media_state( $id );
		$report[] = array(
			'id'      => $id,
			'label'   => $id > 0 ? (string) get_the_title( $id ) : '',
			'state'   => $state,
			'message' => easy_svg_panel_add_message( $state ),
		);
	}

	$payload           = easy_svg_panel_library_payload();
	$payload['report'] = $report;
	return new WP_REST_Response( $payload );
}

/**
 * Store one media-library attachment as an icon, and answer with the word for
 * what happened. The three refusals are separate words on purpose: "that is a
 * PNG" and "that file is not on this server" send a person somewhere different.
 */
function easy_svg_panel_media_state( int $id ): string {
	if ( $id <= 0 || ! get_post( $id ) ) {
		return 'not_found';
	}
	if ( 'image/svg+xml' !== strtolower( (string) get_post_mime_type( $id ) ) ) {
		return 'not_svg';
	}
	$path = get_attached_file( $id );
	if ( ! is_string( $path ) || '' === $path || ! is_readable( $path ) ) {
		return 'no_file';
	}
	$markup = file_get_contents( $path );
	if ( ! is_string( $markup ) ) {
		return 'no_file';
	}
	return (string) easy_svg_add_icon( sanitize_text_field( (string) get_the_title( $id ) ), $markup );
}

/**
 * POST /library/rename -> change one icon's visible name, and ONLY that.
 *
 * `post_name` is the icon's identity -- a theme and a saved Icon block both
 * carry the slug -- so moving it would point every page using the icon at a
 * name nothing answers to, and silently: core renders it as nothing at all.
 */
function easy_svg_panel_library_rename( $request ) {
	$body = (array) $request->get_json_params();
	$id   = isset( $body['id'] ) ? max( 0, (int) $body['id'] ) : 0;
	$post = $id > 0 ? get_post( $id ) : null;
	if ( ! $post || ! defined( 'EASY_SVG_ICON_POST_TYPE' ) || EASY_SVG_ICON_POST_TYPE !== $post->post_type ) {
		return new WP_Error( 'easy_svg_no_icon', __( 'Icon not found.', 'easy-svg' ), array( 'status' => 404 ) );
	}

	// Same bar as the add route: the title is stored text, so it is sanitised as
	// text. What is left of it decides whether there is a rename at all -- a
	// blank title would leave the tile with nothing to read but its slug.
	$label = isset( $body['label'] ) ? sanitize_text_field( (string) $body['label'] ) : '';
	if ( '' === $label ) {
		return new WP_Error( 'easy_svg_bad_label', __( 'An icon needs a name.', 'easy-svg' ), array( 'status' => 422 ) );
	}

	/*
	 * kses off for this one write, and only if it was on. wp_update_post()
	 * re-saves the row whole, so the markup passes `content_save_pre` again and
	 * kses would strip the SVG for any administrator without unfiltered_html --
	 * renaming would quietly empty the icon. Only the new title is slashed.
	 */
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
		return new WP_Error( 'easy_svg_rename_failed', __( 'The icon could not be renamed.', 'easy-svg' ), array( 'status' => 500 ) );
	}

	/*
	 * Drop the cached icon list, or the editor offers the old name for up to a
	 * day. The `save_post_esw_icon` hook that does this is registered only while
	 * the icons feature is on, and an icon post outlives the switch -- so it is
	 * called here as well, where a second call is a no-op.
	 */
	if ( function_exists( 'easy_svg_forget_icons' ) ) {
		easy_svg_forget_icons();
	}

	return new WP_REST_Response( easy_svg_panel_library_payload() );
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
	return new WP_REST_Response( easy_svg_panel_library_payload() );
}

function easy_svg_panel_rest_permission(): bool {
	return current_user_can( 'manage_options' );
}

/** GET /easy-svg/v1/settings -> the free settings (svg_upload, icons, max_mb). */
function easy_svg_panel_settings_get( $request ) {
	return new WP_REST_Response( easy_svg_settings() );
}

/**
 * POST /easy-svg/v1/settings -> store the free settings. The body runs through
 * easy_svg_sanitize_settings(): booleans coerced, max_mb clamped. Sanitising
 * and the size cap stay always-on and are not toggles here.
 */
function easy_svg_panel_settings_post( $request ) {
	$body = (array) $request->get_json_params();
	/*
	 * Merged onto what is STORED, not onto the defaults. A missing key reads as
	 * its default inside easy_svg_sanitize_settings(), which an unchecked box
	 * needs and a REST body does not: a partial write would switch the keys it
	 * never named off, answering 200. An explicit false still switches off.
	 */
	update_option( EASY_SVG_SETTINGS_OPTION, easy_svg_sanitize_settings( array_merge( easy_svg_settings(), $body ) ) );
	return new WP_REST_Response( easy_svg_settings() );
}
