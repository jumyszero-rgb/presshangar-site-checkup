<?php
/**
 * Module: feature-duplicate detection.
 *
 * PressHangar Site Checkup's flagship diagnostic. Looks at every *active* plugin, guesses
 * which "job" it does (SEO, caching, backups, ...) from a signature
 * dictionary first and a keyword heuristic second, and flags any job that
 * two or more active plugins are doing at once — the classic "two SEO
 * plugins fighting over the same meta tags" situation, explained in plain
 * language rather than left for the site owner to notice the hard way.
 *
 * Entirely read-only: this module only ever reads `get_plugins()` and the
 * `active_plugins` option. It never activates, deactivates, or deletes
 * anything.
 *
 * All the actual decision logic lives in small, static, side-effect-free
 * methods (`categorize_plugin()`, `build_findings()`) that take plain
 * arrays in and return plain arrays out, precisely so they can be exercised
 * from a CLI test harness without a WordPress runtime.
 *
 * @package PressHangar Site Checkup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PHCHECKUP_Duplicates
 */
class PHCHECKUP_Duplicates {

	/**
	 * Signature dictionary: plugin folder slug (the directory name portion
	 * of the plugin's main file, e.g. "wordpress-seo" for
	 * "wordpress-seo/wp-seo.php") mapped to the feature category it belongs
	 * to and whether it's one of Musubiemu's own PressHangar-suite plugins.
	 *
	 * This is the high-confidence path: an exact folder-slug match. Anything
	 * not listed here falls through to the keyword heuristic in
	 * `categorize_plugin()`.
	 *
	 * @var array<string,array{category:string,own:bool}>
	 */
	const DICTIONARY = array(
		// SEO.
		'wordpress-seo'                       => array( 'category' => 'seo', 'own' => false ),
		'seo-by-rank-math'                     => array( 'category' => 'seo', 'own' => false ),
		'all-in-one-seo-pack'                  => array( 'category' => 'seo', 'own' => false ),
		'wp-seopress'                          => array( 'category' => 'seo', 'own' => false ),
		'autodescription'                      => array( 'category' => 'seo', 'own' => false ),
		'seopilot'                             => array( 'category' => 'seo', 'own' => true ),
		'citepilot'                            => array( 'category' => 'seo', 'own' => true ),

		// Caching.
		'wp-super-cache'                       => array( 'category' => 'cache', 'own' => false ),
		'w3-total-cache'                       => array( 'category' => 'cache', 'own' => false ),
		'wp-rocket'                            => array( 'category' => 'cache', 'own' => false ),
		'litespeed-cache'                      => array( 'category' => 'cache', 'own' => false ),
		'wp-fastest-cache'                     => array( 'category' => 'cache', 'own' => false ),
		'cache-enabler'                        => array( 'category' => 'cache', 'own' => false ),
		'cachepilot'                           => array( 'category' => 'cache', 'own' => true ),

		// Security.
		'wordfence'                            => array( 'category' => 'security', 'own' => false ),
		'sucuri-scanner'                       => array( 'category' => 'security', 'own' => false ),
		'better-wp-security'                   => array( 'category' => 'security', 'own' => false ),
		'all-in-one-wp-security-and-firewall'  => array( 'category' => 'security', 'own' => false ),

		// Backup.
		'updraftplus'                          => array( 'category' => 'backup', 'own' => false ),
		'backwpup'                             => array( 'category' => 'backup', 'own' => false ),
		'duplicator'                           => array( 'category' => 'backup', 'own' => false ),
		'all-in-one-wp-migration'              => array( 'category' => 'backup', 'own' => false ),

		// Forms.
		'contact-form-7'                       => array( 'category' => 'forms', 'own' => false ),
		'wpforms-lite'                         => array( 'category' => 'forms', 'own' => false ),
		'gravityforms'                         => array( 'category' => 'forms', 'own' => false ),
		'ninja-forms'                          => array( 'category' => 'forms', 'own' => false ),
		'formidable'                           => array( 'category' => 'forms', 'own' => false ),

		// Image optimization.
		'wp-smushit'                           => array( 'category' => 'image', 'own' => false ),
		'shortpixel-image-optimiser'           => array( 'category' => 'image', 'own' => false ),
		'ewww-image-optimizer'                 => array( 'category' => 'image', 'own' => false ),
		'imagify'                              => array( 'category' => 'image', 'own' => false ),
		'optimole-wp'                          => array( 'category' => 'image', 'own' => false ),
		'webp-converter-for-media'             => array( 'category' => 'image', 'own' => false ),
		'imagepilot'                           => array( 'category' => 'image', 'own' => true ),

		// Sitemap. (SEOPilot also produces a sitemap, but it's filed under
		// "seo" above, not here — an own-suite plugin only ever occupies
		// one category, which is what keeps it out of unrelated collisions.)
		'google-sitemap-generator'             => array( 'category' => 'sitemap', 'own' => false ),
		'xml-sitemap-feed'                     => array( 'category' => 'sitemap', 'own' => false ),
		'wp-sitemap-page'                      => array( 'category' => 'sitemap', 'own' => false ),

		// Related posts.
		'yet-another-related-posts-plugin'     => array( 'category' => 'related', 'own' => false ),
		'contextual-related-posts'             => array( 'category' => 'related', 'own' => false ),

		// Link management / cloaking.
		'pretty-link'                          => array( 'category' => 'links', 'own' => false ),
		'thirsty-affiliates'                   => array( 'category' => 'links', 'own' => false ),
		'linkpilot'                            => array( 'category' => 'links', 'own' => true ),

		// RSS aggregation / import.
		'feedzy-rss-feeds'                     => array( 'category' => 'rss', 'own' => false ),
		'wp-rss-aggregator'                    => array( 'category' => 'rss', 'own' => false ),
		'feedpilot'                            => array( 'category' => 'rss', 'own' => true ),

		// Code snippets / header-footer injection.
		'code-snippets'                        => array( 'category' => 'snippets', 'own' => false ),
		'insert-headers-and-footers'           => array( 'category' => 'snippets', 'own' => false ),
		'wpcode'                               => array( 'category' => 'snippets', 'own' => false ),
		'musubiemu-custom'                     => array( 'category' => 'snippets', 'own' => true ),

		// --- Expanded coverage: PressHangar suite (own) + more third-party. ---
		// Breadcrumbs.
		'breadcrumb-navxt'                     => array( 'category' => 'breadcrumbs', 'own' => false ),
		'flexy-breadcrumb'                     => array( 'category' => 'breadcrumbs', 'own' => false ),
		'presshangar-breadcrumbs'              => array( 'category' => 'breadcrumbs', 'own' => true ),
		// Table of contents.
		'easy-table-of-contents'               => array( 'category' => 'toc', 'own' => false ),
		'luckywp-table-of-contents'            => array( 'category' => 'toc', 'own' => false ),
		'table-of-contents-plus'               => array( 'category' => 'toc', 'own' => false ),
		'presshangar-table-of-contents'        => array( 'category' => 'toc', 'own' => true ),
		// Related posts (own).
		'presshangar-related-posts'            => array( 'category' => 'related', 'own' => true ),
		// Redirects.
		'redirection'                          => array( 'category' => 'redirects', 'own' => false ),
		'simple-301-redirects'                 => array( 'category' => 'redirects', 'own' => false ),
		'eps-301-redirects'                    => array( 'category' => 'redirects', 'own' => false ),
		'presshangar-link-router'              => array( 'category' => 'redirects', 'own' => true ),
		// Affiliate link management.
		'thirstyaffiliates'                    => array( 'category' => 'affiliate', 'own' => false ),
		'aawp'                                 => array( 'category' => 'affiliate', 'own' => false ),
		'presshangar-geo-link-localizer'       => array( 'category' => 'affiliate', 'own' => true ),
		// Broken-link checking.
		'broken-link-checker'                  => array( 'category' => 'linkcheck', 'own' => false ),
		'presshangar-affiliate-link-sentinel'  => array( 'category' => 'linkcheck', 'own' => true ),
		// Scheduled / drip publishing.
		'wp-scheduled-posts'                   => array( 'category' => 'scheduling', 'own' => false ),
		'tao-schedule-update'                  => array( 'category' => 'scheduling', 'own' => false ),
		'presshangar-draft-pacer'              => array( 'category' => 'scheduling', 'own' => true ),
		'drippilot'                            => array( 'category' => 'scheduling', 'own' => true ),
		// Site config export / migration.
		'wp-migrate-db'                        => array( 'category' => 'migration', 'own' => false ),
		'presshangar-site-blueprint'           => array( 'category' => 'migration', 'own' => true ),
		// Index request / IndexNow.
		'instant-indexing'                     => array( 'category' => 'indexing', 'own' => false ),
		'fast-indexing-api'                    => array( 'category' => 'indexing', 'own' => false ),
		'presshangar-index-cockpit'            => array( 'category' => 'indexing', 'own' => true ),
		'presshangar-ai-citations'             => array( 'category' => 'indexing', 'own' => true ),
		// Analytics.
		'google-site-kit'                      => array( 'category' => 'analytics', 'own' => false ),
		'google-analytics-for-wordpress'       => array( 'category' => 'analytics', 'own' => false ),
		'ga-google-analytics'                  => array( 'category' => 'analytics', 'own' => false ),
		// Cookie / consent banners.
		'cookie-notice'                        => array( 'category' => 'consent', 'own' => false ),
		'complianz-gdpr'                       => array( 'category' => 'consent', 'own' => false ),
		'cookie-law-info'                      => array( 'category' => 'consent', 'own' => false ),
		// PressHangar suite in existing categories (own).
		'presshangar-contact-box'              => array( 'category' => 'forms', 'own' => true ),
		'presshangar-webp-converter'           => array( 'category' => 'image', 'own' => true ),
		'presshangar-lite-cache'               => array( 'category' => 'cache', 'own' => true ),
		'presshangar-feed-import'              => array( 'category' => 'rss', 'own' => true ),
		'presshangar-seo-starter'              => array( 'category' => 'seo', 'own' => true ),
	);

