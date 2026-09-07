<?php
/**
 * Plugin Name:       PressHangar Site Checkup
 * Plugin URI:        https://presshangar.com/presshangar-site-checkup
 * Description:       A read-only site health checkup that explains what it finds in plain language. Detects overlapping plugins, gives approximate "heaviness" hints, and lists unused plugins/themes — all on demand, never automatically. PressHangar Site Checkup never changes anything on your site.
 * Version:           0.3.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Musubiemu LLC
 * Author URI:        https://presshangar.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       presshangar-site-checkup
 * Domain Path:       /languages
 *
 * PressHangar Site Checkup is strictly read-only. Every module here only ever reads
 * WordPress state (options, the plugin/theme file lists, a bounded amount
 * of the filesystem, and — only when a site owner explicitly clicks the
 * "measure" button — a single outbound HTTP request to the site's own
 * front page). The ONLY writes this plugin ever performs are to its own
 * two options, `phcheckup_settings` and `phcheckup_last_scan`. It never deactivates or
 * deletes a plugin/theme, never edits a post or another plugin's option,
 * and never calls any of WordPress's destructive APIs. PressHangar Site Checkup
 * diagnoses and advises; any cleanup is left entirely to the site owner,
 * done by hand, in the normal Plugins/Themes screens.
 *
 * Diagnosis is on-demand only: nothing in this plugin runs on a normal
 * front-end request, and there is no always-on profiler or background
 * cron job. Every module runs once, synchronously, when a site owner with
 * `manage_options` clicks "Run checkup" (or the separate, explicit
 * "Measure front-page load time" button) on the PressHangar Site Checkup admin screen.
 *
 * @package PressHangar Site Checkup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin version. */
define( 'PHCHECKUP_VERSION', '0.3.2' );


/* Load translations: bundled /languages first, then WordPress.org language packs. */
add_action( 'init', function () {
	load_plugin_textdomain( 'presshangar-site-checkup', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}, 0 );
/** Absolute path to the main plugin file. */
define( 'PHCHECKUP_FILE', __FILE__ );

/** Absolute path to the plugin directory, with trailing slash. */
define( 'PHCHECKUP_DIR', plugin_dir_path( __FILE__ ) );

/** URL to the plugin directory, with trailing slash. */
define( 'PHCHECKUP_URL', plugin_dir_url( __FILE__ ) );

/** Plugin basename, used for admin menu / textdomain loading. */
define( 'PHCHECKUP_BASENAME', plugin_basename( __FILE__ ) );

/** Option name holding PressHangar Site Checkup's own settings array. This is one of only two options this plugin ever writes. */
define( 'PHCHECKUP_OPTION_SETTINGS', 'phcheckup_settings' );

/** Option name holding the cached result of the last checkup run. This is the other of only two options this plugin ever writes. */
define( 'PHCHECKUP_OPTION_LAST_SCAN', 'phcheckup_last_scan' );

require_once PHCHECKUP_DIR . 'includes/class-phcheckup-duplicates.php';
require_once PHCHECKUP_DIR . 'includes/class-phcheckup-conflicts.php';
require_once PHCHECKUP_DIR . 'includes/class-phcheckup-weight.php';
require_once PHCHECKUP_DIR . 'includes/class-phcheckup-inventory.php';
require_once PHCHECKUP_DIR . 'includes/class-phcheckup-admin.php';

/**
 * Get PressHangar Site Checkup's settings array, merged with defaults.
 *
 * Centralized here (rather than duplicated per-module) so every module and
 * the admin screen reads a single, consistently-shaped array. Every module
 * defaults ON — unlike a plugin that changes site behaviour, a read-only
 * diagnostic module has no downside to running by default.
 *
 * @return array
 */
function phcheckup_get_settings() {
	$settings = get_option( PHCHECKUP_OPTION_SETTINGS, array() );

	if ( ! is_array( $settings ) ) {
		$settings = array();
	}

	return wp_parse_args( $settings, PHCHECKUP_Admin::get_defaults() );
}

/**
 * Get the cached result of the last checkup run, merged with a safe empty
 * shape so callers never need to guard against missing keys.
 *
 * @return array {
 *     @type int   $time    Unix timestamp of the last run, or 0 if never run.
 *     @type array $results Per-module results, keyed by module slug.
 * }
 */
function phcheckup_get_last_scan() {
	$scan = get_option( PHCHECKUP_OPTION_LAST_SCAN, array() );

	if ( ! is_array( $scan ) ) {
		$scan = array();
	}

	return wp_parse_args(
		$scan,
		array(
			'time'    => 0,
			'results' => array(),
		)
	);
}

/**
 * Bootstrap. Deferred to `plugins_loaded` so translations and admin hooks
 * register at the normal time; nothing here does any diagnostic work — that
 * only ever happens inside PHCHECKUP_Admin's admin-post handlers, in direct
 * response to an explicit button click.
 */
function phcheckup_bootstrap() {
	PHCHECKUP_Admin::init();
}
add_action( 'plugins_loaded', 'phcheckup_bootstrap' );

// No activation hook is registered: PressHangar Site Checkup is read-only and has
// nothing to set up. `phcheckup_get_settings()` and `phcheckup_get_last_scan()` both
// resolve sensible defaults on their own the first time they're called, so
// there is no state that needs to exist before the plugin can run.
