<?php
/**
 * Module: real output/behaviour conflicts.
 *
 * Where PHCHECKUP_Duplicates reasons about plugin *identity* ("two plugins
 * whose job is SEO are both active"), this module looks for the *symptoms*
 * those overlaps actually produce on the live site — the things a standard
 * Site Health check never surfaces:
 *
 *   1. Search-engine visibility set to "discourage" while an SEO plugin is
 *      active (you are optimizing a site you have told Google to ignore).
 *   2. More than one XML sitemap being served (WordPress core's own
 *      /wp-sitemap.xml still answering while an SEO/sitemap plugin also
 *      publishes its own).
 *   3. Duplicated head tags on the front page — two canonical links, two
 *      og:title tags, two meta descriptions — the tell-tale sign of two
 *      plugins writing the same output.
 *
 * Every check is read-only and best-effort: the two that need to fetch a URL
 * (sitemap + front-page scan) use a short-timeout loopback request and, if
 * it fails for any reason, simply say nothing rather than guessing. Nothing
 * here ever changes a setting or a file.
 *
 * @package PressHangar Site Checkup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PHCHECKUP_Conflicts
 */
class PHCHECKUP_Conflicts {

	/** Loopback request timeout, in seconds. Deliberately short. */
	const HTTP_TIMEOUT = 5;