	/**
	 * Keyword heuristic, checked in order against "<Name> <Description>"
	 * when a plugin's folder slug isn't in the dictionary above. The first
	 * pattern that matches wins. Word-boundaries are used for common short
	 * English words (e.g. "form", "seo") specifically so this doesn't fire
	 * on unrelated words that merely contain the letters ("inFORMation",
	 * "plat SEO unds" — i.e. false positives), while looser stem matches
	 * (e.g. "cach", "optimiz") are used where the extra recall is wanted
	 * ("caching", "optimization", "optimizer", ...).
	 *
	 * Anything matched here is inherently a guess, so callers must treat it
	 * as low-confidence — see `categorize_plugin()`.
	 *
	 * @var array<int,array{0:string,1:string}> List of [ regex, category ].
	 */
	const KEYWORD_PATTERNS = array(
		array( '/\bseo\b/i', 'seo' ),
		array( '/キャッシュ/u', 'cache' ),
		array( '/\bcach(e|ing)\b/i', 'cache' ),
		array( '/バックアップ/u', 'backup' ),
		array( '/\bbackup/i', 'backup' ),
		array( '/セキュリティ/u', 'security' ),
		array( '/\bsecurity\b/i', 'security' ),
		array( '/フォーム/u', 'forms' ),
		array( '/\bforms?\b/i', 'forms' ),
		array( '/\bgallery\b/i', 'gallery' ),
		array( '/\bslider\b/i', 'slider' ),
		array( '/\brelated\b/i', 'related' ),
		array( '/\bsitemap\b/i', 'sitemap' ),
		array( '/画像/u', 'image' ),
		array( '/optimiz/i', 'image' ),
	);

