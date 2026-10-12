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

/*
 * Priority 9, before WordPress appends post-type submenus.
 *
 * Core hooks `_add_post_type_submenus` on `admin_menu` at the default 10
 * (wp-includes/default-filters.php). The paid plugin nests its icon-set post
 * type under this menu, and WordPress points a top-level link at whichever
 * submenu entry was registered FIRST -- so at the default priority the panel's
 * own entry lands behind the post type and clicking "Icons" opens a post list
 * instead of the panel. Plugin load order decides it otherwise, which is not
 * something this plugin controls.
 */
add_action( 'admin_menu', 'easy_svg_panel_menu', 9 );
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
	 * The panel's own entry inside its submenu, and it is not decoration.
	 *
	 * add_menu_page() registers no submenu item, and while nothing nests under
	 * this slug WordPress just shows the top-level link, so the omission is
	 * invisible. The moment something does nest here, WordPress renders the
	 * submenu and points the PARENT link at its first entry -- which would be the
	 * icon-set list, so "Icons" would land on a post list and the panel could not
	 * be reached from the menu at all.
	 *
	 * Same slug and callback as the parent: that is what makes it the self-entry
	 * rather than a second page.
	 */
	add_submenu_page(
		EASY_SVG_PANEL_SLUG,
		__( 'Icons', 'easy-svg' ),
		__( 'Icons', 'easy-svg' ),
		'manage_options',
		EASY_SVG_PANEL_SLUG,
		'easy_svg_panel_render'
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
	 * No webfont ships with this plugin.
	 *
	 * It self-hosted two Ubuntu woff2 files, and the Ubuntu Font Licence 1.0 is
	 * not the SIL OFL the stylesheet claimed it was. Debian treats UFL-1.0 as
	 * not meeting its free-software guidelines, and wordpress.org requires
	 * everything in the directory to be GPL-compatible -- fonts included. The
	 * panel's type stack starts at system-ui instead, which is what it fell
	 * back to on any machine the download failed on anyway.
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
	 * Serve the panel's JS translations. The third argument is required for the
	 * catalogue this plugin BUNDLES: without a path, WordPress looks only in
	 * WP_LANG_DIR/plugins (the wordpress.org language pack), and on 6.7+ also in
	 * the textdomain registry -- which happens to carry this plugin's own
	 * languages directory because of the `Domain Path` header, but not on the
	 * 6.6 floor this plugin still supports. Measured on 7.1.3: found either way;
	 * on 6.6 only with the path. The file is named after md5() of the enqueued
	 * script's plugin-relative path (`build/panel.js`), which is pinned by a test.
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
		)
	);

	/*
	 * Take the page title back from wp-admin.
	 *
	 * The panel renders inside `<div class="wrap">`, and core styles `.wrap h1`
	 * at 23px/400. Chakra emits its own rules inside a CSS layer, and a layered
	 * rule loses to ANY unlayered one no matter how specific it is -- so the
	 * design system's h1 token silently lost and the biggest text on the screen
	 * was neither the right size nor bold. Measured: 23px/400 inside .wrap,
	 * 24px/700 the moment the class is removed.
	 *
	 * This rule is unlayered and carries an id, so it wins. It repeats the two
	 * numbers from the h1 textStyle in src/theme.js; change them in both or the
	 * heading drifts. Only size and weight: colour and letter-spacing already
	 * come through.
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
		. '.wrap #easy-svg-panel h1{font-size:28px;font-weight:700;margin:0;padding:0;font-family:Ubuntu,system-ui,sans-serif}'
		// Same reason as the heading: core's forms.css is unlayered and beats any
		// layered Chakra rule, so without these the fields sit there in raw
		// wp-admin grey (#949494, 2px radius). Base and focus repeat ui.sunk,
		// ui.border, the forge radius, ui.primary and ui.ring from src/theme.js;
		// change them in both. This is the one input style for every panel tab,
		// Pro's included.
		. '.wrap #easy-svg-panel :is(input[type=text],input[type=search],input[type=number],input[type=email],input[type=url],textarea,select)'
		. '{background:#f5f7fa;border:1px solid #e3e7f0;border-radius:5px;padding:8px 12px}'
		. '.wrap #easy-svg-panel :is(input[type=text],input[type=search],input[type=number],input[type=email],input[type=url],select){min-height:40px}'
		. '.wrap #easy-svg-panel :is(input,textarea,select):is(:focus,:focus-visible){background:#fff;border-color:#203159;box-shadow:0 0 0 3px rgba(32,49,89,.26);outline:none}'
	);

	// Let Pro enqueue its extension bundle with ours as a dependency.
	do_action( 'easy_svg_panel_enqueue', 'easy-svg-panel' );
}

function easy_svg_panel_rest(): void {
	$perm = 'easy_svg_panel_rest_permission';

	/*
	 * Declared arguments, not just hand-checked ones.
	 *
	 * Every handler below re-reads and re-sanitises what it is given, and that
	 * stays: it is what the tests cover, and what protects a caller that is not
	 * the REST layer -- WP-CLI, another plugin. What the schema adds is the two
	 * things a hand-check cannot: WordPress refuses a wrong type or a missing
	 * required field before the callback runs, and OPTIONS on the route finally
	 * says what the route takes. It answered an empty list until now.
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
 * How many icons one library response carries.
 *
 * A bound, not a page size: the response holds every icon's hardened markup, so
 * it is the payload rather than the row count that has to stay sane.
 */
