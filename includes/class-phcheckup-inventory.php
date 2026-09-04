<?php
/**
 * Module: unused-item inventory.
 *
 * Lists plugins that are installed but not active, and themes that are
 * installed but neither the active theme nor its parent — the kind of
 * clutter that quietly accumulates on most long-running sites. Advice
 * only: PressHangar Site Checkup never deletes or deactivates anything itself.
 *
 * As with the other modules, the actual set-difference and filtering logic
 * lives in small, static, side-effect-free methods so it can be exercised
 * from a CLI test harness without a WordPress runtime.
 *
 * @package PressHangar Site Checkup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PHCHECKUP_Inventory
 */
class PHCHECKUP_Inventory {

	/** Age (in seconds) beyond which a plugin/theme's last file change is called out as "long untouched". Approximately 2 years. */
	const STALE_AGE_SECONDS = 2 * YEAR_IN_SECONDS;

	/**
	 * Compute which installed plugins are not currently active. Pure
	 * function: takes the same shapes `get_plugins()` and the
	 * `active_plugins` option already provide, so it's testable with plain
	 * fixture arrays.
	 *
	 * @param array $all_plugins    Result of `get_plugins()`: plugin_file => plugin data array.
	 * @param array $active_plugins Result of `get_option('active_plugins')`: list of plugin_file strings.
	 * @return array List of plugin_file strings that are installed but not active, preserving `$all_plugins` order.
	 */
	public static function inactive_plugin_files( array $all_plugins, array $active_plugins ) {
		$active_lookup = array_fill_keys( $active_plugins, true );

		$inactive = array();
		foreach ( array_keys( $all_plugins ) as $plugin_file ) {
			if ( ! isset( $active_lookup[ $plugin_file ] ) ) {
				$inactive[] = $plugin_file;
			}
		}

		return $inactive;
	}

	/**
	 * Compute which installed theme stylesheets are neither the active
	 * theme nor its parent. Pure function, testable with fixture arrays.
	 *
	 * @param array  $all_stylesheets    List of every installed theme's stylesheet (directory) slug.
	 * @param string $active_stylesheet  Active theme's stylesheet slug (`get_stylesheet()`).
	 * @param string $active_template    Active theme's template/parent slug (`get_template()`) — same as
	 *                                   `$active_stylesheet` for a theme with no parent.
	 * @return array List of unused stylesheet slugs.
	 */
	public static function unused_theme_slugs( array $all_stylesheets, $active_stylesheet, $active_template ) {
		$keep = array( $active_stylesheet, $active_template );

		$unused = array();
		foreach ( $all_stylesheets as $slug ) {
			if ( ! in_array( $slug, $keep, true ) ) {
				$unused[] = $slug;
			}
		}

		return $unused;
	}

	/**
	 * Whether a given file's last-modified time counts as "long untouched".
	 * Uses filesystem mtime as a free, always-available, read-only proxy
	 * for "last updated" — PressHangar Site Checkup deliberately does not make a network
	 * call per plugin/theme to fetch a precise wordpress.org update date,
	 * since that would mean N outbound HTTP requests on every checkup run
	 * (against the "no heavy work on a normal request" rule). It's an
	 * approximation, and is presented as one.
	 *
	 * @param int $mtime Unix timestamp, or 0/false if unknown.
	 * @param int $now   Current Unix timestamp (injectable for testing).
	 * @return bool
	 */
	public static function is_stale( $mtime, $now = null ) {
		if ( empty( $mtime ) ) {
			return false;
		}

		if ( null === $now ) {
			$now = time();
		}

		return ( $now - (int) $mtime ) >= self::STALE_AGE_SECONDS;
	}

	/**
	 * Build the friendly findings for the inactive-plugins portion of this
	 * module. Pure function.
	 *
	 * @param array $inactive_plugins List of ['name' => string, 'version' => string, 'stale' => bool].
	 * @return array{severity:string,what:string,why:string,suggestion:string}|null Null when there's nothing to report.
	 */
	public static function build_inactive_plugins_finding( array $inactive_plugins ) {
		$count = count( $inactive_plugins );

		if ( 0 === $count ) {
			return null;
		}

		$names = wp_list_pluck( $inactive_plugins, 'name' );
		$stale_count = count(
			array_filter(
				$inactive_plugins,
				function ( $plugin ) {
					return ! empty( $plugin['stale'] );
				}
			)
		);

		$what = sprintf(
			/* translators: 1: number of inactive plugins, 2: comma-separated plugin names. */
			_n( '%1$d installed plugin is not active: %2$s.', '%1$d installed plugins are not active: %2$s.', $count, 'presshangar-site-checkup' ),
			$count,
			implode( ', ', $names )
		);

		$why = __( 'Inactive plugins still take up disk space, still show up as something to keep patched, and can quietly become a security weak point if they\'re never updated again.', 'presshangar-site-checkup' );

		if ( $stale_count > 0 ) {
			$why .= ' ' . sprintf(
				/* translators: %d: number of inactive plugins whose files haven't changed in 2+ years. */
				_n( '%d of them hasn\'t been touched in a long time (2+ years since its files last changed).', '%d of them haven\'t been touched in a long time (2+ years since their files last changed).', $stale_count, 'presshangar-site-checkup' ),
				$stale_count
			);
		}

		return array(
			'severity'   => $count >= 5 ? 'attention' : 'heads-up',
			'what'       => $what,
			'why'        => $why,
			'suggestion' => __( 'If you\'re confident you won\'t reactivate one of these, consider deleting it from the Plugins screen. PressHangar Site Checkup only points these out — it never deletes anything itself.', 'presshangar-site-checkup' ),
		);
	}

