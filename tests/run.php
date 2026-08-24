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