	/**
	 * Human-readable, translated labels for each category slug used above.
	 *
	 * @return array<string,string>
	 */
	public static function get_category_labels() {
		return array(
			'seo'      => __( 'SEO', 'presshangar-site-checkup' ),
			'cache'    => __( 'Caching', 'presshangar-site-checkup' ),
			'security' => __( 'Security', 'presshangar-site-checkup' ),
			'backup'   => __( 'Backup', 'presshangar-site-checkup' ),
			'forms'    => __( 'Forms', 'presshangar-site-checkup' ),
			'image'    => __( 'Image optimization', 'presshangar-site-checkup' ),
			'sitemap'  => __( 'Sitemap', 'presshangar-site-checkup' ),
			'related'  => __( 'Related posts', 'presshangar-site-checkup' ),
			'links'    => __( 'Link management', 'presshangar-site-checkup' ),
			'rss'      => __( 'RSS import/aggregation', 'presshangar-site-checkup' ),
			'snippets' => __( 'Code snippets', 'presshangar-site-checkup' ),
			'gallery'  => __( 'Image galleries', 'presshangar-site-checkup' ),
			'slider'   => __( 'Sliders/carousels', 'presshangar-site-checkup' ),
			'breadcrumbs' => __( 'Breadcrumbs', 'presshangar-site-checkup' ),
			'toc'      => __( 'Table of contents', 'presshangar-site-checkup' ),
			'redirects' => __( 'Redirects', 'presshangar-site-checkup' ),
			'affiliate' => __( 'Affiliate link management', 'presshangar-site-checkup' ),
			'linkcheck' => __( 'Broken-link checking', 'presshangar-site-checkup' ),
			'scheduling' => __( 'Scheduled publishing', 'presshangar-site-checkup' ),
			'migration' => __( 'Migration / site config', 'presshangar-site-checkup' ),
			'indexing' => __( 'Index requests (IndexNow)', 'presshangar-site-checkup' ),
			'analytics' => __( 'Analytics', 'presshangar-site-checkup' ),
			'consent'  => __( 'Cookie / consent banners', 'presshangar-site-checkup' ),
		);
	}