	/**
	 * Build the friendly findings for the unused-themes portion of this
	 * module. Pure function.
	 *
	 * @param array $unused_themes List of ['name' => string, 'version' => string, 'stale' => bool].
	 * @return array{severity:string,what:string,why:string,suggestion:string}|null Null when there's nothing to report.
	 */
	public static function build_unused_themes_finding( array $unused_themes ) {
		$count = count( $unused_themes );

		if ( 0 === $count ) {
			return null;
		}

		$names = wp_list_pluck( $unused_themes, 'name' );

		$what = sprintf(
			/* translators: 1: number of unused themes, 2: comma-separated theme names. */
			_n( '%1$d installed theme is not in use: %2$s.', '%1$d installed themes are not in use: %2$s.', $count, 'presshangar-site-checkup' ),
			$count,
			implode( ', ', $names )
		);

		return array(
			'severity'   => $count >= 4 ? 'attention' : 'heads-up',
			'what'       => $what,
			'why'        => __( 'Like plugins, unused themes still take up disk space and can still be a security weak point if they\'re never updated again.', 'presshangar-site-checkup' ),
			'suggestion' => __( 'It\'s worth keeping one official WordPress default theme (e.g. Twenty Twenty-Four) installed as a safe fallback in case you ever need to quickly switch away from your main theme — but the rest can usually be deleted from the Themes screen. PressHangar Site Checkup only points these out — it never deletes anything itself.', 'presshangar-site-checkup' ),
		);
	}

	/**
	 * Run the full module against the live site.
	 *
	 * @return array{summary:string,severity:string,items:array}
	 */
	public static function scan() {
		if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$items = array();
		$now   = time();

		// --- Inactive plugins -------------------------------------------------.
		if ( function_exists( 'get_plugins' ) ) {
			$all_plugins    = get_plugins();
			$active_plugins = (array) get_option( 'active_plugins', array() );
			$inactive_files = self::inactive_plugin_files( $all_plugins, $active_plugins );

			$inactive_plugins = array();
			foreach ( $inactive_files as $plugin_file ) {
				$data  = $all_plugins[ $plugin_file ];
				$mtime = 0;

				if ( defined( 'WP_PLUGIN_DIR' ) ) {
					$full_path = wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) . $plugin_file );
					if ( file_exists( $full_path ) ) {
						$mtime = filemtime( $full_path );
					}
				}

				$inactive_plugins[] = array(
					'name'    => isset( $data['Name'] ) ? $data['Name'] : $plugin_file,
					'version' => isset( $data['Version'] ) ? $data['Version'] : '',
					'stale'   => self::is_stale( $mtime, $now ),
				);
			}

			$finding = self::build_inactive_plugins_finding( $inactive_plugins );
			if ( null !== $finding ) {
				$items[] = $finding;
			}
		}

		// --- Unused themes ------------------------------------------------------.
		if ( function_exists( 'wp_get_themes' ) ) {
			$all_themes        = wp_get_themes();
			$active_stylesheet = function_exists( 'get_stylesheet' ) ? get_stylesheet() : '';
			$active_template   = function_exists( 'get_template' ) ? get_template() : $active_stylesheet;

			$unused_slugs = self::unused_theme_slugs( array_keys( $all_themes ), $active_stylesheet, $active_template );

			$unused_themes = array();
			foreach ( $unused_slugs as $slug ) {
				$theme     = $all_themes[ $slug ];
				$theme_dir = method_exists( $theme, 'get_stylesheet_directory' ) ? $theme->get_stylesheet_directory() : '';
				$mtime     = 0;

				if ( $theme_dir && file_exists( $theme_dir . '/style.css' ) ) {
					$mtime = filemtime( $theme_dir . '/style.css' );
				}

				$unused_themes[] = array(
					'name'    => method_exists( $theme, 'get' ) ? $theme->get( 'Name' ) : $slug,
					'version' => method_exists( $theme, 'get' ) ? $theme->get( 'Version' ) : '',
					'stale'   => self::is_stale( $mtime, $now ),
				);
			}

			$finding = self::build_unused_themes_finding( $unused_themes );
			if ( null !== $finding ) {
				$items[] = $finding;
			}
		}

		if ( empty( $items ) ) {
			return array(
				'summary'  => __( 'Nothing unused found — every installed plugin is active and every installed theme is in use.', 'presshangar-site-checkup' ),
				'severity' => 'ok',
				'items'    => array(),
			);
		}

		$severity = 'heads-up';
		foreach ( $items as $item ) {
			if ( 'attention' === $item['severity'] ) {
				$severity = 'attention';
				break;
			}
		}

		return array(
			'summary'  => __( 'Some installed items aren\'t currently in use. None of this is urgent, but it\'s worth a periodic tidy-up.', 'presshangar-site-checkup' ),
			'severity' => $severity,
			'items'    => $items,
		);
	}
}
