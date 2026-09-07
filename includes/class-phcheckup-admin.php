<?php
/**
 * Admin screen: Tools > PressHangar Site Checkup.
 *
 * A single page, no tabs: a big "Run checkup" button up top, the last
 * result underneath (or a first-run empty state), and a small settings
 * card at the bottom to turn individual modules on/off. Every action here
 * is capability-gated (`manage_options`) and nonce-protected via
 * `admin-post.php`, matching the rest of the PressHangar suite's admin
 * pattern.
 *
 * This class is the only place in PressHangar Site Checkup that ever calls
 * `update_option()`, and it only ever writes the options this plugin owns:
 * `PHCHECKUP_OPTION_SETTINGS`, `PHCHECKUP_OPTION_LAST_SCAN`, and the
 * `phcheckup_review_dismissed` flag for the one-time review request.
 *
 * @package PressHangar Site Checkup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PHCHECKUP_Admin
 */
class PHCHECKUP_Admin {

	/** Settings page slug (under Tools). */
	const PAGE_SLUG = 'presshangar-site-checkup';

	const NONCE_RUN_CHECKUP      = 'phcheckup_run_checkup';
	const NONCE_MEASURE_FRONT    = 'phcheckup_measure_frontpage';
	const NONCE_SAVE_SETTINGS    = 'phcheckup_save_settings';

	/** Option flag: the user dismissed the review request. */
	const OPTION_REVIEW_DISMISSED = 'phcheckup_review_dismissed';

