<?php
/**
 * File: class-scope-loader.php
 * 
 * The main orchestrator for dynamically loading WordPress plugins based on environment scopes.
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
 * Class Scope_Loader
 *
 * Core class responsible for hooking into WordPress plugin loading processes,
 * reading plugin scope configurations via Header_Parser, and dynamically
 * filtering the active plugins array to optimize performance.
 */
class Scope_Loader {

	/**
	 * Configuration constant for the database option key used to store the cache.
	 */
	private const CACHE_KEY = 'wandtech_scope_loader_cache';

	/**
	 * Cache versioning to ensure automatic invalidation upon core MU-Plugin updates.
	 */
	private const CACHE_VERSION = '2.0.0';

	/**
	 * The current detected WordPress environment scope (e.g., 'admin', 'front', 'ajax').
	 * @var string
	 */
	private string $current_scope;

	/**
	 * Instance of the Header_Parser class to read plugin metadata.
	 * @var Header_Parser
	 */
	private Header_Parser $parser;

	/**
	 * In-memory cache for parsed plugin data to prevent duplicate disk I/O.
	 * @var array<string, array>
	 */
	private array $plugin_cache = [];

	/**
	 * Flag to track if new data was parsed during the request, requiring a database write.
	 * @var bool
	 */
	private bool $cache_modified = false;

	/**
	 * Tracks unique paths of blocked plugins to prevent double-counting
	 * when WordPress calls the active_plugins filter multiple times.
	 * Ensures $O(1)$ uniqueness.
	 * 
	 * @var array<string, bool>
	 */
	private array $blocked_plugins = [];

	/**
	 * Tracks the number of successfully loaded plugins in $O(1)$ time.
	 * 
	 * @var int
	 */
	private int $loaded_plugins_count = 0;

	/**
	 * Constructor.
	 *
	 * @param string        $current_scope The environment scope detected by Environment_Detector.
	 * @param Header_Parser $parser        Instance of the parser.
	 */
	public function __construct( string $current_scope, Header_Parser $parser ) {
		$this->current_scope = $current_scope;
		$this->parser        = $parser;

		// Load cache from the database immediately upon instantiation (Zero I/O bottleneck).
		$this->load_cache();
	}

	/**
	 * Initializes the core hooks for the Scope Loader.
	 */
	public function init(): void {
		// 1. Core Filtering Hooks
		add_filter( 'option_active_plugins', [ $this, 'filter_active_plugins' ], 10, 1 );
		add_filter( 'site_option_active_sitewide_plugins', [ $this, 'filter_active_sitewide_plugins' ], 10, 1 );

		// 2. Deferred I/O Save Strategy (Performance Masterpiece)
		add_action( 'shutdown', [ $this, 'save_cache_on_shutdown' ] );

		// 3. Cache Invalidation Hooks (Triggered on state changes)
		add_action( 'activated_plugin', [ $this, 'clear_cache' ] );
		add_action( 'deactivated_plugin', [ $this, 'clear_cache' ] );
		add_action( 'upgrader_process_complete', [ $this, 'clear_cache' ], 10, 0 );

		// 4. Debug Notices (Visible only in Admin to Administrators)
		add_action( 'admin_notices', [ $this, 'render_debug_notice' ] );
		add_action( 'network_admin_notices', [ $this, 'render_debug_notice' ] );
	}

	/**
	 * Filters standard active plugins (Single Site configuration).
	 *
	 * @param mixed $plugins Array of active plugins (indexed array).
	 * @return mixed Filtered array, or original data if conditions aren't met.
	 */
	public function filter_active_plugins( $plugins ) {
		if ( ! is_array( $plugins ) || $this->should_bypass_filtering() ) {
			return $plugins;
		}

		$filtered_plugins = [];

		foreach ( $plugins as $plugin_file ) {
			if ( $this->should_load_plugin( $plugin_file ) ) {
				$filtered_plugins[] = $plugin_file;
			} else {
				// Save plugin path as key to ensure exact uniqueness
				$this->blocked_plugins[ $plugin_file ] = true;
			}
		}

		// Record the exact number of plugins allowed to load in this scope
		$this->loaded_plugins_count = count( $filtered_plugins );

		return $filtered_plugins;
	}

	/**
	 * Filters network-active plugins (Multisite configuration).
	 *
	 * @param mixed $plugins Associative array of network-active plugins [ plugin_file => timestamp ].
	 * @return mixed Filtered array, or original data if conditions aren't met.
	 */
	public function filter_active_sitewide_plugins( $plugins ) {
		if ( ! is_array( $plugins ) || $this->should_bypass_filtering() ) {
			return $plugins;
		}

		$filtered_plugins = [];

		foreach ( $plugins as $plugin_file => $timestamp ) {
			if ( $this->should_load_plugin( $plugin_file ) ) {
				$filtered_plugins[ $plugin_file ] = $timestamp;
			} else {
				// Save plugin path as key to ensure exact uniqueness
				$this->blocked_plugins[ $plugin_file ] = true;
			}
		}

		// Record the exact number of sitewide plugins allowed to load in this scope
		$this->loaded_plugins_count = count( $filtered_plugins );

		return $filtered_plugins;
	}

