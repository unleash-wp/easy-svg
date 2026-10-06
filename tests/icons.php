<?php
/**
 * Names, and the shapes core refuses in silence.
 *
 * `WP_Icons_Registry::register()` rejects a bad name through
 * `_doing_it_wrong`, which on a production site means the icon never appears
 * and nothing anywhere says why. Every rule it applies is therefore checked
 * here, before core is asked -- and checked against the SAME pattern core
 * uses, copied from its source.
 *
 * Usage: php tests/icons.php
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['filters'] = array();
function apply_filters( string $hook, $value ) {
    foreach ( $GLOBALS['filters'][ $hook ] ?? array() as $cb ) {
        $value = $cb( $value );
    }
    return $value;
}
function add_filter( string $hook, $cb, int $p = 10, int $n = 1 ): bool {
    $GLOBALS['filters'][ $hook ][] = $cb;
    return true;
}
function __( string $text, string $domain = '' ): string {
    return $text;
}

require dirname( __DIR__ ) . '/includes/icons.php';

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

/**
 * Core's own rule, written out from WP_Icons_Registry so the checks below are
 * measured against WordPress rather than against our copy of it.
 */
function core_would_accept( string $unqualified ): bool {
    if ( 1 === preg_match( '/[A-Z]/', $unqualified ) ) {
        return false;
    }
    return 1 === preg_match( '/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$/', $unqualified );
}

// ─── Names ───────────────────────────────────────────────────────────────────

$sanitize = static function ( string $label ): string {
    // Stands in for sanitize_title: lowercase, non-word runs to hyphens.
    $s = strtolower( trim( $label ) );
    $s = (string) preg_replace( '/[^a-z0-9]+/', '-', $s );
    return trim( $s, '-' );
};

foreach ( array( 'Arrow Left', 'arrow_left', 'ICON 42', 'Pfeil  links', 'a' ) as $label ) {
    $slug = easy_svg_icon_slug( $label, $sanitize );
    check( "'{$label}' becomes a name core accepts: '{$slug}'", '' !== $slug && core_would_accept( $slug ) );
}

// ─── Names that cannot be made ───────────────────────────────────────────────

foreach ( array( '', '   ', '---', '!!!', '###' ) as $label ) {
    check( "'{$label}' yields nothing rather than something core refuses", '' === easy_svg_icon_slug( $label, $sanitize ) );
}

/*
 * A sanitiser that returns something core refuses must not get through. This is
 * the reason the function exists: it is not a reimplementation of
 * sanitize_title, it is the guarantee about what leaves.
 */
$bad_sanitizers = array(
    'leading hyphen'  => static function ( string $l ): string { return '-arrow'; },
    'trailing hyphen' => static function ( string $l ): string { return 'arrow-'; },
    'a slash'         => static function ( string $l ): string { return 'a/b'; },
    'a space'         => static function ( string $l ): string { return 'arrow left'; },
    'a dot'           => static function ( string $l ): string { return 'arrow.left'; },
    'empty'           => static function ( string $l ): string { return ''; },
);
foreach ( $bad_sanitizers as $what => $fn ) {
    check( "a sanitiser returning {$what} is refused here, not by core in silence", '' === easy_svg_icon_slug( 'x', $fn ) );
}

// Uppercase is folded, not refused: core rejects it, but it is a configuration
// problem rather than the user's mistake.
$shouting = static function ( string $l ): string { return 'ARROW-LEFT'; };
check( 'uppercase from a sanitiser is folded down', 'arrow-left' === easy_svg_icon_slug( 'x', $shouting ) );

// Underscores are legal in core's pattern and must not be thrown away.
$under = static function ( string $l ): string { return 'arrow_left'; };
check( 'SILENCE: an underscore survives, because core allows it', 'arrow_left' === easy_svg_icon_slug( 'x', $under ) );

// ─── The namespaced name ─────────────────────────────────────────────────────

