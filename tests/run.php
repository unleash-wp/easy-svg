<?php
/**
 * The checks, in plain PHP.
 *
 * No PHPUnit, no WordPress bootstrap. This plugin ships to wordpress.org as a
 * zip and is edited by people who will not install a toolchain to run one file,
 * so the suite has to work with nothing but the PHP that is already here. The
 * sanitiser comes from the vendor directory, which is committed.
 *
 * WordPress itself is stubbed to the handful of functions the plugin touches.
 * That is enough for the two things worth asserting: which hooks get
 * registered, and what actually happens to the bytes of a file.
 *
 * Usage: php tests/run.php
 */

declare(strict_types=1);

$root = dirname( __DIR__ );

define( 'ABSPATH', $root . '/' );
define( 'MB_IN_BYTES', 1048576 );

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

// ─── The smallest WordPress this plugin touches ──────────────────────────────

$GLOBALS['hooks']      = [];
$GLOBALS['priorities'] = [];

function add_filter( string $hook, $cb, int $priority = 10, int $args = 1 ): bool {
	$GLOBALS['hooks'][ $hook ][] = $cb;
	return true;
}
function add_action( string $hook, $cb, int $priority = 10, int $args = 1 ): bool {
	// The priority is recorded, not discarded: core registers its own icon
	// collections on `init` at 0, and an icon registered before its collection
	// is refused. Order is a property worth asserting.
	$GLOBALS['hooks'][ $hook ][]              = $cb;
	$GLOBALS['priorities'][ $hook ][ is_string( $cb ) ? $cb : 'closure' ] = $priority;
	return true;
}
function apply_filters( string $hook, $value, ...$args ) {
	// Real enough to prove the allow-list is reachable. A stub that always
	// returned its input would let a plugin ignore the filter entirely and
	// still pass every check below. Extra arguments are passed on, as core
	// does: `wp_check_filetype_and_ext` hands its callbacks four more.
	foreach ( $GLOBALS['hooks'][ $hook ] ?? [] as $cb ) {
		$value = $cb( $value, ...$args );
	}
	return $value;
}
function __return_false(): bool {
	return false;
}
function __return_true(): bool {
	return true;
}
function __( string $text, string $domain = '' ): string {
	return $text;
}
function _n( string $single, string $plural, int $n, string $domain = '' ): string {
	return 1 === $n ? $single : $plural;
}
function esc_attr( string $text ): string {
	return $text;
}
function get_allowed_mime_types(): array {
	// Core's list, abridged to the types the checks below need, and run
	// through `upload_mimes` the way core runs it -- that filter is where this
	// plugin adds SVG.
	return apply_filters(
		'upload_mimes',
		[
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'mp3|m4a|m4b'  => 'audio/mpeg',
			'pdf'          => 'application/pdf',
			'txt|asc|c|cc|h|srt' => 'text/plain',
		]
	);
}
// Answering false on purpose: this suite is not an admin request, so hiding the
// icons behind `is_admin()` shows up as icons that never register rather than
// as a fatal.
function is_admin(): bool {
	return false;
}

/*
 * Core's file-type check, cut down to the branches that matter here but
 * otherwise as core writes it (wp-includes/functions.php): the extension is
 * taken from the filename, the content is looked at with fileinfo, and the
 * result goes through the `wp_check_filetype_and_ext` filter with all of its
 * arguments. Close to core on purpose, so the upload checks below are tested
 * against what WordPress really answers.
 */
function wp_check_filetype( $filename, $mimes = null ): array {
	$mimes = $mimes ?: get_allowed_mime_types();
	$type  = false;
	$ext   = false;
	foreach ( $mimes as $ext_preg => $mime_match ) {
		if ( preg_match( '!\\.(' . $ext_preg . ')$!i', (string) $filename, $m ) ) {
			$type = $mime_match;
			$ext  = $m[1];
			break;
		}
	}
	return compact( 'ext', 'type' );
}
function wp_check_filetype_and_ext( $file, $filename, $mimes = null ): array {
	$proper_filename = false;
	$checked         = wp_check_filetype( $filename, $mimes );
	$ext             = $checked['ext'];
	$type            = $checked['type'];
	$real_mime       = false;

	if ( $type && 0 === strpos( $type, 'image/' ) && file_exists( $file ) ) {
		$info      = @getimagesize( $file );
		$real_mime = $info['mime'] ?? false;
		if ( $real_mime && $real_mime !== $type ) {
			$type = false;
			$ext  = false;
		}
	}
	if ( $type && ! $real_mime && file_exists( $file ) ) {
		$real_mime = finfo_file( finfo_open( FILEINFO_MIME_TYPE ), $file );
		if ( 'text/plain' === $real_mime ) {
			if ( 'text/plain' !== $type ) {
				$type = false;
				$ext  = false;
			}
		} elseif ( $type !== $real_mime ) {
			$type = false;
			$ext  = false;
		}
	}
	if ( $type && ! in_array( $type, get_allowed_mime_types(), true ) ) {
		$type = false;
		$ext  = false;
	}

	return apply_filters( 'wp_check_filetype_and_ext', compact( 'ext', 'type', 'proper_filename' ), $file, $filename, $mimes, $real_mime );
}

// ─── The icon store, as far as the plugin can see it ─────────────────────────

define( 'DAY_IN_SECONDS', 86400 );

/** The esw_icon posts this fake site holds, oldest first. */
$GLOBALS['icon_posts'] = [];
/** Every get_posts() call, so a test can tell a cache hit from a query. */
$GLOBALS['get_posts_calls'] = [];

function get_posts( array $args = [] ): array {
	$GLOBALS['get_posts_calls'][] = $args;
	$per_page = (int) ( $args['posts_per_page'] ?? 5 );
	$page     = max( 1, (int) ( $args['paged'] ?? 1 ) );
	$posts    = array_values(
		array_filter(
			$GLOBALS['icon_posts'],
			static function ( $p ) use ( $args ) {
				return ( $args['post_type'] ?? '' ) === $p->post_type && ( 'any' === ( $args['post_status'] ?? 'publish' ) || 'publish' === $p->post_status );
			}
		)
	);
	$result = $per_page < 0 ? $posts : array_slice( $posts, ( $page - 1 ) * $per_page, $per_page );
	if ( 'ids' === ( $args['fields'] ?? '' ) ) {
		$result = array_map( static function ( $p ) { return $p->ID; }, $result );
	}
	// Something else happening on the site AFTER this read answered and
	// before the reader stores what it got.
	if ( isset( $GLOBALS['during_query'] ) && count( $result ) < $per_page ) {
		$during = $GLOBALS['during_query'];
		unset( $GLOBALS['during_query'] );
		$during();
	}
	return $result;
}

function icon_post( int $id, string $type = 'esw_icon' ): object {
	return (object) [
		'ID'           => $id,
		'post_type'    => $type,
		'post_status'  => 'publish',
		'post_name'    => "icon-{$id}",
		'post_title'   => "Icon {$id}",
		'post_content' => '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>',
	];
}

$GLOBALS['options'] = [];
function get_option( string $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['options'] ) ? $GLOBALS['options'][ $key ] : $default;
}
// Single site by default, so the upload checks below behave as on wordpress.org's
// most common install; a later test flips $GLOBALS['is_multisite'] to exercise
// the network branch.
$GLOBALS['is_multisite'] = false;
$GLOBALS['site_options'] = [];
function is_multisite(): bool {
	return ! empty( $GLOBALS['is_multisite'] );
}
function get_site_option( string $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['site_options'] ) ? $GLOBALS['site_options'][ $key ] : $default;
}
function update_option( string $key, $value, $autoload = null ): bool {
	$GLOBALS['options'][ $key ] = $value;
	return true;
}

$GLOBALS['transients'] = [];
$GLOBALS['transient_ttl'] = [];
function get_transient( string $key ) {
	return $GLOBALS['transients'][ $key ] ?? false;
}
function set_transient( string $key, $value, int $ttl = 0 ): bool {
	$GLOBALS['transients'][ $key ]    = $value;
	$GLOBALS['transient_ttl'][ $key ] = $ttl;
	return true;
}
function delete_transient( string $key ): bool {
	unset( $GLOBALS['transients'][ $key ] );
	return true;
}

/** Runs what is registered on a hook, the way do_action would. */
function fire( string $hook, ...$args ): void {
	foreach ( $GLOBALS['hooks'][ $hook ] ?? [] as $cb ) {
		$cb( ...$args );
	}
}

// ─── Saving an icon ──────────────────────────────────────────────────────────

class WP_Error {
	public function __construct( string $code = '', string $message = '' ) {}
}
function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}
/** What wp_insert_post() answers next; a test sets it. */
$GLOBALS['insert_returns'] = 1;
$GLOBALS['inserted']       = [];
/*
 * As core does on the way in: the data is unslashed, and for a user without
 * unfiltered_html the content goes through wp_filter_post_kses -- which knows
 * no SVG and leaves nothing of an icon. Both are what a multisite sub-site
 * administrator gets.
 */
$GLOBALS['kses_on']      = false;
$GLOBALS['stored_posts'] = [];
$GLOBALS['deleted_posts'] = [];
function wp_insert_post( array $post, bool $wp_error = false, bool $fire_after_hooks = true ) {
	$GLOBALS['inserted'][] = [ 'post' => $post, 'wp_error' => $wp_error, 'kses' => $GLOBALS['kses_on'] ];
	$answer = $GLOBALS['insert_returns'];
	if ( is_int( $answer ) && $answer > 0 ) {
		$content = stripslashes( (string) ( $post['post_content'] ?? '' ) );
		if ( $GLOBALS['kses_on'] ) {
			$content = trim( strip_tags( $content ) );
		}
		$GLOBALS['stored_posts'][ $answer ] = (object) [ 'ID' => $answer, 'post_type' => $post['post_type'] ?? '', 'post_content' => $content ];
	}
	// As core does: without $wp_error a failure is 0, never an object.
	return ( ! $wp_error && $answer instanceof WP_Error ) ? 0 : $answer;
}
function get_post( $id ) {
	return $GLOBALS['stored_posts'][ $id ] ?? null;
}
function wp_delete_post( $id, $force = false ) {
	$GLOBALS['deleted_posts'][] = $id;
	unset( $GLOBALS['stored_posts'][ $id ] );
	$GLOBALS['icon_posts'] = array_values( array_filter( $GLOBALS['icon_posts'] ?? [], static function ( $p ) use ( $id ) { return $p->ID !== $id; } ) );
	return true;
}
function wp_slash( $value ) {
	return is_array( $value ) ? array_map( 'wp_slash', $value ) : ( is_string( $value ) ? addslashes( $value ) : $value );
}
function has_filter( string $hook, $cb = false ) {
	return ( 'content_save_pre' === $hook && 'wp_filter_post_kses' === $cb && $GLOBALS['kses_on'] ) ? 10 : false;
}
function kses_remove_filters(): void {
	if ( empty( $GLOBALS['kses_stuck'] ) ) {
		$GLOBALS['kses_on'] = false;
	}
}
function kses_init_filters(): void {
	$GLOBALS['kses_on'] = true;
}

// ─── wp_kses, as far as an allow-list goes ───────────────────────────────────

/*
 * Not WordPress's parser -- that needs WordPress. What this proves is the
 * ALLOW-LIST the plugin hands over: an element or attribute it leaves out is
 * dropped, exactly as wp_kses drops it. The real wp_kses is exercised on a real
 * WordPress in the Playground smoke run.
 */
$GLOBALS['kses_calls'] = 0;
function wp_kses( string $content, $allowed_html, array $allowed_protocols = [] ): string {
	$GLOBALS['kses_calls']++;
	$doc = new DOMDocument();
	if ( ! @$doc->loadXML( $content ) ) {
		return '';
	}
	$walk = static function ( DOMNode $node ) use ( &$walk, $allowed_html ): void {
		for ( $i = $node->childNodes->length - 1; $i >= 0; $i-- ) {
			$child = $node->childNodes->item( $i );
			if ( ! $child instanceof DOMElement ) {
				continue;
			}
			$tag = strtolower( $child->localName );
			if ( ! isset( $allowed_html[ $tag ] ) ) {
				// As wp_kses does: the TAG goes, what was inside it stays and is
				// checked in turn -- text included.
				$walk( $child );
				while ( $child->firstChild ) {
					$node->insertBefore( $child->firstChild, $child );
				}
				$node->removeChild( $child );
				continue;
			}
			for ( $a = $child->attributes->length - 1; $a >= 0; $a-- ) {
				$attr = $child->attributes->item( $a );
				if ( empty( $allowed_html[ $tag ][ strtolower( $attr->nodeName ) ] ) ) {
					$child->removeAttributeNode( $attr );
				}
			}
			$walk( $child );
		}
	};
	$walk( $doc );
	return null === $doc->documentElement ? '' : (string) $doc->saveXML( $doc->documentElement );
}

$GLOBALS['media_pages'] = [];
function add_media_page( string $page_title, string $menu_title, string $capability, string $slug, $callback = '' ) {
	$GLOBALS['media_pages'][] = $slug;
	return 'media_page_' . $slug;
}

// ─── Attachments, as far as the safety net sees them ─────────────────────────

