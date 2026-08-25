<?php
/**
 * This repository is PUBLIC, so it must never run on our own machine.
 *
 * ─── Why this guard lives here and not in a document ────────────────────────
 *
 * On a public repository anybody can open a pull request, and a `pull_request`
 * workflow runs the code in that pull request. On GitHub's runner that is a
 * throwaway virtual machine. On ours it is a container on the server that also
 * holds the licence database, with our deploy key in reach.
 *
 * The organisation has self-hosted runners and they are scoped per repository
 * on purpose. The tempting simplification is one ORGANISATION-scoped runner
 * serving everything -- and that is exactly the change that would hand this
 * repository the machine, silently, without anybody editing a file here.
 *
 * This plugin went public on 2026-08-24, which is what makes the guard
 * necessary now: before that the rule was true by accident.
 *
 * ─── What it checks ─────────────────────────────────────────────────────────
 *
 * A shape, not one string. `runs-on: self-hosted`, a label list containing it,
 * and the `vars.RUNNER_LABEL` switch the private repositories use are three
 * different ways to arrive at the same place.
 *
 * Usage: php tests/runner.php
 */

declare(strict_types=1);

$dir = dirname( __DIR__ ) . '/.github/workflows';

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

$files = glob( $dir . '/*.yml' );

// Without this the loop below could pass by finding nothing at all.
check( 'there are workflows to check', is_array( $files ) && count( $files ) > 0 );

foreach ( (array) $files as $file ) {
	$name = basename( $file );
	$src  = (string) file_get_contents( $file );

	check(
		"{$name} does not name a self-hosted runner",
		1 !== preg_match( '/runs-on:.*self-hosted/i', $src )
	);

	// The switch the private repositories carry. Harmless there, and here it
	// would mean one repository variable stands between a stranger's pull
	// request and our filesystem.
	check(
		"{$name} does not carry the RUNNER_LABEL switch",
		false === strpos( $src, 'RUNNER_LABEL' )
	);

	// And it must run somewhere. A workflow with no runner at all would satisfy
	// both lines above.
	check(
		"{$name} runs on a hosted runner",
		1 === preg_match( '/runs-on:\s*ubuntu-[a-z0-9.]+/i', $src )
	);
}

echo 0 === $failed
	? "all {$passed} checks passed\n"
	: "{$failed} of " . ( $passed + $failed ) . " checks FAILED\n";

exit( 0 === $failed ? 0 : 1 );
