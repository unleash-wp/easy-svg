<?php
/**
 * REST /easy-svg/v1/settings: the panel's read/write of the free settings.
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
// The third parameter is the search term, and it is declared because PHP drops
// a surplus argument to a user-defined function without a word: a two-argument
// stub kept the suite green while it could not see the term at all.
function easy_svg_icon_page( $page, $per_page, $search = '' ): array {
	$GLOBALS['asked_per_page'] = $per_page;
	$GLOBALS['asked_search']   = $search;
	$n = (int) ( $GLOBALS['stored_matches'] ?? $GLOBALS['stored_icons'] ?? 0 );
	return array_fill( 0, min( $n, (int) $per_page ), array( 'id' => 1, 'slug' => 'x', 'label' => 'X', 'content' => '<svg/>' ) );
}
function wp_count_posts( $type ) {
	return (object) array( 'publish' => (int) ( $GLOBALS['stored_icons'] ?? 0 ) );
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

// ─── a partial write changes only what it was given ───────────────────────────
// The sanitiser fills a missing key from the DEFAULTS, which is right for the
// form it was written for -- an unchecked box sends no key and means off. Over
// REST it meant something else entirely: a body carrying one setting switched
// the other two off, and answered 200. Measured on a live site, a POST of
// {max_mb} turned svg_upload from on to off without mentioning it.
$GLOBALS['opt'][ EASY_SVG_SETTINGS_OPTION ] = array(
	'svg_upload' => true,
	'icons'      => true,
	'max_mb'     => 2,
);
$partial = easy_svg_panel_settings_post( new WP_REST_Request( array( 'max_mb' => 5 ) ) )->get_data();
check( 'BELL: a key that was not sent keeps its stored value', true === $partial['svg_upload'] && true === $partial['icons'] );
check( 'SILENCE: and the key that WAS sent changes', 5 === $partial['max_mb'] );
check( 'BELL: the stored option says the same', true === ( $GLOBALS['opt'][ EASY_SVG_SETTINGS_OPTION ]['svg_upload'] ?? false ) );

// Switching a feature OFF must still be possible: the panel sends false, and
// false is a value, not an absence.
$off = easy_svg_panel_settings_post( new WP_REST_Request( array( 'svg_upload' => false ) ) )->get_data();
check( 'BELL: an explicit false still switches a feature off', false === $off['svg_upload'] && true === $off['icons'] );

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
		$GLOBALS['added_label']  = $label;
		$GLOBALS['added_markup'] = $markup;
		$GLOBALS['added'][]      = array( $label, $markup );
		return $GLOBALS['add_result'] ?? 'added';
	}
}
easy_svg_panel_library_add( new WP_REST_Request( array( 'label' => "<script>alert(1)</script>Heart", 'markup' => '<svg/>' ) ) );
check( 'BELL: the label is sanitised before storage (no markup reaches the title)', ! str_contains( (string) ( $GLOBALS['added_label'] ?? '' ), '<' ) );
check( 'SILENCE: and the readable part survives', str_contains( (string) ( $GLOBALS['added_label'] ?? '' ), 'Heart' ) );

// ─── library/media: the media library, read on the SERVER ─────────────────────
// The browser sends attachment ids, never bytes. An offloaded library serves
// attachments from another origin, so a fetch() in the browser would be blocked
// by CORS for exactly the sites that asked for this; get_attached_file() either
// answers with a local path or it does not, and that is a per-file refusal the
// report can carry.
$GLOBALS['attachments'] = array();
function get_post_mime_type( $id ) {
	return $GLOBALS['attachments'][ $id ]['mime'] ?? false;
}
function get_attached_file( $id, $unfiltered = false ) {
	return $GLOBALS['attachments'][ $id ]['file'] ?? false;
}
function get_the_title( $id ) {
	return $GLOBALS['attachments'][ $id ]['title'] ?? '';
}

$svg_file = tempnam( sys_get_temp_dir(), 'esw' );
file_put_contents( $svg_file, '<svg viewBox="0 0 16 16"><path d="M0 0h16v16H0z"/></svg>' );
$GLOBALS['attachments'][11] = array( 'mime' => 'image/svg+xml', 'file' => $svg_file, 'title' => 'Star' );
$GLOBALS['attachments'][12] = array( 'mime' => 'image/png', 'file' => $svg_file, 'title' => 'Photo' );
$GLOBALS['attachments'][13] = array( 'mime' => 'image/svg+xml', 'file' => '/nowhere/gone.svg', 'title' => 'Offloaded' );
foreach ( array_keys( $GLOBALS['attachments'] ) as $att_id ) {
	$GLOBALS['posts'][ $att_id ] = (object) array( 'ID' => $att_id, 'post_type' => 'attachment' );
}

check( 'BELL: no ids is refused', easy_svg_panel_library_media( new WP_REST_Request( array() ) ) instanceof WP_Error );
check( 'BELL: ids that are not a list are refused', easy_svg_panel_library_media( new WP_REST_Request( array( 'ids' => 'all' ) ) ) instanceof WP_Error );

// One click must not be able to ask the server for unbounded work. The picker
// sends more than the cap in chunks; the server never accepts them in one go.
check(
	'BELL: more ids than the cap are refused',
	easy_svg_panel_library_media( new WP_REST_Request( array( 'ids' => range( 1, EASY_SVG_MEDIA_MAX_IDS + 1 ) ) ) ) instanceof WP_Error
);

$GLOBALS['added'] = array();
$out              = easy_svg_panel_library_media( new WP_REST_Request( array( 'ids' => array( 11 ) ) ) )->get_data();
check( 'BELL: an SVG attachment is added', 1 === count( $GLOBALS['added'] ) );
check( 'BELL: the FILE is what gets stored, not the title or the url', str_contains( (string) ( $GLOBALS['added_markup'] ?? '' ), '<path' ) );
check( 'BELL: the attachment title becomes the label', 'Star' === ( $GLOBALS['added_label'] ?? '' ) );
check( 'SILENCE: the library payload comes back with the report', isset( $out['items'] ) && isset( $out['report'] ) );

// Each refusal is its own word, because "that is a PNG" and "that file is not on
// this server" send a person somewhere completely different.
$GLOBALS['added'] = array();
$report           = easy_svg_panel_library_media( new WP_REST_Request( array( 'ids' => array( 12, 13, 999 ) ) ) )->get_data()['report'];
$states           = array_column( $report, 'state' );
check( 'BELL: a PNG is refused as not an SVG', in_array( 'not_svg', $states, true ) );
check( 'BELL: an attachment with no local file is refused on its own terms', in_array( 'no_file', $states, true ) );
check( 'BELL: an unknown id is refused as not found', in_array( 'not_found', $states, true ) );
check( 'BELL: and none of the three was stored', 0 === count( $GLOBALS['added'] ) );
check( 'SILENCE: every row names the attachment it is about', 3 === count( array_filter( $report, static fn( $r ) => isset( $r['id'] ) ) ) );

// The server owns the verdict AND the sentence for it, as it does on /library/add.
// A client that invented its own wording would be a second set of strings to
// translate and a second place for the two to drift apart.
$sentences = array_column( $report, 'message' );
check( 'BELL: each row carries the sentence for its state', 3 === count( array_filter( $sentences ) ) );
check( 'BELL: and the three sentences differ from one another', 3 === count( array_unique( $sentences ) ) );

// An attachment whose file exists but cannot be read is the one case where
// is_readable() earns its line: file_get_contents() would answer the same
// 'no_file', but only after raising a warning that prints the server path on a
// site with display_errors on.
$locked = tempnam( sys_get_temp_dir(), 'esw' );
file_put_contents( $locked, '<svg/>' );
chmod( $locked, 0000 );
$GLOBALS['attachments'][14] = array( 'mime' => 'image/svg+xml', 'file' => $locked, 'title' => 'Locked' );
$GLOBALS['posts'][14]       = (object) array( 'ID' => 14, 'post_type' => 'attachment' );
if ( is_readable( $locked ) ) {
	// Running as a user that ignores the mode (root, or a filesystem without
	// permissions). The case cannot be staged here, so it is not asserted.
	check( 'SILENCE: skipped -- this user can read a 0000 file', true );
} else {
	$warned = '';
	set_error_handler(
		static function ( $no, $str ) use ( &$warned ) {
			$warned = (string) $str;
			return true;
		}
	);
	$locked_state = easy_svg_panel_media_state( 14 );
	restore_error_handler();
	check( 'BELL: an unreadable file is refused WITHOUT raising a warning', 'no_file' === $locked_state && '' === $warned );
}
chmod( $locked, 0600 );
@unlink( $locked );

// A mixed selection is the normal case: the good ones must still land.
$GLOBALS['added'] = array();
$mixed            = easy_svg_panel_library_media( new WP_REST_Request( array( 'ids' => array( 11, 12 ) ) ) )->get_data()['report'];
check( 'BELL: one bad file does not stop the good one', 1 === count( $GLOBALS['added'] ) && 2 === count( $mixed ) );

// The sanitiser's own refusal is reported, not swallowed into a success.
$GLOBALS['add_result'] = 'not_svg';
$refused               = easy_svg_panel_library_media( new WP_REST_Request( array( 'ids' => array( 11 ) ) ) )->get_data()['report'];
check( 'BELL: what easy_svg_add_icon refuses is what the row says', 'not_svg' === ( $refused[0]['state'] ?? '' ) );
unset( $GLOBALS['add_result'] );
@unlink( $svg_file );

// ─── every route is behind the same capability ────────────────────────────────
// A new route added without a permission callback -- or with a laxer one -- is a
// hole nothing else in this file would notice, because each handler is called
// here directly. So the registration itself is read.
easy_svg_panel_rest();
check( 'SILENCE: the rename route is registered', isset( $GLOBALS['routes']['easy-svg/v1/library/rename'] ) );
check( 'SILENCE: the media route is registered', isset( $GLOBALS['routes']['easy-svg/v1/library/media'] ) );
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

// ─── every parameter a handler reads is declared ──────────────────────────────
// The handlers re-read and re-sanitise what they are given and that stays -- it
// is what covers a caller who is not the REST layer. What the schema adds is
// the two things a hand-check cannot: WordPress refuses a wrong type or an
// absent required field before the callback runs, and OPTIONS finally answers
// what the route takes. It answered an empty list for every route until now.
//
// Pinned by name, so a parameter added to a handler without a schema fails here
// rather than silently reaching the callback unvalidated.
$declared = array();
foreach ( $GLOBALS['routes'] as $route => $registered ) {
	foreach ( isset( $registered['methods'] ) ? array( $registered ) : $registered as $definition ) {
		if ( 'POST' !== ( $definition['methods'] ?? '' ) ) {
			continue;
		}
		$declared[ $route ] = array_keys( (array) ( $definition['args'] ?? array() ) );
	}
}
check( 'BELL: /settings declares its three, none of them required', array( 'svg_upload', 'icons', 'max_mb' ) === ( $declared['easy-svg/v1/settings'] ?? array() ) );
check( 'BELL: /library/add declares markup and label', array( 'markup', 'label' ) === ( $declared['easy-svg/v1/library/add'] ?? array() ) );
check( 'BELL: /library/media declares ids', array( 'ids' ) === ( $declared['easy-svg/v1/library/media'] ?? array() ) );
check( 'BELL: /library/rename declares id and label', array( 'id', 'label' ) === ( $declared['easy-svg/v1/library/rename'] ?? array() ) );
check( 'BELL: /library/delete declares id', array( 'id' ) === ( $declared['easy-svg/v1/library/delete'] ?? array() ) );

$settings_args = $GLOBALS['routes']['easy-svg/v1/settings'][1]['args'] ?? array();
check(
	'BELL: no settings key is required, because an absent one must keep its stored value',
	array() === array_filter( $settings_args, static fn( $a ) => ! empty( $a['required'] ) )
);
check( 'BELL: max_mb carries the same ceiling the sanitiser clamps to', 20 === ( $settings_args['max_mb']['maximum'] ?? 0 ) );

$media_args = $GLOBALS['routes']['easy-svg/v1/library/media']['args'] ?? array();
check( 'BELL: the ids cap is declared where a client can read it', EASY_SVG_MEDIA_MAX_IDS === ( $media_args['ids']['maxItems'] ?? 0 ) );
check( 'SILENCE: and ids is an array of integers', 'array' === ( $media_args['ids']['type'] ?? '' ) && 'integer' === ( $media_args['ids']['items']['type'] ?? '' ) );

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

// ─── the library payload says how many there really are ──────────────────────
//
// Five routes returned easy_svg_icon_page( 1, 500 ) -- page one, five hundred,
// with no_found_rows so not even a count. A site with 501 icons showed 500 and
// said nothing: no error, no note, no way to reach the rest. The cap can stay;
// being silent about it cannot.

$GLOBALS['stored_icons'] = 12;
$payload = easy_svg_panel_library_payload();
check( 'BELL: the payload carries the icons', 12 === count( $payload['items'] ) );
check( 'BELL: and the real total', 12 === $payload['total'] );
check( 'SILENCE: nothing is truncated when everything fits', false === $payload['truncated'] );

$GLOBALS['stored_icons'] = 812;
$payload = easy_svg_panel_library_payload();
check( 'BELL: past the cap only the cap comes back', EASY_SVG_PANEL_LIBRARY_MAX === count( $payload['items'] ) );
check( 'BELL: but the total is the truth, not the page size', 812 === $payload['total'] );
check( 'BELL: and it says so', true === $payload['truncated'] );
check( 'SILENCE: the cap is what was asked for, not a bigger read', EASY_SVG_PANEL_LIBRARY_MAX === $GLOBALS['asked_per_page'] );

// The shape stays readable by a client that only knows the old array: items
// first, so `response.items ?? response` is the whole migration.
check( 'BELL: items is a list, not a map', array_keys( $payload['items'] ) === range( 0, count( $payload['items'] ) - 1 ) );

// The search goes to the store, and a search reports ITS OWN count.
$GLOBALS['stored_icons']   = 812;
$GLOBALS['stored_matches'] = 3;
$GLOBALS['asked_search']   = 'untouched';
$payload                   = easy_svg_panel_library_payload( 'pfeil' );
check( 'BELL: the term reaches the store', 'pfeil' === $GLOBALS['asked_search'] );
check( 'BELL: a search answers with its matches', 3 === count( $payload['items'] ) );
check( 'BELL: and counts them, not the library', 3 === $payload['total'] );
check( 'SILENCE: three matches out of 812 is not a truncated list', false === $payload['truncated'] );

// A search that fills the cap: the total cannot reveal the cut, so the page
// size has to.
$GLOBALS['stored_matches'] = 900;
$payload                   = easy_svg_panel_library_payload( 'pfeil' );
check( 'BELL: a capped search says it was cut short', true === $payload['truncated'] );

/*
 * A decision pinned, not behaviour: `post_name` may not go back into
 * `search_columns`. It reads as a slug search and is not one -- core narrows
 * that list to title, excerpt and content and drops the rest, measured as zero
 * results for a slug-only term on WordPress 7.1.
 */
$manager_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/icon-manager.php' );
check( 'SILENCE: post_name is not handed to search_columns, where core drops it', ! preg_match( '/search_columns.{0,80}post_name/', $manager_src ) );
check( 'BELL: the slug is matched by its own query', function_exists( 'easy_svg_icon_ids_by_slug_like' ) || str_contains( $manager_src, 'post_name LIKE' ) );
check( 'BELL: prepared, and the term escaped for LIKE', str_contains( $manager_src, 'esc_like' ) && str_contains( $manager_src, '$wpdb->prepare' ) );

// Without a term the store is asked without one, so the unfiltered count rules.
$GLOBALS['stored_matches'] = null;
$payload                   = easy_svg_panel_library_payload();
check( 'SILENCE: no term reaches the store when none was given', '' === $GLOBALS['asked_search'] );

$GLOBALS['stored_icons']   = 0;
$GLOBALS['stored_matches'] = null;

echo 0 === $failed
	? "all {$passed} checks passed\n"
	: "{$failed} of " . ( $passed + $failed ) . " checks FAILED\n";

exit( 0 === $failed ? 0 : 1 );