check( 'the name is namespaced into our collection', 'easy-svg/arrow-left' === easy_svg_icon_name( 'arrow-left' ) );
check( 'BELL: the collection is not core, which is reserved', 0 !== strpos( easy_svg_icon_name( 'a' ), 'core/' ) );
check( 'a slug core would refuse yields no name at all', '' === easy_svg_icon_name( 'Arrow' ) );
check( 'and neither does an already-namespaced one', '' === easy_svg_icon_name( 'easy-svg/arrow' ) );

// ─── The argument array ──────────────────────────────────────────────────────

$args = easy_svg_icon_args( 'Arrow left', '<svg/>' );
check( 'the arguments carry the label', 'Arrow left' === $args['label'] );
check( 'and the markup', '<svg/>' === $args['content'] );
/*
 * Core refuses ANY key beyond label, content and file_path -- through
 * _doing_it_wrong, so an extra key means the icon silently does not exist.
 * Asserted as the whole key set, because a check for three known keys would
 * pass while a fourth sat beside them.
 */
check( 'BELL: and nothing else, because core refuses unknown keys', array( 'label', 'content' ) === array_keys( $args ) );

// ─── Older WordPress ─────────────────────────────────────────────────────────

// wp_register_icon is @since 7.1.0 and this plugin declares 6.0. Absent-safe is
// not optional at 40,000 installs.
check( 'BELL: without the 7.1 API, icons are reported unsupported', ! easy_svg_icons_supported() );

// ─── Accepting an icon ───────────────────────────────────────────────────────

$strip = static function ( string $svg ) {
    return (string) preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $svg );
};
$refuse = static function ( string $svg ) {
    return false;
};

$SVG = '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>';

$out = easy_svg_accept_icon( 'Arrow Left', $SVG, $strip, $sanitize );
check( 'BELL: a good icon is accepted', 'ok' === $out['state'] );
check( 'with a name core will take', 'arrow-left' === $out['slug'] );
check( 'and the markup', false !== strpos( $out['content'], '<path' ) );

// The reason this plugin is the right home for an icon manager.
$dirty = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><path d="M0 0"/></svg>';
$out   = easy_svg_accept_icon( 'Bad', $dirty, $strip, $sanitize );
check( 'BELL: what gets stored is the CLEANED markup', 'ok' === $out['state'] && false === strpos( $out['content'], '<script' ) );
check( 'SILENCE: and the drawing survives it', false !== strpos( $out['content'], '<path' ) );

// ─── Stored icon markup carries nothing active ───────────────────────────────

/*
 * An icon is inlined into the page by the core Icon block, so whatever is
 * stored runs in the document's own context. A site's SVG allow-list may keep
 * a `<style>` element (its rules would then apply to the whole page) or an
 * animation element (which can rewrite an href to javascript: after the
 * sanitiser has checked it). The stored markup must carry none of it, whatever
 * the sanitiser left -- the admin preview already strips these; so must what is
 * registered. The fake sanitiser here does not remove them, so this measures
 * the icon hardening, not the sanitiser.
 */
$withStyle = '<svg xmlns="http://www.w3.org/2000/svg"><style>body{outline:5px solid red}</style><path d="M0 0"/></svg>';
$out = easy_svg_accept_icon( 'Styled', $withStyle, $strip, $sanitize );
check( 'BELL: a stored icon keeps no style element', 'ok' === $out['state'] && false === stripos( $out['content'], '<style' ) );
check( 'SILENCE: and the drawing survives the stripping', false !== strpos( $out['content'], '<path' ) );

$withAnim = '<svg xmlns="http://www.w3.org/2000/svg"><a href="#x"><path d="M0 0"/></a><animate attributeName="href" values="javascript:alert(1)"/></svg>';
$out = easy_svg_accept_icon( 'Animated', $withAnim, $strip, $sanitize );
check( 'BELL: a stored icon keeps no animation element', 'ok' === $out['state'] && false === stripos( $out['content'], '<animate' ) );

$withExtHref = '<svg xmlns="http://www.w3.org/2000/svg"><a href="https://evil.example/x"><path d="M0 0"/></a></svg>';
$out = easy_svg_accept_icon( 'Linked', $withExtHref, $strip, $sanitize );
check( 'BELL: a stored icon keeps no off-drawing href', 'ok' === $out['state'] && false === stripos( $out['content'], 'evil.example' ) );

