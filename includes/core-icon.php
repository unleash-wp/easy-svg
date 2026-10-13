<?php
/**
 * What WordPress keeps of an icon, and how to hand it something faithful.
 *
 * `WP_Icons_Registry::sanitize_icon_content()` runs `wp_kses` over every icon
 * it is given and allows `svg`, `path` and `polygon` -- with `class`, `xmlns`,
 * `width`, `height`, `viewbox`, `aria-hidden`, `role` and `focusable` on the
 * root, and `fill`, `fill-rule`, `d`, `transform` on a path. There is no
 * filter. Everything else is dropped on the way IN, so `wp_get_icon()` and the
 * Icon block can only ever show what survived that pass.
 *
 * Measured on 7.1.3, which is what this file exists for:
 *
 *   <circle>, <rect>, <ellipse>, <line>, <polyline>, <g>  -> gone
 *   stroke, stroke-width, stroke-linecap, stroke-linejoin -> gone
 *   fill / opacity on the root <svg>                      -> gone
 *
 * An icon set drawn with strokes -- Feather, Lucide, Tabler, Heroicons outline,
 * most of what an agency ships -- therefore arrives as a handful of paths with
 * no stroke and no fill: an empty box, in the block and in every template that
 * calls `wp_get_icon()`.
 *
 * Two things can be done about it without fighting core, and this file does
 * both:
 *
 *   1. Geometry. A circle, a rect, an ellipse, a line and a polyline are each
 *      exactly expressible as a path, and a `<g>` is unwrapped onto its
 *      children -- `transform` is allowed on a path, so the grouping survives
 *      as composition.
 *   2. Presentation. `class` IS allowed on the root, so what core drops is
 *      carried as class names and restored by one small stylesheet.
 *
 * What cannot be carried is reported rather than silently lost: gradients,
 * masks, clip paths, `<use>`, `<text>` and per-element opacity have no
 * equivalent inside that allow-list.
 *
 * Pure: no WordPress, so every rule here is checked in tests/core-icon.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The class that restores a stroked icon, and the widths it can carry. */
const EASY_SVG_STROKE_CLASS = 'esvg-stroke';

/**
 * Stroke widths this can express as a class, as `width => suffix`.
 *
 * A fixed list rather than a generated class per value: the stylesheet ships
 * with the plugin, so a width nobody anticipated has to round to one of these
 * or be reported. These five cover every mainstream outline set.
 */
const EASY_SVG_STROKE_WIDTHS = array(
    '1'    => '1',
    '1.25' => '125',
    '1.5'  => '15',
    '1.75' => '175',
    '2'    => '2',
    '2.5'  => '25',
    '3'    => '3',
);

/**
 * One element's attributes as name => value.
 *
 * Quotes are required, which is what the sanitiser emits; an unquoted value is
 * not read rather than guessed at.
 *
 * @param string $tag The whole opening tag, `<path … />`.
 * @return array<string,string>
 */
function easy_svg_tag_attributes( $tag ) {
    $out = array();
    if ( preg_match_all( '/([a-zA-Z_:][a-zA-Z0-9_:.-]*)\s*=\s*"([^"]*)"/', (string) $tag, $m, PREG_SET_ORDER ) ) {
        foreach ( $m as $pair ) {
            $out[ strtolower( $pair[1] ) ] = $pair[2];
        }
    }
    return $out;
}

/** A number as SVG path data writes it: no trailing zeros, no locale comma. */
function easy_svg_path_number( $value ) {
    $number = round( (float) $value, 4 );
    $text   = rtrim( rtrim( number_format( $number, 4, '.', '' ), '0' ), '.' );
    return '' === $text ? '0' : $text;
}

/**
 * A circle or an ellipse as a path: two half arcs, which is the one form that
 * closes exactly and needs no curve approximation.
 */
function easy_svg_ellipse_path( $cx, $cy, $rx, $ry ) {
    $cx = (float) $cx;
    $cy = (float) $cy;
    $rx = abs( (float) $rx );
    $ry = abs( (float) $ry );
    if ( 0.0 === $rx || 0.0 === $ry ) {
        return '';
    }
    $n = 'easy_svg_path_number';
    return 'M' . $n( $cx - $rx ) . ' ' . $n( $cy )
        . 'a' . $n( $rx ) . ' ' . $n( $ry ) . ' 0 1 0 ' . $n( 2 * $rx ) . ' 0'
        . 'a' . $n( $rx ) . ' ' . $n( $ry ) . ' 0 1 0 ' . $n( -2 * $rx ) . ' 0z';
}

