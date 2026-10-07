<?php
/**
 * Checks a built package against what the WooCommerce.com Marketplace reads when a ZIP is uploaded:
 * a changelog.txt in its format, whose newest entry carries the plugin's version. Not shipped in the release ZIP.
 *
 * Run: php tests/package.php build/<plugin-folder>
 *
 * @package Inline_Order_Editor
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

$ioefw_dir    = rtrim( isset( $argv[1] ) ? $argv[1] : '', '/' );
$ioefw_failed = 0;

/**
 * Prints one result line and counts a failure.
 *
 * @param bool   $passed Whether the check held.
 * @param string $label  What was checked.
 */
function ioefw_package_check( $passed, $label ) {
	global $ioefw_failed;

	echo ( $passed ? 'PASS  ' : 'FAIL  ' ) . $label . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A command-line script.

	if ( ! $passed ) {
		++$ioefw_failed;
	}
}

$ioefw_header   = '';
$ioefw_name     = '';
$ioefw_constant = '';

foreach ( (array) glob( $ioefw_dir . '/*.php' ) as $ioefw_file ) {
	$ioefw_source = (string) file_get_contents( $ioefw_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file, outside WordPress.

	if ( preg_match( '/^[ \t\/*#@]*Plugin Name:\s*(.+)$/mi', $ioefw_source, $ioefw_found ) && preg_match( '/^[ \t\/*#@]*Version:\s*(\S+)/mi', $ioefw_source, $ioefw_version ) ) {
		$ioefw_name     = trim( $ioefw_found[1] );
		$ioefw_header   = $ioefw_version[1];
		$ioefw_constant = preg_match( "/define\\(\\s*'[A-Z_]+_VERSION',\\s*'([^']+)'/", $ioefw_source, $ioefw_defined ) ? $ioefw_defined[1] : '';
	}
}

ioefw_package_check( '' !== $ioefw_header, 'The package has a plugin file with a Version header.' );
ioefw_package_check( '' !== $ioefw_header && $ioefw_constant === $ioefw_header, 'The version constant in that file is the header version, ' . $ioefw_header . '.' );

$ioefw_readme = is_readable( $ioefw_dir . '/readme.txt' ) ? (string) file_get_contents( $ioefw_dir . '/readme.txt' ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file, outside WordPress.
$ioefw_stable = preg_match( '/^Stable tag:\s*(\S+)/mi', $ioefw_readme, $ioefw_found ) ? $ioefw_found[1] : '';

ioefw_package_check( '' !== $ioefw_header && $ioefw_stable === $ioefw_header, 'The readme\'s Stable tag is the header version, ' . $ioefw_header . '.' );

$ioefw_log   = is_readable( $ioefw_dir . '/changelog.txt' ) ? (string) file_get_contents( $ioefw_dir . '/changelog.txt' ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file, outside WordPress.
$ioefw_lines = null === $ioefw_log ? array() : preg_split( '/\r?\n/', rtrim( $ioefw_log ) );

ioefw_package_check( null !== $ioefw_log, 'changelog.txt ships in the package.' );
ioefw_package_check( $ioefw_lines && '*** ' . $ioefw_name . ' Changelog ***' === $ioefw_lines[0], 'It opens with "*** ' . $ioefw_name . ' Changelog ***".' );

$ioefw_versions = array();
$ioefw_dates    = array();
$ioefw_odd      = array();

foreach ( array_slice( $ioefw_lines, 1 ) as $ioefw_line ) {
	if ( '' === $ioefw_line ) {
		continue;
	}

	if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2}) - version (\d+\.\d+\.\d+)$/', $ioefw_line, $ioefw_found ) && checkdate( (int) $ioefw_found[2], (int) $ioefw_found[3], (int) $ioefw_found[1] ) ) {
		$ioefw_versions[] = $ioefw_found[4];
		$ioefw_dates[]    = $ioefw_found[1] . $ioefw_found[2] . $ioefw_found[3];
	} elseif ( ! $ioefw_versions || ! preg_match( '/^( {4})?\* \S/', $ioefw_line ) ) {
		// Anything else has to be a "* " entry, or one nested four spaces under it, below a dated version.
		$ioefw_odd[] = $ioefw_line;
	}
}

ioefw_package_check( $ioefw_versions && $ioefw_versions[0] === $ioefw_header, 'Its newest entry is version ' . $ioefw_header . ', the same as the plugin header.' );
ioefw_package_check( $ioefw_lines && ! $ioefw_odd, 'Every other line is a dated version or a "* " entry.' . ( $ioefw_odd ? ' Not: ' . $ioefw_odd[0] : '' ) );

$ioefw_ordered = count( $ioefw_versions ) > 0;

foreach ( $ioefw_versions as $ioefw_index => $ioefw_one ) {
	if ( $ioefw_index && ( ! version_compare( $ioefw_versions[ $ioefw_index - 1 ], $ioefw_one, '>' ) || $ioefw_dates[ $ioefw_index - 1 ] < $ioefw_dates[ $ioefw_index ] ) ) {
		$ioefw_ordered = false;
	}
}

ioefw_package_check( $ioefw_ordered, 'Versions run from newest to oldest, and no entry is dated before the one below it.' );

echo ( $ioefw_failed ? $ioefw_failed . ' failed.' : 'All passed.' ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A command-line script.

exit( $ioefw_failed ? 1 : 0 );
