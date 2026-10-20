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
 * Where the add-on is sold. One place, and filterable: it is an address on a
 * website, and the plugin should not need a release when that page moves.
 */
const EASY_SVG_PRO_URL = 'https://www.unleash-wp.com';

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
/**
 * The plugin's own row on the Plugins screen: a way in, beside Deactivate.
 *
 * Somebody who just activated this is on that screen, and the thing they want
 * next is the Icons page. WordPress puts our links before its own, which is
 * where a reader looks first.
 *
 * @param array $links
 * @return array
 */
function easy_svg_plugin_action_links( $links ) {
	$ours = array(
		'easy_svg_panel' => sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . EASY_SVG_PANEL_SLUG ) ),
			esc_html__( 'Icons', 'easy-svg' )
		),
	);

	/*
	 * And the add-on, for a site that does not have it.
	 *
	 * Gone the moment it is installed: an upsell that stays after somebody has
	 * paid is the kind of thing people write reviews about. The address is
	 * filterable because it is marketing, and marketing moves.
	 */
	if ( ! function_exists( 'easy_svg_pro_pro_enabled' ) ) {
		$ours['easy_svg_pro'] = sprintf(
			// Core's own red, the one wp-admin uses for a link that leaves the
			// ordinary flow. Picked rather than invented so it sits in the row
			// like a WordPress link and not like a banner.
			'<a href="%s" target="_blank" rel="noopener noreferrer" style="color:#d63638;font-weight:600">%s</a>',
			esc_url( (string) apply_filters( 'easy_svg_pro_url', EASY_SVG_PRO_URL ) ),
			esc_html__( 'Buy Easy SVG Pro', 'easy-svg' )
		);
	}

	return array_merge( $ours, (array) $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( dirname( __DIR__ ) . '/easy-svg.php' ), 'easy_svg_plugin_action_links' );

add_action( 'admin_menu', 'easy_svg_panel_menu', 9 );
/*
 * And again at the very end, to take the last place rather than ask for it.
 *
 * A position only decides where an entry is INSERTED, and every plugin that
 * registers after this one lands below it however high the number: measured on
 * a site with thirteen plugins, position 99.7 put Icons 22nd of 26.
 */
add_action( 'admin_menu', 'easy_svg_panel_menu_to_the_end', 9999 );

/**
 * The menu icon, sized and centred.
 *
 * Core's rule for a file icon is `width:20px` plus 9px of padding on top, which
 * is built for a glyph that fills its box edge to edge. This mark is a bulb with
 * air around it, so at 20px it reads small and the padding pushes it off the
 * line of the label. Flex centring puts it on that line at any row height, and
 * the padding goes rather than being compensated for.
 *
 * On every admin screen, because that is where the menu is -- three rules, in
 * the head, for one element.
 */
function easy_svg_panel_menu_icon_css(): void {
	$selector = '#adminmenu .toplevel_page_' . EASY_SVG_PANEL_SLUG . ' .wp-menu-image';

	/*
	 * Enqueued rather than echoed into admin_head. The selector is built from a
	 * constant and escapes nothing a CSS context would want escaped, so the
	 * echo was safe -- but it reads to a reviewer, and to Plugin Check, as
	 * output that was never escaped, and arguing with that on a wordpress.org
	 * review is a worse use of everyone's time than using the enqueue the
	 * platform already has.
	 */
	$handle = 'easy-svg-menu-icon';
	wp_register_style( $handle, false, array(), (string) filemtime( __FILE__ ) );
	wp_enqueue_style( $handle );
	wp_add_inline_style(
		$handle,
		$selector . '{display:flex;align-items:center;justify-content:center;padding:0}'
		. $selector . ' img{width:26px;height:26px;padding:0;max-width:none}'
		// Folded menu and the mobile bar: the row is taller there, and the
		// same two rules keep it centred rather than needing their own.
		. '.folded ' . $selector . ',#adminmenu .wp-submenu ' . $selector . '{padding:0}'
	);
}
add_action( 'admin_enqueue_scripts', 'easy_svg_panel_menu_icon_css' );
add_filter( 'submenu_file', 'easy_svg_panel_current_submenu' );
add_action( 'admin_enqueue_scripts', 'easy_svg_panel_assets' );
add_action( 'rest_api_init', 'easy_svg_panel_rest' );

