<?php
/**
 * Environment Detector Class.
 *
 * @package   WandTech\ScopeLoader
 * @author    WandTech
 * @version   2.0.0
 */

declare( strict_types=1 );

namespace WandTech\ScopeLoader;

// Security First: Prevent direct access to the file.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Environment_Detector
 * 
 * A hyper-optimized, fail-safe environment detector designed specifically 
 * for MU-Plugins where core WordPress functions might not be fully loaded.
 * 
 * It determines the current execution context to allow precise plugin filtering.
 */
class Environment_Detector {

	/**
	 * Scope Constants
	 * Defining scopes as constants prevents typos and allows strict comparisons.
	 */
	public const SCOPE_CLI   = 'cli';
	public const SCOPE_CRON  = 'cron';
	public const SCOPE_AJAX  = 'ajax';
	public const SCOPE_REST  = 'rest';
	public const SCOPE_ADMIN = 'admin';
	public const SCOPE_FRONT = 'front';

	/**
	 * Detects the current WordPress execution context.
	 * 
	 * Order of execution is CRITICAL to prevent scope bleeding.
	 * We use Yoda conditions (e.g., 'cli' === php_sapi_name()) to comply with WPCS
	 * and prevent accidental assignment (= instead of ==).
	 *
	 * @return string The detected scope constant.
	 */
	public function detect(): string {
		// 1. CLI: Checked first. Includes WP-CLI and raw terminal scripts.
		if ( 'cli' === php_sapi_name() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return self::SCOPE_CLI;
		}

		// 2. CRON: Short-circuit evaluation for maximum performance.
		if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || wp_doing_cron() ) {
			return self::SCOPE_CRON;
		}

		// 3. AJAX: Must be checked BEFORE is_admin() because admin-ajax.php triggers is_admin() as true.
		if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || wp_doing_ajax() ) {
			return self::SCOPE_AJAX;
		}

		// 4. REST API: Uses an isolated, hyper-fast custom method.
		if ( $this->is_rest_request() ) {
			return self::SCOPE_REST;
		}

		// 5. ADMIN: Only reliable after AJAX and REST have been filtered out.
		if ( is_admin() ) {
			return self::SCOPE_ADMIN;
		}

		// 6. FRONT: If none of the above match, it is definitively a frontend request.
		return self::SCOPE_FRONT;
	}

	/**
	 * Safely detects REST API requests in an early-load (MU-Plugin) context.
	 * 
	 * Overcomes the lack of the REST_REQUEST constant at this stage and prevents 
	 * False Positives without triggering premature database queries.
	 *
	 * @return bool True if it is a REST request, false otherwise.
	 */
	private function is_rest_request(): bool {
		// A) Official WP Check (For future compatibility if plugins load later).
		if ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() ) {
			return true;
		}

		// B) Fast Check for Plain Permalinks (e.g., ?rest_route=/wp/v2/posts).
		if ( ! empty( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		// C) Safe and Ultra-fast URI processing for Pretty Permalinks.
		// We ignore sanitization here because we only pass it to parse_url() which is safe and read-only.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$uri = $_SERVER['REQUEST_URI'] ?? '';

		// Extract Path to prevent False Positives from Query Strings.
		$path = parse_url( $uri, PHP_URL_PATH );
		if ( ! $path ) {
			return false;
		}

		// Check function existence to prevent Fatal Errors in the MU-Plugin layer.
		$prefix = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json';
		$prefix = '/' . trim( $prefix, '/' ) . '/';

		// Use str_contains for an extremely fast $O(1)$ substring search (PHP 8.0+).
		return str_contains( $path, $prefix );
	}
}