	/**
	 * Categorize a single plugin from its folder slug, name, and
	 * description. Pure function — no WordPress calls — so it's directly
	 * unit-testable.
	 *
	 * @param string $folder_slug Plugin directory slug, e.g. "wordpress-seo".
	 * @param string $name        Plugin "Name" header value.
	 * @param string $description Plugin "Description" header value.
	 * @return array{category:?string,confidence:string,own:bool} `category`
	 *               is null when nothing matched. `confidence` is "high"
	 *               (dictionary hit) or "low" (keyword guess).
	 */
	public static function categorize_plugin( $folder_slug, $name = '', $description = '' ) {
		$folder_slug = strtolower( trim( (string) $folder_slug ) );

		if ( isset( self::DICTIONARY[ $folder_slug ] ) ) {
			$entry = self::DICTIONARY[ $folder_slug ];

			return array(
				'category'   => $entry['category'],
				'confidence' => 'high',
				'own'        => (bool) $entry['own'],
			);
		}

		$haystack = trim( $name . ' ' . $description );

		foreach ( self::KEYWORD_PATTERNS as $pattern ) {
			list( $regex, $category ) = $pattern;

			if ( '' !== $haystack && preg_match( $regex, $haystack ) ) {
				return array(
					'category'   => $category,
					'confidence' => 'low',
					'own'        => false,
				);
			}
		}

		return array(
			'category'   => null,
			'confidence' => 'low',
			'own'        => false,
		);
	}

