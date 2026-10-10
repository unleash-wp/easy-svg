<?php
/**
 * REST /easy-svg/v1/settings — the panel's read/write of the free settings.
 *
 * The write must be held to the same bar as the Settings-API form it replaces:
 * the server re-runs easy_svg_sanitize_settings(), so max_mb is clamped and the
 * booleans coerced whatever the client sent.
 *
 * Usage: php tests/panel-rest.php
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'MB_IN_BYTES', 1048576 );

$GLOBALS['opt']          = array();
$GLOBALS['is_admin_cap'] = true;

function get_option( $k, $d = false ) {
	return $GLOBALS['opt'][ $k ] ?? $d;
}
function update_option( $k, $v, $a = true ): bool {
	$GLOBALS['opt'][ $k ] = $v;
	return true;
}
function current_user_can( $c ): bool {
	return (bool) ( $GLOBALS['is_admin_cap'] ?? true );
}
function add_action( ...$a ): bool {
	return true;
}
function add_filter( ...$a ): bool {
	return true;
}
function apply_filters( $h, $v, ...$a ) {
	return $v;
}
function __( $s, $d = '' ): string {
	return (string) $s;
}
function sanitize_text_field( $s ): string {
	return trim( preg_replace( '/<[^>]*>/', '', (string) $s ) );
}
function register_setting( ...$a ): void {}
function add_settings_section( ...$a ): void {}
function add_settings_field( ...$a ): void {}

class WP_REST_Request {
	public function __construct( private array $json = array() ) {}
	public function get_json_params(): array {
		return $this->json;
	}
}
class WP_REST_Response {
	public function __construct( public $data = null ) {}
	public function get_data() {
		return $this->data;
	}
}
class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
}

define( 'EASY_SVG_ICON_POST_TYPE', 'esw_icon' );
$GLOBALS['posts']      = array();
$GLOBALS['deleted']    = array();
$GLOBALS['updated']    = array();
$GLOBALS['routes']     = array();
$GLOBALS['kses_calls'] = array();
$GLOBALS['kses_on']    = true;
$GLOBALS['forgot']     = 0;
function get_post( $id ) {
	return $GLOBALS['posts'][ $id ] ?? null;
}
function wp_delete_post( $id, $force = false ): bool {
	$GLOBALS['deleted'][] = (int) $id;
	return true;
}
function easy_svg_icon_page( $page, $per_page ): array {
	return array();
}
function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}
function wp_slash( $value ) {
	return is_array( $value ) ? array_map( 'wp_slash', $value ) : addslashes( (string) $value );
}
function register_rest_route( $namespace, $route, $args = array(), $override = false ): bool {
	$GLOBALS['routes'][ $namespace . $route ] = $args;
	return true;
}

/*
 * kses, modelled only as far as the question goes: whether the handler switched
 * it off for its write and switched it back on afterwards. It does not strip
 * anything here -- what the real filter would do to SVG markup is not this
 * file's subject, only that it is not given the chance.
 */
function has_filter( $tag, $callback = false ) {
	return $GLOBALS['kses_on'] ? 10 : false;
}
function kses_remove_filters(): void {
	$GLOBALS['kses_on']      = false;
	$GLOBALS['kses_calls'][] = 'off';
}
function kses_init_filters(): void {
	$GLOBALS['kses_on']      = true;
	$GLOBALS['kses_calls'][] = 'on';
}

/*
 * wp_update_post(), as far as it matters here: it merges the fields it is handed
 * onto the stored row. That is what makes the slug bell below a real one -- a
 * `post_name` in the payload MOVES the slug through this stub exactly as it
 * would through WordPress.
 */
function wp_update_post( $postarr, $wp_error = false ) {
	$GLOBALS['updated'][] = $postarr;
	$id                   = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
	if ( ! isset( $GLOBALS['posts'][ $id ] ) ) {
		return 0;
	}
	foreach ( $postarr as $field => $value ) {
		if ( 'ID' !== $field ) {
			$GLOBALS['posts'][ $id ]->$field = stripslashes( (string) $value );
		}
	}
	return $id;
}
function easy_svg_forget_icons(): void {
	++$GLOBALS['forgot'];
}

