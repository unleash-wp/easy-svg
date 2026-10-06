<?php
/**
 * One option, one page, for turning features off and sizing the parser.
 *
 * Everything here reads a single option so the gate below cannot drift from
 * what the screen shows. Defaults are on and usable: the free plugin is whole
 * with the settings untouched.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EASY_SVG_SETTINGS_OPTION = 'easy_svg_settings';

/** The shape and the on-by-default values. */
function easy_svg_settings_defaults(): array {
	return array(
		'svg_upload' => true,
		'icons'      => true,
		'max_mb'     => 2,
	);
}

/** Stored settings over the defaults, read once per request. */
function easy_svg_settings(): array {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}
	$stored = get_option( EASY_SVG_SETTINGS_OPTION, array() );
	$cache  = easy_svg_sanitize_settings( is_array( $stored ) ? $stored : array() );
	return $cache;
}

/**
 * Booleans are booleans; the size is an integer megabyte in 1..20.
 *
 * A missing key keeps its default (on), so an older stored option without a
 * newer toggle does not read as "off".
 *
 * @param mixed $input
 * @return array
 */
function easy_svg_sanitize_settings( $input ): array {
	$input    = is_array( $input ) ? $input : array();
	$defaults = easy_svg_settings_defaults();

	$max_mb = isset( $input['max_mb'] ) ? (int) $input['max_mb'] : $defaults['max_mb'];
	$max_mb = max( 1, min( 20, $max_mb ) );

	return array(
		'svg_upload' => array_key_exists( 'svg_upload', $input ) ? (bool) $input['svg_upload'] : $defaults['svg_upload'],
		'icons'      => array_key_exists( 'icons', $input ) ? (bool) $input['icons'] : $defaults['icons'],
		'max_mb'     => $max_mb,
	);
}