// The same for an xlink:-prefixed href, which a sanitiser may still emit.
$withXlink = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><a xlink:href="https://evil.example/x"><path d="M0 0"/></a></svg>';
$out = easy_svg_accept_icon( 'Xlinked', $withXlink, $strip, $sanitize );
check( 'BELL: a stored icon keeps no off-drawing xlink:href', 'ok' === $out['state'] && false === stripos( $out['content'], 'evil.example' ) );

// An icon that is nothing but active markup hardens to an empty drawing, so it
// is refused rather than stored as a blank icon with a success message.
$allActive = '<svg xmlns="http://www.w3.org/2000/svg"><style>body{color:red}</style></svg>';
check( 'BELL: an icon that is only active markup is refused', 'not_svg' === easy_svg_accept_icon( 'Empty', $allActive, $strip, $sanitize )['state'] );

// ─── Every refusal is its own word ───────────────────────────────────────────

/*
 * "That name makes no icon name" and "that is not an SVG" send a person to two
 * different places. One message covering both sends half of them wrong.
 */
check( 'BELL: a label that makes no name says so', 'bad_name' === easy_svg_accept_icon( '###', $SVG, $strip, $sanitize )['state'] );
check( 'BELL: empty markup says so', 'empty' === easy_svg_accept_icon( 'X', '   ', $strip, $sanitize )['state'] );
check( 'BELL: a sanitiser that refuses means not an SVG', 'not_svg' === easy_svg_accept_icon( 'X', $SVG, $refuse, $sanitize )['state'] );

/*
 * A whole HTML document survives a sanitiser as a string and contains no
 * drawing. Storing it would put an empty icon in the picker with nothing to
 * explain it, so the `<svg` root is required of the CLEANED markup.
 */
$html = '<html><body><script>alert(1)</script><p>hello</p></body></html>';
check( 'BELL: markup with no svg root is refused', 'not_svg' === easy_svg_accept_icon( 'X', $html, $strip, $sanitize )['state'] );

// ─── There is no cap ─────────────────────────────────────────────────────────

/*
 * WordPress.org guideline 5: no functionality in a hosted plugin may be locked
 * until somebody pays. 4.3 was going to ship five icons and a filter a paid
 * add-on raised -- the whole feature present, with its sixth use for sale. The
 * free plugin is unlimited instead, and these checks keep it that way.
 */
check( 'BELL: there is no icon limit left to filter', ! function_exists( 'easy_svg_icon_limit' ) );
check( 'BELL: and no number it would have read', ! defined( 'EASY_SVG_ICON_LIMIT' ) );
check( 'BELL: and nothing that decides whether one more is allowed', ! function_exists( 'easy_svg_icon_may_add' ) );

// A site that still carries a filter from an older add-on must not be capped
// by it. Registered on the real hook registry this file stubs, so a plugin
// that went on reading the filter would be caught here.
add_filter( 'easy_svg_icon_limit', static function () { return 0; } );
check( 'BELL: a leftover limit filter cannot refuse an icon', 'ok' === easy_svg_accept_icon( 'Sixth', $SVG, $strip, $sanitize )['state'] );

// ─── Handing them to core ────────────────────────────────────────────────────

$collection_calls = array();
$icon_calls       = array();
$ok_collection    = static function ( $slug, $args ) use ( &$collection_calls ) {
    $collection_calls[] = $slug;
    return true;
};
$ok_icon = static function ( $name, $args ) use ( &$icon_calls ) {
    $icon_calls[] = $name;
    return true;
};

$icons = array(
    array( 'slug' => 'arrow-left', 'label' => 'Arrow left', 'content' => $SVG ),
    array( 'slug' => 'arrow-right', 'label' => 'Arrow right', 'content' => $SVG ),
);

