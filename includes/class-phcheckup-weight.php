<?php
/**
 * Module: "heaviness hints".
 *
 * PressHangar Site Checkup never runs a profiler and never measures precise millisecond
 * timings — that would mean hooking into every request, which violates the
 * plugin's "nothing runs except when you click the button" rule. Instead,
 * this module reports a handful of safe, cheap, honestly-labelled *proxy*
 * signals that correlate with a slow site: how many plugins are active, how
 * much data WordPress loads on every single page view via `autoload`
 * options, and roughly how much disk space each active plugin occupies.
 *
 * The one thing here that does touch the network — a single front-page
 * `wp_remote_get()` — only ever runs when a site owner explicitly clicks
 * the separate "Measure front-page load time" button; it never runs as
 * part of "Run checkup" and never runs on a normal request.
 *
 * @package PressHangar Site Checkup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PHCHECKUP_Weight
 */
class PHCHECKUP_Weight {

	/** Time budget (seconds) for the entire disk-footprint scan across all plugins. */
	const SCAN_TIME_BUDGET = 3.0;

	/** Maximum files to stat per plugin folder before giving up on that one plugin. */
	const SCAN_MAX_FILES_PER_PLUGIN = 4000;

	/** Timeout (seconds) for the optional front-page measurement request. */
	const FRONTPAGE_TIMEOUT = 10;

	/**
	 * Plugin-count thresholds and the friendly hint for each band. Pure
	 * data + pure function below, so directly testable.
	 *
	 * @param int $count Number of active plugins.
	 * @return array{severity:string,text:string}
	 */
	public static function plugin_count_hint( $count ) {
		$count = (int) $count;

		if ( $count <= 15 ) {
			return array(
				'severity' => 'ok',
				'text'     => __( 'A plugin count like this is unlikely to be a performance problem by itself.', 'presshangar-site-checkup' ),
			);
		}

		if ( $count <= 30 ) {
			return array(
				'severity' => 'heads-up',
				'text'     => __( 'This is on the higher side. It\'s not necessarily a problem — well-built plugins that only do their work when needed add little overhead — but it\'s worth a periodic look at whether everything here still earns its keep.', 'presshangar-site-checkup' ),
			);
		}

		return array(
			'severity' => 'attention',
			'text'     => __( 'This is a lot of active plugins. Each one adds at least a little overhead to every admin (and often front-end) page load; a plugin count this high is worth reviewing for anything you\'ve stopped actively using.', 'presshangar-site-checkup' ),
		);
	}

	/**
	 * Format a byte count as a friendly, human-readable size string.
	 *
	 * @param int|float $bytes Byte count.
	 * @return string
	 */
	public static function format_bytes( $bytes ) {
		$bytes = max( 0, (float) $bytes );

		if ( $bytes < 1024 ) {
			return sprintf( '%d B', (int) $bytes );
		}

		if ( $bytes < 1024 * 1024 ) {
			return sprintf( '%s KB', number_format_i18n( $bytes / 1024, 1 ) );
		}

		return sprintf( '%s MB', number_format_i18n( $bytes / ( 1024 * 1024 ), 1 ) );
	}

	/**
	 * Turn the raw autoload query results into the module's friendly
	 * summary text + severity. Pure function so it's testable with
	 * hand-built fixture rows, including the empty-result case (a fresh
	 * install, or a `$wpdb` failure that returned no rows).
	 *
	 * @param array     $top_rows   List of ['option_name' => string, 'len' => int], largest first.
	 * @param int|float $total_bytes Sum of all autoloaded option sizes (0 if unknown/empty).
	 * @return array{severity:string,text:string,top:array}
	 */
	public static function format_autoload_summary( array $top_rows, $total_bytes ) {
		$total_bytes = (float) $total_bytes;

		if ( empty( $top_rows ) && $total_bytes <= 0 ) {
			return array(
				'severity' => 'ok',
				'text'     => __( 'Could not read any autoloaded options (or there are none) — nothing to report here.', 'presshangar-site-checkup' ),
				'top'      => array(),
			);
		}

		// Rough, deliberately generous thresholds: autoload data is read on
		// literally every page load, so even a moderate total is worth a
		// heads-up, while anything approaching a megabyte is worth
		// attention. These are the same "approximate hint" thresholds used
		// elsewhere in this module, not a precision benchmark.
		if ( $total_bytes < 800 * 1024 ) {
			$severity = 'ok';
		} elseif ( $total_bytes < 1.5 * 1024 * 1024 ) {
			$severity = 'heads-up';
		} else {
			$severity = 'attention';
		}

		$formatted_top = array();
		foreach ( $top_rows as $row ) {
			$formatted_top[] = array(
				'option_name' => isset( $row['option_name'] ) ? $row['option_name'] : '',
				'bytes'       => isset( $row['len'] ) ? (int) $row['len'] : 0,
				'formatted'   => self::format_bytes( isset( $row['len'] ) ? $row['len'] : 0 ),
			);
		}

		$names = array_slice( wp_list_pluck( $formatted_top, 'option_name' ), 0, 5 );

		$text = sprintf(
			/* translators: 1: total autoload size (e.g. "1.2 MB"), 2: comma-separated list of the largest option names. */
			__( 'WordPress loads about %1$s of "autoload" option data on every single page view. The largest contributors are: %2$s.', 'presshangar-site-checkup' ),
			self::format_bytes( $total_bytes ),
			implode( ', ', $names )
		);

		return array(
			'severity' => $severity,
			'text'     => $text,
			'top'      => $formatted_top,
		);
	}