	/**
	 * Run all conflict checks and aggregate their findings.
	 *
	 * @return array{summary:string,severity:string,items:array}
	 */
	public static function scan() {
		$items = array();

		foreach ( array( 'check_visibility', 'check_sitemaps', 'check_front_page_tags' ) as $method ) {
			try {
				$found = self::$method();
				if ( ! empty( $found ) ) {
					$items[] = $found;
				}
			} catch ( Throwable $e ) {
				// A single failing check must never take down the module.
				continue;
			}
		}

		if ( empty( $items ) ) {
			return array(
				'summary'  => __( 'No output conflicts detected. Nothing looks like it is being written twice.', 'presshangar-site-checkup' ),
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
			'summary'  => sprintf(
				/* translators: %d: number of conflict findings. */
				_n( '%d possible output conflict found.', '%d possible output conflicts found.', count( $items ), 'presshangar-site-checkup' ),
				count( $items )
			),
			'severity' => $severity,
			'items'    => $items,
		);
	}

	/**
	 * Whether at least one active plugin looks like an SEO plugin, reusing
	 * the duplicate-detector's categoriser so the two modules stay in sync.
	 *
	 * @return bool
	 */
	private static function has_active_seo_plugin() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all    = function_exists( 'get_plugins' ) ? get_plugins() : array();
		$active = array_values( array_unique( (array) get_option( 'active_plugins', array() ) ) );

		foreach ( $active as $file ) {
			if ( ! isset( $all[ $file ] ) ) {
				continue;
			}
			$slug   = PHCHECKUP_Duplicates::folder_slug_from_file( $file );
			$result = PHCHECKUP_Duplicates::categorize_plugin(
				$slug,
				isset( $all[ $file ]['Name'] ) ? $all[ $file ]['Name'] : '',
				isset( $all[ $file ]['Description'] ) ? $all[ $file ]['Description'] : ''
			);
			if ( 'seo' === $result['category'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check 1: "discourage search engines" is on while an SEO plugin runs.
	 *
	 * @return array|null
	 */
	private static function check_visibility() {
		$discouraged = ( '0' === (string) get_option( 'blog_public', '1' ) );

		if ( ! $discouraged || ! self::has_active_seo_plugin() ) {
			return null;
		}

		return array(
			'severity'   => 'attention',
			'what'       => __( 'Search engines are being discouraged while an SEO plugin is active.', 'presshangar-site-checkup' ),
			'why'        => __( 'Settings > Reading has "Discourage search engines from indexing this site" turned on, which asks Google and others not to index the site at all. Running an SEO plugin at the same time is working against itself — the SEO effort cannot take effect while indexing is discouraged.', 'presshangar-site-checkup' ),
			'suggestion' => __( 'If the site is meant to be found in search, turn that setting off under Settings > Reading. If the site is intentionally private/under construction, you can ignore this — but then the SEO plugin is not doing anything useful yet.', 'presshangar-site-checkup' ),
		);
	}

	/**
	 * Check 2: WordPress core sitemap still answering while an SEO/sitemap
	 * plugin is also active (two sitemaps).
	 *
	 * @return array|null
	 */
	private static function check_sitemaps() {
		// If core sitemaps are disabled outright, there is nothing to double up.
		if ( function_exists( 'wp_sitemaps_enabled' ) && ! wp_sitemaps_enabled() ) {
			return null;
		}

		// Prefer the core Sitemaps API's own index URL over a hardcoded path.
		$phcheckup_sitemaps = function_exists( 'wp_sitemaps_get_server' ) ? wp_sitemaps_get_server() : null;
		$core_url           = ( $phcheckup_sitemaps && isset( $phcheckup_sitemaps->index ) )
			? $phcheckup_sitemaps->index->get_index_url()
			: home_url( '/wp-sitemap.xml' );
		$response = wp_remote_get(
			$core_url,
			array(
				'timeout'     => self::HTTP_TIMEOUT,
				'redirection' => 0,
				'sslverify'   => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null; // Loopback unavailable — say nothing.
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		// Core sitemap is genuinely serving (200) — is another sitemap
		// generator also active?
		if ( 200 !== $code ) {
			return null; // A plugin has already taken over / redirected core's sitemap; no doubling.
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all    = function_exists( 'get_plugins' ) ? get_plugins() : array();
		$active = array_values( array_unique( (array) get_option( 'active_plugins', array() ) ) );

		$sitemap_makers = array();
		foreach ( $active as $file ) {
			if ( ! isset( $all[ $file ] ) ) {
				continue;
			}
			$slug   = PHCHECKUP_Duplicates::folder_slug_from_file( $file );
			$result = PHCHECKUP_Duplicates::categorize_plugin(
				$slug,
				isset( $all[ $file ]['Name'] ) ? $all[ $file ]['Name'] : '',
				isset( $all[ $file ]['Description'] ) ? $all[ $file ]['Description'] : ''
			);
			if ( in_array( $result['category'], array( 'sitemap', 'seo' ), true ) ) {
				$sitemap_makers[] = isset( $all[ $file ]['Name'] ) ? $all[ $file ]['Name'] : $slug;
			}
		}

		if ( empty( $sitemap_makers ) ) {
			return null;
		}

		return array(
			'severity'   => 'heads-up',
			'what'       => sprintf(
				/* translators: %s: comma-separated plugin names. */
				__( 'WordPress is serving its own sitemap at /wp-sitemap.xml, and another sitemap plugin is also active: %s.', 'presshangar-site-checkup' ),
				implode( __( ', ', 'presshangar-site-checkup' ), $sitemap_makers )
			),
			'why'        => __( 'When two sitemaps are published, search engines can be handed two different lists of your pages. Usually you want exactly one — most SEO plugins expect to replace the built-in sitemap, not run alongside it.', 'presshangar-site-checkup' ),
			'suggestion' => __( 'Pick one sitemap: either turn off your SEO plugin\'s sitemap (and keep the built-in one), or have the SEO plugin disable the core sitemap. PressHangar Site Checkup does not change this for you.', 'presshangar-site-checkup' ),
		);
	}

	/**
	 * Check 3: fetch the front page and count duplicated head tags.
	 *
	 * @return array|null
	 */
	private static function check_front_page_tags() {
		$response = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'     => self::HTTP_TIMEOUT,
				'redirection' => 2,
				'sslverify'   => false,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$html = (string) wp_remote_retrieve_body( $response );
		if ( '' === $html ) {
			return null;
		}

		// Only look at the <head>, to avoid counting tags echoed inside
		// post content.
		if ( preg_match( '/<head\b[^>]*>(.*?)<\/head>/is', $html, $m ) ) {
			$head = $m[1];
		} else {
			$head = $html;
		}

		$checks = array(
			'canonical'        => array( '/<link[^>]+rel=["\']canonical["\'][^>]*>/i', __( 'canonical links', 'presshangar-site-checkup' ) ),
			'og:title'         => array( '/<meta[^>]+property=["\']og:title["\'][^>]*>/i', __( 'Open Graph titles (og:title)', 'presshangar-site-checkup' ) ),
			'description'      => array( '/<meta[^>]+name=["\']description["\'][^>]*>/i', __( 'meta descriptions', 'presshangar-site-checkup' ) ),
		);

		$dupes = array();
		foreach ( $checks as $key => $data ) {
			list( $regex, $label ) = $data;
			$count = preg_match_all( $regex, $head );
			if ( $count > 1 ) {
				$dupes[] = sprintf(
					/* translators: 1: count, 2: tag label e.g. "canonical links". */
					__( '%1$d %2$s', 'presshangar-site-checkup' ),
					$count,
					$label
				);
			}
		}

		if ( empty( $dupes ) ) {
			return null;
		}

		return array(
			'severity'   => 'attention',
			'what'       => sprintf(
				/* translators: %s: list like "2 canonical links, 2 og:title tags". */
				__( 'Your front page outputs duplicated head tags: %s.', 'presshangar-site-checkup' ),
				implode( __( ', ', 'presshangar-site-checkup' ), $dupes )
			),
			'why'        => __( 'A page should have exactly one canonical link, one og:title, and one meta description. Two of any of these almost always means two plugins are both writing them — for example two SEO plugins. Search engines and social networks may then pick the wrong one.', 'presshangar-site-checkup' ),
			'suggestion' => __( 'This is the on-page symptom of overlapping SEO plugins (see the Overlapping plugins check). Keep one SEO plugin active and the duplicates should disappear. PressHangar Site Checkup only looks — it never edits your pages.', 'presshangar-site-checkup' ),
		);
	}
}