$GLOBALS['attachments'] = [];   // id => [ 'file' => path, 'mime' => type ]
$GLOBALS['deleted_attachments'] = [];
function get_attached_file( $id ) {
	return $GLOBALS['attachments'][ $id ]['file'] ?? false;
}
function get_post_mime_type( $id = null ) {
	return $GLOBALS['attachments'][ $id ]['mime'] ?? false;
}
/** The uploads directory; temporary files in the checks below live in it. */
function wp_upload_dir( $time = null, $create_dir = true, $refresh_cache = false ): array {
	$base = (string) realpath( sys_get_temp_dir() );
	return [ 'basedir' => $base, 'path' => $base, 'error' => false ];
}
function wp_delete_attachment( $id, $force = false ) {
	$GLOBALS['deleted_attachments'][] = [ $id, $force ];
	return (object) [ 'ID' => $id ];
}

$GLOBALS['post_types'] = [];
function register_post_type( string $type, array $args = [] ) {
	$GLOBALS['post_types'][ $type ] = $args;
	return (object) [ 'name' => $type ];
}

/*
 * Caught, so a plugin that does not load is a FAIL LINE rather than a dead
 * process. A suite that dies reports nothing, and "nothing" is the one result
 * indistinguishable from "not covered".
 */
/*
 * Features are OFF by default (5.0): a fresh install registers no upload or icon
 * hooks until the admin turns them on. The behavioural tests below exercise
 * those paths, so the option is seeded ON before the plugin loads -- the hooks
 * register at require time. The default-off behaviour itself is asserted in the
 * settings section near the end of this file.
 */
$GLOBALS['options']['easy_svg_settings'] = array(
	'svg_upload' => true,
	'icons'      => true,
	'max_mb'     => 2,
);
$loaded = true;
try {
	require $root . '/easy-svg.php';
} catch ( \Throwable $e ) {
	$loaded = false;
	echo '      load error: ' . $e->getMessage() . "\n";
}

check( 'the plugin loads', $loaded );

// ─── Both ways a file can arrive ─────────────────────────────────────────────

/*
 * The bug this file was written for. WordPress builds the hook name from the
 * action -- `apply_filters( "{$action}_prefilter", $file )` -- and `$action` is
 * `wp_handle_upload` or `wp_handle_sideload`. Only the first was registered, so
 * `media_sideload_image()`, WP-CLI `wp media import` and every importer put
 * files in without the sanitiser ever seeing them.
 */
$upload   = $GLOBALS['hooks']['wp_handle_upload_prefilter'] ?? [];
$sideload = $GLOBALS['hooks']['wp_handle_sideload_prefilter'] ?? [];

check( 'BELL: the uploader path is filtered', [] !== $upload );
check( 'BELL: and so is the sideload path', [] !== $sideload );
// The SAME callback. Two different checks would drift, and the one nobody
// exercises is the one that stops matching.
check( 'BELL: both run the same check, not two that can drift', $upload === $sideload );

// ─── A hook name that cannot fire ────────────────────────────────────────────

/*
 * `wp_AJAX_svg_get_attachment_url` sat here for four years. Core fires
 * `do_action( "wp_ajax_{$action}" )` in lower case and refuses the request
 * earlier still when nothing is registered under that name, so the handler
 * could never run -- in any released version, checked through the history.
 *
 * Asserted as a SHAPE rather than as that one name, because the next one will
 * be spelled differently.
 */
foreach ( array_keys( $GLOBALS['hooks'] ) as $hook ) {
	check(
		"the hook name '{$hook}' is one WordPress can fire",
		$hook === strtolower( $hook )
	);
}

// ─── What actually happens to the bytes ──────────────────────────────────────

$callback = $upload[0] ?? null;
check( 'the filter callback was found', is_callable( $callback ) );

/** Writes bytes to a temp file and returns the file array WordPress passes. */
function file_array( string $bytes, string $name = 'x.svg' ): array {
	$path = tempnam( sys_get_temp_dir(), 'esw' );
	file_put_contents( $path, $bytes );
	return [ 'name' => $name, 'tmp_name' => $path, 'type' => 'image/svg+xml' ];
}

$scripted = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect/></svg>';

$file  = file_array( $scripted );
$after = $callback( $file );
$clean = (string) file_get_contents( $file['tmp_name'] );
unlink( $file['tmp_name'] );

check( 'BELL: the script element is gone from the stored bytes', false === strpos( $clean, '<script' ) );
check( 'SILENCE: and the rest of the drawing survives', false !== strpos( $clean, 'rect' ) );
check( 'SILENCE: a good file is not rejected', ! isset( $after['error'] ) );

/*
 * The same callback, reached the other way. Asserted on the BYTES, because
 * registering a hook and having it do the right thing are two claims.
 *
 * Guarded, so that removing the registration produces two FAIL lines and a
 * summary rather than a fatal. A suite that dies reports nothing, and "nothing"
 * is the one result that cannot be told apart from "not covered" -- which is
 * exactly the shape of bug this file exists for.
 */
$sideload_cb = $sideload[0] ?? null;
if ( is_callable( $sideload_cb ) ) {
	$file  = file_array( $scripted );
	$sideload_cb( $file );
	$clean = (string) file_get_contents( $file['tmp_name'] );
	unlink( $file['tmp_name'] );

	check( 'BELL: a sideloaded file is sanitised too', false === strpos( $clean, '<script' ) );
} else {
	check( 'BELL: a sideloaded file is sanitised too', false );
}

// ─── CVE-2025-12451: the client Content-Type cannot skip the sanitiser ───────
//
// The reported attack (<= 4.0): upload a .svg carrying a script, then change
// the request Content-Type to image/gif so the old, type-header-driven check
// was skipped and the raw file was stored. The decision is now server-side
// (wp_check_filetype_and_ext over the bytes and the name), so a spoofed
// $file['type'] is ignored: the file is sanitised, exactly as with an honest
// type. The same bytes also behave identically whichever type is claimed.
$spoofed        = file_array( $scripted, 'poc.svg' );
$spoofed['type'] = 'image/gif'; // the attacker's forged header
$after          = $callback( $spoofed );
$stored         = (string) file_get_contents( $spoofed['tmp_name'] );
unlink( $spoofed['tmp_name'] );
check( 'BELL: CVE-2025-12451 -- a Content-Type-spoofed SVG is sanitised, not stored raw', ! isset( $after['error'] ) && false === strpos( $stored, '<script' ) && false !== strpos( $stored, 'rect' ) );

$honest        = file_array( $scripted, 'poc.svg' ); // same bytes, truthful type
$after_h       = $callback( $honest );
$stored_h      = (string) file_get_contents( $honest['tmp_name'] );
unlink( $honest['tmp_name'] );
check( 'BELL: and the forged type changes nothing -- same outcome as an honest one', ( isset( $after['error'] ) === isset( $after_h['error'] ) ) && $stored === $stored_h );

// ─── Size and emptiness, before the sanitiser does any real work ─────────────

// An SVG larger than the cap is refused before it is parsed. Parsing scales
// with size; a ceiling keeps one upload from tying up a request.
$huge  = '<svg xmlns="http://www.w3.org/2000/svg">' . str_repeat( '<rect/>', 400000 ) . '</svg>';
$file  = file_array( $huge, 'huge.svg' );
$after = $callback( $file );
unlink( $file['tmp_name'] );
check( 'BELL: an oversized SVG is refused before it is parsed', isset( $after['error'] ) );

// The sanitiser returns '' for input PHP empty() treats as empty -- a file
// whose only content is "0" among them. That is a refusal, not an empty
// stored file.
$file  = file_array( '0', 'zero.svg' );
$after = $callback( $file );
unlink( $file['tmp_name'] );
check( 'BELL: an SVG that sanitises to nothing is refused', isset( $after['error'] ) );

// ─── An animation element may not rewrite an href to a script URL ────────────

// The default allow-list already drops <animate>; a site that widens it keeps
// the element, and the sanitiser does not check what attributeName/values make
// it do. This fixed pass removes only an animation element that targets href
// or an on* handler, and leaves a benign one (opacity, transform) alone.
$anim = '<svg xmlns="http://www.w3.org/2000/svg"><a href="#x"><rect/></a>'
      . '<animate attributeName="href" values="javascript:alert(1)"/>'
      . '<animate attributeName="opacity" values="0;1"/></svg>';
$n = easy_svg_neutralize_href_animation( $anim );
check( 'BELL: an animate that targets href is removed', false === strpos( $n, 'javascript:' ) );
check( 'SILENCE: a benign animate is kept', false !== stripos( $n, 'opacity' ) );

// And the upload path applies it: with the allow-list widened to 'animate',
// an uploaded SVG is stored without the href-targeting animation.
add_filter( 'esw_svg_allowed_tags', static function ( $tags ) { $tags[] = 'animate'; return $tags; } );
$file  = file_array( $anim, 'anim.svg' );
$after = $callback( $file );
$stored = (string) file_get_contents( $file['tmp_name'] );
unlink( $file['tmp_name'] );
check( 'BELL: a widened-in animate href does not survive the upload', ! isset( $after['error'] ) && false === strpos( $stored, 'javascript:' ) );

// ─── Files that are not what they claim ──────────────────────────────────────

$file  = file_array( '<html><script>alert(1)</script></html>', 'evil.svg' );
$after = $callback( $file );
$left  = (string) file_get_contents( $file['tmp_name'] );
unlink( $file['tmp_name'] );

check( 'BELL: a .svg that is not an SVG is refused', isset( $after['error'] ) );

$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );
$file  = file_array( $png, 'photo.png' );
$after = $callback( $file );
$kept  = (string) file_get_contents( $file['tmp_name'] );
unlink( $file['tmp_name'] );

check( 'SILENCE: an ordinary image passes straight through', ! isset( $after['error'] ) );
check( 'SILENCE: and its bytes are not touched', $png === $kept );

// ─── One rule for what counts as an SVG ──────────────────────────────────────

/*
 * A file that is an SVG -- by its extension, in any case, or by the type
 * WordPress settled on -- is sanitised or refused.
 */
foreach ( array( 'X.SVG', 'x.Svg', 'x.sVg' ) as $mixed_name ) {
	$file  = file_array( $scripted, $mixed_name );
	$after = $callback( $file );
	$clean = (string) file_get_contents( $file['tmp_name'] );
	unlink( $file['tmp_name'] );
	check( "BELL: {$mixed_name} is sanitised like x.svg", false === stripos( $clean, '<script' ) && false !== strpos( $clean, 'rect' ) );
	check( "SILENCE: and {$mixed_name} is accepted as an SVG", ! isset( $after['error'] ) && 'image/svg+xml' === ( $after['type'] ?? '' ) );
}

// An SVG without an XML declaration that fileinfo calls text/plain is still an
// SVG by its name -- and so it is sanitised, not waved through.
$bare = '<svg><script>alert(1)</script><rect/></svg>';
$file  = file_array( $bare, 'BARE.SVG' );
$after = $callback( $file );
$clean = (string) file_get_contents( $file['tmp_name'] );
unlink( $file['tmp_name'] );
check( 'BELL: an SVG fileinfo cannot name is still sanitised', false === stripos( $clean, '<script' ) || isset( $after['error'] ) );

// A type mapped to SVG under another extension is an SVG too.
add_filter(
	'upload_mimes',
	static function ( $mimes ) {
		$mimes['svgicon'] = 'image/svg+xml';
		return $mimes;
	}
);
$file  = file_array( $scripted, 'x.svgicon' );
$after = $callback( $file );
$clean = (string) file_get_contents( $file['tmp_name'] );
unlink( $file['tmp_name'] );
check( 'BELL: a file whose type is image/svg+xml is sanitised whatever its extension', false === stripos( $clean, '<script' ) || isset( $after['error'] ) );
array_pop( $GLOBALS['hooks']['upload_mimes'] );

// ─── Ways in that never pass the upload filter ───────────────────────────────

/*
 * Two routes put a file on disk without `wp_handle_upload` or
 * `wp_handle_sideload`: `wp_upload_bits()` (XML-RPC media uploads use it) and
 * importers that copy a file into place themselves. The first is checked on
 * its own filter; behind both, every new SVG attachment is checked once more.
 */
$bits_cb = $GLOBALS['hooks']['wp_upload_bits'][0] ?? null;
check( 'BELL: wp_upload_bits is filtered', is_callable( $bits_cb ) );

if ( is_callable( $bits_cb ) ) {
	$clean_svg = (string) easy_svg_sanitizer()->sanitize( '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>' );
	$verdict   = $bits_cb( [ 'name' => 'x.SVG', 'bits' => $scripted, 'time' => null ] );
	check( 'BELL: bits for an SVG that the sanitiser would change are refused', is_string( $verdict ) && '' !== $verdict );

	$verdict = $bits_cb( [ 'name' => 'x.svg', 'bits' => '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY e "x">]><svg xmlns="http://www.w3.org/2000/svg">&e;</svg>', 'time' => null ] );
	check( 'BELL: bits the sanitiser cannot use are refused', is_string( $verdict ) );

	$verdict = $bits_cb( [ 'name' => 'x.svg', 'bits' => $clean_svg, 'time' => null ] );
	check( 'SILENCE: an SVG that is already clean passes', is_array( $verdict ) && $clean_svg === $verdict['bits'] );

	// The WordPress importer reserves the file with empty bits and fills it
	// afterwards; that must not be refused here (the attachment check below
	// sees the finished file).
	$verdict = $bits_cb( [ 'name' => 'x.svg', 'bits' => '', 'time' => null ] );
	check( 'SILENCE: empty bits, as the importer writes them first, pass', is_array( $verdict ) );

	$verdict = $bits_cb( [ 'name' => 'photo.png', 'bits' => $scripted, 'time' => null ] );
	check( 'SILENCE: bits for anything that is not an SVG are left alone', is_array( $verdict ) && $scripted === $verdict['bits'] );
}