/**
 * A rect as a path, rounded corners included.
 *
 * SVG's own rules for rx/ry: one given means both, and each is clamped to half
 * the side. Getting that wrong draws a shape the original never had.
 */
function easy_svg_rect_path( $x, $y, $width, $height, $rx = '', $ry = '' ) {
    $x = (float) $x;
    $y = (float) $y;
    $w = (float) $width;
    $h = (float) $height;
    if ( $w <= 0.0 || $h <= 0.0 ) {
        return '';
    }

    $has_rx = '' !== (string) $rx;
    $has_ry = '' !== (string) $ry;
    $r_x    = $has_rx ? abs( (float) $rx ) : ( $has_ry ? abs( (float) $ry ) : 0.0 );
    $r_y    = $has_ry ? abs( (float) $ry ) : ( $has_rx ? abs( (float) $rx ) : 0.0 );
    $r_x    = min( $r_x, $w / 2 );
    $r_y    = min( $r_y, $h / 2 );

    $n = 'easy_svg_path_number';
    if ( 0.0 === $r_x || 0.0 === $r_y ) {
        return 'M' . $n( $x ) . ' ' . $n( $y ) . 'h' . $n( $w ) . 'v' . $n( $h ) . 'h' . $n( -$w ) . 'z';
    }

    return 'M' . $n( $x + $r_x ) . ' ' . $n( $y )
        . 'h' . $n( $w - 2 * $r_x )
        . 'a' . $n( $r_x ) . ' ' . $n( $r_y ) . ' 0 0 1 ' . $n( $r_x ) . ' ' . $n( $r_y )
        . 'v' . $n( $h - 2 * $r_y )
        . 'a' . $n( $r_x ) . ' ' . $n( $r_y ) . ' 0 0 1 ' . $n( -$r_x ) . ' ' . $n( $r_y )
        . 'h' . $n( -( $w - 2 * $r_x ) )
        . 'a' . $n( $r_x ) . ' ' . $n( $r_y ) . ' 0 0 1 ' . $n( -$r_x ) . ' ' . $n( -$r_y )
        . 'v' . $n( -( $h - 2 * $r_y ) )
        . 'a' . $n( $r_x ) . ' ' . $n( $r_y ) . ' 0 0 1 ' . $n( $r_x ) . ' ' . $n( -$r_y )
        . 'z';
}

/** A points list as a path. Open for a polyline, closed for a polygon. */
function easy_svg_points_path( $points, $close ) {
    $numbers = preg_split( '/[\s,]+/', trim( (string) $points ), -1, PREG_SPLIT_NO_EMPTY );
    if ( ! is_array( $numbers ) || count( $numbers ) < 4 ) {
        return '';
    }
    $pairs = array();
    for ( $i = 0; $i + 1 < count( $numbers ); $i += 2 ) {
        $pairs[] = easy_svg_path_number( $numbers[ $i ] ) . ' ' . easy_svg_path_number( $numbers[ $i + 1 ] );
    }
    return 'M' . implode( 'L', $pairs ) . ( $close ? 'z' : '' );
}

/**
 * The same icon, rewritten so WordPress's registry can keep what matters.
 *
 * DOM rather than a regular expression, like the hardener next door: this
 * rewrites structure, and a pattern over markup is how a `<g>` ends up half
 * unwrapped.
 *
 * @param string $markup Sanitised, hardened icon markup.
 * @return array{content:string,classes:array<int,string>,lost:array<int,string>}
 */