	/**
	 * Recursively sum a directory's file size and file count, bounded by a
	 * wall-clock deadline and a maximum file count so a single unusually
	 * large plugin folder (or a filesystem with slow I/O) can never turn
	 * "Run checkup" into a long-running request. When either bound is hit,
	 * the scan simply stops and reports what it saw so far, flagged as
	 * incomplete via `skipped`.
	 *
	 * @param string $dir      Absolute directory path.
	 * @param float  $deadline `microtime(true)` timestamp to stop scanning at.
	 * @param int    $max_files Maximum number of files to stat before stopping.
	 * @return array{size_bytes:int,file_count:int,skipped:bool}
	 */
	public static function scan_directory( $dir, $deadline, $max_files ) {
		$size_bytes = 0;
		$file_count = 0;
		$skipped    = false;

		// A directory we simply can't read is not a "skipped" (truncated)
		// scan — it just contributes nothing. `skipped` is reserved for the
		// case where we started measuring but stopped early because of a
		// time/file bound, which is the only case the UI should flag as a
		// possible undercount. Conflating the two produced a misleading
		// "too large to measure" note for perfectly ordinary sites.
		if ( ! is_dir( $dir ) || ! is_readable( $dir ) ) {
			return array(
				'size_bytes' => 0,
				'file_count' => 0,
				'skipped'    => false,
			);
		}

		try {
			// CATCH_GET_CHILD makes the iterator skip a sub-directory it can't
			// descend into (permissions, a vanished path) instead of throwing
			// mid-iteration — without it, one unreadable sub-folder would
			// abort the whole weight module rather than degrading gracefully.
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY,
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);
		} catch ( Throwable $e ) {
			return array(
				'size_bytes' => 0,
				'file_count' => 0,
				'skipped'    => false,
			);
		}

		try {
			foreach ( $iterator as $file ) {
				if ( microtime( true ) > $deadline || $file_count >= $max_files ) {
					$skipped = true;
					break;
				}

				if ( $file->isFile() ) {
					$size = $file->getSize();
					if ( false !== $size ) {
						$size_bytes += $size;
					}
					++$file_count;
				}
			}
		} catch ( Throwable $e ) {
			// Anything the iterator throws while walking (a race with a file
			// being removed, an unexpected filesystem error) leaves us with a
			// partial-but-valid count. Report it as an incomplete measurement
			// rather than letting it bubble up and take out the whole module.
			$skipped = true;
		}