	/**
	 * Extract the folder slug from a plugin's relative file path, matching
	 * how WordPress keys `active_plugins` and `get_plugins()`
	 * (e.g. "wordpress-seo/wp-seo.php" -> "wordpress-seo"). Single-file
	 * plugins with no folder (e.g. "hello.php") return the file's basename
	 * without extension.
	 *
	 * @param string $plugin_file Relative plugin file path.
	 * @return string
	 */
	public static function folder_slug_from_file( $plugin_file ) {
		$plugin_file = (string) $plugin_file;

		if ( false !== strpos( $plugin_file, '/' ) ) {
			return strtolower( strtok( $plugin_file, '/' ) );
		}

		return strtolower( preg_replace( '/\.php$/i', '', $plugin_file ) );
	}

	/**
	 * Build the duplicate-category findings from a list of already
	 * categorized active plugins. Pure function — the actual grouping and
	 * "is this worth flagging" rule live here so they're testable without a
	 * WordPress runtime.
	 *
	 * The flagging rule: a category is flagged only when at least one
	 * *non-own* (third-party) plugin occupies it and the category has 2 or
	 * more plugins total. In other words:
	 *   - Two or more of Musubiemu's own PressHangar-suite plugins sharing a
	 *     category → never flagged (they're built to work together).
	 *   - One own plugin + one third-party plugin in the same category →
	 *     flagged ("you probably only need one of these").
	 *   - Two or more third-party plugins in the same category → flagged
	 *     (the classic case).
	 *   - Exactly one plugin in a category (own or not) → never flagged.
	 *
	 * @param array $items List of items, each:
	 *                     array{
	 *                         file: string,
	 *                         name: string,
	 *                         category: ?string,
	 *                         confidence: string,
	 *                         own: bool,
	 *                     }.
	 * @return array List of findings, each:
	 *               array{
	 *                   category: string,
	 *                   severity: string ('heads-up'|'attention'),
	 *                   plugins: array<int,array{name:string,own:bool}>,
	 *                   own_count: int,
	 *                   other_count: int,
	 *                   any_low_confidence: bool,
	 *               }.
	 */
	public static function build_findings( array $items ) {
		$by_category = array();

		foreach ( $items as $item ) {
			if ( empty( $item['category'] ) ) {
				continue;
			}

			$category = $item['category'];

			if ( ! isset( $by_category[ $category ] ) ) {
				$by_category[ $category ] = array();
			}

			$by_category[ $category ][] = $item;
		}

		$findings = array();

		foreach ( $by_category as $category => $plugins_in_category ) {
			$own_count   = 0;
			$other_count = 0;
			$low_conf    = false;

			foreach ( $plugins_in_category as $plugin ) {
				if ( ! empty( $plugin['own'] ) ) {
					++$own_count;
				} else {
					++$other_count;
				}

				if ( isset( $plugin['confidence'] ) && 'low' === $plugin['confidence'] ) {
					$low_conf = true;
				}
			}

			$total = $own_count + $other_count;

			// The flagging rule, see docblock above.
			$should_flag = ( $other_count >= 1 ) && ( $total >= 2 );

			if ( ! $should_flag ) {
				continue;
			}

			$findings[] = array(
				'category'           => $category,
				'severity'           => $total >= 3 ? 'attention' : 'heads-up',
				'plugins'            => array_map(
					function ( $plugin ) {
						return array(
							'name' => $plugin['name'],
							'own'  => ! empty( $plugin['own'] ),
						);
					},
					$plugins_in_category
				),
				'own_count'          => $own_count,
				'other_count'        => $other_count,
				'any_low_confidence' => $low_conf,
			);
		}

		return $findings;
	}

