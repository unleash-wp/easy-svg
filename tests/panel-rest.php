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
$GLOBALS['posts']   = array();
$GLOBALS['deleted'] = array();
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

echo 0 === $failed
	? "all {$passed} checks passed\n"
	: "{$failed} of " . ( $passed + $failed ) . " checks FAILED\n";

exit( 0 === $failed ? 0 : 1 );