/*
 * "Already clean" means nothing in the bytes beyond the drawing itself. A
 * document type can declare attribute defaults that no element shows, and
 * bytes after the closing tag are not part of the drawing but still part of
 * the file that would be stored.
 */
$clean_rect = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="9" height="9"/></svg>';
check( 'SILENCE: a plain clean SVG counts as clean', easy_svg_markup_is_clean( $clean_rect ) );
check( 'BELL: a declared attribute default is not clean', ! easy_svg_markup_is_clean( '<!DOCTYPE svg [<!ATTLIST rect onclick CDATA "alert(1)">]>' . $clean_rect ) );
check( 'BELL: nor a declared link default', ! easy_svg_markup_is_clean( '<!DOCTYPE svg [<!ATTLIST a xlink:href CDATA "javascript:alert(1)" xmlns:xlink CDATA "http://www.w3.org/1999/xlink">]><svg xmlns="http://www.w3.org/2000/svg"><a><rect width="9" height="9"/></a></svg>' ) );
check( 'BELL: any document type at all is not clean', ! easy_svg_markup_is_clean( '<?xml version="1.0"?><!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">' . $clean_rect ) );
check( 'BELL: bytes after the root element are not clean', ! easy_svg_markup_is_clean( $clean_rect . "\x00<script>alert(1)</script>" ) );
check( 'BELL: nor markup after it', ! easy_svg_markup_is_clean( $clean_rect . '<script>alert(1)</script>' ) );
check( 'SILENCE: trailing whitespace is fine', easy_svg_markup_is_clean( $clean_rect . "\n\n" ) );

$attach_cb = $GLOBALS['hooks']['add_attachment'][0] ?? null;
check( 'BELL: new attachments are checked', is_callable( $attach_cb ) );

if ( is_callable( $attach_cb ) ) {
	$path = tempnam( sys_get_temp_dir(), 'esw' ) . '.SVG';
	file_put_contents( $path, $scripted );
	$GLOBALS['attachments'][501] = [ 'file' => $path, 'mime' => 'image/svg+xml' ];
	$attach_cb( 501 );
	$cleaned = (string) file_get_contents( $path );
	check( 'BELL: an SVG attachment that skipped the upload filter is sanitised in place', false === stripos( $cleaned, '<script' ) && false !== strpos( $cleaned, 'rect' ) );
	check( 'SILENCE: and kept', [] === $GLOBALS['deleted_attachments'] );
	unlink( $path );

	$path = tempnam( sys_get_temp_dir(), 'esw' ) . '.svg';
	file_put_contents( $path, '<html><script>alert(1)</script></html>' );
	$GLOBALS['attachments'][502] = [ 'file' => $path, 'mime' => 'image/svg+xml' ];
	$attach_cb( 502 );
	check( 'BELL: one the sanitiser cannot use is deleted, file and all', [ [ 502, true ] ] === $GLOBALS['deleted_attachments'] );
	@unlink( $path );

	$GLOBALS['deleted_attachments'] = [];
	$GLOBALS['attachments'][503] = [ 'file' => sys_get_temp_dir() . '/does-not-exist.svg', 'mime' => 'image/svg+xml' ];
	$attach_cb( 503 );
	check( 'SILENCE: an attachment with no local file (offloaded media) is not deleted', [] === $GLOBALS['deleted_attachments'] );

	/*
	 * Only files under the uploads directory are this check's business. A
	 * plugin may register an SVG it ships in its own folder as an attachment;
	 * rewriting that file, or deleting the library entry because it cannot be
	 * written, is not this plugin's call.
	 */
	$outside = $root . '/tests/outside-uploads.svg';
	file_put_contents( $outside, $scripted );
	$GLOBALS['deleted_attachments'] = [];
	$GLOBALS['attachments'][505] = [ 'file' => $outside, 'mime' => 'image/svg+xml' ];
	$attach_cb( 505 );
	check( 'BELL: a file outside the uploads directory is not rewritten', $scripted === file_get_contents( $outside ) );
	unlink( $outside );

	$outside = $root . '/tests/outside-broken.svg';
	file_put_contents( $outside, '<html><script>alert(1)</script></html>' );
	chmod( $outside, 0444 );
	$GLOBALS['attachments'][506] = [ 'file' => $outside, 'mime' => 'image/svg+xml' ];
	$attach_cb( 506 );
	chmod( $outside, 0644 );
	unlink( $outside );
	check( 'BELL: and its attachment is not deleted, even when the file is unusable and unwritable', [] === $GLOBALS['deleted_attachments'] );

	// A path that only looks inside: ../ out of the uploads directory.
	$escape = (string) realpath( sys_get_temp_dir() ) . '/../' . basename( dirname( $root ) ) . '-esw-escape.svg';
	$GLOBALS['attachments'][507] = [ 'file' => $escape, 'mime' => 'image/svg+xml' ];
	$attach_cb( 507 );
	check( 'SILENCE: a path that climbs out of the uploads directory counts as outside', [] === $GLOBALS['deleted_attachments'] );

	$path = tempnam( sys_get_temp_dir(), 'esw' ) . '.png';
	file_put_contents( $path, $png );
	$GLOBALS['attachments'][504] = [ 'file' => $path, 'mime' => 'image/png' ];
	$attach_cb( 504 );
	check( 'SILENCE: other attachments are not touched', $png === file_get_contents( $path ) && [] === $GLOBALS['deleted_attachments'] );
	unlink( $path );
}

// ─── The type check leaves other files to core ───────────────────────────────

/*
 * `esw_upload_check` exists so an SVG that fileinfo misreads is not refused.
 * For every other file core's answer stands: a mismatch between name and
 * bytes stays a refusal.
 */
foreach ( array(
	'x.mp3' => '<?php echo 1;',
	'x.pdf' => '<html><script>alert(1)</script></html>',
	'x.PNG' => '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>',
	'x.jpg' => 'plain text',
) as $mismatch_name => $mismatch_bytes ) {
	$path = tempnam( sys_get_temp_dir(), 'esw' );
	file_put_contents( $path, $mismatch_bytes );
	$verdict = wp_check_filetype_and_ext( $path, $mismatch_name, get_allowed_mime_types() );
	unlink( $path );
	check( "BELL: {$mismatch_name} with other content stays refused by the type check", empty( $verdict['type'] ) && empty( $verdict['ext'] ) );
}

$path = tempnam( sys_get_temp_dir(), 'esw' );
file_put_contents( $path, $png );
$verdict = wp_check_filetype_and_ext( $path, 'photo.png', get_allowed_mime_types() );
unlink( $path );
check( 'SILENCE: and a genuine image keeps its type', 'image/png' === ( $verdict['type'] ?? '' ) );

// ─── A cleaned file that cannot be written back is not stored ────────────────

$file = file_array( $scripted );
chmod( $file['tmp_name'], 0444 );
set_error_handler( static function () { return true; } );
$after = $callback( $file );
restore_error_handler();
chmod( $file['tmp_name'], 0644 );
$left = (string) file_get_contents( $file['tmp_name'] );
unlink( $file['tmp_name'] );
check( 'BELL: when the cleaned bytes cannot be written back, the upload is refused', isset( $after['error'] ) || false === stripos( $left, '<script' ) );

// ─── The icon manager is actually wired ──────────────────────────────────────

/*
 * The question a user would ask: after loading, does this plugin do the thing.
 *
 * Its paid add-on once shipped a version where every file existed, every suite
 * was green, and NOTHING required them -- a licence field and nothing else.
 * Testing a file cannot catch that. Only testing the loaded plugin can.
 */
check( 'the icon code was loaded', function_exists( 'easy_svg_accept_icon' ) );
check( 'the store is registered on init', in_array( 'easy_svg_register_icon_store', $GLOBALS['hooks']['init'] ?? [], true ) );
check( 'and the icons are handed to core on init', in_array( 'easy_svg_boot_icons', $GLOBALS['hooks']['init'] ?? [], true ) );

/*
 * Core registers its own collections on `init` at 0, and WP_Icons_Registry
 * refuses an icon whose collection is not there yet. The store must also exist
 * before the icons are read out of it. Neither order is a preference.
 */
$store_at = $GLOBALS['priorities']['init']['easy_svg_register_icon_store'] ?? null;
$boot_at  = $GLOBALS['priorities']['init']['easy_svg_boot_icons'] ?? null;

check( 'BELL: both run after core registers its collections at 0', $store_at > 0 && $boot_at > 0 );
check( 'BELL: and the store exists before the icons are read from it', $store_at < $boot_at );

/*
 * NOT behind is_admin(). The Icon block is server-rendered: `wp_get_icon()`
 * resolves the name when a visitor's page is built. An admin-only registration
 * shows every icon in the editor and nothing at all on the site.
 */
$main_source = (string) file_get_contents( $root . '/easy-svg.php' );
check(
	'BELL: the icons are registered on the front end too, not only in wp-admin',
	1 !== preg_match( '/is_admin\(\).{0,200}easy_svg_boot_icons/s', $main_source )
);

/*
 * ONE page may answer to the panel's slug.
 *
 * There were two. The classic icons screen registered `easy-svg-icons` as a
 * Media submenu and the panel registers that same slug as a top-level menu, so
 * on WordPress 7.1 BOTH render callbacks ran on the one screen and the old
 * table drew itself underneath the React panel. Nothing errored; it just looked
 * broken -- which is the kind of defect a suite has to hold down, because the
 * two registrations sat in different files and neither looked wrong alone.
 *
 * Asserted against the shipped source rather than by registering menus:
 * WordPress only collides at `admin_menu` time, on a version this suite is not,
 * and the fact worth pinning is which FILES claim the slug at all.
 */
$slug_owners = array();
foreach ( array_merge( array( $root . '/easy-svg.php' ), glob( $root . '/includes/*.php' ) ?: array() ) as $slug_file ) {
	$claims = substr_count( (string) file_get_contents( $slug_file ), 'easy-svg-icons' );
	if ( $claims > 0 ) {
		$slug_owners[ basename( $slug_file ) ] = $claims;
	}
}
check( 'BELL: the icon manager registers no screen of its own', ! isset( $slug_owners['icon-manager.php'] ) );
check(
	'BELL: and the panel slug is claimed exactly once across the shipped source (' . ( json_encode( $slug_owners ) ?: '?' ) . ')',
	array( 'panel.php' => 1 ) === $slug_owners
);

// ─── Only who may manage icons can write them ────────────────────────────────

/*
 * The screen checks edit_theme_options, but the post type is reachable from
 * other places -- XML-RPC's wp.newPost, for one -- that ask the post type's own
 * capabilities. With the `post` defaults an Author could create an icon there
 * and an Editor could change anybody's. Every capability maps to the screen's.
 */
easy_svg_register_icon_store();
check( 'BELL: before 7.1 the icon post type is not registered at all', ! isset( $GLOBALS['post_types']['esw_icon'] ) );

// ─── Every icon, read once ───────────────────────────────────────────────────

/*
 * Two bugs in one place. The list was capped at 200 while the add handler
 * counted every post, so icon 201 was stored and then appeared nowhere -- not
 * on the screen, not in the block. And it was read with a full query on EVERY
 * request on WordPress 7.1, front end included, for a list that changes only
 * when somebody adds or removes an icon.
 */
for ( $i = 1; $i <= 250; $i++ ) {
	$GLOBALS['icon_posts'][] = icon_post( $i );
}
// Not an icon: must never be read, counted or cached as one.
$GLOBALS['icon_posts'][] = icon_post( 9001, 'page' );

$GLOBALS['get_posts_calls'] = [];
$listed = easy_svg_stored_icons();

check( 'BELL: all 250 icons are listed, not the first 200', 250 === count( $listed ) );
check( 'SILENCE: oldest first, so the picker does not reshuffle', 1 === ( $listed[0]['id'] ?? 0 ) && 250 === ( $listed[249]['id'] ?? 0 ) );
check( 'SILENCE: and a page is not an icon', ! in_array( 9001, array_column( $listed, 'id' ), true ) );
check(
	'SILENCE: read in bounded pages rather than one unbounded query',
	[] !== $GLOBALS['get_posts_calls'] && [] === array_filter(
		$GLOBALS['get_posts_calls'],
		static function ( $a ) { return (int) ( $a['posts_per_page'] ?? 0 ) < 1 || (int) $a['posts_per_page'] > 100; }
	)
);

$GLOBALS['get_posts_calls'] = [];
$again = easy_svg_stored_icons();
check( 'BELL: the second request does not query the database again', [] === $GLOBALS['get_posts_calls'] );
check( 'SILENCE: and gets the same list', $again === $listed );

$cache_keys = array_keys( $GLOBALS['transients'] );
check( 'the list is cached in a transient', 1 === count( $cache_keys ) );
// An expiry keeps it out of autoloaded options, so a site with many icons does
// not carry their markup into every request's alloptions.
check( 'BELL: with an expiry, so it is never autoloaded', ( $GLOBALS['transient_ttl'][ $cache_keys[0] ?? '' ] ?? 0 ) > 0 );

// Adding an icon goes through wp_insert_post, which fires save_post_esw_icon.
$GLOBALS['icon_posts'][] = icon_post( 251 );
fire( 'save_post_esw_icon', 251, icon_post( 251 ), false );
$GLOBALS['get_posts_calls'] = [];
check( 'BELL: an added icon appears at once, because adding drops the cache', 251 === count( easy_svg_stored_icons() ) );
check( 'SILENCE: by asking the database again', [] !== $GLOBALS['get_posts_calls'] );

// Deleting a PAGE must not throw away the icon cache.
fire( 'deleted_post', 9001, icon_post( 9001, 'page' ) );
$GLOBALS['get_posts_calls'] = [];
easy_svg_stored_icons();
check( 'SILENCE: deleting something that is not an icon keeps the cache', [] === $GLOBALS['get_posts_calls'] );