$taken = easy_svg_register_icons( $icons, $ok_collection, $ok_icon );
check( 'BELL: both icons are registered', 2 === $taken );
check( 'BELL: namespaced into our collection', array( 'easy-svg/arrow-left', 'easy-svg/arrow-right' ) === $icon_calls );
// WP_Icons_Registry refuses an icon whose collection is not registered, so the
// order is not a style choice.
check( 'BELL: and the collection was registered first', array( 'easy-svg' ) === $collection_calls );

// A collection core would not take means no icons, not icons into nothing.
$icon_calls = array();
$refused    = static function ( $slug, $args ) { return false; };
check( 'BELL: no collection means no icons are offered', 0 === easy_svg_register_icons( $icons, $refused, $ok_icon ) );
check( 'SILENCE: and none were attempted', array() === $icon_calls );

// One bad name must not cost the others.
$icon_calls = array();
$mixed      = array(
    array( 'slug' => 'Arrow',  'label' => 'Bad name', 'content' => $SVG ),
    array( 'slug' => 'good-one', 'label' => 'Fine',   'content' => $SVG ),
);
check( 'BELL: a bad name is skipped, not fatal', 1 === easy_svg_register_icons( $mixed, $ok_collection, $ok_icon ) );
check( 'SILENCE: and the good one still went', array( 'easy-svg/good-one' ) === $icon_calls );

/*
 * Counted from what core ANSWERED. A screen saying "5 icons" about icons core
 * refused is worse than no screen, and core refuses silently.
 */
$half = static function ( $name, $args ) {
    return 'easy-svg/arrow-left' === $name;
};
check( 'BELL: only what core took is counted', 1 === easy_svg_register_icons( $icons, $ok_collection, $half ) );

// ─── Reading every icon, a page at a time ────────────────────────────────────

/*
 * The screen and the registration once read a list capped at 200 while the
 * add handler counted every post, so the 201st icon was stored, counted -- and
 * never appeared anywhere. With no product limit the list has no natural end,
 * so it is read in pages until a page comes back short.
 */
$store = static function ( int $n ): callable {
    return static function ( int $page, int $per_page ) use ( $n ): array {
        $out   = array();
        $first = ( $page - 1 ) * $per_page + 1;
        for ( $id = $first; $id <= min( $n, $first + $per_page - 1 ); $id++ ) {
            $out[] = array( 'id' => $id, 'slug' => "i{$id}", 'label' => "I{$id}", 'content' => '<svg/>' );
        }
        return $out;
    };
};

$all = easy_svg_collect_icons( $store( 250 ), 100 );
check( 'BELL: 250 icons are all read, not the first 200', 250 === count( $all ) );
check( 'SILENCE: in the order they were stored', 1 === $all[0]['id'] && 250 === $all[249]['id'] );
check( 'SILENCE: and none twice', 250 === count( array_unique( array_column( $all, 'id' ) ) ) );

$calls   = 0;
$counted = static function ( int $page, int $per_page ) use ( $store, &$calls ): array {
    $calls++;
    return $store( 200 )( $page, $per_page );
};
check( 'BELL: exactly two full pages are both read', 200 === count( easy_svg_collect_icons( $counted, 100 ) ) );
check( 'SILENCE: and the empty third page ends it', 3 === $calls );

$calls = 0;
$none  = static function ( int $page, int $per_page ) use ( &$calls ): array {
    $calls++;
    return array();
};
check( 'SILENCE: no icons is an empty list', array() === easy_svg_collect_icons( $none, 100 ) );
check( 'SILENCE: after one question', 1 === $calls );

/*
 * A query that ignores `paged` -- a filter on the query, a caching plugin --
 * hands back the same full page for ever. That must end the loop rather than
 * the request.
 */
$stuck = static function ( int $page, int $per_page ) use ( $store ): array {
    return $store( 500 )( 1, $per_page );
};
check( 'BELL: a page that repeats itself ends the read instead of looping', 100 === count( easy_svg_collect_icons( $stuck, 100 ) ) );

echo 0 === $failed
    ? "all {$passed} checks passed\n"
    : "{$failed} of " . ( $passed + $failed ) . " checks FAILED\n";

exit( 0 === $failed ? 0 : 1 );