function easy_svg_core_icon( $markup ) {
    $empty = array(
        'content' => '',
        'classes' => array(),
        'lost'    => array(),
    );

    $doc      = new DOMDocument();
    $internal = libxml_use_internal_errors( true );
    $loaded   = $doc->loadXML( (string) $markup, LIBXML_NONET );
    libxml_clear_errors();
    libxml_use_internal_errors( $internal );

    if ( ! $loaded || null === $doc->documentElement ) {
        return $empty;
    }

    $root = $doc->documentElement;
    if ( 'svg' !== strtolower( $root->localName ) ) {
        return $empty;
    }

    $lost  = array();
    $xpath = new DOMXPath( $doc );

    // ── What the registry will drop, read before anything is rewritten ──────
    $stroke = easy_svg_stroke_presentation( $xpath, $root, $lost );

    // ── Groups, innermost first, so a nested one is flattened into its own
    // parent before that parent is flattened in turn ───────────────────────
    $groups = array();
    foreach ( $xpath->query( '//*[local-name()="g"]' ) as $group ) {
        $groups[] = $group;
    }
    foreach ( array_reverse( $groups ) as $group ) {
        easy_svg_flatten_group( $group );
    }

    // ── Shapes the registry has no tag for ─────────────────────────────────
    foreach ( $xpath->query( '//*' ) as $element ) {
        $name = strtolower( $element->localName );
        if ( in_array( $name, array( 'svg', 'path', 'polygon' ), true ) ) {
            continue;
        }

        $path = easy_svg_shape_as_path( $doc, $element );
        if ( null !== $path ) {
            $element->parentNode->replaceChild( $path, $element );
            continue;
        }

        // Gradients, masks, <use>, <text>: nothing in the allow-list can hold
        // them, and core would drop them anyway. Said out loud rather than
        // left for somebody to notice on a page.
        $lost[] = $name;
        if ( $element->parentNode ) {
            $element->parentNode->removeChild( $element );
        }
    }

    $classes = $stroke['classes'];
    if ( array() !== $classes ) {
        $existing = trim( (string) $root->getAttribute( 'class' ) );
        $root->setAttribute( 'class', trim( $existing . ' ' . implode( ' ', $classes ) ) );
    }

    return array(
        'content' => (string) $doc->saveXML( $root ),
        'classes' => $classes,
        'lost'    => array_values( array_unique( $lost ) ),
    );
}

/**
 * How this icon is stroked, as classes the registry keeps.
 *
 * Only the case that can be restored honestly: every drawing element either
 * says `fill="none"` or inherits it from the root, and something sets a
 * stroke. A mixed icon -- some parts filled, some stroked -- cannot be
 * expressed with one class, so its stroke is reported as lost rather than
 * forced onto the filled parts too.
 *
 * @param DOMXPath   $xpath
 * @param DOMElement $root
 * @param array      $lost  Collected losses, appended to.
 * @return array{classes:array<int,string>}
 */
function easy_svg_stroke_presentation( $xpath, $root, &$lost ) {
    $drawing = array();
    foreach ( $xpath->query( '//*[local-name()!="svg"]' ) as $element ) {
        $drawing[] = $element;
    }
    if ( array() === $drawing ) {
        return array( 'classes' => array() );
    }

    $root_fill   = strtolower( trim( (string) $root->getAttribute( 'fill' ) ) );
    $root_stroke = trim( (string) $root->getAttribute( 'stroke' ) );

    $width   = '';
    $cap     = '';
    $join    = '';
    $stroked = false;
    $filled  = false;
    $colour  = '';

    foreach ( $drawing as $element ) {
        $fill        = strtolower( trim( (string) $element->getAttribute( 'fill' ) ) );
        $own_stroke  = trim( (string) $element->getAttribute( 'stroke' ) );
        $this_stroke = '' !== $own_stroke ? $own_stroke : $root_stroke;
        $this_fill   = '' !== $fill ? $fill : $root_fill;

        if ( '' !== $this_stroke && 'none' !== strtolower( $this_stroke ) ) {
            $stroked = true;
            if ( '' === $colour ) {
                $colour = $this_stroke;
            }
            $width = '' !== $width ? $width : trim( (string) $element->getAttribute( 'stroke-width' ) );
            $cap   = '' !== $cap ? $cap : strtolower( trim( (string) $element->getAttribute( 'stroke-linecap' ) ) );
            $join  = '' !== $join ? $join : strtolower( trim( (string) $element->getAttribute( 'stroke-linejoin' ) ) );
        }
        if ( 'none' !== $this_fill ) {
            $filled = true;
        }
    }

    if ( ! $stroked ) {
        return array( 'classes' => array() );
    }

    // Mixed: one class cannot say "stroke these and fill those".
    if ( $filled ) {
        $lost[] = 'stroke';
        return array( 'classes' => array() );
    }

    $classes = array( EASY_SVG_STROKE_CLASS );

    $width = '' !== $width ? $width : trim( (string) $root->getAttribute( 'stroke-width' ) );
    $width = '' === $width ? '1' : $width;
    if ( isset( EASY_SVG_STROKE_WIDTHS[ $width ] ) ) {
        $classes[] = 'esvg-sw-' . EASY_SVG_STROKE_WIDTHS[ $width ];
    } else {
        // A width the stylesheet has no class for. The icon is visible with the
        // default rather than invisible, and the difference is reported.
        $lost[] = 'stroke-width';
    }

    $cap  = '' !== $cap ? $cap : strtolower( trim( (string) $root->getAttribute( 'stroke-linecap' ) ) );
    $join = '' !== $join ? $join : strtolower( trim( (string) $root->getAttribute( 'stroke-linejoin' ) ) );
    if ( 'round' === $cap ) {
        $classes[] = 'esvg-cap-round';
    }
    if ( 'round' === $join ) {
        $classes[] = 'esvg-join-round';
    }

    // A stroke in a fixed colour becomes currentColor: visible, and honest
    // about the one thing that changed.
    $colour = strtolower( $colour );
    if ( '' !== $colour && 'currentcolor' !== $colour && 'inherit' !== $colour ) {
        $lost[] = 'stroke-colour';
    }

    return array( 'classes' => $classes );
}

