<?php
/**
 * Plugin Name: WandTech Scope Loader
 * Plugin URI:  https://wandtech.com/
 * Description: Advanced Must-Use Plugin for conditional and highly optimized loading of standard plugins based on execution scope.
 * Version:     2.0.0
 * Author:      WandTech
 * Author URI:  https://wandtech.com/
 * License:     GPL-2.0+
 * 
 * @package WandTech\ScopeLoader
 */

// Enable strict typing for robust PHP execution
declare( strict_types=1 );

// 1. Security First: Prevent direct access to the file
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 2. Core Constants Definition
define( 'WANDTECH_SCOPE_LOADER_VERSION', '2.0.0' );
define( 'WANDTECH_SCOPE_LOADER_DIR', __DIR__ . '/inc' );

/**
 * 3. Custom SPL Autoloader
 * 
 * Instead of multiple hardcoded `require_once` statements which can cause fatal errors
 * if a file is missing, we use an autoloader. This is PSR-4 inspired but adapted 
 * for WordPress standard file naming conventions (class-class-name.php).
 */
spl_autoload_register( static function ( string $class ): void {
	// Only handle classes within our specific namespace
	$namespace_prefix = 'WandTech\\ScopeLoader\\';
	$prefix_length    = strlen( $namespace_prefix );

	if ( strncmp( $namespace_prefix, $class, $prefix_length ) !== 0 ) {
		return;
	}

	// Extract the class name without the namespace
	$relative_class = substr( $class, $prefix_length );

	// Convert Class_Name to class-class-name.php (WordPress standard)
	$file_name = 'class-' . strtolower( str_replace( '_', '-', $relative_class ) ) . '.php';
	$file_path = WANDTECH_SCOPE_LOADER_DIR . '/' . $file_name;

	// Only require the file if it physically exists on the disk
	if ( file_exists( $file_path ) ) {
		require $file_path;
	}
} );

/**
 * 4. Bootstrapping the Application
 * 
 * This function handles the Dependency Injection and initialization.
 * We wrap it in a try-catch block to ensure that if ANY logic fails,
 * it fails gracefully without bringing down the entire WordPress site.
 */
function wandtech_boot_scope_loader(): void {
	// Final safety check to ensure critical classes were autoloaded successfully
	if ( ! class_exists( \WandTech\ScopeLoader\Environment_Detector::class ) ) {
		error_log( '[WandTech Scope Loader] ERROR: Core classes are missing. Bootstrapping aborted to prevent fatal errors.' );
		return;
	}

	try {
		// Step A: Detect the current environment (admin, ajax, cron, front, etc.)
		$detector      = new \WandTech\ScopeLoader\Environment_Detector();
		$current_scope = $detector->detect();

		// Step B: Instantiate the Header Parser for reading plugin scopes
		$parser = new \WandTech\ScopeLoader\Header_Parser();

		// Step C: Inject dependencies into the Orchestrator and initialize hooks
		$loader = new \WandTech\ScopeLoader\Scope_Loader( $current_scope, $parser );
		$loader->init();

	} catch ( \Throwable $e ) {
		// Catch any Throwable (Exception or Error) to guarantee Zero-Downtime
		error_log( sprintf( 
			'[WandTech Scope Loader] CRITICAL EXCEPTION during boot: %s in %s on line %d', 
			$e->getMessage(), 
			$e->getFile(), 
			$e->getLine() 
		) );
	}
}

/**
 * 5. Execution Hook
 * 
 * We hook into 'muplugins_loaded' at priority $0$.
 * This guarantees our code runs AFTER all MU-Plugins are loaded, 
 * but BEFORE WordPress queries the database for standard 'active_plugins'.
 * This is the exact microsecond required for successful scope interception.
 */
add_action( 'muplugins_loaded', 'wandtech_boot_scope_loader', 0 );