require dirname( __DIR__ ) . '/includes/settings.php';
require dirname( __DIR__ ) . '/includes/panel.php';

$passed = 0;
$failed = 0;
function check( string $what, bool $ok ): void {
	global $passed, $failed;
	if ( $ok ) {
		$passed++;
		return;
	}
	$failed++;
	echo "FAIL  {$what}\n";
}

// ─── permission ───────────────────────────────────────────────────────────────
$GLOBALS['is_admin_cap'] = false;
check( 'BELL: a non-admin is refused', false === easy_svg_panel_rest_permission() );
$GLOBALS['is_admin_cap'] = true;
check( 'BELL: an admin is allowed', true === easy_svg_panel_rest_permission() );

// ─── POST re-sanitises server-side ────────────────────────────────────────────
$out = easy_svg_panel_settings_post(
	new WP_REST_Request(
		array(
			'svg_upload' => 1,
			'icons'      => true,
			'max_mb'     => 999,
		)
	)
)->get_data();
check( 'BELL: a truthy toggle becomes a real boolean', true === $out['svg_upload'] && true === $out['icons'] );
check( 'BELL: max_mb is clamped to the ceiling (20)', 20 === $out['max_mb'] );
check( 'BELL: the STORED option is the sanitised one', 20 === ( $GLOBALS['opt']['easy_svg_settings']['max_mb'] ?? 0 ) );

// ─── GET returns the sanitised settings ───────────────────────────────────────
$g = easy_svg_panel_settings_get( new WP_REST_Request() )->get_data();
check( 'BELL: GET returns the stored settings', array_key_exists( 'svg_upload', $g ) && array_key_exists( 'max_mb', $g ) );

// ─── library/delete only ever removes an esw_icon post ────────────────────────
$GLOBALS['posts'][5] = (object) array( 'ID' => 5, 'post_type' => 'page' );
$r = easy_svg_panel_library_delete( new WP_REST_Request( array( 'id' => 5 ) ) );
check( 'BELL: delete refuses a post that is not an icon', $r instanceof WP_Error );
check( 'SILENCE: and it deleted nothing', array() === $GLOBALS['deleted'] );
$GLOBALS['posts'][6] = (object) array( 'ID' => 6, 'post_type' => 'esw_icon' );
easy_svg_panel_library_delete( new WP_REST_Request( array( 'id' => 6 ) ) );
check( 'BELL: delete removes an esw_icon post by id', in_array( 6, $GLOBALS['deleted'], true ) );
check( 'BELL: a missing id is refused', easy_svg_panel_library_delete( new WP_REST_Request( array() ) ) instanceof WP_Error );

// ─── library/add refuses when the store (icons feature) is off ────────────────
// easy_svg_add_icon() is defined only when the icons feature registered the CPT;
// here it is not, so add must say so rather than fatal.
check( 'BELL: add is refused when the icon store is off', easy_svg_panel_library_add( new WP_REST_Request( array( 'markup' => '<svg/>' ) ) ) instanceof WP_Error );

// ─── library/add sanitises the label before storing it ────────────────────────
// Defined here, after the store-off check above, so that one still sees no
// easy_svg_add_icon (a conditional definition is not hoisted). This stub records
// the label it is handed, which is what easy_svg_add_icon() documents as "already
// sanitised as text" -- the REST must hold to that, like the form handler it
// replaces, or an unsanitised title is stored for later output to choke on.
if ( ! function_exists( 'easy_svg_add_icon' ) ) {
	function easy_svg_add_icon( $label, $markup ) {
		$GLOBALS['added_label'] = $label;
		return 'added';
	}
}
easy_svg_panel_library_add( new WP_REST_Request( array( 'label' => "<script>alert(1)</script>Heart", 'markup' => '<svg/>' ) ) );
check( 'BELL: the label is sanitised before storage (no markup reaches the title)', ! str_contains( (string) ( $GLOBALS['added_label'] ?? '' ), '<' ) );
check( 'SILENCE: and the readable part survives', str_contains( (string) ( $GLOBALS['added_label'] ?? '' ), 'Heart' ) );