// Deleting an icon -- from the screen, WP-CLI or anywhere -- drops it.
array_pop( $GLOBALS['icon_posts'] );
fire( 'deleted_post', 251, icon_post( 251 ) );
check( 'BELL: a deleted icon disappears at once, from wherever it was deleted', 250 === count( easy_svg_stored_icons() ) );

/*
 * The race: a request that missed the cache reads the icons; while it reads,
 * another request adds one and drops the cache; the first then stores the list
 * it read -- without the new icon -- for a day. A version that every drop
 * raises makes that stale list unusable the moment it is stored.
 */
easy_svg_forget_icons();
$GLOBALS['during_query'] = static function () {
	$GLOBALS['icon_posts'][] = icon_post( 260 );
	fire( 'save_post_esw_icon', 260, icon_post( 260 ), false );
};
easy_svg_stored_icons();
check( 'BELL: an icon added while another request was reading is not lost to its stale list', in_array( 260, array_column( easy_svg_stored_icons(), 'id' ), true ) );
array_pop( $GLOBALS['icon_posts'] );
fire( 'deleted_post', 260, icon_post( 260 ) );

// Whatever else might sit under the key is a miss, not a list.
$GLOBALS['transients'][ $cache_keys[0] ?? 'x' ] = 'garbage';
check( 'SILENCE: a cache entry that is not a list is read again, not trusted', 250 === count( easy_svg_stored_icons() ) );

// ─── A failed save is not "Icon added." ──────────────────────────────────────

/*
 * The handler said "Icon added." whatever wp_insert_post() answered. A full
 * disk, a read-only replica, a plugin vetoing the insert -- each showed a green
 * notice above a table without the icon.
 */
$good_svg = '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>';

$GLOBALS['inserted']       = [];
$GLOBALS['insert_returns'] = 42;
check( 'SILENCE: a saved icon is reported as added', 'added' === easy_svg_add_icon( 'Arrow', $good_svg ) );
check( 'SILENCE: and was written once', 1 === count( $GLOBALS['inserted'] ) );
check( 'BELL: asking for a WP_Error, so a failure says why instead of 0', true === ( $GLOBALS['inserted'][0]['wp_error'] ?? false ) );
check( 'BELL: what is written is the cleaned markup', false !== strpos( (string) ( $GLOBALS['inserted'][0]['post']['post_content'] ?? '' ), '<path' ) );

$GLOBALS['insert_returns'] = new WP_Error( 'db_insert_error', 'Could not insert post into the database.' );
check( 'BELL: a WP_Error from the insert is not reported as added', 'not_saved' === easy_svg_add_icon( 'Arrow', $good_svg ) );

$GLOBALS['insert_returns'] = 0;
check( 'BELL: neither is a 0', 'not_saved' === easy_svg_add_icon( 'Arrow', $good_svg ) );

$GLOBALS['inserted']       = [];
$GLOBALS['insert_returns'] = 42;
check( 'SILENCE: a file that is not an SVG is refused before anything is written', 'not_svg' === easy_svg_add_icon( 'Arrow', 'not markup at all <' ) );
check( 'SILENCE: nothing was written for it', [] === $GLOBALS['inserted'] );

// ─── An icon survives the save, whoever saves it ─────────────────────────────

/*
 * wp_insert_post() runs content through wp_filter_post_kses for anybody
 * without unfiltered_html -- every administrator of a multisite sub-site, and
 * every site with DISALLOW_UNFILTERED_HTML -- and kses knows no SVG. The icon
 * was stored empty and the screen said "Icon added.". It also unslashes, so a
 * backslash in the markup was lost on every site.
 */
$GLOBALS['kses_on']        = true;
$GLOBALS['inserted']       = [];
$GLOBALS['insert_returns'] = 77;
$slashed_svg = '<svg xmlns="http://www.w3.org/2000/svg"><text>a\\b</text><path d="M0 0"/></svg>';
$state_k     = easy_svg_add_icon( 'Kses', $slashed_svg );
$stored_k    = (string) ( get_post( 77 )->post_content ?? '' );

check( 'BELL: a user without unfiltered_html can add an icon that is not empty', 'added' === $state_k && false !== strpos( $stored_k, '<path' ) );
check( 'BELL: kses is off for exactly that insert', false === ( $GLOBALS['inserted'][0]['kses'] ?? true ) );
check( 'BELL: and on again afterwards', true === $GLOBALS['kses_on'] );
check( 'BELL: a backslash in the markup survives the save', false !== strpos( $stored_k, 'a\\b' ) );

$GLOBALS['insert_returns'] = new WP_Error( 'x', 'y' );
easy_svg_add_icon( 'Kses', $slashed_svg );
check( 'SILENCE: kses comes back on after a failed insert too', true === $GLOBALS['kses_on'] );

$GLOBALS['kses_on']        = false;
$GLOBALS['insert_returns'] = 78;
easy_svg_add_icon( 'Plain', $slashed_svg );
check( 'SILENCE: where kses was off it stays off', false === $GLOBALS['kses_on'] );

/*
 * Whatever else empties the content on the way -- another plugin's filter --
 * the stored result is checked, and an empty icon is neither kept nor
 * reported as added. Simulated by kses that cannot be switched off.
 */
$GLOBALS['kses_on']        = true;
$GLOBALS['kses_stuck']     = true;
$GLOBALS['insert_returns'] = 79;
$GLOBALS['deleted_posts']  = [];
$state_e = easy_svg_add_icon( 'Empty', $slashed_svg );
unset( $GLOBALS['kses_stuck'] );
check( 'BELL: an icon stored empty is reported as not saved', 'not_saved' === $state_e );
check( 'BELL: and the empty post is removed again', in_array( 79, $GLOBALS['deleted_posts'], true ) );
$GLOBALS['kses_on']        = false;
$GLOBALS['insert_returns'] = 42;

// ─── A sanitiser that throws is a refusal, not a crash ───────────────────────

/*
 * The library throws a LogicException for well-formed XML that has no single
 * <svg> root -- an HTML page or an XML export saved as .svg. Uncaught, that was
 * a fatal error: a white screen on the icon form, and a broken upload.
 * Guarded, so a throw is a FAIL line here rather than a dead suite.
 */
$not_an_svg = '<?xml version="1.0"?><html><p>not a drawing</p></html>';

try {
	$state = easy_svg_add_icon( 'Arrow', $not_an_svg );
} catch ( \Throwable $e ) {
	$state = 'threw ' . get_class( $e );
}
check( "BELL: an XML file with no svg root is refused as not an SVG ({$state})", 'not_svg' === $state );

$file = file_array( $not_an_svg );
try {
	$after = $callback( $file );
} catch ( \Throwable $e ) {
	$after = [ 'threw' => get_class( $e ) ];
}
unlink( $file['tmp_name'] );
check( 'BELL: and the same file through the media uploader is an upload error, not a fatal', isset( $after['error'] ) && ! isset( $after['threw'] ) );

// ─── Every refusal has a sentence of its own ──────────────────────────────────

/*
 * A refused add reaches the person as the REST error the Library tab prints, so
 * `easy_svg_panel_add_message()` is now the only place a refusal gets words.
 * The states come from `easy_svg_accept_icon()` and `easy_svg_add_icon()`, so
 * the two lists are checked against each other rather than a hand-written copy
 * of one of them.
 *
 * Compared against the GENERIC sentence, not against "". The function has a
 * `default` branch, so "says something" would pass for a state that silently
 * falls through to it -- a person told only that it did not work, which is true,
 * useless, and indistinguishable from a bug. A new state with no case of its own
 * has to fail here rather than ship as a shrug.
 *
 * No 'limit_reached' in this list, because there is no limit: that one is
 * asserted as a shape against the shipped source below, not as a missing string.
 */
$generic_refusal = function_exists( 'easy_svg_panel_add_message' ) ? easy_svg_panel_add_message( 'nonsense' ) : '';
check( 'the panel has a sentence for a state it does not know', '' !== $generic_refusal );
foreach ( array( 'bad_name', 'empty', 'not_svg', 'no_sanitizer', 'not_saved', 'too_large', 'too_complex' ) as $state ) {
	check(
		"BELL: the '{$state}' refusal says what went wrong, not merely that something did",
		'' !== $generic_refusal && $generic_refusal !== easy_svg_panel_add_message( $state )
	);
}

// ─── Nothing in this plugin is for sale ──────────────────────────────────────

/*
 * WordPress.org guideline 5: a hosted plugin may not hold back functionality
 * until somebody pays. The free plugin is unlimited, and a paid add-on may only
 * sell code that lives in the add-on.
 *
 * Asserted against the SHIPPED source as a shape, because the way back in is
 * never the same spelling twice: a constant, a filter, a state, or a comment
 * telling a reviewer which product lifts what.
 */
$shipped = '';
foreach ( array_merge( array( $root . '/easy-svg.php' ), glob( $root . '/includes/*.php' ) ?: array() ) as $shipped_file ) {
	$shipped .= (string) file_get_contents( $shipped_file );
}
check( 'SILENCE: the shipped source was read', false !== strpos( $shipped, 'easy_svg_accept_icon' ) );
foreach ( array( 'easy_svg_icon_limit', 'EASY_SVG_ICON_LIMIT', 'limit_reached', 'PHP_INT_MAX', 'icon_may_add' ) as $needle ) {
	check( "BELL: the shipped code carries no '{$needle}'", false === strpos( $shipped, $needle ) );
}

// ─── And the icon-add path is bounded ────────────────────────────────────────

/*
 * The gate reads 0 and null as "no bound" -- right for a CLI import of a site's
 * own files, wrong for a request -- so the WIRING is what can regress in
 * silence: the gate would pass every check in tests/icons.php while the panel's
 * REST add went back to handing the sanitiser's DOM parser whatever arrived.
 * Asserted against the one shipped file that stores an uploaded icon, which has
 * no other reason to name either bound.
 */
$manager = (string) file_get_contents( $root . '/includes/icon-manager.php' );
check( 'BELL: the shipped icon-add asks the gate for the size bound', false !== strpos( $manager, 'easy_svg_max_bytes()' ) );
check( 'BELL: and for the complexity bound', false !== strpos( $manager, "'easy_svg_svg_too_complex'" ) );
// The Icons panel (includes/panel.php) is the one intended Pro touchpoint: it
// detects Pro, draws the locked Pro tabs and the upsell. That is allowed and is
// the product's design. Everything ELSE free ships must stay free of paid cruft
// -- no "lifts the limit" teasing in the upload or icon code.
$shipped_functional = '';
foreach ( array_merge( array( $root . '/easy-svg.php' ), glob( $root . '/includes/*.php' ) ?: array() ) as $f ) {
	if ( 'panel.php' === basename( $f ) ) {
		continue;
	}
	$shipped_functional .= (string) file_get_contents( $f );
}
check(
	'BELL: and no comment pointing at a paid product (outside the Icons panel)',
	1 !== preg_match( '/easy svg pro|\bpro\b|\bpaid\b|\bpaying\b|premium|lifts? the (cap|limit)/i', $shipped_functional )
);

// ─── The contract an add-on may rely on ──────────────────────────────────────

/*
 * One documented function, and a number that says what shape it has.
 *
 * An add-on that reached for `esw_svg_tags` by name would pin every rename in
 * this file, and the breakage would be silent: `class_exists()` goes false, the
 * add-on decides this plugin is not installed, and it tells a paying customer
 * to activate something that is already active.
 */
check( 'BELL: the API version is declared', defined( 'EASY_SVG_API' ) && is_int( EASY_SVG_API ) );

/*
 * The number and the surface must move together.
 *
 * An add-on decides what it may call by comparing this integer. 3 is the
 * version WITHOUT the icon limit filter that 2 introduced: an add-on that
 * checks for 3 knows the filter is gone and must not build on it.
 */
check( 'BELL: at API 3 nothing answers to the icon limit filter', EASY_SVG_API < 3 || ! function_exists( 'easy_svg_icon_limit' ) );
check( 'BELL: and the icon feature is still there, unlimited', function_exists( 'easy_svg_accept_icon' ) );

/*
 * Pinned to today's value, on purpose.
 *
 * A number claiming MORE than exists cannot be caught by asking what exists --
 * there is nothing to look for. So the number is pinned instead, and raising it
 * means changing this line: whoever does has to say, here, what surface the new
 * number covers. Add-ons in other repositories compare against it, and they
 * cannot be asked from here.
 */
check( 'BELL: the API is 3 (raise this line WITH the surface it covers)', 3 === EASY_SVG_API );

// Documented where an add-on author looks, not only in the source.
$readme_text = (string) file_get_contents( $root . '/readme.txt' );
check( 'the API number is documented for add-on authors', false !== strpos( $readme_text, 'EASY_SVG_API' ) );
check( 'BELL: with the value it has today', 1 === preg_match( '/EASY_SVG_API`? is (an integer.*?It is )?3\b/s', $readme_text ) );
// The cap never shipped. A readme explaining its removal would document,
// to 40,000 sites, a feature they never had.
check( 'SILENCE: the readme does not mention the cap that never shipped', false === strpos( $readme_text, 'easy_svg_icon_limit' ) && false === stripos( $readme_text, 'capped' ) && 1 !== preg_match( '/\bAPI 2\b|that 2 introduced/', $readme_text ) );

/*
 * wordpress.org merges sections it does not know into the Description, so the
 * add-on notes live under the FAQ, a section it renders on its own.
 */