/**
 * Move the Icons entry to the end of the admin menu.
 *
 * `$menu` is keyed BY position and WordPress sorts it by those keys, so the
 * last place is one key past whatever the highest is -- not a bigger number
 * guessed in advance. Nothing else is touched: every other entry keeps its own
 * key, and a plugin that insists on being last after this will be, which is
 * the polite end of this argument.
 */
function easy_svg_panel_menu_to_the_end(): void {
	global $menu;

	if ( ! is_array( $menu ) ) {
		return;
	}

	$ours = null;
	foreach ( $menu as $position => $entry ) {
		if ( is_array( $entry ) && isset( $entry[2] ) && EASY_SVG_PANEL_SLUG === $entry[2] ) {
			$ours = $entry;
			unset( $menu[ $position ] );
			break;
		}
	}

	if ( null === $ours ) {
		return;
	}

	$keys = array_map( 'intval', array_keys( $menu ) );
	$last = $keys ? max( $keys ) + 1 : 100;

	$menu[ (string) $last ] = $ours;
}

function easy_svg_panel_menu(): void {
	/*
	 * A file, not a data URI: WordPress hands a base64 SVG to svg-painter.js,
	 * which repaints every fill to the admin colour scheme -- right for a
	 * monochrome glyph and the end of a brand mark that has fifteen colours in
	 * it. As a URL it is left alone, and the browser caches it instead of
	 * carrying it in the markup of every admin page.
	 *
	 * Position as a string: a bare 99 is a slot, and a second plugin that wants
	 * the same slot REPLACES whatever is in it. A decimal cannot collide with
	 * core's own integers, and past Settings (80) is as far down as a plugin
	 * can politely put itself.
	 */
	$tabs = easy_svg_panel_tabs_for_user();
	if ( array() === $tabs ) {
		return;
	}

	/*
	 * The parent asks for a capability this user holds, taken from a tab they
	 * can already see. A parent on `manage_options` would hide every entry
	 * under it however low their own capability was -- WordPress draws no
	 * submenu whose parent the user cannot reach.
	 */
	$parent_cap = (string) reset( $tabs )['capability'];

	add_menu_page(
		__( 'Icons', 'easy-svg' ),
		__( 'Icons', 'easy-svg' ),
		$parent_cap,
		EASY_SVG_PANEL_SLUG,
		'easy_svg_panel_render',
		plugins_url( 'assets/menu-icon.svg', dirname( __DIR__ ) . '/easy-svg.php' ),
		'99.7'
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
		$parent_cap,
		EASY_SVG_PANEL_SLUG,
		'easy_svg_panel_render'
	);

	/*
	 * One entry per tab: the panel is a single page, and each entry is that
	 * page with `?tab=` in the slug, so WordPress builds the URL and marks the
	 * entry current by itself. Pro contributes its own through the filter.
	 */
	foreach ( $tabs as $tab => $meta ) {
		add_submenu_page(
			EASY_SVG_PANEL_SLUG,
			$meta['label'],
			$meta['label'],
			$meta['capability'],
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
	if ( ! isset( easy_svg_panel_tabs_for_user()[ $tab ] ) ) {
		return $submenu_file;
	}
	return EASY_SVG_PANEL_SLUG . '&tab=' . $tab;
}

/**
 * The tabs that get their own entry in the menu, as slug => label.
 *
 * @return array<string,string|array<string,string>>
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

/**
 * The same tabs, each with the capability it asks for, and only the ones this
 * user may see.
 *
 * A tab may pass `array( 'label' => .., 'capability' => .. )` instead of a
 * plain label. A plain label still works and means `manage_options`, which is
 * what every tab asked for before this existed -- an add-on built against the
 * older contract keeps the access it had rather than silently widening it.
 *
 * @return array<string,array{label:string,capability:string}>
 */
function easy_svg_panel_tabs_for_user(): array {
	$out = array();
	foreach ( easy_svg_panel_menu_tabs() as $slug => $tab ) {
		$label      = is_array( $tab ) ? (string) ( $tab['label'] ?? $slug ) : (string) $tab;
		$capability = is_array( $tab ) ? (string) ( $tab['capability'] ?? 'manage_options' ) : 'manage_options';
		if ( ! current_user_can( $capability ) ) {
			continue;
		}
		$out[ $slug ] = array(
			'label'      => $label,
			'capability' => $capability,
		);
	}
	return $out;
}

/**
 * Whether this user may open the panel at all: may they see at least one tab?
 *
 * Not `manage_options` any more. The add-on puts a tab here that an editor is
 * meant to reach -- replacing icons -- and the panel has to be able to open
 * for them without handing them the rest of it. Somebody who may see no tab
 * gets no page, no menu entry and no bundle, which is still every subscriber.
 *
 * The REST routes are untouched: each checks its own capability, because the
 * screen is never the authority.
 */
function easy_svg_panel_user_may_open(): bool {
	return array() !== easy_svg_panel_tabs_for_user();
}

/**
 * Every known tab and the capability it asks for, this user's or not.
 *
 * @return array<string,string>
 */
function easy_svg_panel_tab_capabilities(): array {
	$out = array();
	foreach ( easy_svg_panel_menu_tabs() as $slug => $tab ) {
		$out[ $slug ] = is_array( $tab ) ? (string) ( $tab['capability'] ?? 'manage_options' ) : 'manage_options';
	}
	return $out;
}

/**
 * Which of those capabilities this user holds, plus `manage_options` for the
 * tabs that have no menu entry of their own.
 *
 * @return array<string,bool>
 */
function easy_svg_panel_user_caps(): array {
	$out = array( 'manage_options' => current_user_can( 'manage_options' ) );
	foreach ( easy_svg_panel_tab_capabilities() as $capability ) {
		$out[ $capability ] = current_user_can( $capability );
	}
	return $out;
}

function easy_svg_panel_render(): void {
	if ( ! easy_svg_panel_user_may_open() ) {
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
			// The contract level, so an add-on can tell what this panel takes
			// without probing for it. The constant itself, not a repeat of the
			// number: the PHP side and the JS side cannot drift apart that way.
			'panelApi'     => (int) EASY_SVG_PANEL_API,
			// What each tab asks for, and which of those this user holds, so
			// the rail can leave out what the menu already leaves out. The
			// verdict is PHP's; the screen only draws it, and every REST route
			// still checks for itself.
			'tabCaps'      => easy_svg_panel_tab_capabilities(),
			'caps'         => easy_svg_panel_user_caps(),
			'restRoot'     => esc_url_raw( trailingslashit( rest_url() ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'icon'         => easy_svg_panel_icon_uri( '#203159' ),
			// Shown in the panel's footer, the way Forge shows its own. Read from
			// the plugin header rather than a constant, because the header is the
			// single version source this plugin has.
			'version'      => (string) ( get_file_data( $main, array( 'Version' => 'Version' ) )['Version'] ?? '' ),
			'proActive'    => $pro_active,
			// The add-on's own version, when it is here. The panel shows one
			// product and two plugins make it, and the licence screen was the
			// only place that said which version of the paid half is running.
			'proVersion'   => function_exists( 'easy_svg_pro_version' ) ? (string) easy_svg_pro_version() : '',
			'proLicensed'  => $pro_licensed,
			'iconsEnabled' => function_exists( 'easy_svg_feature_enabled' ) && easy_svg_feature_enabled( 'icons' ),
			// Core's Icon block exists only with the 7.1 icon registry; without it
			// the panel does not offer a block to paste.
			'iconBlock'    => function_exists( 'easy_svg_icons_supported' ) && easy_svg_icons_supported(),
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
		// html too: body is only as tall as the viewport, and below it the
		// page showed html's white.
		'html:has(body.toplevel_page_' . EASY_SVG_PANEL_SLUG . '),body.toplevel_page_' . EASY_SVG_PANEL_SLUG . '{background:#eef1f6}'
		// Edge to edge: the panel's header and footer run the full width of
		// the content area instead of sitting in wp-admin's margins.
		. 'body.toplevel_page_' . EASY_SVG_PANEL_SLUG . ' #wpcontent{padding-left:0}'
		. '.wrap:has(> #easy-svg-panel){margin:0}'
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
		// Aimed at the field's own attribute, not at a scope the library emits:
		// this read [data-scope=input-group], Chakra renders .chakra-group, and
		// the rule quietly stopped matching anything.
		. '.wrap #easy-svg-panel input[data-esw-search]{padding-inline-start:36px}'
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
						/*
						 * No `maximum`. It carried the ceiling, so WordPress answered
						 * 400 before easy_svg_sanitize_settings() -- which clamps to
						 * 1..ceiling -- ever ran. With a save button that was
						 * survivable: the field clamped itself on blur and the click
						 * sent an allowed number. The panel saves as you type now, so
						 * the typed number is what goes out, and a refusal turns an
						 * explainable clamp into an error nobody can act on.
						 *
						 * Nothing is loosened: the sanitiser still pins the stored
						 * value, which is the only guarantee that ever mattered.
						 */
						'description' => __( 'The largest SVG the sanitizer will parse, in megabytes. Always enforced; only its value is settable. A number above the ceiling is clamped to it.', 'easy-svg' ),
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
					'description' => __( 'The SVG markup. Sanitized and hardened on the way in, whatever is sent.', 'easy-svg' ),
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
			return __( 'The sanitizer is unavailable, so nothing was stored. Reinstall the plugin to restore it.', 'easy-svg' );
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
			return __( 'That is not an SVG the sanitizer accepts.', 'easy-svg' );
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
	$result = easy_svg_add_icon_result( $label, $markup );
	if ( 'added' !== $result['state'] ) {
		return new WP_Error( 'easy_svg_add_failed', easy_svg_panel_add_message( (string) $result['state'] ), array( 'status' => 422 ) );
	}
	$payload          = easy_svg_panel_library_payload();
	$payload['added'] = easy_svg_panel_added_names( $result );
	return new WP_REST_Response( $payload );
}

/**
 * The slug an icon got and the one it asked for. They differ when the name was
 * taken (`star` became `star-2`), and the slug is what a block or a theme calls.
 *
 * @param array $result What easy_svg_add_icon_result() answered.
 * @return array{slug:string, wanted:string}
 */
function easy_svg_panel_added_names( array $result ): array {
	$slug = (string) ( $result['slug'] ?? '' );
	return array(
		'slug'   => $slug,
		'wanted' => (string) ( $result['wanted'] ?? $slug ),
	);
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
		$result   = easy_svg_panel_media_add( $id );
		$state    = (string) $result['state'];
		$report[] = array_merge(
			array(
				'id'      => $id,
				'label'   => $id > 0 ? (string) get_the_title( $id ) : '',
				'state'   => $state,
				'message' => easy_svg_panel_add_message( $state ),
			),
			'added' === $state ? easy_svg_panel_added_names( $result ) : array()
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
	return (string) easy_svg_panel_media_add( $id )['state'];
}

/**
 * The same, answering with the whole add result, so the report can name the
 * slug the icon got.
 *
 * @return array{state:string, id?:int, slug?:string, wanted?:string}
 */
function easy_svg_panel_media_add( int $id ): array {
	if ( $id <= 0 || ! get_post( $id ) ) {
		return array( 'state' => 'not_found' );
	}
	if ( 'image/svg+xml' !== strtolower( (string) get_post_mime_type( $id ) ) ) {
		return array( 'state' => 'not_svg' );
	}
	$path = get_attached_file( $id );
	if ( ! is_string( $path ) || '' === $path || ! is_readable( $path ) ) {
		return array( 'state' => 'no_file' );
	}
	$markup = file_get_contents( $path );
	if ( ! is_string( $markup ) ) {
		return array( 'state' => 'no_file' );
	}
	return easy_svg_add_icon_result( sanitize_text_field( (string) get_the_title( $id ) ), $markup );
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

	// Same bar as the add route: the title is stored text, so it is sanitised as
	// text. What is left of it decides whether there is a rename at all -- a
	// blank title would leave the tile with nothing to read but its slug.
	$label = isset( $body['label'] ) ? sanitize_text_field( (string) $body['label'] ) : '';

	// The write itself is easy_svg_rename_icon()'s: it carries the kses handling
	// and the cache bust, and an add-on renaming an icon has to meet the same two.
	$state = easy_svg_rename_icon( $id, $label );
	if ( 'not_found' === $state ) {
		return new WP_Error( 'easy_svg_no_icon', __( 'Icon not found.', 'easy-svg' ), array( 'status' => 404 ) );
	}
	if ( 'no_label' === $state ) {
		return new WP_Error( 'easy_svg_bad_label', __( 'An icon needs a name.', 'easy-svg' ), array( 'status' => 422 ) );
	}
	if ( 'renamed' !== $state ) {
		return new WP_Error( 'easy_svg_rename_failed', __( 'The icon could not be renamed.', 'easy-svg' ), array( 'status' => 500 ) );
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
