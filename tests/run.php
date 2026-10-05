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
function _n( string $single, string $plural, int $n, string $domain = '' ): string {
	return 1 === $n ? $single : $plural;
}
function esc_attr( string $text ): string {
	return $text;
}
function get_allowed_mime_types(): array {
	return [ 'svg' => 'image/svg+xml' ];
}
// Answering false on purpose: this suite is not an admin request, so hiding the
// icons behind `is_admin()` shows up as icons that never register rather than
// as a fatal.
function is_admin(): bool {
	return false;
}

/** Answers as core does for a genuine SVG unless a test says otherwise. */
$GLOBALS['filetype'] = [ 'ext' => 'svg', 'type' => 'image/svg+xml' ];
function wp_check_filetype_and_ext( $file, $filename, $mimes = null ): array {
	return $GLOBALS['filetype'];
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
				return ( $args['post_type'] ?? '' ) === $p->post_type && 'publish' === $p->post_status;
			}
		)
	);
	if ( $per_page < 0 ) {
		return $posts;
	}
	return array_slice( $posts, ( $page - 1 ) * $per_page, $per_page );
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
function wp_insert_post( array $post, bool $wp_error = false, bool $fire_after_hooks = true ) {
	$GLOBALS['inserted'][] = [ 'post' => $post, 'wp_error' => $wp_error ];
	$answer = $GLOBALS['insert_returns'];
	// As core does: without $wp_error a failure is 0, never an object.
	return ( ! $wp_error && $answer instanceof WP_Error ) ? 0 : $answer;
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

/*
 * Caught, so a plugin that does not load is a FAIL LINE rather than a dead
 * process. A suite that dies reports nothing, and "nothing" is the one result
 * indistinguishable from "not covered".
 */
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

check( 'the screen is hooked', in_array( 'easy_svg_icons_menu', $GLOBALS['hooks']['admin_menu'] ?? [], true ) );

/*
 * On a WordPress without the icon API there is nothing to manage, and a menu
 * entry that opens onto "this needs 7.1" is clutter on every older site. This
 * suite is such a WordPress until its last section.
 */
easy_svg_icons_menu();
check( 'BELL: before 7.1 there is no SVG icons submenu', [] === $GLOBALS['media_pages'] );
check( 'adding an icon is reachable', isset( $GLOBALS['hooks']['admin_post_easy_svg_add_icon'] ) );
check( 'removing one is reachable', isset( $GLOBALS['hooks']['admin_post_easy_svg_delete_icon'] ) );

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

// ─── Every refusal has a sentence ────────────────────────────────────────────

/*
 * A state with no message shows an empty notice box, which reads as a bug. The
 * states come from `easy_svg_accept_icon()`, so the two lists are checked
 * against each other rather than a hand-written copy of one of them.
 */
foreach ( array( 'added', 'deleted', 'bad_name', 'empty', 'not_svg', 'no_sanitizer', 'not_saved' ) as $state ) {
	check(
		"the '{$state}' state has something to say",
		function_exists( 'easy_svg_icon_message' ) && '' !== easy_svg_icon_message( $state )
	);
}
check(
	'SILENCE: and an unknown state says nothing rather than something wrong',
	function_exists( 'easy_svg_icon_message' ) && '' === easy_svg_icon_message( 'nonsense' )
);

// ─── The count is a count, not a quota ───────────────────────────────────────

/*
 * With no cap there is nothing to count against, and the line above the table
 * says only what is there. "N of 5" would advertise a limit that is gone.
 */
check( 'BELL: the counter names how many there are, and nothing to be measured against', '7 icons. They appear in the Icon block.' === easy_svg_icon_count_message( 7 ) );
check( 'SILENCE: and one icon reads as one', '1 icon. They appear in the Icon block.' === easy_svg_icon_count_message( 1 ) );
check( 'SILENCE: there is no refusal for being full', '' === easy_svg_icon_message( 'limit_reached' ) );

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
check(
	'BELL: and no comment pointing at a paid product',
	1 !== preg_match( '/easy svg pro|\bpro\b|\bpaid\b|\bpaying\b|premium|lifts? the (cap|limit)/i', $shipped )
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
check( 'BELL: with the value it has today', false !== strpos( $readme_text, 'It is 3.' ) );
check( 'BELL: and the readme says the limit filter is gone', 1 === preg_match( '/easy_svg_icon_limit`? (filter )?(no longer exists|was removed)/', $readme_text ) );
check( 'BELL: the sanitiser is reachable by function', function_exists( 'easy_svg_sanitizer' ) );
check( 'BELL: and it returns a sanitiser', easy_svg_sanitizer() instanceof \enshrined\svgSanitize\Sanitizer );

// Two callers must not share one object. The old load-time global was
// reconfigured on every upload, so whichever ran last decided what the other
// one stripped.
check( 'SILENCE: each call gets its own', easy_svg_sanitizer() !== easy_svg_sanitizer() );

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

// ─── The preview cannot run what the store let through ───────────────────────

/*
 * The icons screen printed stored markup raw. That was safe only as long as
 * the site's allow-list was: a site that widens it -- as the filters above now
 * have, to `script` and `onload` -- stores icons that would run in an
 * administrator's browser the moment the screen opened.
 *
 * So the preview goes through wp_kses with the site's own SVG allow-list, minus
 * everything that can execute, whatever a filter added.
 */
$hostile = '<?xml version="1.0"?>'
	. '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
	. '<script>alert(document.cookie)</script>'
	. '<rect onload="alert(1)" width="24" height="24"/>'
	. '<foreignObject><body xmlns="http://www.w3.org/1999/xhtml"><iframe src="javascript:alert(1)"/></body></foreignObject>'
	. '<path d="M0 0L24 24"/></svg>';

$GLOBALS['kses_calls'] = 0;
$preview = easy_svg_icon_preview( $hostile );

check( 'BELL: the preview goes through wp_kses', 1 === $GLOBALS['kses_calls'] );
check( 'BELL: a script element is not emitted, even where the site allows it', false === stripos( $preview, '<script' ) );
check( 'BELL: nor an event handler the site allowed', false === stripos( $preview, 'onload' ) );
check( 'BELL: nor foreignObject, which can carry HTML', false === stripos( $preview, 'foreignobject' ) && false === stripos( $preview, 'iframe' ) );
check( 'SILENCE: and the drawing is still there', false !== strpos( $preview, '<path' ) && false !== strpos( $preview, '<rect' ) );

$preview_html = easy_svg_icon_preview_allowed_html();
check( 'SILENCE: the preview allow-list is lower case, the way wp_kses looks names up', isset( $preview_html['lineargradient'] ) || isset( $preview_html['path'] ) );
foreach ( array( 'script', 'foreignobject', 'iframe', 'set', 'animate', 'handler', 'listener' ) as $never ) {
	check( "BELL: '{$never}' is never in the preview allow-list", ! isset( $preview_html[ $never ] ) );
}

$manager_source = (string) file_get_contents( $root . '/includes/icon-manager.php' );
check(
	'BELL: the screen no longer prints stored markup raw',
	1 !== preg_match( '/echo\s+\$icon\[\s*\'content\'\s*\]/', $manager_source )
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

// ─── What wordpress.org shows ────────────────────────────────────────────────

/*
 * A "Tested up to" below the current WordPress hides the plugin from search on
 * wordpress.org; Plugin Check reports it as an error. And the upgrade notice is
 * the one line a site owner reads before clicking update.
 */
check( 'BELL: tested up to the WordPress the icon feature needs', 1 === preg_match( '/^Tested up to:\s*7\.1\s*$/mi', $readme ) );
$upgrade_notice = (string) substr( $readme, (int) strpos( $readme, '== Upgrade Notice ==' ) );
check( 'BELL: there is an upgrade notice for this version', false !== strpos( $readme, '== Upgrade Notice ==' ) && false !== strpos( $upgrade_notice, '= ' . ( $header_v[1] ?? 'x' ) . ' =' ) );
check( 'BELL: it tells a site owner that sideloaded SVGs are now sanitised', false !== stripos( $upgrade_notice, 'wp media import' ) );
check( 'SILENCE: and no longer talks about WordPress 4', false === strpos( $upgrade_notice, '4.0 to 4.9' ) );
check( 'SILENCE: one changelog entry for this release, with the unreleased 4.2 folded in', false === strpos( $readme, '= 4.2 =' ) );
preg_match( '/^Tags:\s*(.+)$/mi', $readme, $tags_line );
check( 'SILENCE: at most five tags, as wordpress.org reads them', isset( $tags_line[1] ) && count( array_filter( array_map( 'trim', explode( ',', $tags_line[1] ) ) ) ) <= 5 );

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

easy_svg_icons_menu();
check( 'BELL: on 7.1 the SVG icons submenu is there', [ 'easy-svg-icons' ] === $GLOBALS['media_pages'] );

easy_svg_boot_icons();
check( 'BELL: all 250 icons are handed to core, not the first 200', 250 === count( $GLOBALS['registered_icons'] ) );
check( 'SILENCE: each under its own name', 250 === count( array_unique( $GLOBALS['registered_icons'] ) ) );

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