check( 'BELL: no custom readme section for add-on authors', false === strpos( $readme_text, '== For add-on authors ==' ) );
$faq = (string) substr( $readme_text, (int) strpos( $readme_text, '== Frequently Asked Questions ==' ), (int) strpos( $readme_text, '== Screenshots ==' ) - (int) strpos( $readme_text, '== Frequently Asked Questions ==' ) );
check( 'BELL: the add-on notes are in the FAQ', false !== strpos( $faq, 'easy_svg_sanitizer()' ) && false !== strpos( $faq, 'EASY_SVG_API' ) );
check( 'SILENCE: "Then you can", not "Than you can"', false === strpos( $readme_text, 'Than you can' ) );
// Support runs through the wordpress.org forum only; a personal address on a
// page 40,000 sites read is one nobody can hand over or rotate.
check( 'BELL: the readme points to the support forum', false !== strpos( $readme_text, 'https://wordpress.org/support/plugin/easy-svg/' ) );
check( 'SILENCE: and names no e-mail address', 1 !== preg_match( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $readme_text ) );
check( 'SILENCE: the free plugin page advertises no paid product', 1 !== preg_match( '/\bPro\b|premium|upgrade to/i', $readme_text ) );
check( 'SILENCE: the old typos stay gone', false === stripos( $readme_text, 'Libary' ) && false === stripos( $readme_text, 'direcly' ) && false === stripos( $readme_text, 'persons' ) );
check( 'BELL: the readme explains why an SVG needs sanitizing', false !== strpos( $readme_text, 'It is XML' ) && false !== strpos( $readme_text, 'enshrined/svg-sanitize' ) );
check( 'BELL: the short description fits the 150 characters wordpress.org shows', ( static function ( $text ) {
	$blocks = explode( "\n\n", str_replace( "\r\n", "\n", $text ) );
	return isset( $blocks[1] ) && '' !== trim( $blocks[1] ) && mb_strlen( trim( $blocks[1] ) ) <= 150;
} )( $readme_text ) );
check( 'BELL: the sanitiser is reachable by function', function_exists( 'easy_svg_sanitizer' ) );
check( 'BELL: and it returns a sanitiser', easy_svg_sanitizer() instanceof \enshrined\svgSanitize\Sanitizer );

// Two callers must not share one object. The old load-time global was
// reconfigured on every upload, so whichever ran last decided what the other
// one stripped.
check( 'SILENCE: each call gets its own', easy_svg_sanitizer() !== easy_svg_sanitizer() );

// ─── External references are removed unless a site asks to keep them ────────

/*
 * An SVG that loads something from another server makes every visitor's
 * browser ask that server -- a tracking pixel, a stylesheet that changes the
 * page. Removed by default on every path that sanitises; a filter keeps them
 * for a site that needs them.
 */
$external = '<svg xmlns="http://www.w3.org/2000/svg">'
	. '<style>@import url(https://elsewhere.invalid/a.css);</style>'
	. '<image href="https://elsewhere.invalid/p.png" width="1" height="1"/>'
	. '<rect width="9" height="9"/></svg>';

$ext_out = (string) easy_svg_sanitizer()->sanitize( $external );
check( 'BELL: external references are removed by default', '' !== $ext_out && false === strpos( $ext_out, 'elsewhere.invalid' ) );
check( 'SILENCE: and the drawing stays', false !== strpos( $ext_out, '<rect' ) );

$file = file_array( $external, 'ext.svg' );
$callback( $file );
$ext_stored = (string) file_get_contents( $file['tmp_name'] );
unlink( $file['tmp_name'] );
check( 'BELL: on upload too', false === strpos( $ext_stored, 'elsewhere.invalid' ) && false !== strpos( $ext_stored, '<rect' ) );
check( 'BELL: and an icon keeps none either', 'ok' === easy_svg_accept_icon( 'Ext', $external, array( easy_svg_sanitizer(), 'sanitize' ) )['state'] && false === strpos( easy_svg_accept_icon( 'Ext', $external, array( easy_svg_sanitizer(), 'sanitize' ) )['content'], 'elsewhere.invalid' ) );

add_filter( 'esw_svg_remove_remote_references', '__return_false' );
$kept_ext = (string) easy_svg_sanitizer()->sanitize( $external );
check( 'BELL: a site that returns false from esw_svg_remove_remote_references keeps them', false !== strpos( $kept_ext, 'elsewhere.invalid/p.png' ) );
array_pop( $GLOBALS['hooks']['esw_svg_remove_remote_references'] );
$readme_now = (string) file_get_contents( $root . '/readme.txt' );
check( 'BELL: the changelog says external references are now removed', false !== strpos( $readme_now, 'External references in uploaded SVGs are now removed by default; filter `esw_svg_remove_remote_references` restores the old behaviour.' ) );
check( 'SILENCE: and the developer notes show how to keep them', 2 <= substr_count( $readme_now, 'esw_svg_remove_remote_references' ) );
check( 'SILENCE: and without the filter they go again', false === strpos( (string) easy_svg_sanitizer()->sanitize( $external ), 'elsewhere.invalid' ) );

// ─── The bundled sanitiser is not a known-vulnerable one ─────────────────────

/*
 * svg-sanitize 0.22.0 is affected by four advisories fixed only in 1.0.0. The
 * two worth proving at the bytes, with payloads from the advisories:
 *
 * GHSA-9rjx-3jch-6vjf: an entity named like an HTML5 character reference.
 * In XML `&Tab;` expands to "#", so the href looked like a fragment and
 * passed; saveXML() kept the REFERENCE and dropped the DTD, and a browser
 * reading it inline resolves `&Tab;` to a tab and runs `javascript:`.
 */
$entity_href = '<!DOCTYPE svg [<!ENTITY Tab "#">]>'
	. '<svg xmlns="http://www.w3.org/2000/svg"><a href="&Tab;javascript:alert(document.domain)"><rect width="9" height="9"/></a></svg>';
$entity_out = easy_svg_sanitizer()->sanitize( $entity_href );
check(
	'BELL: GHSA-9rjx-3jch-6vjf -- an entity-smuggled javascript: href does not survive',
	false === $entity_out || ( false === stripos( (string) $entity_out, 'javascript:' ) && false === strpos( (string) $entity_out, '&Tab;' ) )
);

$file = file_array( $entity_href );
$after_entity = $callback( $file );
$entity_bytes = (string) file_get_contents( $file['tmp_name'] );
unlink( $file['tmp_name'] );
check( 'BELL: and an upload carrying it is refused or cleaned, never stored as it came', isset( $after_entity['error'] ) || false === stripos( $entity_bytes, 'javascript:' ) );

// The same trick smuggling markup instead of a URL.
$entity_script = '<!DOCTYPE svg [<!ENTITY s "<script>alert(1)</script>">]><svg xmlns="http://www.w3.org/2000/svg">&s;<rect width="1"/></svg>';
$entity_script_out = (string) easy_svg_sanitizer()->sanitize( $entity_script );
check( 'BELL: a DTD entity carrying a script element yields no script', false === stripos( $entity_script_out, '<script' ) && false === strpos( $entity_script_out, '&s;' ) );

/*
 * GHSA-m9xh-6747-9r6f: the <use> nesting-bomb check selected `xlink:href`
 * case-sensitively, and a later step rewrote `xlink:HrEf` to `xlink:href` --
 * handing back a live bomb it would have defused in canonical spelling. The
 * mixed-case input must come out exactly as defused as the canonical one.
 */
$use_bomb = static function ( string $attr ): string {
	$svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><defs><path id="l0" d="M0 0h1"/>';
	for ( $level = 1; $level <= 6; $level++ ) {
		$svg .= '<g id="l' . $level . '">' . str_repeat( '<use ' . $attr . '="#l' . ( $level - 1 ) . '"/>', 8 ) . '</g>';
	}
	return $svg . '</defs><use ' . $attr . '="#l6"/></svg>';
};
$uses_canonical = substr_count( (string) easy_svg_sanitizer()->sanitize( $use_bomb( 'xlink:href' ) ), '<use' );
$uses_mixed     = substr_count( (string) easy_svg_sanitizer()->sanitize( $use_bomb( 'xlink:HrEf' ) ), '<use' );
check( "BELL: GHSA-m9xh-6747-9r6f -- mixed-case xlink:HrEf is defused like xlink:href ({$uses_mixed} vs {$uses_canonical} <use> left)", $uses_mixed === $uses_canonical );

// The fix must not cost ordinary files: an editor export with a PUBLIC DOCTYPE
// and no custom entities is still cleaned, not refused.
$public_doctype = '<?xml version="1.0"?><!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">'
	. '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1"/></svg>';
check( 'SILENCE: an export with a PUBLIC DOCTYPE still sanitises', false !== strpos( (string) easy_svg_sanitizer()->sanitize( $public_doctype ), '<rect' ) );

/*
 * GHSA-v383-3rw5-q8rf: a DTD attribute declaration made the library remove an
 * attribute twice, and the second removal crashed the PHP process itself --
 * nothing a try/catch could see. Run in a child process for that reason: a
 * crash is a FAIL line here, not a dead suite.
 */
$dtd_attr = '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE svg [<!ATTLIST svg badhref CDATA #FIXED "javascript:x">]>'
	. '<svg xmlns="http://www.w3.org/2000/svg" badhref="javascript:x"><rect width="1" height="1"/></svg>';
$child = 'require ' . var_export( $root . '/vendor/autoload.php', true ) . ';'
	. '$s = new \\enshrined\\svgSanitize\\Sanitizer();'
	. 'try { $r = $s->sanitize( base64_decode( $argv[1] ) ); } catch ( \\Throwable $e ) { $r = false; }'
	. 'echo false === $r ? "refused" : ( false === stripos( (string) $r, "javascript:" ) ? "clean" : "dirty" );';
$child_out    = array();
$child_status = 1;
exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $child ) . ' ' . escapeshellarg( base64_encode( $dtd_attr ) ) . ' 2>&1', $child_out, $child_status );
$child_said = implode( ' ', $child_out );
check( "BELL: GHSA-v383-3rw5-q8rf -- a DTD attribute declaration neither crashes nor survives ({$child_status}: {$child_said})", 0 === $child_status && in_array( $child_said, array( 'refused', 'clean' ), true ) );

/*
 * GHSA-qhmf-972w-m957: with removeRemoteReferences(true) -- which a site can
 * switch on through the 4.1 global -- remote references in <style>, in a bare
 * href and in an unquoted url() were kept.
 */
$remote = '<svg xmlns="http://www.w3.org/2000/svg">'
	. '<style>@import url(https://tracker.invalid/a.css); rect { fill: url(https://tracker.invalid/b); }</style>'
	. '<image href="https://tracker.invalid/pixel.png" width="1" height="1"/>'
	. '<rect width="9" height="9" style="fill:url(https://tracker.invalid/c)"/>'
	. '</svg>';
$no_remote = easy_svg_sanitizer();
$no_remote->removeRemoteReferences( true );
$remote_out = (string) $no_remote->sanitize( $remote );
check( 'BELL: GHSA-qhmf-972w-m957 -- with remote references off, none survive in style, href or url()', '' !== $remote_out && false === strpos( $remote_out, 'tracker.invalid' ) );
check( 'SILENCE: and the drawing is still there', false !== strpos( $remote_out, '<rect' ) );

/*
 * Composer classes are global. If another plugin bundles an older copy of this
 * library and its autoloader runs first, its Sanitizer is the one every
 * upload here uses -- with every advisory above unfixed, and nothing on screen
 * saying so. `removeDoctype` exists only from 1.0.0 on: it is the fix itself.
 */
check( 'BELL: the loaded sanitiser is recognised as current', easy_svg_sanitizer_is_current() );
check( 'SILENCE: and then there is nothing to warn about', '' === easy_svg_outdated_sanitizer_message() );
eval( 'namespace OldCopy; class Sanitizer { public function sanitize( $s ) { return $s; } }' );
check( 'BELL: an older copy without the 1.0.0 fix is recognised as outdated', ! easy_svg_sanitizer_is_current( '\\OldCopy\\Sanitizer' ) );
check( 'BELL: and produces a warning for administrators', '' !== easy_svg_outdated_sanitizer_message( '\\OldCopy\\Sanitizer' ) );
check( 'SILENCE: the warning is wired to the admin', in_array( 'easy_svg_outdated_sanitizer_notice', $GLOBALS['hooks']['admin_notices'] ?? [], true ) );

// Pinned, so a downgrade of vendor/ -- a stale checkout, a bad merge -- fails
// here instead of shipping the vulnerable library again.
$installed = (array) @include $root . '/vendor/composer/installed.php';
$bundled   = (string) ( $installed['versions']['enshrined/svg-sanitize']['version'] ?? '' );
check( "BELL: the bundled svg-sanitize is 1.0 or newer ({$bundled})", '' !== $bundled && version_compare( $bundled, '1.0.0', '>=' ) );

// ─── The 4.1 global, still honoured ──────────────────────────────────────────

/*
 * 4.1 created `$sanitizer` at load and sanitised every upload with it, so a
 * site snippet could write
 *
 *     global $sanitizer;
 *     $sanitizer->removeRemoteReferences( true );
 *
 * 4.2 dropped the global. Such a snippet then either died on a method call on
 * null, or -- if it created its own -- was silently ignored. Both are a
 * regression on a site that did nothing but update.
 */
$legacy = $GLOBALS['sanitizer'] ?? null;
check( 'BELL: the 4.1 global exists after load', $legacy instanceof \enshrined\svgSanitize\Sanitizer );

$plain_svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1"/></svg>';