	/**
	 * Core Logic: Determines if a specific plugin should be loaded based on its scope and dependencies.
	 * Includes Circular Dependency prevention.
	 *
	 * @param string               $plugin_file The plugin file path.
	 * @param array<string, bool>  $processed   Array of already processed plugins to prevent infinite loops.
	 *
	 * @return bool True if the plugin should load, False otherwise.
	 */
	private function should_load_plugin( string $plugin_file, array $processed = [] ): bool {
		// Circular Dependency Prevention
		if ( isset( $processed[ $plugin_file ] ) ) {
			error_log( sprintf( '[WandTech Scope Loader] ERROR: Circular dependency detected involving "%s". Scope parsing aborted for this branch.', $plugin_file ) );
			return false; // Break the infinite loop gracefully
		}
		$processed[ $plugin_file ] = true;

		$plugin_data = $this->get_plugin_data( $plugin_file );
		$scopes      = $plugin_data['scopes'];
		$requires    = $plugin_data['requires'];

		// --- Rule 1: Fail-Closed Check ---
		if ( in_array( '__never__', $scopes, true ) ) {
			return false;
		}

		// --- Rule 2: Scope Match ---
		if ( ! empty( $scopes ) && ! in_array( $this->current_scope, $scopes, true ) ) {
			return false;
		}

		// --- Rule 3: Dependency Resolution (Scope-Requires) ---
		foreach ( $requires as $dependency ) {
			// If a required plugin cannot be loaded (due to its own scope restrictions),
			// then the current plugin MUST NOT load either.
			if ( ! $this->should_load_plugin( $dependency, $processed ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Determines if the filtering logic should be bypassed (e.g., on plugin management screens).
	 *
	 * @return bool True to bypass filtering, False to proceed.
	 */
	private function should_bypass_filtering(): bool {
		// Safely determine the current requested script in MU-Plugins context
		$php_self = $_SERVER['PHP_SELF'] ?? '';

		// Bypass on critical plugin management and update pages to maintain UI integrity
		if ( strpos( $php_self, 'plugins.php' ) !== false || strpos( $php_self, 'plugin-install.php' ) !== false || strpos( $php_self, 'update-core.php' ) !== false ) {
			return true;
		}

		// Bypass for specific WP-CLI commands that manipulate plugin states
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$cli_args = $_SERVER['argv'] ?? [];
			$command  = implode( ' ', array_slice( $cli_args, 1, 2 ) ); // e.g., 'plugin list'
			
			$bypass_commands = [ 'plugin list', 'plugin toggle', 'plugin activate', 'plugin deactivate' ];
			if ( in_array( $command, $bypass_commands, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Retrieves plugin data from memory RAM, or parses it and flags for deferred database saving.
	 *
	 * @param string $plugin_file Path to the plugin.
	 * @return array Parsed scope data.
	 */
	private function get_plugin_data( string $plugin_file ): array {
		if ( isset( $this->plugin_cache[ $plugin_file ] ) ) {
			return $this->plugin_cache[ $plugin_file ];
		}

		$parsed_data = $this->parser->parse( $plugin_file );
		
		$this->plugin_cache[ $plugin_file ] = $parsed_data;
		$this->cache_modified               = true; // Trigger Deferred I/O

		return $parsed_data;
	}

	/**
	 * Loads cached data from the database into RAM. Runs ONCE per request.
	 */
	private function load_cache(): void {
		$cache_data = get_option( self::CACHE_KEY, [] );

		if ( isset( $cache_data['version'] ) && $cache_data['version'] === self::CACHE_VERSION ) {
			$this->plugin_cache = $cache_data['data'] ?? [];
		} else {
			$this->plugin_cache   = [];
			$this->cache_modified = true;
		}
	}

	/**
	 * Deferred Execution strategy. Writes to the database only at the absolute end of the request.
	 * Prevents N+1 queries and TTFB delays.
	 */
	public function save_cache_on_shutdown(): void {
		if ( $this->cache_modified && ! empty( $this->plugin_cache ) ) {
			$data_to_save = [
				'version' => self::CACHE_VERSION,
				'data'    => $this->plugin_cache,
			];
			// Using autoload = false is CRITICAL to prevent memory bloat in wp_options.
			update_option( self::CACHE_KEY, $data_to_save, false );
		}
	}

	/**
	 * Fully purges the cache from DB and RAM.
	 */
	public function clear_cache(): void {
		delete_option( self::CACHE_KEY );
		$this->plugin_cache   = [];
		$this->cache_modified = false;
	}

	/**
	 * Renders an admin notice displaying optimization statistics if debugging is enabled.
	 */
	public function render_debug_notice(): void {
		$blocked_count = count( $this->blocked_plugins );
		$loaded_count  = $this->loaded_plugins_count;

		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG || ! current_user_can( 'manage_options' ) || $blocked_count === 0 ) {
			return;
		}

		printf(
			'<div class="notice notice-info is-dismissible"><p><strong>WandTech Scope Loader:</strong> Successfully blocked <strong>%d</strong> plugin(s) from loading in the current scope (<code>%s</code>) to optimize performance. <strong>%d</strong> plugin(s) were successfully loaded.</p></div>',
			$blocked_count,
			esc_html( $this->current_scope ),
			$loaded_count
		);
	}
}
