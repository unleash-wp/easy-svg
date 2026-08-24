<?php
/**
 * Names, limits, and the shapes core refuses in silence.
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

// ─── The limit ───────────────────────────────────────────────────────────────

check( 'the default limit is five', 5 === easy_svg_icon_limit() );

// The whole unlock a paid add-on performs.
add_filter( 'easy_svg_icon_limit', static function ( $n ) { return 500; } );
check( 'BELL: the filter is what raises it', 500 === easy_svg_icon_limit() );

add_filter( 'easy_svg_icon_limit', static function ( $n ) { return -3; } );
// Negative would read as "none allowed" in one comparison and "no limit" in
// another, depending on who compared what.
check( 'SILENCE: a negative limit becomes zero, not infinity', 0 === easy_svg_icon_limit() );

check( 'under the limit, one more may be added', easy_svg_icon_may_add( 4, 5 ) );
check( 'BELL: at the limit, it may not', ! easy_svg_icon_may_add( 5, 5 ) );
// The property the product depends on: a site that drops below its paid limit
// keeps every icon it has. Only the next one is refused.
check( 'BELL: over the limit, still only ADDING is refused', ! easy_svg_icon_may_add( 40, 5 ) );

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

echo 0 === $failed
    ? "all {$passed} checks passed\n"
    : "{$failed} of " . ( $passed + $failed ) . " checks FAILED\n";

exit( 0 === $failed ? 0 : 1 );