if ( $legacy instanceof \enshrined\svgSanitize\Sanitizer ) {
	$tags_before = $legacy->getAllowedTags();

	// What a 4.1-era snippet does: configure the global.
	$legacy->removeXMLTag( true );

	check( 'BELL: a snippet configuring the global reaches easy_svg_sanitizer()', false === strpos( (string) easy_svg_sanitizer()->sanitize( $plain_svg ), '<?xml' ) );

	$file = file_array( $plain_svg );
	$callback( $file );
	$uploaded = (string) file_get_contents( $file['tmp_name'] );
	unlink( $file['tmp_name'] );
	check( 'BELL: and reaches the upload, as it did in 4.1', false === strpos( $uploaded, '<?xml' ) && false !== strpos( $uploaded, '<rect' ) );

	// Still one object per caller: the global is a template, never handed out.
	check( 'SILENCE: callers get a copy, not the global itself', easy_svg_sanitizer() !== $legacy );
	check( 'SILENCE: and the global is not reconfigured by being used', $tags_before === $legacy->getAllowedTags() );

	$legacy->removeXMLTag( false );
}

// A site where the global is gone, or holds something else entirely.
unset( $GLOBALS['sanitizer'] );
check( 'SILENCE: with the global absent there is still a sanitiser', easy_svg_sanitizer() instanceof \enshrined\svgSanitize\Sanitizer );

$GLOBALS['sanitizer'] = new stdClass();
try {
	$foreign = easy_svg_sanitizer();
} catch ( \Throwable $e ) {
	$foreign = null;
}
check( 'BELL: another plugin\'s $sanitizer is ignored, not called', $foreign instanceof \enshrined\svgSanitize\Sanitizer );

$GLOBALS['sanitizer'] = $legacy;

/*
 * The prefix rule asks for no unprefixed globals, and the autoloader path was
 * one. The 4.1 `$sanitizer` is the deliberate exception above.
 */
check( 'SILENCE: no unprefixed $composer_package global', ! array_key_exists( 'composer_package', $GLOBALS ) );

/*
 * The upload path goes through that same function, proved at the BYTES.
 *
 * A site widens the allow-list through `esw_svg_allowed_tags`, and this asserts
 * the widening REACHES the upload. If the upload were ever wired to the
 * library defaults instead, the add-on and the uploader would disagree about
 * this site while both looked correct on their own.
 */
add_filter(
	'esw_svg_allowed_tags',
	static function ( $tags ) {
		$tags[] = 'script';
		return $tags;
	}
);

$file = file_array( $scripted );
$callback( $file );
$kept = (string) file_get_contents( $file['tmp_name'] );
unlink( $file['tmp_name'] );

check( 'BELL: a site that allows a tag keeps it on upload', false !== strpos( $kept, '<script' ) );

// And the add-on sees the same site.
$widened = easy_svg_sanitizer();
$out     = $widened->sanitize( $scripted );
check( 'BELL: and an add-on asking through the API sees the same allow-list', false !== strpos( (string) $out, '<script' ) );

/*
 * The same for attributes, and it is not a duplicate: a probe showed that
 * dropping `setAllowedAttrs()` from the API left every other check green. Tags
 * and attributes are two allow-lists and two calls, so they need two proofs.
 */
add_filter(
	'esw_svg_allowed_attributes',
	static function ( $attrs ) {
		$attrs[] = 'onload';
		return $attrs;
	}
);

$handler = '<svg xmlns="http://www.w3.org/2000/svg"><rect onload="x()" width="1"/></svg>';

$file = file_array( $handler );
$callback( $file );
$kept_attr = (string) file_get_contents( $file['tmp_name'] );
unlink( $file['tmp_name'] );

check( 'BELL: a site that allows an attribute keeps it on upload', false !== strpos( $kept_attr, 'onload' ) );
check(
	'BELL: and the API hands an add-on that same attribute list',
	false !== strpos( (string) easy_svg_sanitizer()->sanitize( $handler ), 'onload' )
);

// ─── The stored hardener strips what no icon may carry (defence in depth) ─────

/*
 * There used to be a block here about the admin PREVIEW: the classic icons
 * screen printed stored markup into the page, so it ran every icon through
 * wp_kses with the site's own SVG allow-list minus everything executable. A
 * site that widens that allow-list -- as the filters just above do, to `script`
 * and `onload` -- stores icons that would otherwise have run in an
 * administrator's browser the moment the screen opened.
 *
 * That screen and those helpers are gone with the slug collision. The DEFENCE
 * did not go with them; it sits on both sides of the removed code:
 *
 *   - on the way out, the panel's Library tab runs every preview through
 *     DOMPurify's SVG profile before it reaches innerHTML, in the browser
 *     (src/panel/tabs/Library.jsx -- the only place stored markup is drawn now);
 *   - on the way in, the markup is hardened before it is ever stored, which is
 *     the layer checked below -- and the one that also protects visitors,
 *     because the Icon block inlines it into their pages too.
 *
 * So the check that matters is the hardener, not a preview.
 */
// easy_svg_harden_icon_markup() produces the markup the Icon block inlines for
// visitors. On a widened allow-list the sanitiser can pass an on* handler
// through to it, so the hardener drops event handlers itself -- the one layer
// every reader of an icon, administrator or visitor, sits behind.
$hardened = easy_svg_harden_icon_markup( '<svg xmlns="http://www.w3.org/2000/svg"><rect onload="alert(1)" onclick="x()" width="24" height="24"/><path d="M0 0h9"/></svg>' );
check( 'BELL: the hardener drops an on* event handler', false === stripos( $hardened, 'onload' ) && false === stripos( $hardened, 'onclick' ) );
check( 'SILENCE: and keeps the drawing', false !== strpos( $hardened, '<rect' ) && false !== strpos( $hardened, '<path' ) );

// ─── The two places a version is written ─────────────────────────────────────

/*
 * wordpress.org serves whatever `Stable tag` names, and the plugin header is
 * what a site compares against to decide it needs an update. Let them drift and
 * the directory serves one version while every install believes it has another
 * -- silently, and in the direction where the update never arrives.
 */
$readme = (string) file_get_contents( $root . '/readme.txt' );

preg_match( '/^\s*\*?\s*Version:\s*(\S+)/mi', (string) file_get_contents( $root . '/easy-svg.php' ), $header_v );
preg_match( '/^Stable tag:\s*(\S+)/mi', $readme, $stable_v );

check( 'the plugin header names a version', isset( $header_v[1] ) );
check( 'the readme names a stable tag', isset( $stable_v[1] ) );
check(
	'BELL: and they are the same: ' . ( $header_v[1] ?? '?' ) . ' vs ' . ( $stable_v[1] ?? '?' ),
	isset( $header_v[1], $stable_v[1] ) && $header_v[1] === $stable_v[1]
);
check( 'the changelog mentions it', false !== strpos( $readme, '= ' . ( $header_v[1] ?? 'x' ) ) );

// ─── What wordpress.org shows ────────────────────────────────────────────────

/*
 * A "Tested up to" below the current WordPress hides the plugin from search on
 * wordpress.org; Plugin Check reports it as an error. And the upgrade notice is
 * the one line a site owner reads before clicking update.
 */
check( 'BELL: tested up to the WordPress the icon feature needs', 1 === preg_match( '/^Tested up to:\s*7\.1\s*$/mi', $readme ) );
// The panel enqueues the `react-jsx-runtime` script handle, which WordPress
// first registered in 6.6. Claiming to run on less gives the lower half of the
// range a blank Icons screen, so the floor must be 6.6+ and the two headers
// must agree.
preg_match( '/Requires at least:\s*([\d.]+)/i', (string) file_get_contents( $root . '/easy-svg.php' ), $hmin );
preg_match( '/Requires at least:\s*([\d.]+)/i', $readme, $rmin );
check( 'BELL: requires WordPress 6.6+ (the panel needs react-jsx-runtime)', version_compare( $hmin[1] ?? '0', '6.6', '>=' ) );
check( 'BELL: and the readme agrees with the header', ( $hmin[1] ?? 'h' ) === ( $rmin[1] ?? 'r' ) );
$upgrade_notice = (string) substr( $readme, (int) strpos( $readme, '== Upgrade Notice ==' ) );
check( 'BELL: there is an upgrade notice for this version', false !== strpos( $readme, '== Upgrade Notice ==' ) && false !== strpos( $upgrade_notice, '= ' . ( $header_v[1] ?? 'x' ) . ' =' ) );
check( 'BELL: it tells a site owner that sideloaded SVGs are now sanitised', false !== stripos( $upgrade_notice, 'wp media import' ) );
check( 'SILENCE: and no longer talks about WordPress 4', false === strpos( $upgrade_notice, '4.0 to 4.9' ) );
$changelog_43 = (string) substr( $readme, (int) strpos( $readme, '== Changelog ==' ), 4000 );
foreach ( array( 'GHSA-9rjx-3jch-6vjf', 'GHSA-m9xh-6747-9r6f', 'GHSA-v383-3rw5-q8rf', 'GHSA-qhmf-972w-m957' ) as $advisory ) {
	check( "BELL: the changelog names {$advisory}", false !== strpos( $changelog_43, $advisory ) );
}
check( 'BELL: the upgrade notice names the advisories in words a site owner reads', false !== strpos( $upgrade_notice, 'four published security advisories in the bundled sanitizer' ) );
check( 'BELL: and warns that SVGs with custom DTD entities are now refused', false !== strpos( $upgrade_notice, 'DTD entities' ) );
check( 'SILENCE: the upgrade notice fits the 300 characters wordpress.org shows', mb_strlen( trim( (string) substr( $upgrade_notice, (int) strpos( $upgrade_notice, '= 5.0.0 =' ) + 9 ) ) ) <= 300 );
check( 'SILENCE: one changelog entry for this release, with the unreleased 4.2 folded in', false === strpos( $readme, '= 4.2 =' ) );
preg_match( '/^Tags:\s*(.+)$/mi', $readme, $tags_line );
check( 'SILENCE: at most five tags, as wordpress.org reads them', isset( $tags_line[1] ) && count( array_filter( array_map( 'trim', explode( ',', $tags_line[1] ) ) ) ) <= 5 );

// ─── Who it is from ──────────────────────────────────────────────────────────

/*
 * The header names the publisher; the readme names the wordpress.org account
 * that may commit, which must stay a real username. The readme header carries
 * only the fields wordpress.org reads -- anything else there is noise a
 * reviewer has to ask about.
 */
check( 'BELL: the plugin is published by UnleashWP', 1 === preg_match( '/^\s*Author:\s*UnleashWP\s*$/mi', $main_header_src = (string) file_get_contents( $root . '/easy-svg.php' ) ) );
check( 'SILENCE: with its site as Author URI', 1 === preg_match( '#^\s*Author URI:\s*https://www\.unleash-wp\.com\s*$#mi', $main_header_src ) );
check( 'SILENCE: the copyright line is unchanged', false !== strpos( $main_header_src, 'Copyright (C) 2017-2026 Benjamin Zekavica.' ) );
check( 'SILENCE: the contributor is still the wordpress.org account', 1 === preg_match( '/^Contributors:\s*Benjamin_Zekavica\s*$/mi', $readme ) );
$readme_head = (string) substr( $readme, 0, (int) strpos( $readme, "\n\n" ) );
foreach ( array_slice( explode( "\n", $readme_head ), 1 ) as $head_line ) {
	$field = trim( (string) strstr( $head_line, ':', true ) );
	check(
		"BELL: '{$field}' is a field the readme header may carry",
		in_array( $field, array( 'Contributors', 'Donate link', 'Tags', 'Requires at least', 'Tested up to', 'Requires PHP', 'Stable tag', 'License', 'License URI' ), true )
	);
}

// ─── One licence, said the same way everywhere ───────────────────────────────

/*
 * WordPress.org guideline 1: the plugin must be GPL-compatible and say so
 * consistently. The header said "GPL3" and then "All rights reserved", the
 * readme said "GPLv3" without "or later" while the header text said "any later
 * version", and license.txt was a summary rather than the licence.
 */
$main_header = (string) file_get_contents( $root . '/easy-svg.php' );
$licence     = (string) file_get_contents( $root . '/license.txt' );

check( 'BELL: the header names the licence by its SPDX id', 1 === preg_match( '/^\s*License:\s*GPL-3\.0-or-later\s*$/mi', $main_header ) );
check( 'BELL: and links to it', 1 === preg_match( '#^\s*License URI:\s*https://www\.gnu\.org/licenses/gpl-3\.0\.html\s*$#mi', $main_header ) );
check( 'BELL: and reserves no rights the licence gives away', false === stripos( $main_header, 'All rights reserved' ) );
check( 'BELL: the readme says the same licence', 1 === preg_match( '/^License:\s*GPLv3 or later\s*$/mi', $readme ) );
check( 'SILENCE: with the same link', 1 === preg_match( '#^License URI:\s*https://www\.gnu\.org/licenses/gpl-3\.0\.html\s*$#mi', $readme ) );
check( 'BELL: license.txt is the licence itself', false !== strpos( $licence, 'GNU GENERAL PUBLIC LICENSE' ) && false !== strpos( $licence, 'Version 3, 29 June 2007' ) );
check( 'SILENCE: all of it', false !== strpos( $licence, 'END OF TERMS AND CONDITIONS' ) );

// ─── What reaches wordpress.org ──────────────────────────────────────────────

/*
 * The deploy action copies the repository into SVN minus `.distignore`;
 * `git archive` builds a zip minus `export-ignore`. Two lists that must say
 * the same thing, or the zip somebody tests is not the release 40,000 sites
 * get. `.git` is the one entry only rsync needs: git archive never packs it.
 */
