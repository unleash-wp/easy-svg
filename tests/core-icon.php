<?php
/**
 * What survives WordPress's icon registry, and what this plugin does about it.
 *
 * Every rule here was measured against WP 7.1.3 first: `wp_kses` with
 * `svg`/`path`/`polygon` and a short attribute list, no filter. A stroked icon
 * arrives as paths with no stroke and no fill -- an empty box. These checks
 * pin the two answers: geometry becomes a path, presentation becomes a class.
 *
 * Usage: php tests/core-icon.php
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/includes/core-icon.php';

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

/** What the registry would keep of a piece of markup, as core does it. */
function kses_like( string $markup ): string {
    // The allow-list copied from WP_Icons_Registry::sanitize_icon_content(),
    // applied with the same crude rule: an element not named here is dropped
    // with its attributes.
    $kept = array( 'svg', 'path', 'polygon' );
    return (string) preg_replace_callback(
        '/<\/?([a-zA-Z0-9:-]+)[^>]*>/',
        static function ( $m ) use ( $kept ) {
            return in_array( strtolower( $m[1] ), $kept, true ) ? $m[0] : '';
        },
        $markup
    );
}

// ─── Geometry ────────────────────────────────────────────────────────────────

$circle = easy_svg_core_icon( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/></svg>' );
check( 'BELL: a circle leaves as a path', false !== strpos( $circle['content'], '<path' ) && false === strpos( $circle['content'], '<circle' ) );
check( 'BELL: and the path survives what the registry keeps', false !== strpos( kses_like( $circle['content'] ), '<path' ) );
check( 'SILENCE: nothing is reported lost for a circle', array() === $circle['lost'] );

$rect = easy_svg_core_icon( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="3" fill="#f00"/></svg>' );
check( 'BELL: a rect leaves as a path', false !== strpos( $rect['content'], '<path' ) );
check( 'BELL: and keeps its fill, which the registry allows on a path', false !== strpos( $rect['content'], 'fill="#f00"' ) );
// Rounded corners: rx without ry means both, and each is capped at half the side.
$wide = easy_svg_rect_path( 0, 0, 10, 4, '9', '' );
check( 'BELL: an rx larger than half the side is clamped, not drawn as given', false !== strpos( $wide, 'a5 2' ) );

$line = easy_svg_core_icon( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><line x1="1" y1="2" x2="3" y2="4" stroke="currentColor"/></svg>' );
check( 'BELL: a line leaves as a path', false !== strpos( $line['content'], 'd="M1 2L3 4"' ) );

$poly = easy_svg_core_icon( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><polyline points="1,1 2,2 3,1" stroke="currentColor" fill="none"/></svg>' );
check( 'BELL: a polyline leaves as an OPEN path', false !== strpos( $poly['content'], 'd="M1 1L2 2L3 1"' ) && false === strpos( $poly['content'], '1z"' ) );

$keep = easy_svg_core_icon( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><polygon points="1,1 2,2 3,1"/></svg>' );
check( 'SILENCE: a polygon is left alone -- the registry keeps it', false !== strpos( $keep['content'], '<polygon' ) );

// ─── Groups ──────────────────────────────────────────────────────────────────

$group = easy_svg_core_icon( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><g transform="translate(2 3)" fill="#0f0"><path d="M0 0h2"/><circle cx="1" cy="1" r="1"/></g></svg>' );
check( 'BELL: the group is gone', false === strpos( $group['content'], '<g ' ) );
check( 'BELL: its transform rides on each child, parent first', 2 === substr_count( $group['content'], 'transform="translate(2 3)"' ) );
check( 'BELL: and its fill is pushed down', 2 === substr_count( $group['content'], 'fill="#0f0"' ) );

$nested = easy_svg_core_icon( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><g transform="translate(1 0)"><g transform="scale(2)"><path d="M0 0h2"/></g></g></svg>' );
check( 'BELL: a nested group composes outer before inner', false !== strpos( $nested['content'], 'transform="translate(1 0) scale(2)"' ) );

// ─── Presentation ────────────────────────────────────────────────────────────

/*
 * The case this file exists for: an outline icon, drawn the way Feather,
 * Lucide and Tabler draw one. Through the registry it keeps two paths with no
 * stroke and no fill, which renders as nothing at all.
 */
$outline = easy_svg_core_icon(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h18"/><path d="M12 3v18"/></svg>'
);
check( 'BELL: a stroked icon is marked so a stylesheet can restore it', in_array( 'esvg-stroke', $outline['classes'], true ) );
check( 'BELL: its stroke width travels as a class', in_array( 'esvg-sw-2', $outline['classes'], true ) );
check( 'BELL: round caps and joins travel too', in_array( 'esvg-cap-round', $outline['classes'], true ) && in_array( 'esvg-join-round', $outline['classes'], true ) );
check( 'BELL: and the class is on the root, where the registry keeps it', false !== strpos( kses_like( $outline['content'] ), 'esvg-stroke' ) );
check( 'SILENCE: nothing is reported lost for an ordinary outline icon', array() === $outline['lost'] );

$half = easy_svg_core_icon(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h2" fill="#000"/><path d="M4 4h2" fill="none" stroke="currentColor"/></svg>'
);
check( 'BELL: a half-stroked, half-filled icon gets no class', array() === $half['classes'] );
check( 'BELL: and says the stroke could not be carried', in_array( 'stroke', $half['lost'], true ) );

$odd = easy_svg_core_icon(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="7"><path d="M3 12h18"/></svg>'
);
check( 'BELL: a width with no class is reported rather than silently changed', in_array( 'stroke-width', $odd['lost'], true ) );
check( 'SILENCE: and the icon is still marked, so it is visible', in_array( 'esvg-stroke', $odd['classes'], true ) );

$red = easy_svg_core_icon(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#f00" stroke-width="2"><path d="M3 12h18"/></svg>'
);
check( 'BELL: a stroke colour that becomes currentColor is reported', in_array( 'stroke-colour', $red['lost'], true ) );

// ─── What cannot be carried ──────────────────────────────────────────────────

$gradient = easy_svg_core_icon(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><defs><linearGradient id="g"><stop offset="0"/></linearGradient></defs><path d="M0 0h2" fill="url(#g)"/></svg>'
);
check( 'BELL: a gradient is named as lost', in_array( 'defs', $gradient['lost'], true ) || in_array( 'lineargradient', $gradient['lost'], true ) );
check( 'SILENCE: and is not left behind to point at nothing', false === strpos( $gradient['content'], '<defs' ) );

check( 'SILENCE: markup that is not an svg is refused', '' === easy_svg_core_icon( '<p>hallo</p>' )['content'] );
check( 'SILENCE: and so is markup that does not parse', '' === easy_svg_core_icon( '<svg><path d="M0 0h2"' )['content'] );

// ─── Nodes that are not elements ─────────────────────────────────────────────

/*
 * A `<g>` holds more than shapes: comments, and the whitespace between tags.
 * The flattener walks childNodes and pushes `transform` and `fill` down, so the
 * walk has to move every child while touching only the elements -- calling
 * setAttribute on a comment is a fatal, and skipping the non-elements loses the
 * author's own notes. The DOM loops here are typed only as DOMNode, so neither
 * half is provable by reading the signatures.
 */

$mixed = easy_svg_core_icon(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><!-- hand drawn --> <g transform="translate(2 2)" fill="#f00"><!-- inner --> <path d="M0 0h2"/> </g></svg>'
);
check( 'BELL: a comment between the shapes does not stop the group flattening', false === strpos( $mixed['content'], '<g' ) );
check( 'BELL: the transform still reaches the path it sat above', false !== strpos( $mixed['content'], 'transform="translate(2 2)"' ) );
check( 'BELL: and so does the fill', false !== strpos( $mixed['content'], 'fill="#f00"' ) );
check( 'BELL: the comments themselves are carried over, not dropped', 2 === substr_count( $mixed['content'], '<!--' ) );
check( 'SILENCE: and none of it is reported lost', array() === $mixed['lost'] );

echo 0 === $failed
    ? "all {$passed} checks passed\n"
    : "{$failed} of " . ( $passed + $failed ) . " checks FAILED\n";

exit( 0 === $failed ? 0 : 1 );
