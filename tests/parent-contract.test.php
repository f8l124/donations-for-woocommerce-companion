<?php
/**
 * Phase F.2 — parent-plugin contract watcher.
 *
 * Reads tests/parent-contract.baseline.json and compares the SHA-256 of each
 * declared (file, start_line, end_line) range against the current parent
 * source. Exits 0 on match, 1 on any mismatch. Designed for CI cron.
 *
 * Configure the parent plugin path via the DFWC_PARENT_PATH environment
 * variable (defaults to ./tests/donation-for-woocommerce/, which CI populates
 * by extracting the wp.org zip).
 *
 * Usage:
 *   DFWC_PARENT_PATH=/path/to/donation-for-woocommerce php tests/parent-contract.test.php
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This script must be run from the CLI.\n" );
	exit( 2 );
}

$baseline_path = __DIR__ . '/parent-contract.baseline.json';
if ( ! is_file( $baseline_path ) ) {
	fwrite( STDERR, "ERROR: baseline file missing at {$baseline_path}.\n" );
	fwrite( STDERR, "Run `php tests/parent-contract.update-baseline.php` first.\n" );
	exit( 2 );
}

$parent_path = rtrim( getenv( 'DFWC_PARENT_PATH' ) ?: ( __DIR__ . '/donation-for-woocommerce' ), '/\\' );
if ( ! is_dir( $parent_path ) ) {
	fwrite( STDERR, "ERROR: parent plugin path not found: {$parent_path}\n" );
	fwrite( STDERR, "Set DFWC_PARENT_PATH to the donation-for-woocommerce directory.\n" );
	exit( 2 );
}

$baseline_raw = file_get_contents( $baseline_path );
$baseline     = json_decode( $baseline_raw, true );
if ( ! is_array( $baseline ) || ! isset( $baseline['ranges'] ) ) {
	fwrite( STDERR, "ERROR: baseline file is malformed.\n" );
	exit( 2 );
}

$failures = 0;
$total    = 0;

foreach ( $baseline['ranges'] as $entry ) {
	$total++;
	$rel_file = (string) ( $entry['file'] ?? '' );
	$start    = (int) ( $entry['start'] ?? 0 );
	$end      = (int) ( $entry['end'] ?? 0 );
	$expected = (string) ( $entry['sha256'] ?? '' );
	$note     = (string) ( $entry['note'] ?? '' );

	$abs_file = $parent_path . DIRECTORY_SEPARATOR . $rel_file;
	if ( ! is_file( $abs_file ) ) {
		fwrite( STDERR, "FAIL  {$rel_file}: file missing\n" );
		$failures++;
		continue;
	}

	$actual = sha256_of_lines( $abs_file, $start, $end );
	if ( $actual !== $expected ) {
		$failures++;
		fwrite( STDERR, "FAIL  {$rel_file}:{$start}-{$end}\n" );
		if ( $note !== '' ) {
			fwrite( STDERR, "      ({$note})\n" );
		}
		fwrite( STDERR, "      expected: {$expected}\n" );
		fwrite( STDERR, "      actual:   {$actual}\n" );
		fwrite( STDERR, "      Diff manually, then either patch the companion or refresh the baseline.\n\n" );
	} else {
		fwrite( STDOUT, "OK    {$rel_file}:{$start}-{$end}\n" );
	}
}

if ( $failures > 0 ) {
	fwrite( STDERR, "\n{$failures} of {$total} contract checks FAILED.\n" );
	fwrite( STDERR, "If parent intentionally changed something we depend on, audit the\n" );
	fwrite( STDERR, "diff and run: php tests/parent-contract.update-baseline.php\n" );
	exit( 1 );
}

fwrite( STDOUT, "\nAll {$total} contract checks passed.\n" );
exit( 0 );

/**
 * Hash a 1-indexed inclusive line range from a file.
 */
function sha256_of_lines( string $path, int $start, int $end ): string {
	$lines = file( $path, FILE_IGNORE_NEW_LINES );
	if ( ! is_array( $lines ) ) {
		return '';
	}
	$slice = array_slice( $lines, max( 0, $start - 1 ), max( 0, $end - $start + 1 ) );
	return hash( 'sha256', implode( "\n", $slice ) );
}