	/**
	 * Run the full duplicate-detection module against the site's currently
	 * active plugins.
	 *
	 * @return array{summary:string,severity:string,items:array} Module result.
	 */
	public static function scan() {
		$items = array();

		if ( function_exists( 'get_plugins' ) ) {
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$all_plugins = get_plugins();
			// De-duplicate: a corrupted `active_plugins` option can list the
			// same plugin file twice. Left unchecked that would put the same
			// plugin into a category twice and produce a bogus "2 plugins are
			// doing SEO: Yoast, Yoast" overlap finding.
			$active_plugins = array_values( array_unique( (array) get_option( 'active_plugins', array() ) ) );

			foreach ( $active_plugins as $plugin_file ) {
				if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
					continue; // Listed active but no longer on disk; nothing to categorize.
				}

				$data   = $all_plugins[ $plugin_file ];
				$slug   = self::folder_slug_from_file( $plugin_file );
				$result = self::categorize_plugin(
					$slug,
					isset( $data['Name'] ) ? $data['Name'] : '',
					isset( $data['Description'] ) ? $data['Description'] : ''
				);

				$items[] = array(
					'file'       => $plugin_file,
					'name'       => isset( $data['Name'] ) ? $data['Name'] : $plugin_file,
					'category'   => $result['category'],
					'confidence' => $result['confidence'],
					'own'        => $result['own'],
				);
			}
		}

		$findings = self::build_findings( $items );
		$labels   = self::get_category_labels();

		$rendered = array();

		foreach ( $findings as $finding ) {
			$names = wp_list_pluck( $finding['plugins'], 'name' );
			$label = isset( $labels[ $finding['category'] ] ) ? $labels[ $finding['category'] ] : $finding['category'];

			$what = sprintf(
				/* translators: 1: number of plugins, 2: category label (e.g. "SEO"), 3: comma-separated plugin names. */
				__( '%1$d active plugins are doing %2$s: %3$s.', 'presshangar-site-checkup' ),
				count( $names ),
				$label,
				implode( __( ', ', 'presshangar-site-checkup' ), $names )
			);

			if ( $finding['own_count'] > 0 && $finding['other_count'] > 0 ) {
				$why = __( 'One of these is part of our own PressHangar suite, and it looks like it overlaps with a third-party plugin doing the same job. Running both usually means duplicated work in the background, and sometimes conflicting output (e.g. two sets of meta tags or two sitemaps).', 'presshangar-site-checkup' );
			} else {
				$why = __( 'Running more than one plugin for the same job usually means duplicated work happening on every page load, and can sometimes cause the two plugins to actively conflict (for example, both trying to write the same meta tags or the same sitemap).', 'presshangar-site-checkup' );
			}

			$suggestion = __( 'Keep the one you rely on most and consider deactivating the other(s) — PressHangar Site Checkup never does this for you, so please do it by hand from the Plugins screen when you\'re ready.', 'presshangar-site-checkup' );

			if ( $finding['any_low_confidence'] ) {
				$why .= ' ' . __( '(This category was guessed from the plugin\'s name/description rather than a known signature, so double-check before acting on it.)', 'presshangar-site-checkup' );
			}

			$rendered[] = array(
				'severity'   => $finding['severity'],
				'what'       => $what,
				'why'        => $why,
				'suggestion' => $suggestion,
			);
		}

		if ( empty( $rendered ) ) {
			$summary  = __( 'No overlapping plugins found. Each active plugin appears to be doing a distinct job.', 'presshangar-site-checkup' );
			$severity = 'ok';
		} else {
			$summary  = sprintf(
				/* translators: %d: number of overlap findings. */
				_n( '%d possible overlap found among your active plugins.', '%d possible overlaps found among your active plugins.', count( $rendered ), 'presshangar-site-checkup' ),
				count( $rendered )
			);
			$severity = 'ok';
			foreach ( $rendered as $item ) {
				if ( 'attention' === $item['severity'] ) {
					$severity = 'attention';
					break;
				}
				$severity = 'heads-up';
			}
		}

		return array(
			'summary'  => $summary,
			'severity' => $severity,
			'items'    => $rendered,
		);
	}
}
