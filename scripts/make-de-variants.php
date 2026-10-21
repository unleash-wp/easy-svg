<?php
/**
 * Build the German locale variants from languages/*-de_DE.po.
 *
 * WordPress loads a catalogue named after the EXACT locale. A site set to
 * de_DE_formal, de_AT or de_CH looks for its own file, finds nothing, and
 * falls back to English -- measured on a de_DE_formal site: the panel was
 * German (the JS catalogue resolves differently) while the admin menu beside
 * it and every PHP message were not. For a plugin on wordpress.org the
 * translation site fills those in eventually; a plugin sold off it has only
 * what ships.
 *
 * WordPress's de_CH is the FORMAL Swiss locale and de_CH_informal the du one,
 * so de_CH is derived from the formal wording and de_CH_informal from de_DE,
 * both with Swiss spelling (ss for ß). de_AT is a plain copy of de_DE.
 *
 * de_AT, de_CH and de_CH_informal were once the same translation: regional, and
 * informal like ours. de_DE_formal is NOT -- somebody chose "Sie". Its file is
 * derived through de_DE_formal.map.json, one entry per string that addresses
 * the reader, and a test fails when a new one appears that the map has no
 * answer for.
 *
 * Usage: php scripts/make-de-variants.php   (called by scripts/build-i18n.sh)
 */

declare(strict_types=1);

$root    = dirname( __DIR__ );
$dir     = $root . '/languages';
$sources = glob( $dir . '/*-de_DE.po' ) ?: array();

if ( array() === $sources ) {
	fwrite( STDERR, "no *-de_DE.po in {$dir}\n" );
	exit( 1 );
}

/** Plain copies: same words, different locale name. */
const COPIES = array( 'de_AT' );

/** Swiss orthography in every translation: no ß, ss instead. msgids stay as written. */
function swiss( string $po ): string {
	$blocks = explode( "\n\n", $po );
	foreach ( $blocks as $k => $block ) {
		$at = strpos( $block, "\nmsgstr" );
		if ( false === $at ) {
			$at = 0 === strpos( $block, 'msgstr' ) ? 0 : false;
		}
		if ( false !== $at ) {
			$blocks[ $k ] = substr( $block, 0, $at ) . str_replace( array( 'ß', 'ẞ' ), array( 'ss', 'SS' ), substr( $block, $at ) );
		}
	}
	return implode( "\n\n", $blocks );
}

/** Rename the Language header of a derived catalogue. */
function relabel( string $po, string $from, string $to ): string {
	return (string) preg_replace( '/^("Language: )' . $from . '(\\\\n")$/m', '${1}' . $to . '${2}', $po );
}

/** Read a .po into blocks, so a multi-line entry survives the round trip. */
function po_blocks( string $text ): array {
	return explode( "\n\n", $text );
}

/** The joined text of one msgid/msgstr run. */
function po_text( array $lines ): string {
	$out = '';
	foreach ( $lines as $line ) {
		if ( 1 === preg_match( '/^(?:msgid|msgid_plural|msgstr(?:\[\d\])?)?\s*"(.*)"$/', $line, $m ) ) {
			$out .= $m[1];
		}
	}
	return str_replace( array( '\\"', '\\\\' ), array( '"', '\\' ), $out );
}

/** One msgstr line, escaped as a .po wants it. */
function po_line( string $keyword, string $text ): string {
	$escaped = str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $text );
	return sprintf( '%s "%s"', $keyword, $escaped );
}

/**
 * Rewrite every msgstr through $map, and say which map entries were unused and
 * which addressed strings had no entry.
 *
 * @param array<string,string> $map
 * @return array{0:string,1:array<int,string>,2:array<int,string>}
 */
