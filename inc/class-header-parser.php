<?php
/**
 * File: class-header-parser.php
 * 
 * Handles the extraction and validation of custom plugin headers for the Scope Loader.
 * 
 * @package WandTech\ScopeLoader
 * @version 2.0.0
 */

declare(strict_types=1);

namespace WandTech\ScopeLoader;

// Prevent direct file access for security.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Header_Parser
 *
 * Responsible for extracting and validating custom headers ('Scope', 'Scope-Requires')
 * from plugin files. It utilizes pure PHP file reading and optimized Regex
 * to avoid early-load dependencies on WordPress core functions like get_file_data.
 *
 * Implements a strict Fail-Closed architecture for scope validation.
 */
class Header_Parser {

	/**
	 * Defines the set of universally valid environment scopes.
	 * Any scope outside this list will be considered invalid.
	 * 
	 * @var array<string>
	 */
	public const VALID_SCOPES = [
		'all',       // Loads on all environments
		'front',     // Loads on the website's front-end only
		'admin',     // Loads in the WordPress admin area only
		'ajax',      // Loads during AJAX requests
		'rest',      // Loads during REST API requests
		'cron',      // Loads during WP-Cron job executions
		'cli',       // Loads when executed via WP-CLI
	];

	/**
	 * Parses a given plugin file to extract and validate its custom headers.
	 *
	 * This method reads only the initial segment of the plugin file (8KB) for efficiency,
	 * avoiding unnecessary file I/O bottlenecks. It then uses optimized Regex to find 
	 * and parse the 'Scope' and 'Scope-Requires' headers.
	 *
	 * @param string $plugin_file The relative path to the plugin file (e.g., 'plugin-folder/plugin.php').
	 *
	 * @return array{scopes: array<string>, requires: array<string>} An associative array containing:
	 *         - 'scopes': Array of validated scopes. Empty if 'all' or no scope is defined.
	 *         - 'requires': Array of plugin dependencies.
	 */
	public function parse( string $plugin_file ): array {
		$result = [
			'scopes'   => [],
			'requires' => [],
		];

		// Construct the full server path to the plugin file.
		$full_path = WP_PLUGIN_DIR . '/' . $plugin_file;

		// Ensure the file exists and is readable before attempting to open it.
		if ( ! is_readable( $full_path ) ) {
			error_log( sprintf( '[WandTech Scope Loader] WARNING: Plugin file "%s" is not readable.', $plugin_file ) );
			return $result;
		}

		// Open the file in read mode.
		$fp = fopen( $full_path, 'r' );
		if ( ! $fp ) {
			error_log( sprintf( '[WandTech Scope Loader] WARNING: Could not open plugin file "%s".', $plugin_file ) );
			return $result;
		}

		// Read only the first 8192 bytes (8KB). 
		// This is a standard WordPress optimization to avoid reading entire massive PHP files into RAM.
		$file_data = fread( $fp, 8192 );
		fclose( $fp );

		if ( false === $file_data ) {
			error_log( sprintf( '[WandTech Scope Loader] WARNING: Could not read data from plugin file "%s".', $plugin_file ) );
			return $result;
		}

		// Normalize line endings (\n) for consistent Regex matching across different Operating Systems.
		$file_data = str_replace( "\r", "\n", $file_data );

		// --- Header Extraction using Regex ---

		// 1. Extract 'Scope' header.
		// Regex explanation: Matches lines starting with optional whitespace/comment chars, 
		// followed by 'Scope:', then captures everything until the end of the line.
		if ( preg_match( '/^[ \t\/*#@]*Scope:\s*(.*)$/mi', $file_data, $scope_match ) && ! empty( $scope_match[1] ) ) {
			$raw_scopes = array_map( 'trim', explode( ',', strtolower( $scope_match[1] ) ) );
			// Validate and sanitize the extracted scopes.
			$result['scopes'] = $this->validate_scopes( $raw_scopes, $plugin_file );
		}

		// 2. Extract 'Scope-Requires' header.
		if ( preg_match( '/^[ \t\/*#@]*Scope-Requires:\s*(.*)$/mi', $file_data, $requires_match ) && ! empty( $requires_match[1] ) ) {
			// Split dependencies, trim whitespace, and filter out empty strings.
			$raw_requires = array_map( 'trim', explode( ',', $requires_match[1] ) );
			$result['requires'] = array_values( array_filter( $raw_requires ) );
		}

		return $result;
	}

	/**
	 * Validates the extracted scopes against the predefined valid scopes.
	 *
	 * Implements the Fail-Closed architecture: if a plugin explicitly declares scopes
	 * but ALL of them are invalid (e.g., due to typos like 'admn'), the plugin will never load
	 * to prevent unexpected behavior in production.
	 * 
	 * @param array<string> $raw_scopes  The raw, potentially invalid, scopes extracted from the header.
	 * @param string        $plugin_file The path to the plugin file, used for logging context.
	 *
	 * @return array<string> An array containing only the validated and allowed scopes.
	 *                       Returns ['__never__'] if the Fail-Closed condition is met.
	 */
	private function validate_scopes( array $raw_scopes, string $plugin_file ): array {
		// Filter out any empty items from the raw array
		$raw_scopes = array_filter( $raw_scopes );

		if ( empty( $raw_scopes ) ) {
			return [];
		}

		// Calculate valid and invalid scopes using array intersection and difference.
		$valid_scopes   = array_intersect( $raw_scopes, self::VALID_SCOPES );
		$invalid_scopes = array_diff( $raw_scopes, self::VALID_SCOPES );

		// Log a warning if there are typos or unsupported scopes.
		if ( ! empty( $invalid_scopes ) ) {
			error_log( 
				sprintf( 
					'[WandTech Scope Loader] WARNING: Invalid scope(s) "%s" found in plugin "%s".', 
					implode( ', ', $invalid_scopes ), 
					$plugin_file 
				) 
			);
		}

		// Fail-Closed Logic: 
		// If the developer defined scopes, but none of them were valid, we MUST NOT load the plugin.
		// Example: They wrote "Scope: frnt" (typo). We block it entirely.
		if ( empty( $valid_scopes ) ) {
			error_log( sprintf( '[WandTech Scope Loader] CRITICAL: Plugin "%s" has NO valid scopes and will be completely disabled (Fail-Closed).', $plugin_file ) );
			return [ '__never__' ];
		}

		// If 'all' is present among other scopes, it overrides everything else.
		if ( in_array( 'all', $valid_scopes, true ) ) {
			return [];
		}

		// Return the cleanly re-indexed array of valid scopes.
		return array_values( $valid_scopes );
	}
}