$read_list = static function ( string $file, bool $attributes ): array {
	$out = [];
	foreach ( (array) @file( $file, FILE_IGNORE_NEW_LINES ) as $line ) {
		$line = trim( (string) $line );
		if ( '' === $line || '#' === $line[0] ) {
			continue;
		}
		if ( $attributes ) {
			if ( 1 !== preg_match( '/^(\S+)\s+export-ignore\s*$/', $line, $m ) ) {
				continue;
			}
			$line = $m[1];
		}
		$out[] = ltrim( $line, '/' );
	}
	sort( $out );
	return $out;
};

$distignore = $read_list( $root . '/.distignore', false );
$exportign  = $read_list( $root . '/.gitattributes', true );

foreach ( array( '.git', '.github', 'tests', 'docs', 'composer.json', 'composer.lock', '.distignore', '.gitattributes', '.gitignore', 'README.md', '.DS_Store', 'vendor/enshrined/svg-sanitize/src/svg-scanner.php', 'vendor/enshrined/svg-sanitize/README.md', 'vendor/enshrined/svg-sanitize/CHANGELOG.md', 'vendor/enshrined/svg-sanitize/composer.json' ) as $kept_out ) {
	check( "BELL: .distignore keeps {$kept_out} out of the release", in_array( $kept_out, $distignore, true ) );
}
check(
	'BELL: and .gitattributes export-ignores exactly the same set',
	array_values( array_diff( $distignore, array( '.git' ) ) ) === $exportign
);
/*
 * The library's file layout changes between releases (1.0.0 added a
 * CHANGELOG.md). Every file of it outside src/ is either its LICENSE or listed
 * above, so an update that adds one fails here instead of shipping it.
 */
foreach ( glob( $root . '/vendor/enshrined/svg-sanitize/*' ) ?: array() as $lib_file ) {
	$rel = substr( $lib_file, strlen( $root ) + 1 );
	if ( is_dir( $lib_file ) || 'vendor/enshrined/svg-sanitize/LICENSE' === $rel ) {
		continue;
	}
	check( "BELL: the library's {$rel} is kept out of the release", in_array( $rel, $distignore, true ) );
}
foreach ( array( 'easy-svg.php', 'uninstall.php', 'includes', 'vendor', 'languages', 'readme.txt', 'license.txt', 'index.php', 'vendor/enshrined/svg-sanitize/LICENSE' ) as $shipped_path ) {
	check( "SILENCE: {$shipped_path} still ships", ! in_array( $shipped_path, $distignore, true ) );
}
$gitignore = (string) @file_get_contents( $root . '/.gitignore' );
check( 'SILENCE: composer.lock is tracked, so .gitignore does not claim to ignore it', 1 !== preg_match( '/^composer\.lock\s*$/m', $gitignore ) );
check( 'SILENCE: and .DS_Store is ignored', 1 === preg_match( '/^\.DS_Store\s*$/m', $gitignore ) );

// ─── The JS translations have to be delivered, not merely shipped ────────────

/*
 * Three separate things have to agree before a single translated word reaches
 * the panel, and every one of them fails silently -- the panel just renders in
 * English, which looks like "no translation exists yet" rather than a bug.
 *
 * 1. WordPress asks for a file named after md5() of the enqueued script's path
 *    RELATIVE TO THE PLUGIN (`build/panel.js`). A JSON under any other name is
 *    never opened. The hash is path-based, so rebuilding the bundle is safe --
 *    but renaming or moving the script silently orphans the catalogue.
 * 2. It looks for that file in WP_LANG_DIR/plugins and, since 6.7, in the
 *    textdomain registry -- never inside the plugin unless a path is passed as
 *    the third argument to wp_set_script_translations(). The floor here is 6.6.
 * 3. translate.wordpress.org names each JSON after the JS reference paths in the
 *    PO. `.distignore` keeps `/src` out of the release, so a POT generated from
 *    `src/*.jsx` produces pack filenames for files that do not exist in the zip,
 *    and WordPress asks for a name the pack never contains. The POT therefore
 *    has to be generated against the built bundle.
 */
$panel_src = 'build/panel.js';
check(
	'BELL: the shipped JS catalogue is named after md5() of the enqueued script path',
	is_readable( $root . '/languages/easy-svg-de_DE-' . md5( $panel_src ) . '.json' )
);
$panel_php = (string) @file_get_contents( $root . '/includes/panel.php' );
check(
	'BELL: and wp_set_script_translations is given a path, so the bundled catalogue is looked for inside the plugin',
	1 === preg_match( '/wp_set_script_translations\(\s*[^;]*languages/s', $panel_php )
);
$pot = (string) @file_get_contents( $root . '/languages/easy-svg.pot' );
check(
	'BELL: and the POT points its JS strings at the built bundle, which is what ships',
	false !== strpos( $pot, '#: ' . $panel_src ) && false === strpos( $pot, '#: src/' )
);

// ─── How a release leaves this repository ────────────────────────────────────

/*
 * A tag deploys to wordpress.org. The shape is checked here because the
 * mistakes are all quiet ones: a tag that names one version while the header
 * names another ships a release every site believes is something else; an
 * action pinned to a moving branch runs code nobody reviewed with our SVN
 * password in its environment.
 */
$deploy = (string) @file_get_contents( $root . '/.github/workflows/deploy.yml' );

check( 'BELL: there is a deploy workflow', '' !== $deploy );
check( 'BELL: it runs on a version tag, with no v prefix', false !== strpos( $deploy, "tags: ['[0-9]+.[0-9]+*']" ) );
check( 'BELL: the deploy action is pinned to a commit, not a moving branch', 1 === preg_match( '#uses:\s*10up/action-wordpress-plugin-deploy@[0-9a-f]{40}\b#', $deploy ) );
preg_match_all( '/^\s*(?:-\s*)?uses:\s*(\S+)/m', $deploy, $deploy_uses );
check( 'SILENCE: the deploy workflow uses actions at all', count( $deploy_uses[1] ) >= 3 );
foreach ( $deploy_uses[1] as $deploy_action ) {
	check( "BELL: {$deploy_action} is pinned to a commit", 1 === preg_match( '/@[0-9a-f]{40}$/', $deploy_action ) );
}
$ancestry_at   = strpos( $deploy, 'merge-base --is-ancestor' );
$deploy_action_at = strpos( $deploy, '10up/action-wordpress-plugin-deploy' );
check( 'BELL: only a commit that is on master can be deployed', false !== $ancestry_at && false !== $deploy_action_at && $ancestry_at < $deploy_action_at && 1 === preg_match( '/fetch-depth:\s*0/', $deploy ) );
check( 'BELL: the workflow token can only read', 1 === preg_match( '/^permissions:\s*\n\s+contents:\s*read\s*$/m', $deploy ) );
check( 'SILENCE: the slug is the one on wordpress.org', 1 === preg_match( '/SLUG:\s*easy-svg\s*$/m', $deploy ) );
check( 'SILENCE: the SVN credentials come from secrets', false !== strpos( $deploy, '${{ secrets.SVN_USERNAME }}' ) && false !== strpos( $deploy, '${{ secrets.SVN_PASSWORD }}' ) );
check( 'SILENCE: and the zip is generated', 1 === preg_match( '/generate-zip:\s*true/', $deploy ) );
$guard_at  = strpos( $deploy, 'Stable tag' );
$deploy_at = strpos( $deploy, '10up/action-wordpress-plugin-deploy' );
check( 'BELL: tag, header and Stable tag are compared BEFORE anything is deployed', false !== $guard_at && false !== $deploy_at && $guard_at < $deploy_at && false !== strpos( $deploy, 'GITHUB_REF_NAME' ) );
check( 'SILENCE: the release notes for maintainers stay out of the plugin', in_array( 'RELEASING.md', $distignore, true ) );

// ─── This plugin must never update itself ────────────────────────────────────

/*
 * wordpress.org serves the updates for anything hosted there, and a plugin in
 * the directory that also updates itself from somewhere else is rejected --
 * rightly, because it would be a way to ship code the review never saw.
 *
 * The paid add-on is where the licensed updater lives. Asserted here as a
 * SHAPE, because the next attempt will be spelled differently: an Update URI
 * header, a filter on the update transient, or a plugins_api hook.
 */
$source = (string) file_get_contents( $root . '/easy-svg.php' );

check( 'BELL: no Update URI header', 1 !== preg_match( '/^\s*\*?\s*Update URI:/mi', $source ) );
check( 'BELL: no filter on the plugin update transient', false === strpos( $source, 'site_transient_update_plugins' ) );
check( 'BELL: no plugins_api hook', false === strpos( $source, 'plugins_api' ) );
check( 'SILENCE: and the file was actually read', '' !== $source );

// ─── Where this repository is allowed to run ─────────────────────────────────

$runner_out    = array();
$runner_status = 1;
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/runner.php' ) . ' 2>&1', $runner_out, $runner_status );
check( 'the runner checks pass: ' . ( $runner_out[ count( $runner_out ) - 1 ] ?? 'no output' ), 0 === $runner_status );

// ─── The icon core, in its own process ───────────────────────────────────────

/*
 * Separate, because that file stubs `apply_filters` with a real hook registry
 * to prove the limit filter works -- and this file's stub deliberately does
 * something else.
 */
$icons_out    = array();
$icons_status = 1;
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/icons.php' ) . ' 2>&1', $icons_out, $icons_status );
check( 'the icon checks pass: ' . ( $icons_out[ count( $icons_out ) - 1 ] ?? 'no output' ), 0 === $icons_status );

// ─── On WordPress 7.1 ────────────────────────────────────────────────────────

/*
 * Everything above ran as an older WordPress, without the icon API. From here
 * on it exists: defined at RUNTIME inside a block, so the checks before this
 * point saw it missing. PHP cannot take a function away again, which is why
 * this section is last.
 */
$GLOBALS['registered_icons'] = [];
if ( ! function_exists( 'wp_register_icon' ) ) {
	function wp_register_icon_collection( $slug, $args ): bool {
		return 'easy-svg' === $slug;
	}
	function wp_register_icon( $name, $args ): bool {
		$GLOBALS['registered_icons'][] = $name;
		return true;
	}
}

check( 'the icon API now counts as present', easy_svg_icons_supported() );

// No menu assertion here any more: the Icons panel's menu entry does not depend
// on the icon API at all (it hosts the Settings and licence tabs too, which work
// on any supported WordPress), so there is nothing version-dependent left to
// check. What 7.1 gates is the STORE and the handover to core, below.
easy_svg_register_icon_store();
$store_args = $GLOBALS['post_types']['esw_icon'] ?? [];
$caps       = (array) ( $store_args['capabilities'] ?? [] );
check( 'on 7.1 the icon post type is registered', [] !== $store_args );
foreach ( array( 'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'delete_posts', 'publish_posts', 'read_private_posts', 'create_posts' ) as $cap ) {
	check( "BELL: '{$cap}' on icons needs edit_theme_options", 'edit_theme_options' === ( $caps[ $cap ] ?? null ) );
}
check( 'BELL: and no meta capability is mapped back to post-author rules', false === ( $store_args['map_meta_cap'] ?? null ) );

easy_svg_boot_icons();
check( 'BELL: all 250 icons are handed to core, not the first 200', 250 === count( $GLOBALS['registered_icons'] ) );
check( 'SILENCE: each under its own name', 250 === count( array_unique( $GLOBALS['registered_icons'] ) ) );

// ─── Uninstalling takes the icons with it ────────────────────────────────────

check( 'BELL: there is an uninstall.php', is_file( $root . '/uninstall.php' ) );

// Without WordPress uninstalling it, the file does nothing at all.
$un_out    = array();
$un_status = 1;
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $root . '/uninstall.php' ) . ' 2>&1', $un_out, $un_status );
check( 'BELL: opened directly, uninstall.php does nothing', 0 === $un_status && array() === $un_out );

if ( is_file( $root . '/uninstall.php' ) ) {
	$GLOBALS['blog_switches'] = [];
	$GLOBALS['is_multisite']  = true;
	function get_sites( array $args = [] ): array {
		return [ 1, 2 ];
	}
	function switch_to_blog( $id ): bool {
		$GLOBALS['blog_switches'][] = $id;
		return true;
	}
	function restore_current_blog(): bool {
		$GLOBALS['blog_switches'][] = 'restore';
		return true;
	}
	function delete_option( string $key ): bool {
		unset( $GLOBALS['options'][ $key ] );
		return true;
	}

	$GLOBALS['icon_posts'] = [];
	for ( $i = 1; $i <= 130; $i++ ) {
		$GLOBALS['icon_posts'][] = icon_post( 1000 + $i );
	}
	$GLOBALS['icon_posts'][]       = icon_post( 2001, 'page' );
	$GLOBALS['transients']['easy_svg_icons'] = [ 'version' => 1, 'icons' => [] ];
	$GLOBALS['options']['easy_svg_icons_version'] = 3;

	define( 'WP_UNINSTALL_PLUGIN', 'easy-svg/easy-svg.php' );
	include $root . '/uninstall.php';

	$left_types = array_column( array_map( 'get_object_vars', $GLOBALS['icon_posts'] ), 'post_type' );
	check( 'BELL: uninstalling deletes every icon, past one page of them', ! in_array( 'esw_icon', $left_types, true ) );
	check( 'SILENCE: and nothing that is not an icon', in_array( 'page', $left_types, true ) );
	check( 'BELL: and the cached list and its version', ! isset( $GLOBALS['transients']['easy_svg_icons'] ) && ! isset( $GLOBALS['options']['easy_svg_icons_version'] ) );
	check( 'BELL: on a network, on every site, switching back each time', [ 1, 'restore', 2, 'restore' ] === $GLOBALS['blog_switches'] );
}