/**
 * Move a group's children up to its parent and let the group go.
 *
 * `transform` is allowed on a path, so the grouping survives as composition:
 * the parent's transform runs first, which is the order SVG reads a transform
 * list in. `fill` and `fill-rule` are pushed down to any child that has none
 * of its own.
 *
 * @param DOMElement $group
 */
function easy_svg_flatten_group( $group ) {
    if ( ! $group->parentNode ) {
        return;
    }

    $transform = trim( (string) $group->getAttribute( 'transform' ) );
    $fill      = trim( (string) $group->getAttribute( 'fill' ) );
    $fill_rule = trim( (string) $group->getAttribute( 'fill-rule' ) );

    $children = array();
    foreach ( $group->childNodes as $child ) {
        $children[] = $child;
    }

    foreach ( $children as $child ) {
        if ( XML_ELEMENT_NODE === $child->nodeType ) {
            if ( '' !== $transform ) {
                $own = trim( (string) $child->getAttribute( 'transform' ) );
                $child->setAttribute( 'transform', trim( $transform . ' ' . $own ) );
            }
            if ( '' !== $fill && '' === trim( (string) $child->getAttribute( 'fill' ) ) ) {
                $child->setAttribute( 'fill', $fill );
            }
            if ( '' !== $fill_rule && '' === trim( (string) $child->getAttribute( 'fill-rule' ) ) ) {
                $child->setAttribute( 'fill-rule', $fill_rule );
            }
        }
        $group->parentNode->insertBefore( $child, $group );
    }

    $group->parentNode->removeChild( $group );
}

/**
 * One shape as a `<path>`, or null when it is not a shape this can express.
 *
 * `fill`, `fill-rule` and `transform` travel with it -- the three attributes
 * the registry keeps on a path.
 *
 * @param DOMDocument $doc
 * @param DOMElement  $element
 * @return DOMElement|null
 */
function easy_svg_shape_as_path( $doc, $element ) {
    $name = strtolower( $element->localName );
    $a    = array();
    foreach ( $element->attributes as $attribute ) {
        $a[ strtolower( $attribute->localName ) ] = (string) $attribute->nodeValue;
    }

    switch ( $name ) {
        case 'circle':
            $d = easy_svg_ellipse_path( $a['cx'] ?? 0, $a['cy'] ?? 0, $a['r'] ?? 0, $a['r'] ?? 0 );
            break;
        case 'ellipse':
            $d = easy_svg_ellipse_path( $a['cx'] ?? 0, $a['cy'] ?? 0, $a['rx'] ?? 0, $a['ry'] ?? 0 );
            break;
        case 'rect':
            $d = easy_svg_rect_path( $a['x'] ?? 0, $a['y'] ?? 0, $a['width'] ?? 0, $a['height'] ?? 0, $a['rx'] ?? '', $a['ry'] ?? '' );
            break;
        case 'line':
            $d = 'M' . easy_svg_path_number( $a['x1'] ?? 0 ) . ' ' . easy_svg_path_number( $a['y1'] ?? 0 )
                . 'L' . easy_svg_path_number( $a['x2'] ?? 0 ) . ' ' . easy_svg_path_number( $a['y2'] ?? 0 );
            break;
        case 'polyline':
            $d = easy_svg_points_path( $a['points'] ?? '', false );
            break;
        default:
            return null;
    }

    if ( '' === $d ) {
        return null;
    }

    $path = $doc->createElementNS( 'http://www.w3.org/2000/svg', 'path' );
    $path->setAttribute( 'd', $d );
    foreach ( array( 'fill', 'fill-rule', 'transform' ) as $keep ) {
        if ( isset( $a[ $keep ] ) && '' !== $a[ $keep ] ) {
            $path->setAttribute( $keep, $a[ $keep ] );
        }
    }

    return $path;
}
