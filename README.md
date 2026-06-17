# WandTech Scope Loader

**Version:** 2.0.0 | **PHP:** 8.0+ | **Type:** Must-Use Plugin (MU-Plugin)

**WandTech Scope Loader** is an enterprise-grade conditional plugin loader for WordPress. It is designed to dramatically improve site performance by selectively loading plugins based on their execution environment (Scope), preventing the compilation and execution of unnecessary PHP code on every request.

---

## 🚀 Why WandTech Scope Loader? (Competitive Advantages)

Unlike popular alternatives that rely on heavy user interfaces and dedicated database tables, WandTech Scope Loader is built on modern software engineering principles:

*   **Declarative Approach (Configuration as Code):** No manual, error-prone configurations in complex admin panels. Developers define the plugin's behavior by simply adding a standard header to the plugin's main file.
*   **Zero Overhead & Modular OOP:** Built with a strict Object-Oriented architecture, separating concerns into specialized classes for parsing, environment detection, and filtering.
*   **Highly Optimized Architecture:** Utilizes an efficient $O(n)$ filtering loop for active plugins, combined with **Deferred Cache Saving**. Plugin scope caching is processed in RAM and only written to the database using a single $O(1)$ operation during the `shutdown` hook, eliminating redundant database I/O.
*   **Fail-Safe Design:** Engineered to fail gracefully. If a core file is missing or a critical exception occurs during the early load phase, the system aborts safely without causing a WordPress WSOD (White Screen of Death).

---

## ⚡ Performance Impact

Implementing this MU-Plugin in scalable environments yields significant results:

*   **Significant Reduction in PHP Execution:** Prevents the inclusion of plugins irrelevant to the current request. In heavy environments (e.g., WooCommerce sites with 30+ plugins), this can improve TTFB by 10% to 35% and noticeably reduce memory consumption.
*   **Drastically Improved TTFB:** Lowers Time To First Byte and overall response times by unburdening the WordPress lifecycle.
*   **Reduced Memory Footprint:** Frees up server RAM (PHP Workers) by not instantiating unnecessary classes and functions.
*   **Lower Database Load:** Prevents the execution of autoload queries and hooks associated with inactive plugins in the current scope.

---

## 🛠 Installation & Architecture

Because this is a Must-Use Plugin (MU-Plugin) with a modular structure, the installation requires copying the entire directory:

1. Download the `wandtech-scope-loader` directory (containing the main PHP file and the classes).
2. Navigate to the `wp-content/mu-plugins/` directory on your server.
3. Upload the `wandtech-scope-loader.php` file directly into `mu-plugins/` and place the accompanying class files in a subdirectory (or as structured in the repository).
4. The custom `spl_autoload_register` will automatically handle the loading of dependencies. No dashboard configuration is required.

---

## 📖 Usage

To control the loading behavior of any plugin, simply add the following headers to the comment block of the plugin's main PHP file.

### Supported Headers:
*   `Scope`: A comma-separated list of environments where the plugin is allowed to load.
*   `Scope-Requires`: Plugin dependencies. The plugin will only load if these required plugins are also present and active (Circular dependencies are handled automatically).

### Example (Inside a custom plugin's main file):
```php
/*
 * Plugin Name: My Custom Admin Tool
 * Description: A tool only needed in the WordPress backend.
 * Scope: admin, cli
 * Scope-Requires: woocommerce/woocommerce.php
 */
```

---

## 🎯 Available Scopes

You can use any combination of the following values in the `Scope` header:

*   `all` (Default): Loads in all environments.
*   `front`: Loads only on the front-end (public-facing pages).
*   `admin`: Loads in the WordPress admin dashboard (`/wp-admin/`).
*   `ajax`: Loads during `admin-ajax.php` requests.
*   `rest`: Loads during WP REST API requests.
*   `cron`: Loads during background processing (WP-Cron).
*   `cli`: Loads during command-line executions (WP-CLI).

---

## 🛡 Technical Details & Stability (v2.0.0 Enhancements)

Version 2.0.0 introduces a massive architectural overhaul for enterprise stability:

1.  **Object-Oriented Refactoring:** Codebase split into `Scope_Loader`, `Environment_Detector`, and `Header_Parser` for clean separation of concerns and testability.
2.  **Fail-Safe Bootstrapper:** Utilizes a custom autoloader wrapped in a `try-catch` block hooked to `muplugins_loaded` at priority `0`. Prevents critical errors if files are missing or modified incorrectly.
3.  **$O(1)$ Uniqueness for Debug Notices:** Fixed a core WordPress quirk where `option_active_plugins` is called multiple times. The system now uses an associative array with plugin paths as unique keys, ensuring $O(1)$ complexity and 100% accurate blocked-plugin counts in admin notices.
4.  **Full Multisite Support:** Seamlessly filters network-active plugins via the `site_option_active_sitewide_plugins` hook.
5.  **WPCS Compliance:** Fully adheres to WordPress Coding Standards, including strict Yoda conditions and proper PHPDoc type hinting.

---

## 📜 Changelog

### [2.0.0] - 2026-03-28
#### Added
- **Major OOP Refactor:** Completely rewritten into modular classes (`Environment_Detector`, `Header_Parser`, `Scope_Loader`).
- **Fail-Safe Autoloader:** Implemented `spl_autoload_register` with a strict `try-catch` wrapper to prevent critical site crashes during the MU-Plugin boot sequence.
- **Multisite Support:** Added support for WordPress Multisite networks via the `site_option_active_sitewide_plugins` hook.
#### Fixed
- Fixed the "Double Counting" bug in the debug admin notice. Implemented an $O(1)$ unique key array to accurately count blocked plugins, regardless of how many times WordPress triggers the active plugins filter.
#### Changed
- Upgraded Environment Detector logic with non-static methods and strict WPCS compliance (Yoda Conditions).

### [1.0.2] - 2026-03-24
#### Fixed
- Fixed a fatal error during WP-CLI executions by implementing strict null-checks.
- Resolved an issue where scoped plugins appeared as "inactive" in the UI.

### [1.0.1] - 2026-03-20
#### Added
- Implemented secure REST API request detection.
#### Changed
- Optimized the deferred caching mechanism to ensure a single $O(1)$ database write.

### [1.0.0] - 2026-03-15
#### Added
- Initial release of WandTech Scope Loader.

---

## Support

For issues, feature requests, or contributions:
- GitHub: [WandTech Scope Loader](https://github.com/HamxaBoustani/WandTech-Scope-Loader)
- Email: HamxaBoustani@gmail.com

---

## 📄 License

This project is licensed under the GPLv2 or later. You are free to use, modify, and distribute it for both commercial and personal projects.

---

## Credits

Developed by **Hamxa Boustani** with ❤️ for the WordPress community.