// ─── On multisite, the network's allowed file types are respected ────────────

// A single site adds svg unconditionally (the default above), as before.
$GLOBALS['is_multisite'] = false;
$GLOBALS['site_options'] = [];
check( 'SILENCE: on a single site svg is always offered', isset( esw_add_support( [] )['svg'] ) );

// On multisite, the network admin's "Upload file types" list decides. svg is
// added only when that list contains it; otherwise the plugin does not override
// the network policy.
$GLOBALS['is_multisite'] = true;
$GLOBALS['site_options']['upload_filetypes'] = 'jpg png';
check( 'BELL: on multisite svg is not forced in when the network excludes it', ! isset( esw_add_support( [ 'jpg' => 'image/jpeg' ] )['svg'] ) );
$GLOBALS['site_options']['upload_filetypes'] = 'jpg png svg';
check( 'SILENCE: and it is added when the network lists it', isset( esw_add_support( [] )['svg'] ) );
// Back to single site so the remaining checks are unaffected.
$GLOBALS['is_multisite'] = false;
$GLOBALS['site_options'] = [];

// ─── The media-library display filter reads dimensions, and tolerates junk ───

$display_cb = $GLOBALS['hooks']['wp_prepare_attachment_for_js'][0] ?? null;
if ( is_callable( $display_cb ) ) {
	$svg_response = static function ( $file ) use ( $display_cb ) {
		$path = tempnam( sys_get_temp_dir(), 'eswdisp' );
		file_put_contents( $path, $file );
		$GLOBALS['attachments'][99] = [ 'file' => $path, 'mime' => 'image/svg+xml' ];
		$resp = $display_cb(
			[ 'type' => 'image', 'subtype' => 'svg+xml', 'url' => 'x.svg' ],
			(object) [ 'ID' => 99 ],
			[]
		);
		unlink( $path );
		return $resp;
	};

	$ok = $svg_response( '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="24"><rect/></svg>' );
	check( 'SILENCE: a normal SVG gets its width and height read for the library', 48 === ( $ok['image']['width'] ?? 0 ) && 24 === ( $ok['image']['height'] ?? 0 ) );

	// One oversized stored file must not read into memory or break the shared
	// listing. The filter returns the response unchanged, no dimensions.
	$big = $svg_response( '<svg xmlns="http://www.w3.org/2000/svg">' . str_repeat( '<rect/>', 400000 ) . '</svg>' );
	check( 'BELL: an oversized stored SVG is not parsed for the library', ! isset( $big['image'] ) );
} else {
	check( 'SILENCE: a normal SVG gets its width and height read for the library', false );
	check( 'BELL: an oversized stored SVG is not parsed for the library', false );
}

// ─── The readme must not hand out a dangerous allow-list example ─────────────
// The "For developers" snippets are copy-paste: each `$tags[] = '...'` or
// `$attributes[] = '...'` line adds one token to a site's allow-list. A reader
// pastes them verbatim, so none may name an element that can run code or load a
// document -- the allow-list only decides which names appear, it does not check
// what their attributes point at.
$readme = (string) file_get_contents( ABSPATH . 'readme.txt' );
preg_match_all( "/\\\$(?:tags|attributes)\\[\\]\\s*=\\s*'([^']+)'/", $readme, $readme_examples );
$recommended = array_map( 'strtolower', $readme_examples[1] );
$dangerous   = [ 'animate', 'animatetransform', 'animatemotion', 'animatecolor', 'set', 'script', 'style', 'foreignobject', 'handler', 'listener', 'iframe', 'embed', 'object' ];
check(
	'BELL: the readme developer examples recommend no active element',
	[] === array_intersect( $recommended, $dangerous )
);

// A security release that updates the sanitiser must say that it does not
// reach files already stored. The Upgrade Notice is what an admin reads at
// update time, so the caveat has to be there, not only in the FAQ.
$notice = (string) substr( $readme, (int) stripos( $readme, '== Upgrade Notice ==' ) );
$notice = (string) substr( $notice, 0, (int) stripos( $notice . '===', '=== ' ) ?: strlen( $notice ) );
check(
	'BELL: the upgrade notice says existing SVGs are not re-checked',
	false !== stripos( $notice, 'already' ) && ( false !== stripos( $notice, 're-upload' ) || false !== stripos( $notice, 're-check' ) )
);

// ─── Settings: defaults, and a sanitiser that clamps the size ────────────────
check( 'BELL: features are OFF by default, the size cap stays', easy_svg_settings_defaults() === array( 'svg_upload' => false, 'icons' => false, 'max_mb' => 2 ) );
check( 'BELL: a wild max_mb is clamped into range', 20 === easy_svg_sanitize_settings( array( 'max_mb' => 9999 ) )['max_mb'] && 1 === easy_svg_sanitize_settings( array( 'max_mb' => 0 ) )['max_mb'] );
check( 'BELL: a missing toggle falls back to its default (off)', false === easy_svg_sanitize_settings( array() )['icons'] );
check( 'an explicit on is kept', true === easy_svg_sanitize_settings( array( 'icons' => true ) )['icons'] );
check( 'an explicit off is kept', false === easy_svg_sanitize_settings( array( 'icons' => false ) )['icons'] );

// ─── Gate and size read the stored option ────────────────────────────────────
// Drop the top-of-file seed so these read the real defaults.
unset( $GLOBALS['options'][ EASY_SVG_SETTINGS_OPTION ] );
check( 'BELL: a feature is disabled until switched on', false === easy_svg_feature_enabled( 'icons' ) );
$GLOBALS['options'][ EASY_SVG_SETTINGS_OPTION ] = array( 'icons' => true );
check( 'BELL: switching a feature on reports enabled', true === easy_svg_feature_enabled( 'icons' ) );
unset( $GLOBALS['options'][ EASY_SVG_SETTINGS_OPTION ] );
check( 'an unknown feature is off', false === easy_svg_feature_enabled( 'no_such_feature' ) );
check( 'the default size cap is 2 MB', 2 * MB_IN_BYTES === easy_svg_max_bytes() );

// ─── The 5.0 upgrade keeps an existing site's uploads on ──────────────────────
check( 'BELL: an existing site with SVGs keeps uploads through the 5.0 migration', array( 'svg_upload' => true, 'icons' => false, 'max_mb' => 2 ) === easy_svg_migration_decision( false, true ) );
check( 'BELL: a fresh install takes the opt-in defaults (no seed)', null === easy_svg_migration_decision( false, false ) );
check( 'SILENCE: a configured site is never overwritten by the migration', null === easy_svg_migration_decision( true, true ) && null === easy_svg_migration_decision( true, false ) );
$GLOBALS['options'][ EASY_SVG_SETTINGS_OPTION ] = array( 'svg_upload' => false, 'max_mb' => 5 );
check( 'BELL: a feature switched off in the option reports disabled', false === easy_svg_feature_enabled( 'svg_upload' ) );
check( 'BELL: the size cap follows the option (5 MB)', 5 * MB_IN_BYTES === easy_svg_max_bytes() );
unset( $GLOBALS['options'][ EASY_SVG_SETTINGS_OPTION ] );
check( 'SILENCE: and it is back to default once the option is gone', 2 * MB_IN_BYTES === easy_svg_max_bytes() );

// ─── A size-capped file can still be too complex to sanitise cheaply (F1) ─────
// The byte cap bounds input SIZE, not the sanitiser's WORK: the bundled library
// has quadratic paths (one pass per nested PHP-processing-instruction layer; a
// per-<use> scan of every id'd element). easy_svg_svg_too_complex() rejects the
// pathological shapes with linear token counts, before parsing. No real drawing
// comes near either bound.
// Built from parts so the test SOURCE carries no literal processing-instruction tokens.
$pi_open     = '<' . '?';
$pi_close    = '?' . '>';
$legit_svg   = $pi_open . 'xml version="1.0"' . $pi_close . '<svg xmlns="http://www.w3.org/2000/svg"><use href="#a"/><rect id="a"/></svg>';
$phptag_bomb = str_repeat( $pi_open . 'p', 2000 ) . $pi_open . 'php A ' . $pi_close . str_repeat( 'hp A ' . $pi_close, 2000 );
$use_bomb    = '<svg xmlns="http://www.w3.org/2000/svg">' . str_repeat( '<use href="#a"/>', 1001 ) . '<rect id="a"/></svg>';
check( 'SILENCE: a normal SVG is not flagged too complex', false === easy_svg_svg_too_complex( $legit_svg ) );
check( 'BELL: a nest of PHP tags is flagged too complex', true === easy_svg_svg_too_complex( $phptag_bomb ) );
check( 'BELL: a flood of <use> is flagged too complex', true === easy_svg_svg_too_complex( $use_bomb ) );

// The guard runs before the sanitiser in the upload sink, so a sub-cap but
// too-complex file is refused without the quadratic work. Removing the guard
// flips this: the file sanitises to a valid SVG and the checker accepts it.
$bomb_path = tempnam( sys_get_temp_dir(), 'eswbomb' ) . '.svg';
file_put_contents( $bomb_path, '<svg xmlns="http://www.w3.org/2000/svg">' . str_repeat( '<use href="#a"/>', 5000 ) . '<rect id="a"/></svg>' );
check( 'BELL: the upload checker refuses a sub-cap but too-complex file', false === esw_svg_file_checker( $bomb_path ) );
@unlink( $bomb_path );
$ok_path = tempnam( sys_get_temp_dir(), 'eswok' ) . '.svg';
file_put_contents( $ok_path, '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>' );
check( 'SILENCE: and still accepts an ordinary SVG', true === esw_svg_file_checker( $ok_path ) );
@unlink( $ok_path );

// The bounds are filterable for the rare site that needs more.
add_filter( 'easy_svg_max_use_tags', static fn( $n ) => 100000 );
check( 'SILENCE: raising the <use> bound lets a bigger file through the guard', false === easy_svg_svg_too_complex( $use_bomb ) );
$GLOBALS['hooks']['easy_svg_max_use_tags'] = array();

// ─── Each feature registers behind its toggle ────────────────────────────────
// The hooks are module-level, so this reads the source: the upload and icon
// registrations sit inside their feature gate. Gate LOGIC is proven above.
$plugin_src = (string) file_get_contents( $root . '/easy-svg.php' );
check( 'BELL: upload hooks are gated on the svg_upload toggle', (bool) preg_match( "/easy_svg_feature_enabled\\(\\s*'svg_upload'\\s*\\)/", $plugin_src ) );
check( 'BELL: icon registration is gated on the icons toggle', (bool) preg_match( "/easy_svg_feature_enabled\\(\\s*'icons'\\s*\\)/", $plugin_src ) );

// ─── The settings page registers against WordPress ───────────────────────────
check( 'BELL: a settings page callback is on admin_menu', in_array( 'easy_svg_settings_menu', $GLOBALS['hooks']['admin_menu'] ?? array(), true ) );
check( 'BELL: the setting is registered on admin_init', in_array( 'easy_svg_settings_register', $GLOBALS['hooks']['admin_init'] ?? array(), true ) );

// ─── One place reads an SVG's width and height ───────────────────────────────
$dim_path = tempnam( sys_get_temp_dir(), 'eswdim' ) . '.svg';
file_put_contents( $dim_path, '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="32"><rect/></svg>' );
check( 'the dimension reader returns width and height', array( 'width' => 64, 'height' => 32 ) === easy_svg_read_svg_dimensions( $dim_path ) );
file_put_contents( $dim_path, str_repeat( 'x', easy_svg_max_bytes() + 1 ) );
check( 'BELL: an oversized file yields no dimensions, no crash', array() === easy_svg_read_svg_dimensions( $dim_path ) );
@unlink( $dim_path );
check( 'SILENCE: a missing file yields no dimensions', array() === easy_svg_read_svg_dimensions( $dim_path ) );

// ─── The Image block gets real dimensions for an SVG attachment ──────────────
$meta_cb = $GLOBALS['hooks']['wp_generate_attachment_metadata'][0] ?? null;
check( 'BELL: attachment metadata is filtered', is_callable( $meta_cb ) );
if ( is_callable( $meta_cb ) ) {
	$svgp = tempnam( sys_get_temp_dir(), 'eswmeta' ) . '.svg';
	file_put_contents( $svgp, '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="24"><rect/></svg>' );
	$GLOBALS['attachments'][77] = array( 'file' => $svgp, 'mime' => 'image/svg+xml' );
	$meta = $meta_cb( array(), 77 );
	@unlink( $svgp );
	check( 'BELL: SVG metadata carries width and height', 48 === ( $meta['width'] ?? 0 ) && 24 === ( $meta['height'] ?? 0 ) );
	$GLOBALS['attachments'][78] = array( 'file' => '/tmp/none.png', 'mime' => 'image/png' );
	check( 'SILENCE: a non-SVG attachment metadata is unchanged', array( 'x' => 1 ) === $meta_cb( array( 'x' => 1 ), 78 ) );
}

// ─── The suite has to be able to fail ────────────────────────────────────────

$before = $failed;
ob_start();
check( 'this deliberate failure proves the harness works', false );
ob_end_clean();
check( 'a failing check is counted', $failed === $before + 1 );
$failed = $before;

echo 0 === $failed
	? "all {$passed} checks passed\n"
	: "{$failed} of " . ( $passed + $failed ) . " checks FAILED\n";

exit( 0 === $failed ? 0 : 1 );