const EASY_SVG_PANEL_LIBRARY_MAX = 500;

/**
 * The library response: the icons, and how many there really are.
 *
 * The routes returned page one of five hundred with `no_found_rows`, so a site
 * with 501 icons was shown 500 and told nothing. The cap can stay; being silent
 * about it cannot.
 *
 * `wp_count_posts()` is the count rather than a query of our own: WordPress
 * caches it per post type and busts it on save.
 *
 * @return array{items:array,total:int,truncated:bool}
 */
function easy_svg_panel_library_payload() {
	if ( ! function_exists( 'easy_svg_icon_page' ) ) {
		return array(
			'items'     => array(),
			'total'     => 0,
			'truncated' => false,
		);
	}

	$items = array_values( (array) easy_svg_icon_page( 1, EASY_SVG_PANEL_LIBRARY_MAX ) );
	$total = count( $items );
	if ( function_exists( 'wp_count_posts' ) ) {
		$counts = wp_count_posts( EASY_SVG_ICON_POST_TYPE );
		$total  = max( $total, (int) ( $counts->publish ?? 0 ) );
	}

	return array(
		'items'     => $items,
		'total'     => $total,
		'truncated' => $total > count( $items ),
	);
}

/** GET /library -> the stored icons (id, slug, label, hardened markup). */
function easy_svg_panel_library_get( $request ) {
	return new WP_REST_Response( easy_svg_panel_library_payload() );
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
 * How many attachments one media request may carry.
 *
 * The server reads and sanitises every file in the request, so one click must
 * not be able to ask for unbounded work. The picker sends more in chunks.
 */
const EASY_SVG_MEDIA_MAX_IDS = 25;

/**
 * POST /library/media -> store the chosen media-library SVGs as icons.
 *
 * The browser sends attachment ids, never bytes. An offloaded media library
 * serves attachments from another origin, so reading them in the browser would
 * be blocked by CORS on exactly the sites that asked for this; here
 * get_attached_file() either answers with a local path or it does not, and that
 * is a refusal the report can name.
 *
 * Every file goes through easy_svg_add_icon() -- the same call the upload path
 * makes, so the same sanitiser, byte cap and complexity bound apply. An SVG that
 * has sat in the media library since before this plugin may never have been
 * cleaned, so ingesting it is the only thing that makes it safe.
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
 * what happened -- `easy_svg_panel_add_message()` has a sentence for each.
 *
 * The three refusals here are separate words on purpose: "that is a PNG" and
 * "that file is not on this server" send a person somewhere entirely different.
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
 * `post_name` is the icon's identity, not a detail of its presentation: a theme
 * calls `easy_svg_icon( 'slug' )` and a saved Icon block carries the slug it was
 * given when it was inserted. Moving the slug would leave every page that
 * already uses the icon pointing at a name nothing answers to -- and silently,
 * because an icon core cannot resolve renders as nothing at all rather than as
 * an error. So the title is the only field written here, `post_name` is never
 * passed, and a test in tests/panel-rest.php holds that shut.
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
	 * kses off for this one write, and only if it was on -- the same hazard
	 * easy_svg_add_icon() documents, from the other side. wp_update_post() re-saves
	 * the row whole, so the markup passes `content_save_pre` again and for any
	 * administrator without unfiltered_html kses would strip the SVG: renaming an
	 * icon would quietly empty it. Restored in `finally`, so no error or exception
	 * leaves kses off for the rest of the request.
	 *
	 * Only the new title is slashed; core slashes the fields it pulls out of the
	 * database itself.
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
	 * Drop the cached icon list, or the editor keeps offering the old name for up
	 * to a day. `easy_svg_forget_icons()` is hooked on `save_post_esw_icon`, which
	 * the update above fires -- but that hook is registered only while the icons
	 * feature is on (easy-svg.php), and an icon post outlives the switch. Called
	 * here as well so this route does not depend on a condition it cannot see;
	 * the second call costs one option write and is otherwise a no-op.
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
 * POST /easy-svg/v1/settings -> store the free settings.
 *
 * The body runs through easy_svg_sanitize_settings() exactly as the Settings-API
 * form it replaces did: booleans coerced, max_mb clamped. Security (sanitise +
 * cap) stays always-on and is not a toggle here.
 */
function easy_svg_panel_settings_post( $request ) {
	$body = (array) $request->get_json_params();
	/*
	 * Merged onto what is stored, not onto the defaults.
	 *
	 * easy_svg_sanitize_settings() reads a missing key as its default, which is
	 * what the form it was written for needs -- an unchecked box sends no key
	 * and means off. A REST body is not a form: it carries the settings the
	 * caller meant to change, and the rest of a partial write used to be
	 * switched off without a word, answering 200. An explicit false is still a
	 * value and still switches a feature off; only an ABSENT key is left alone.
	 */
	update_option( EASY_SVG_SETTINGS_OPTION, easy_svg_sanitize_settings( array_merge( easy_svg_settings(), $body ) ) );
	return new WP_REST_Response( easy_svg_settings() );
}