function apply_map( string $po, array $map ): array {
	// A string that speaks to the reader. Deliberately broad: a false positive
	// costs one map entry, a false negative ships "du" to somebody who asked
	// for "Sie".
	$addresses = '/\b('
		// Imperatives, case-insensitive: "Setze" opens a sentence and
		// "versuche es" closes one, and an earlier version of this list caught
		// only the capitalised ones -- so the formal catalogue it built said
		// "Lade die Seite neu und versuche es erneut" and called itself formal.
		. 'schalte|wähle|lege|leg|gib|nenne|versuche|versuch|aktiviere|kopiere|bündele|erweitere'
		. '|prüfe|trage|klicke|lade|setze|öffne|füge|entferne|speichere|ziehe|drücke|besuche'
		. '|aktualisiere|achte|beachte|starte|wende|kontaktiere|schreibe|lies|benenne'
		// "Führe `wp …` aus" slipped through a list that had every other verb on
		// it: the formal catalogue said "Führe" and nothing complained. Added in
		// easy-svg-pro first and backported, which is why tests/run.php now
		// holds the list to a named set in each repository that has one.
		. '|führe|rufe|melde|wiederhole|exportiere|importiere|installiere'
		// The updater's account sign-in, move and renewal verbs, and "frage":
		// easy-svg-pro's formal catalogue shipped "Frage list-icons …" as du.
		. '|fordere|warte|kaufe|bestätige|verbinde|erneuere|frage'
		// Not "stelle": "an Ort und Stelle" is a noun, and a false positive
		// here asks for a map entry that changes nothing, which reads like an
		// oversight rather than a decision.
		// Pronouns.
		. '|du|dir|dich|dein|deine|deinen|deinem|deiner|deines'
		. ')\b/iu';

	$used    = array();
	$missing = array();
	$out     = array();

	foreach ( po_blocks( $po ) as $block ) {
		if ( 0 === strpos( ltrim( $block ), '#~' ) ) {
			$out[] = $block;
			continue;
		}
		$lines = explode( "\n", $block );
		$new   = array();
		$i     = 0;
		while ( $i < count( $lines ) ) {
			if ( 1 !== preg_match( '/^(msgstr(?:\[\d\])?) "/', $lines[ $i ], $m ) ) {
				$new[] = $lines[ $i ];
				++$i;
				continue;
			}
			$keyword = $m[1];
			$j       = $i + 1;
			while ( $j < count( $lines ) && 0 === strpos( $lines[ $j ], '"' ) ) {
				++$j;
			}
			$text = po_text( array_slice( $lines, $i, $j - $i ) );
			if ( '' !== $text && isset( $map[ $text ] ) ) {
				$new[]  = po_line( $keyword, $map[ $text ] );
				$used[] = $text;
			} else {
				if ( '' !== $text && 1 === preg_match( $addresses, $text ) ) {
					$missing[] = $text;
				}
				$new = array_merge( $new, array_slice( $lines, $i, $j - $i ) );
			}
			$i = $j;
		}
		$out[] = implode( "\n", $new );
	}

	return array( implode( "\n\n", $out ), array_values( array_diff( array_keys( $map ), $used ) ), $missing );
}

$written = array();
$faults  = array();

foreach ( $sources as $source ) {
	$po       = (string) file_get_contents( $source );
	$basename = basename( $source, '-de_DE.po' );

	foreach ( COPIES as $locale ) {
		$copy = preg_replace( '/^("Language: )de_DE(\\\\n")$/m', '${1}' . $locale . '${2}', $po );
		file_put_contents( sprintf( '%s/%s-%s.po', $dir, $basename, $locale ), $copy );
		$written[] = $locale;
	}

	$map_file = $dir . '/de_DE_formal.map.json';
	$map      = is_readable( $map_file ) ? json_decode( (string) file_get_contents( $map_file ), true ) : array();
	if ( ! is_array( $map ) ) {
		fwrite( STDERR, "de_DE_formal.map.json is not valid JSON\n" );
		exit( 1 );
	}

	list( $formal, $unused, $missing ) = apply_map( $po, $map );
	$formal                            = preg_replace( '/^("Language: )de_DE(\\\\n")$/m', '${1}de_DE_formal${2}', $formal );
	file_put_contents( sprintf( '%s/%s-de_DE_formal.po', $dir, $basename ), $formal );
	$written[] = 'de_DE_formal';

	file_put_contents( sprintf( '%s/%s-de_CH.po', $dir, $basename ), swiss( relabel( $formal, 'de_DE_formal', 'de_CH' ) ) );
	file_put_contents( sprintf( '%s/%s-de_CH_informal.po', $dir, $basename ), swiss( relabel( $po, 'de_DE', 'de_CH_informal' ) ) );
	$written[] = 'de_CH';
	$written[] = 'de_CH_informal';

	foreach ( $missing as $text ) {
		$faults[] = 'no formal wording for: ' . $text;
	}
	foreach ( $unused as $text ) {
		$faults[] = 'map entry matches nothing: ' . $text;
	}
}

if ( array() !== $faults ) {
	fwrite( STDERR, "de_DE_formal is incomplete:\n  " . implode( "\n  ", $faults ) . "\n" );
	exit( 1 );
}

echo 'German variants written: ' . implode( ', ', array_unique( $written ) ) . "\n";