	/** Module slugs, in the order they're run and displayed. */
	const MODULES = array( 'duplicates', 'conflicts', 'weight', 'inventory' );

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );

		add_action( 'admin_post_phcheckup_run_checkup', array( __CLASS__, 'handle_run_checkup' ) );
		add_action( 'admin_post_phcheckup_measure_frontpage', array( __CLASS__, 'handle_measure_frontpage' ) );
		add_action( 'admin_post_phcheckup_save_settings', array( __CLASS__, 'handle_save_settings' ) );
	}

	/**
	 * Default settings values. Every module defaults ON: unlike a plugin
	 * that changes site behaviour, a read-only diagnostic has no downside
	 * to running by default. The one exception is the front-page load-time
	 * measurement, which is never part of the default checkup run — it
	 * only ever fires from its own explicit button (see class docblock).
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'modules' => array(
				'duplicates' => true,
				'conflicts'  => true,
				'weight'     => true,
				'inventory'  => true,
			),
		);
	}

	/**
	 * Human-readable label for a module slug.
	 *
	 * @param string $module Module slug.
	 * @return string
	 */
	private static function module_label( $module ) {
		$labels = array(
			'duplicates' => __( 'Overlapping plugins', 'presshangar-site-checkup' ),
			'conflicts'  => __( 'Output conflicts (sitemaps, duplicate tags)', 'presshangar-site-checkup' ),
			'weight'     => __( 'Heaviness hints', 'presshangar-site-checkup' ),
			'inventory'  => __( 'Unused plugins & themes', 'presshangar-site-checkup' ),
		);

		return isset( $labels[ $module ] ) ? $labels[ $module ] : $module;
	}

	/**
	 * Friendly icon + label for a severity level. Deliberately not "red
	 * error" styling for anything — see the class docblock and readme:
	 * PressHangar Site Checkup reports findings, it doesn't raise alarms.
	 *
	 * @param string $severity One of 'ok', 'heads-up', 'attention'.
	 * @return array{icon:string,label:string,class:string}
	 */
	public static function severity_badge( $severity ) {
		switch ( $severity ) {
			case 'attention':
				return array(
					'icon'  => '⚠️',
					'label' => __( 'Worth a look', 'presshangar-site-checkup' ),
					'class' => 'drp-badge-attention',
				);
			case 'heads-up':
				return array(
					'icon'  => '💡',
					'label' => __( 'Heads up', 'presshangar-site-checkup' ),
					'class' => 'drp-badge-heads-up',
				);
			default:
				return array(
					'icon'  => '✅',
					'label' => __( 'All clear', 'presshangar-site-checkup' ),
					'class' => 'drp-badge-ok',
				);
		}
	}

	/**
	 * Combine the per-module results into a single overall headline.
	 * Pure function — takes the module results array, returns text +
	 * worst severity — so it's easy to reason about (and test) independent
	 * of how the results were produced.
	 *
	 * @param array $results Module slug => module result array (or null if the module was skipped/off).
	 * @return array{text:string,severity:string}
	 */
	public static function build_overall_summary( array $results ) {
		$attention_count = 0;
		$heads_up_count  = 0;
		$any_ran         = false;

		foreach ( $results as $result ) {
			if ( ! is_array( $result ) ) {
				continue;
			}

			$any_ran = true;

			foreach ( ( isset( $result['items'] ) ? $result['items'] : array() ) as $item ) {
				if ( isset( $item['severity'] ) && 'attention' === $item['severity'] ) {
					++$attention_count;
				} elseif ( isset( $item['severity'] ) && 'heads-up' === $item['severity'] ) {
					++$heads_up_count;
				}
			}
		}

		if ( ! $any_ran ) {
			return array(
				'text'     => __( 'No checks are turned on, so there\'s nothing to summarize yet.', 'presshangar-site-checkup' ),
				'severity' => 'ok',
			);
		}

		$total = $attention_count + $heads_up_count;

		if ( 0 === $total ) {
			return array(
				'text'     => __( 'Your site looks healthy overall — no notable issues found.', 'presshangar-site-checkup' ),
				'severity' => 'ok',
			);
		}

		if ( $attention_count > 0 ) {
			return array(
				'text'     => sprintf(
					/* translators: %d: number of things worth a closer look. */
					_n( 'We found %d thing worth a closer look.', 'We found %d things worth a closer look.', $total, 'presshangar-site-checkup' ),
					$total
				),
				'severity' => 'attention',
			);
		}

		return array(
			'text'     => sprintf(
				/* translators: %d: number of minor points noticed. */
				_n( 'Mostly healthy — %d small point to be aware of.', 'Mostly healthy — %d small points to be aware of.', $total, 'presshangar-site-checkup' ),
				$total
			),
			'severity' => 'heads-up',
		);
	}

	/**
	 * Register the "Tools > PressHangar Site Checkup" submenu page.
	 */
	public static function add_menu() {
		add_submenu_page(
			'tools.php',
			__( 'PressHangar Site Checkup', 'presshangar-site-checkup' ),
			__( 'PressHangar Site Checkup', 'presshangar-site-checkup' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Whether the current admin screen is this plugin's page.
	 *
	 * @return bool
	 */
	private static function is_plugin_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && false !== strpos( (string) $screen->id, self::PAGE_SLUG );
	}

	/**
	 * Build a redirect URL back to this settings page, with an optional
	 * notice query arg for `render_notices()` to pick up.
	 *
	 * @param string $notice Notice key (optional).
	 * @return string
	 */
	private static function page_url( $notice = '' ) {
		$args = array( 'page' => self::PAGE_SLUG );

		if ( '' !== $notice ) {
			$args['phcheckup_notice'] = $notice;
		}

		return add_query_arg( $args, admin_url( 'tools.php' ) );
	}

	/**
	 * Redirect back to the settings page with a notice and exit. Shared
	 * tail of every admin-post handler below.
	 *
	 * @param string $notice Notice key.
	 */
	private static function redirect( $notice ) {
		wp_safe_redirect( self::page_url( $notice ) );
		exit;
	}

	/**
	 * Common capability + nonce check used at the top of every handler.
	 *
	 * @param string $nonce_action Nonce action name.
	 */
	private static function require_capability_and_nonce( $nonce_action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'presshangar-site-checkup' ) );
		}

		check_admin_referer( $nonce_action );
	}

	/* =========================================================================
	 * Notices
	 * =======================================================================*/

	/**
	 * Render this plugin's own save/action feedback, on its own screen only.
	 */
	public static function render_notices() {
		if ( ! current_user_can( 'manage_options' ) || ! self::is_plugin_screen() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, used only to select which static notice to display after a redirect.
		if ( ! isset( $_GET['phcheckup_notice'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = sanitize_key( wp_unslash( $_GET['phcheckup_notice'] ) );

		$messages = array(
			'checkup_done'    => array( __( 'Checkup complete.', 'presshangar-site-checkup' ), 'success' ),
			'frontpage_done'  => array( __( 'Front-page measurement complete.', 'presshangar-site-checkup' ), 'success' ),
			'settings_saved'  => array( __( 'Settings saved.', 'presshangar-site-checkup' ), 'success' ),
			'nothing_enabled' => array( __( 'All checks are currently turned off — enable at least one below and run the checkup again.', 'presshangar-site-checkup' ), 'error' ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		list( $text, $type ) = $messages[ $notice ];

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( 'success' === $type ? 'success' : 'error' ),
			esc_html( $text )
		);
	}

	/* =========================================================================
	 * Handlers
	 * =======================================================================*/

	/**
	 * Run every enabled module and cache the combined result in
	 * `phcheckup_last_scan`. This is the only place PressHangar Site Checkup ever actually
	 * performs its diagnostic work — it only happens here, in direct
	 * response to this one explicit, nonce-protected button click.
	 */
	public static function handle_run_checkup() {
		self::require_capability_and_nonce( self::NONCE_RUN_CHECKUP );

		$settings = phcheckup_get_settings();
		$modules  = isset( $settings['modules'] ) ? (array) $settings['modules'] : array();

		$results = array();

		foreach ( self::MODULES as $module ) {
			if ( empty( $modules[ $module ] ) ) {
				$results[ $module ] = null;
				continue;
			}

			// Guard each module independently: if one throws, the checkup
			// still completes and reports the rest rather than failing
			// entirely (per the spec's "one module falling over must never
			// take down the others" rule).
			try {
				switch ( $module ) {
					case 'duplicates':
						$results[ $module ] = PHCHECKUP_Duplicates::scan();
						break;
					case 'conflicts':
						$results[ $module ] = PHCHECKUP_Conflicts::scan();
						break;
					case 'weight':
						$results[ $module ] = PHCHECKUP_Weight::scan();
						break;
					case 'inventory':
						$results[ $module ] = PHCHECKUP_Inventory::scan();
						break;
				}
			} catch ( Throwable $e ) {
				$results[ $module ] = array(
					'summary'  => __( 'This check could not complete.', 'presshangar-site-checkup' ),
					'severity' => 'heads-up',
					'items'    => array(),
				);
			}
		}

		if ( array_filter( $modules ) === array() ) {
			self::redirect( 'nothing_enabled' );
		}

		// Preserve any previously-measured front-page timing across a
		// re-run — the measurement is opt-in/explicit and shouldn't be
		// silently discarded just because "Run checkup" was clicked again.
		$previous  = phcheckup_get_last_scan();
		$last_scan = array(
			'time'      => time(),
			'results'   => $results,
			'frontpage' => isset( $previous['frontpage'] ) ? $previous['frontpage'] : null,
		);

		update_option( PHCHECKUP_OPTION_LAST_SCAN, $last_scan );

		self::redirect( 'checkup_done' );
	}

	/**
	 * Run the one-shot, opt-in front-page load-time measurement and merge
	 * it into the cached scan result. Deliberately separate from
	 * `handle_run_checkup()` so it only ever fires when a site owner clicks
	 * this specific button — see class docblock and the plugin header.
	 */
	public static function handle_measure_frontpage() {
		self::require_capability_and_nonce( self::NONCE_MEASURE_FRONT );

		$result = PHCHECKUP_Weight::measure_frontpage_load_time();

		$last_scan             = phcheckup_get_last_scan();
		$last_scan['frontpage'] = array(
			'time'   => time(),
			'result' => $result,
		);

		// If a checkup has never been run yet, still record the
		// measurement — the page renders a first-run empty state for the
		// three modules but can show this result on its own.
		update_option( PHCHECKUP_OPTION_LAST_SCAN, $last_scan );

		self::redirect( 'frontpage_done' );
	}

	/**
	 * Save which modules are turned on. This never affects site behaviour,
	 * only which read-only checks `handle_run_checkup()` performs next time.
	 */
	public static function handle_save_settings() {
		self::require_capability_and_nonce( self::NONCE_SAVE_SETTINGS );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via check_admin_referer().
		$input    = wp_unslash( $_POST );
		$settings = phcheckup_get_settings();

		foreach ( self::MODULES as $module ) {
			$settings['modules'][ $module ] = ! empty( $input['modules'][ $module ] );
		}

		update_option( PHCHECKUP_OPTION_SETTINGS, $settings );

		self::redirect( 'settings_saved' );
	}

	/* =========================================================================
	 * Rendering
	 * =======================================================================*/

	/**
	 * Render the state-aware "Getting started" panel shown at the top of the
	 * page. Each step reflects whether it is already done, so the panel
	 * doubles as a live checklist for first-time users.
	 *
	 * @param bool $has_run       Whether a checkup has been run.
	 * @param bool $has_frontpage Whether the front-page measurement has run.
	 */
	private static function render_getting_started( $has_run, $has_frontpage ) {
		$steps = array(
			array(
				'done'  => $has_run,
				'title' => __( 'Run your first checkup', 'presshangar-site-checkup' ),
				'body'  => __( 'Press "Run checkup" above. It only looks at your site — it never deactivates, deletes, or edits anything.', 'presshangar-site-checkup' ),
			),
			array(
				'done'  => $has_run,
				'title' => __( 'Review the findings in plain language', 'presshangar-site-checkup' ),
				'body'  => __( 'Each result explains what it is and why it matters. Any cleanup is entirely your call, done by hand.', 'presshangar-site-checkup' ),
			),
			array(
				'done'  => $has_frontpage,
				'title' => __( 'Measure your front page (optional)', 'presshangar-site-checkup' ),
				'body'  => __( 'Use the card below to get an approximate load-time reading for your home page. The plugin makes an outbound request only here, and only when you click it.', 'presshangar-site-checkup' ),
			),
		);
		?>
		<div class="card" style="max-width:760px;border-left:4px solid #2271b1;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Getting started', 'presshangar-site-checkup' ); ?></h2>
			<p><?php esc_html_e( 'PressHangar Site Checkup reads your site and reports what it finds in plain language — it never changes anything. New here? These few steps get you going.', 'presshangar-site-checkup' ); ?></p>
			<ol style="list-style:none;margin:0;padding:0;">
				<?php foreach ( $steps as $i => $step ) : ?>
					<li style="display:flex;align-items:flex-start;gap:.6em;margin:.8em 0;">
						<?php if ( $step['done'] ) : ?>
							<span aria-hidden="true" style="flex:0 0 auto;width:1.5em;height:1.5em;border-radius:50%;background:#008a20;color:#fff;text-align:center;line-height:1.5em;font-weight:600;">&#10003;</span>
						<?php else : ?>
							<span aria-hidden="true" style="flex:0 0 auto;width:1.5em;height:1.5em;border-radius:50%;background:#2271b1;color:#fff;text-align:center;line-height:1.5em;font-weight:600;"><?php echo esc_html( number_format_i18n( $i + 1 ) ); ?></span>
						<?php endif; ?>
						<span>
							<strong><?php echo esc_html( $step['title'] ); ?></strong><br />
							<?php echo esc_html( $step['body'] ); ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>
		<?php
	}

	/**
	 * Handle the "No thanks" dismissal of the review request. Nonce- and
	 * capability-checked; sets a single option so the ask never shows again.
	 */
	private static function maybe_dismiss_review() {
		if ( ! isset( $_GET['phcheckup_review_off'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'phcheckup_review_off' ) ) {
			return;
		}
		update_option( self::OPTION_REVIEW_DISMISSED, 1 );
	}

	/**
	 * A gentle, success-gated, dismissible request for a WordPress.org review.
	 * Shows only after the user has had a win (a checkup has run) and only
	 * until dismissed. No incentives are offered (per the .org guidelines).
	 *
	 * @param bool $earned Whether the user has already got value from the plugin.
	 */
	private static function render_review_ask( $earned ) {
		if ( ! $earned || get_option( self::OPTION_REVIEW_DISMISSED ) ) {
			return;
		}
		$review_url  = 'https://wordpress.org/support/plugin/presshangar-site-checkup/reviews/#new-post';
		$dismiss_url = wp_nonce_url( self::page_url() . '&phcheckup_review_off=1', 'phcheckup_review_off' );
		?>
		<div class="card" style="max-width:760px;border-left:4px solid #f6a72a;">
			<p style="margin:.2em 0;">
				<?php esc_html_e( 'Finding this plugin useful? A quick review really helps others discover it — thank you!', 'presshangar-site-checkup' ); ?>
				<a href="<?php echo esc_url( $review_url ); ?>" target="_blank" rel="noopener"><strong><?php esc_html_e( 'Leave a review ★★★★★', 'presshangar-site-checkup' ); ?></strong></a>
				&nbsp;·&nbsp;
				<a href="<?php echo esc_url( $dismiss_url ); ?>" style="color:#787c82;text-decoration:none;"><?php esc_html_e( 'No thanks', 'presshangar-site-checkup' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the settings page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		self::maybe_dismiss_review();

		$settings  = phcheckup_get_settings();
		$last_scan = phcheckup_get_last_scan();
		$has_run   = ! empty( $last_scan['time'] );
		?>
		<div class="wrap drp-wrap">
			<h1><?php esc_html_e( 'PressHangar Site Checkup', 'presshangar-site-checkup' ); ?></h1>
			<p>
				<?php esc_html_e( 'A read-only health checkup for your site, explained in plain language. PressHangar Site Checkup only ever looks — it never deactivates, deletes, or changes anything on its own. Any cleanup is entirely up to you, done by hand.', 'presshangar-site-checkup' ); ?>
			</p>

			<?php self::render_getting_started( $has_run, ! empty( $last_scan['frontpage'] ) ); ?>

			<div class="card" style="max-width:760px;">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="phcheckup_run_checkup" />
					<?php wp_nonce_field( self::NONCE_RUN_CHECKUP ); ?>
					<p>
						<?php submit_button( $has_run ? __( 'Re-run checkup', 'presshangar-site-checkup' ) : __( 'Run checkup', 'presshangar-site-checkup' ), 'primary large', 'submit', false ); ?>
					</p>
				</form>
				<?php if ( $has_run ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: human-readable time since the last checkup, e.g. "12 minutes". */
							esc_html__( 'Diagnosed %s ago.', 'presshangar-site-checkup' ),
							esc_html( human_time_diff( (int) $last_scan['time'], time() ) )
						);
						?>
					</p>
				<?php endif; ?>
			</div>

			<?php if ( $has_run ) : ?>
				<?php self::render_results( $last_scan ); ?>
			<?php else : ?>
				<p><em><?php esc_html_e( 'No checkup has been run yet. Click the button above to get your first health report.', 'presshangar-site-checkup' ); ?></em></p>
			<?php endif; ?>

			<?php self::render_review_ask( $has_run ); ?>

			<?php self::render_frontpage_card( $last_scan ); ?>

			<?php self::render_settings_card( $settings ); ?>
		</div>
		<?php
	}

	/**
	 * Render the overall summary banner + one card per module result.
	 *
	 * @param array $last_scan Result of `phcheckup_get_last_scan()`.
	 */
	private static function render_results( array $last_scan ) {
		$results = isset( $last_scan['results'] ) ? (array) $last_scan['results'] : array();
		$overall = self::build_overall_summary( $results );
		$badge   = self::severity_badge( $overall['severity'] );
		?>
		<div class="card" style="max-width:760px;">
			<h2>
				<span aria-hidden="true"><?php echo esc_html( $badge['icon'] ); ?></span>
				<?php echo esc_html( $overall['text'] ); ?>
			</h2>
		</div>

		<?php foreach ( self::MODULES as $module ) : ?>
			<?php self::render_module_card( $module, isset( $results[ $module ] ) ? $results[ $module ] : null ); ?>
		<?php endforeach; ?>
		<?php
	}

	/**
	 * Render a single module's result card, or an "off" placeholder card
	 * when the module was skipped because it's disabled in settings.
	 *
	 * @param string     $module Module slug.
	 * @param array|null $result Module result, or null if it was skipped/off.
	 */
	private static function render_module_card( $module, $result ) {
		?>
		<div class="card" style="max-width:760px;">
			<h2><?php echo esc_html( self::module_label( $module ) ); ?></h2>
			<?php if ( null === $result ) : ?>
				<p class="description"><?php esc_html_e( 'This check is currently turned off. Enable it below and run the checkup again.', 'presshangar-site-checkup' ); ?></p>
			<?php else : ?>
				<p><?php echo esc_html( isset( $result['summary'] ) ? $result['summary'] : '' ); ?></p>
				<?php if ( empty( $result['items'] ) ) : ?>
					<p><span aria-hidden="true">✅</span> <?php esc_html_e( 'Nothing further to report.', 'presshangar-site-checkup' ); ?></p>
				<?php else : ?>
					<?php foreach ( $result['items'] as $item ) : ?>
						<?php $badge = self::severity_badge( isset( $item['severity'] ) ? $item['severity'] : 'ok' ); ?>
						<div class="drp-finding <?php echo esc_attr( $badge['class'] ); ?>" style="border-top:1px solid #dcdcde;padding:.75em 0;">
							<p>
								<strong><span aria-hidden="true"><?php echo esc_html( $badge['icon'] ); ?></span> <?php echo esc_html( isset( $item['what'] ) ? $item['what'] : '' ); ?></strong>
							</p>
							<?php if ( ! empty( $item['why'] ) ) : ?>
								<p><?php echo esc_html( $item['why'] ); ?></p>
							<?php endif; ?>
							<?php if ( ! empty( $item['suggestion'] ) ) : ?>
								<p class="description"><?php echo esc_html( $item['suggestion'] ); ?></p>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the separate, explicit front-page load-time measurement card.
	 * Deliberately not part of `render_results()` — this measurement never
	 * runs as part of "Run checkup", only from its own button here.
	 *
	 * @param array $last_scan Result of `phcheckup_get_last_scan()`.
	 */
	private static function render_frontpage_card( array $last_scan ) {
		$frontpage = isset( $last_scan['frontpage'] ) ? $last_scan['frontpage'] : null;
		?>
		<div class="card" style="max-width:760px;">
			<h2><?php esc_html_e( 'Front-page load time (optional)', 'presshangar-site-checkup' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'A single, one-time request to your own front page, timed start to finish. This is a rough total, not a per-plugin breakdown, and it only ever runs when you click the button below — never automatically and never as part of "Run checkup".', 'presshangar-site-checkup' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="phcheckup_measure_frontpage" />
				<?php wp_nonce_field( self::NONCE_MEASURE_FRONT ); ?>
				<?php submit_button( __( 'Measure front-page load time', 'presshangar-site-checkup' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php if ( is_array( $frontpage ) && ! empty( $frontpage['result'] ) ) : ?>
				<p style="margin-top:1em;">
					<?php
					printf(
						/* translators: %s: human-readable time since this measurement was taken, e.g. "5 minutes". */
						esc_html__( 'Last measured %s ago:', 'presshangar-site-checkup' ),
						esc_html( human_time_diff( (int) $frontpage['time'], time() ) )
					);
					?>
					<?php echo esc_html( $frontpage['result']['message'] ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the module on/off settings card.
	 *
	 * @param array $settings Current settings (from `phcheckup_get_settings()`).
	 */
	private static function render_settings_card( array $settings ) {
		$modules = isset( $settings['modules'] ) ? (array) $settings['modules'] : array();
		?>
		<div class="card" style="max-width:760px;">
			<h2><?php esc_html_e( 'Checks', 'presshangar-site-checkup' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="phcheckup_save_settings" />
				<?php wp_nonce_field( self::NONCE_SAVE_SETTINGS ); ?>
				<?php foreach ( self::MODULES as $module ) : ?>
					<p>
						<label>
							<input type="checkbox" name="modules[<?php echo esc_attr( $module ); ?>]" value="1" <?php checked( ! empty( $modules[ $module ] ) ); ?> />
							<?php echo esc_html( self::module_label( $module ) ); ?>
						</label>
					</p>
				<?php endforeach; ?>
				<?php submit_button( __( 'Save', 'presshangar-site-checkup' ) ); ?>
			</form>
		</div>
		<?php
	}
}