		return array(
			'size_bytes' => $size_bytes,
			'file_count' => $file_count,
			'skipped'    => $skipped,
		);
	}

	/**
	 * Rank a set of already-measured plugin footprints, largest first, and
	 * produce the friendly summary text. Pure/testable: takes plain arrays,
	 * no filesystem access.
	 *
	 * @param array $footprints List of ['name' => string, 'size_bytes' => int, 'file_count' => int, 'skipped' => bool].
	 * @return array{text:string,ranked:array}
	 */
	public static function format_footprint_summary( array $footprints ) {
		if ( empty( $footprints ) ) {
			return array(
				'text'   => __( 'Could not measure any plugin folder sizes.', 'presshangar-site-checkup' ),
				'ranked' => array(),
			);
		}

		usort(
			$footprints,
			function ( $a, $b ) {
				return $b['size_bytes'] <=> $a['size_bytes'];
			}
		);

		$top = array_slice( $footprints, 0, 5 );

		$lines = array();
		foreach ( $top as $item ) {
			$lines[] = sprintf(
				'%s (%s)',
				$item['name'],
				self::format_bytes( $item['size_bytes'] )
			);
		}

		$text = sprintf(
			/* translators: %s: comma-separated "Plugin Name (size)" entries, largest first. */
			__( 'Largest active plugins by disk space (approximate — this is not a speed measurement, just a size ranking): %s.', 'presshangar-site-checkup' ),
			implode( ', ', $lines )
		);

		return array(
			'text'   => $text,
			'ranked' => $top,
		);
	}

	/**
	 * Run the autoload-size portion of this module against the live
	 * database. Any `$wpdb` failure degrades to an empty result rather than
	 * throwing, since this is diagnostic-only and must never break the
	 * admin screen.
	 *
	 * @return array{severity:string,text:string,top:array}
	 */
	public static function get_autoload_summary() {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return self::format_autoload_summary( array(), 0 );
		}

		$top_limit = 10;

		// The only variable, the row limit, is bound via prepare(). The table
		// identifier is $wpdb->options — constructed by WordPress itself, never
		// user input. This is an on-demand admin diagnostic (no caching).
		$top_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, LENGTH(option_value) AS len FROM {$wpdb->options} WHERE autoload NOT IN ('no','off') ORDER BY len DESC LIMIT %d",
				$top_limit
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- On-demand admin diagnostic; live read must not be cached.

		// The total has no bound parameters (only the trusted $wpdb->options
		// identifier), so there is nothing for prepare() to bind.
		$total = $wpdb->get_var(
			"SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload NOT IN ('no','off')"
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Static query, no user input; only the trusted $wpdb->options identifier.

		return self::format_autoload_summary( is_array( $top_rows ) ? $top_rows : array(), null === $total ? 0 : $total );
	}

	/**
	 * Run the disk-footprint portion of this module against every currently
	 * active plugin, bounded by `SCAN_TIME_BUDGET` overall.
	 *
	 * @return array{text:string,ranked:array,skipped_any:bool}
	 */
	public static function get_footprint_summary() {
		if ( ! function_exists( 'get_plugins' ) ) {
			if ( defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
		}

		if ( ! function_exists( 'get_plugins' ) || ! defined( 'WP_PLUGIN_DIR' ) ) {
			return self::format_footprint_summary( array() );
		}

		$all_plugins = get_plugins();
		// De-duplicate: a corrupted `active_plugins` option can list the same
		// file twice, which would otherwise double-count a plugin's footprint.
		$active_plugins = array_values( array_unique( (array) get_option( 'active_plugins', array() ) ) );
		$deadline       = microtime( true ) + self::SCAN_TIME_BUDGET;

		$footprints  = array();
		$skipped_any = false;

		foreach ( $active_plugins as $plugin_file ) {
			if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
				continue;
			}

			if ( microtime( true ) > $deadline ) {
				$skipped_any = true;
				break; // Overall time budget exhausted; stop measuring further plugins.
			}

			if ( false === strpos( $plugin_file, '/' ) ) {
				// Single-file plugin (e.g. "hello.php"): there is no folder to
				// walk, so measure the file itself rather than looking for a
				// non-existent "<slug>/" directory (which would have reported
				// 0 B and, previously, a spurious "couldn't measure" flag).
				$file_path  = wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) . $plugin_file );
				$size_bytes = is_readable( $file_path ) ? (int) filesize( $file_path ) : 0;
				$result     = array(
					'size_bytes' => $size_bytes,
					'file_count' => 1,
					'skipped'    => false,
				);
			} else {
				$slug   = PHCHECKUP_Duplicates::folder_slug_from_file( $plugin_file );
				$dir    = wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) . $slug );
				$result = self::scan_directory( $dir, min( $deadline, microtime( true ) + 1.0 ), self::SCAN_MAX_FILES_PER_PLUGIN );
			}

			if ( $result['skipped'] ) {
				$skipped_any = true;
			}

			$footprints[] = array(
				'name'       => isset( $all_plugins[ $plugin_file ]['Name'] ) ? $all_plugins[ $plugin_file ]['Name'] : $plugin_file,
				'size_bytes' => $result['size_bytes'],
				'file_count' => $result['file_count'],
				'skipped'    => $result['skipped'],
			);
		}

		$summary                = self::format_footprint_summary( $footprints );
		$summary['skipped_any'] = $skipped_any;

		return $summary;
	}

	/**
	 * Measure a single, one-shot total load time for the site's own front
	 * page. Only ever called from the admin screen's explicit "Measure"
	 * button handler — never from "Run checkup" and never automatically.
	 *
	 * @return array{ok:bool,ms:?int,message:string}
	 */
	public static function measure_frontpage_load_time() {
		if ( ! function_exists( 'wp_remote_get' ) ) {
			return array(
				'ok'      => false,
				'ms'      => null,
				'message' => __( 'Could not measure — the WordPress HTTP API is unavailable.', 'presshangar-site-checkup' ),
			);
		}

		$start    = microtime( true );
		$response = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'   => self::FRONTPAGE_TIMEOUT,
				'sslverify' => false,
			)
		);
		$elapsed_ms = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'ms'      => null,
				'message' => __( 'Could not measure the front page — the request failed. Your site\'s own hosting/network may be blocking outbound requests to itself.', 'presshangar-site-checkup' ),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 400 ) {
			return array(
				'ok'      => false,
				'ms'      => $elapsed_ms,
				/* translators: %d: HTTP status code returned by the front page. */
				'message' => sprintf( __( 'The front page responded with an unexpected status (%d), so this measurement may not be reliable.', 'presshangar-site-checkup' ), $code ),
			);
		}

		return array(
			'ok'      => true,
			'ms'      => $elapsed_ms,
			/* translators: %d: total load time in milliseconds. */
			'message' => sprintf( __( 'Your front page took about %d ms to fully respond, start to finish, for this one request.', 'presshangar-site-checkup' ), $elapsed_ms ),
		);
	}

	/**
	 * Run the full module (everything except the opt-in front-page timing,
	 * which is triggered separately — see `measure_frontpage_load_time()`).
	 *
	 * @return array{summary:string,severity:string,items:array}
	 */
	public static function scan() {
		$items = array();

		$count      = count( (array) get_option( 'active_plugins', array() ) );
		$count_hint = self::plugin_count_hint( $count );

		$items[] = array(
			'severity'   => $count_hint['severity'],
			'what'       => sprintf(
				/* translators: %d: number of active plugins. */
				_n( '%d plugin is currently active.', '%d plugins are currently active.', $count, 'presshangar-site-checkup' ),
				$count
			),
			'why'        => $count_hint['text'],
			'suggestion' => __( 'This is an approximate hint, not a precise measurement — a smaller, well-built set of plugins is generally easier to keep fast than a large one, but plugin quality matters more than plugin count.', 'presshangar-site-checkup' ),
		);

		$autoload = self::get_autoload_summary();
		$items[]  = array(
			'severity'   => $autoload['severity'],
			'what'       => __( 'Autoloaded option data (loaded on every page view)', 'presshangar-site-checkup' ),
			'why'        => $autoload['text'],
			'suggestion' => __( 'This is an approximate hint. If one plugin\'s data dominates this list, it may be worth checking that plugin\'s own settings for anything you can safely trim (PressHangar Site Checkup never edits this data itself).', 'presshangar-site-checkup' ),
		);

		$footprint = self::get_footprint_summary();
		$items[]   = array(
			'severity'   => 'ok',
			'what'       => __( 'Disk space used by active plugins', 'presshangar-site-checkup' ),
			'why'        => $footprint['text'] . ( ! empty( $footprint['skipped_any'] ) ? ' ' . __( '(One or more folders were too large to fully measure within the time PressHangar Site Checkup allows itself, so these numbers may be a partial undercount.)', 'presshangar-site-checkup' ) : '' ),
			'suggestion' => __( 'This is an approximate hint about size, not speed — a large plugin isn\'t automatically a slow one. For precise timing, a developer-focused tool (e.g. Query Monitor) is a better fit.', 'presshangar-site-checkup' ),
		);

		return array(
			'summary'  => __( 'Heaviness hints are approximate signals, not precise timings — use them as a starting point, not a verdict.', 'presshangar-site-checkup' ),
			'severity' => 'ok',
			'items'    => $items,
		);
	}
}
