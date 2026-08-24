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

$GLOBALS['hooks'] = [];

function add_filter( string $hook, $cb, int $priority = 10, int $args = 1 ): bool {
	$GLOBALS['hooks'][ $hook ][] = $cb;
	return true;
}
function add_action( string $hook, $cb, int $priority = 10, int $args = 1 ): bool {
	$GLOBALS['hooks'][ $hook ][] = $cb;
	return true;
}
function apply_filters( string $hook, $value ) {
	// Real enough to prove the allow-list is reachable. A stub that always
	// returned its input would let a plugin ignore the filter entirely and
	// still pass every check below.
	foreach ( $GLOBALS['hooks'][ $hook ] ?? [] as $cb ) {
		$value = $cb( $value );
	}
	return $value;
}
function __( string $text, string $domain = '' ): string {
	return $text;
}
function esc_attr( string $text ): string {
	return $text;
}
function get_allowed_mime_types(): array {
	return [ 'svg' => 'image/svg+xml' ];
}

/** Answers as core does for a genuine SVG unless a test says otherwise. */
$GLOBALS['filetype'] = [ 'ext' => 'svg', 'type' => 'image/svg+xml' ];
function wp_check_filetype_and_ext( $file, $filename, $mimes = null ): array {
	return $GLOBALS['filetype'];
}

require $root . '/easy-svg.php';

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

// ─── Files that are not what they claim ──────────────────────────────────────

$GLOBALS['filetype'] = [ 'ext' => '', 'type' => 'text/html' ];

$file  = file_array( '<html><script>alert(1)</script></html>', 'evil.svg' );
$after = $callback( $file );
unlink( $file['tmp_name'] );

check( 'BELL: a .svg that is not an SVG is refused', isset( $after['error'] ) );

$GLOBALS['filetype'] = [ 'ext' => 'png', 'type' => 'image/png' ];

$file  = file_array( 'not an svg', 'photo.png' );
$after = $callback( $file );
unlink( $file['tmp_name'] );

check( 'SILENCE: an ordinary image passes straight through', ! isset( $after['error'] ) );

$GLOBALS['filetype'] = [ 'ext' => 'svg', 'type' => 'image/svg+xml' ];

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
check( 'BELL: the sanitiser is reachable by function', function_exists( 'easy_svg_sanitizer' ) );
check( 'BELL: and it returns a sanitiser', easy_svg_sanitizer() instanceof \enshrined\svgSanitize\Sanitizer );

// Two callers must not share one object. The old load-time global was
// reconfigured on every upload, so whichever ran last decided what the other
// one stripped.
check( 'SILENCE: each call gets its own', easy_svg_sanitizer() !== easy_svg_sanitizer() );

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