// ─── every route is behind the same capability ────────────────────────────────
// A new route added without a permission callback -- or with a laxer one -- is a
// hole nothing else in this file would notice, because each handler is called
// here directly. So the registration itself is read.
easy_svg_panel_rest();
check( 'SILENCE: the rename route is registered', isset( $GLOBALS['routes']['easy-svg/v1/library/rename'] ) );
$perm_ok = array() !== $GLOBALS['routes'];
foreach ( $GLOBALS['routes'] as $registered ) {
	// /settings registers a list of definitions; the others register one.
	foreach ( isset( $registered['methods'] ) ? array( $registered ) : $registered as $definition ) {
		if ( 'easy_svg_panel_rest_permission' !== ( $definition['permission_callback'] ?? '' ) ) {
			$perm_ok = false;
		}
	}
}
check( 'BELL: every panel REST route is behind the same capability check', $perm_ok );

// ─── library/rename renames, and ONLY renames ─────────────────────────────────
/*
 * The slug is the icon's identity: a theme calls easy_svg_icon( 'slug' ) and a
 * saved Icon block carries the slug it was inserted with. A rename that moved it
 * would break every page already using that icon, and silently -- core renders
 * an icon it cannot resolve as nothing at all.
 */
check( 'BELL: rename refuses a post that is not an icon', easy_svg_panel_library_rename( new WP_REST_Request( array( 'id' => 5, 'label' => 'Nope' ) ) ) instanceof WP_Error );
check( 'SILENCE: and it wrote nothing', array() === $GLOBALS['updated'] );
check( 'BELL: a missing id is refused', easy_svg_panel_library_rename( new WP_REST_Request( array( 'label' => 'Nope' ) ) ) instanceof WP_Error );

$GLOBALS['posts'][7] = (object) array(
	'ID'           => 7,
	'post_type'    => 'esw_icon',
	'post_title'   => 'Heart',
	'post_name'    => 'heart',
	'post_content' => '<svg viewBox="0 0 24 24"><path d="M1 1"/></svg>',
);
check( 'BELL: an empty name is refused', easy_svg_panel_library_rename( new WP_REST_Request( array( 'id' => 7, 'label' => '   ' ) ) ) instanceof WP_Error );
check( 'BELL: and so is a name that is nothing but markup, which only sanitising reveals', easy_svg_panel_library_rename( new WP_REST_Request( array( 'id' => 7, 'label' => '<br>' ) ) ) instanceof WP_Error );
check( 'SILENCE: neither refusal wrote anything', array() === $GLOBALS['updated'] );

$renamed = easy_svg_panel_library_rename( new WP_REST_Request( array( 'id' => 7, 'label' => '<b>Herz</b> voll' ) ) );
check( 'BELL: a rename leaves the slug untouched', 'heart' === $GLOBALS['posts'][7]->post_name );
check( 'SILENCE: the write names only the id and the title, so nothing can derive a new slug', array( 'ID', 'post_title' ) === array_keys( (array) end( $GLOBALS['updated'] ) ) );
check( 'BELL: the new name is sanitised before storage', 'Herz voll' === $GLOBALS['posts'][7]->post_title );
check( 'SILENCE: kses was off for the write and on again afterwards', array( 'off', 'on' ) === $GLOBALS['kses_calls'] && true === $GLOBALS['kses_on'] );
check( 'BELL: a rename drops the cached icon list', 1 === $GLOBALS['forgot'] );
check( 'SILENCE: and it answers with the fresh page', $renamed instanceof WP_REST_Response );

echo 0 === $failed
	? "all {$passed} checks passed\n"
	: "{$failed} of " . ( $passed + $failed ) . " checks FAILED\n";

exit( 0 === $failed ? 0 : 1 );
