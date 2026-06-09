<?php
/**
 * Insights Notifications (digest emailer).
 *
 * Aggregates actionable, ranked insights from the other Local SEO modules
 * (Backlinks, Citations, GMB Optimizer, GMB Competitors, Geo-Grid) into a single
 * HTML digest and emails it to a configurable recipient on a cron schedule.
 *
 * CRITICAL: the production digest is sent ONLY on the 'mls_insights_digest' cron
 * event — never on page load. A separate, nonce + capability guarded "Send test"
 * button delivers the SAME digest to the CURRENT admin's own email so it is
 * unmistakably a manual test and never touches the production recipient.
 *
 * @package Midland_Local_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Insights digest module.
 */
class MLS_Insights {

	const CRON_HOOK         = 'mls_insights_digest';
	const OPT_RECIPIENT     = 'mls_insights_recipient';
	const OPT_CADENCE       = 'mls_insights_cadence';
	const OPT_LAST_RUN      = 'mls_insights_last_run';
	const DEFAULT_RECIPIENT = 'support@midlandfloors.com';
	const FROM_NAME         = 'Midland Local SEO';

	/**
	 * Singleton instance.
	 *
	 * @var MLS_Insights|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return MLS_Insights
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Bind hooks. The cron action is bound here; it is the ONLY place the
	 * production digest is sent.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 19 );
		add_action( 'admin_init', array( $this, 'handle_save' ) );
		add_action( 'admin_init', array( $this, 'handle_test' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_digest' ) );
		add_action( 'init', array( $this, 'maybe_schedule_cron' ) );
	}

	/**
	 * Allowed cadences => cron interval slug (off = unscheduled).
	 *
	 * @return array
	 */
	public static function cadences() {
		return array(
			'weekly' => __( 'Weekly', 'midland-local-seo' ),
			'daily'  => __( 'Daily', 'midland-local-seo' ),
			'off'    => __( 'Off (no automatic emails)', 'midland-local-seo' ),
		);
	}

	/**
	 * Current configured recipient (always a valid email; falls back to default).
	 *
	 * @return string
	 */
	public static function recipient() {
		$email = sanitize_email( (string) get_option( self::OPT_RECIPIENT, self::DEFAULT_RECIPIENT ) );
		return is_email( $email ) ? $email : self::DEFAULT_RECIPIENT;
	}

	/**
	 * Current configured cadence (weekly|daily|off).
	 *
	 * @return string
	 */
	public static function cadence() {
		$c = sanitize_key( (string) get_option( self::OPT_CADENCE, 'weekly' ) );
		return array_key_exists( $c, self::cadences() ) ? $c : 'weekly';
	}

	/**
	 * Activation-time defaults. Called from the plugin activate() hook.
	 */
	public static function set_defaults() {
		add_option( self::OPT_RECIPIENT, self::DEFAULT_RECIPIENT );
		add_option( self::OPT_CADENCE, 'weekly' );
	}

	/**
	 * Clear the digest cron. Called from the plugin deactivate() hook AND below.
	 * wp_unschedule_hook removes the event regardless of args.
	 */
	public static function clear_cron() {
		wp_unschedule_hook( self::CRON_HOOK );
	}

	/**
	 * Schedule (or unschedule) the digest cron to match the stored cadence.
	 */
	public function maybe_schedule_cron() {
		$cadence = self::cadence();
		if ( 'off' === $cadence ) {
			if ( wp_next_scheduled( self::CRON_HOOK ) ) {
				self::clear_cron();
			}
			return;
		}
		$existing = wp_get_schedule( self::CRON_HOOK );
		if ( false === $existing ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, $cadence, self::CRON_HOOK );
		} elseif ( $existing !== $cadence ) {
			// Cadence changed — reschedule cleanly.
			self::clear_cron();
			wp_schedule_event( time() + HOUR_IN_SECONDS, $cadence, self::CRON_HOOK );
		}
	}

	/**
	 * Register the submenu.
	 */
	public function add_menu() {
		add_submenu_page(
			MLS_Plugin::MENU_SLUG,
			__( 'Insights', 'midland-local-seo' ),
			__( 'Insights', 'midland-local-seo' ),
			'manage_options',
			'mls-insights',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Persist recipient + cadence and (re)schedule the cron.
	 */
	public function handle_save() {
		if ( ! isset( $_POST['mls_save_insights'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$nonce = isset( $_POST['_mls_insights_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_mls_insights_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mls_save_insights' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'midland-local-seo' ) );
		}

		$recipient = isset( $_POST['mls_insights_recipient'] ) ? sanitize_email( wp_unslash( $_POST['mls_insights_recipient'] ) ) : '';
		if ( '' === $recipient || ! is_email( $recipient ) ) {
			$recipient = self::DEFAULT_RECIPIENT;
		}
		update_option( self::OPT_RECIPIENT, $recipient );

		$cadence = isset( $_POST['mls_insights_cadence'] ) ? sanitize_key( wp_unslash( $_POST['mls_insights_cadence'] ) ) : 'weekly';
		if ( ! array_key_exists( $cadence, self::cadences() ) ) {
			$cadence = 'weekly';
		}
		update_option( self::OPT_CADENCE, $cadence );

		// Reschedule immediately so the change takes effect without waiting for init.
		self::clear_cron();
		$this->maybe_schedule_cron();

		wp_safe_redirect( admin_url( 'admin.php?page=mls-insights&saved=1' ) );
		exit;
	}

	/**
	 * "Send test now": delivers the SAME digest to the CURRENT admin's own email.
	 * Never targets the production recipient and has no other side effects.
	 */
	public function handle_test() {
		if ( ! isset( $_POST['mls_insights_test'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$nonce = isset( $_POST['_mls_insights_test_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_mls_insights_test_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mls_insights_test' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'midland-local-seo' ) );
		}

		$user = wp_get_current_user();
		$to   = ( $user && is_email( $user->user_email ) ) ? $user->user_email : '';
		$sent = false;
		if ( '' !== $to ) {
			$sent = self::send_digest( $to, true );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=mls-insights&' . ( $sent ? 'tested=1' : 'testfail=1' ) ) );
		exit;
	}

	/**
	 * Cron callback: send the production digest to the configured recipient.
	 * This is the ONLY automatic send path.
	 */
	public function run_digest() {
		$cadence = self::cadence();
		if ( 'off' === $cadence ) {
			return;
		}
		self::send_digest( self::recipient(), false );
		update_option( self::OPT_LAST_RUN, time() );
	}

	/**
	 * Build the HTML digest and send it via wp_mail with a scoped From header.
	 *
	 * @param string $to        Destination email.
	 * @param bool   $is_test   Whether this is a manual test send.
	 * @return bool wp_mail result.
	 */
	public static function send_digest( $to, $is_test = false ) {
		$to = sanitize_email( $to );
		if ( '' === $to || ! is_email( $to ) ) {
			return false;
		}

		$html    = self::build_digest_html( $is_test );
		$subject = $is_test
			? __( '[TEST] Midland Local SEO — Insights digest', 'midland-local-seo' )
			: __( 'Midland Local SEO — Insights digest', 'midland-local-seo' );

		// Scope From overrides to THIS send only, then remove them.
		$from_email = function () {
			return MLS_Insights::DEFAULT_RECIPIENT;
		};
		$from_name = function () {
			return MLS_Insights::FROM_NAME;
		};
		add_filter( 'wp_mail_from', $from_email );
		add_filter( 'wp_mail_from_name', $from_name );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$result  = wp_mail( $to, $subject, $html, $headers );

		remove_filter( 'wp_mail_from', $from_email );
		remove_filter( 'wp_mail_from_name', $from_name );

		return (bool) $result;
	}

	/**
	 * Aggregate ranked, actionable insights from every module. Returns an ordered
	 * list of sections: each { title, lines[ { text, url } ] }. Pure read — no
	 * side effects — so it is safe to call from the settings page for a preview.
	 *
	 * @return array
	 */
	public static function collect_insights() {
		$sections = array();

		// 1) Backlinks — top 5 Tier-A prospects still in 'target' status.
		if ( class_exists( 'MLS_Backlinks' ) ) {
			$lines = array();
			$top   = MLS_Backlinks::top_tier_a_targets( 5 );
			foreach ( $top as $row ) {
				$lines[] = array(
					/* translators: 1: score, 2: org name */
					'text' => sprintf(
						/* translators: 1: priority score, 2: organization */
						__( 'Pursue: %1$s (Tier A, score %2$d) — high-leverage link still untouched.', 'midland-local-seo' ),
						$row['org'],
						(int) $row['score']
					),
					'url'  => admin_url( 'admin.php?page=mls-backlinks' ),
				);
			}
			$sections[] = array(
				'title' => __( 'Backlinks — top opportunities', 'midland-local-seo' ),
				'lines' => $lines,
				'empty' => __( 'No untouched Tier-A prospects — nice work.', 'midland-local-seo' ),
			);
		}

		// 2) Citations — directories not Listed/Verified + NAP mismatches.
		if ( class_exists( 'MLS_Citations' ) ) {
			$lines    = array();
			$registry = MLS_Citations::registry();
			$cites    = MLS_Citations::get_citations();
			$missing  = 0;
			foreach ( $registry as $slug => $info ) {
				$status = isset( $cites[ $slug ]['status'] ) ? $cites[ $slug ]['status'] : '';
				if ( 'listed' !== $status && 'verified' !== $status ) {
					++$missing;
				}
			}
			if ( $missing > 0 ) {
				$lines[] = array(
					/* translators: %d: count of directories */
					'text' => sprintf(
						/* translators: %d: number of directories */
						_n( '%d directory is not yet Listed or Verified.', '%d directories are not yet Listed or Verified.', $missing, 'midland-local-seo' ),
						$missing
					),
					'url'  => admin_url( 'admin.php?page=mls-citations' ),
				);
			}
			$mismatch = self::count_nap_mismatches( $registry, $cites );
			if ( $mismatch > 0 ) {
				$lines[] = array(
					/* translators: %d: count of NAP mismatches */
					'text' => sprintf(
						/* translators: %d: number of NAP mismatches */
						_n( '%d citation has a NAP mismatch against your canonical identity.', '%d citations have NAP mismatches against your canonical identity.', $mismatch, 'midland-local-seo' ),
						$mismatch
					),
					'url'  => admin_url( 'admin.php?page=mls-citations' ),
				);
			}
			$sections[] = array(
				'title' => __( 'Citations', 'midland-local-seo' ),
				'lines' => $lines,
				'empty' => __( 'All directories listed/verified and NAP-consistent.', 'midland-local-seo' ),
			);
		}

		// 3) GMB Optimizer — failing/warn checks (reuse its scorecard logic).
		if ( class_exists( 'MLS_GMB_Optimizer' ) && class_exists( 'MLS_DataForSEO' ) && MLS_DataForSEO::is_configured() ) {
			$lines    = array();
			$identity = class_exists( 'MLS_SameAs' ) ? MLS_SameAs::get_identity() : array();
			$name     = isset( $identity['business_name'] ) && '' !== $identity['business_name'] ? $identity['business_name'] : get_bloginfo( 'name' );
			$lat      = isset( $identity['center_lat'] ) && '' !== $identity['center_lat'] ? (float) $identity['center_lat'] : null;
			$lng      = isset( $identity['center_lng'] ) && '' !== $identity['center_lng'] ? (float) $identity['center_lng'] : null;
			$listing  = MLS_DataForSEO::get_gmb_listing( $name, $lat, $lng );
			if ( ! is_wp_error( $listing ) ) {
				$checks = MLS_GMB_Optimizer::build_scorecard( $listing );
				foreach ( $checks as $c ) {
					if ( isset( $c['state'] ) && ( 'fail' === $c['state'] || 'warn' === $c['state'] ) ) {
						$lines[] = array(
							'text' => sprintf( '%s — %s', $c['label'], $c['fix'] ),
							'url'  => admin_url( 'admin.php?page=mls-gmb-optimizer' ),
						);
					}
				}
				$sections[] = array(
					'title' => __( 'Google Business Profile', 'midland-local-seo' ),
					'lines' => $lines,
					'empty' => __( 'Every GBP check is passing.', 'midland-local-seo' ),
				);
			}
		}

		// 4) GMB Competitors — rivals beating you on rating/reviews (if data present).
		$comp_lines = self::competitor_insights();
		if ( null !== $comp_lines ) {
			$sections[] = array(
				'title' => __( 'Competitors', 'midland-local-seo' ),
				'lines' => $comp_lines,
				'empty' => __( 'No competitor is beating you on rating or reviews.', 'midland-local-seo' ),
			);
		}

		// 5) Geo-Grid — rank drops vs the previous run (needs >= 2 runs).
		$geo_lines = self::geogrid_insights();
		if ( null !== $geo_lines ) {
			$sections[] = array(
				'title' => __( 'Geo-Grid rank movement', 'midland-local-seo' ),
				'lines' => $geo_lines,
				'empty' => __( 'Average rank held or improved vs the previous run.', 'midland-local-seo' ),
			);
		}

		return $sections;
	}

	/**
	 * Count NAP mismatches between stored citations and the canonical identity.
	 *
	 * @param array $registry Directory registry.
	 * @param array $cites    Stored citations.
	 * @return int
	 */
	private static function count_nap_mismatches( $registry, $cites ) {
		if ( ! class_exists( 'MLS_SameAs' ) ) {
			return 0;
		}
		$identity = MLS_SameAs::get_identity();
		$name_n   = isset( $identity['business_name'] ) ? trim( preg_replace( '/\s+/', ' ', strtolower( (string) $identity['business_name'] ) ) ) : '';
		$phone_n  = isset( $identity['business_phone'] ) ? preg_replace( '/\D+/', '', (string) $identity['business_phone'] ) : '';
		if ( '' === $name_n && '' === $phone_n ) {
			return 0;
		}
		$count = 0;
		foreach ( $registry as $slug => $info ) {
			$nap = isset( $cites[ $slug ]['nap'] ) ? (string) $cites[ $slug ]['nap'] : '';
			if ( '' === $nap ) {
				continue;
			}
			$nap_name  = trim( preg_replace( '/\s+/', ' ', strtolower( $nap ) ) );
			$nap_phone = preg_replace( '/\D+/', '', $nap );
			$name_ok   = '' === $name_n || false !== strpos( $nap_name, $name_n );
			$phone_ok  = '' === $phone_n || false !== strpos( $nap_phone, $phone_n );
			if ( ! $name_ok || ! $phone_ok ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Competitor insights — rivals beating you on rating/reviews. Returns null
	 * when no live data is available (so the section is omitted entirely).
	 *
	 * @return array|null
	 */
	private static function competitor_insights() {
		if ( ! class_exists( 'MLS_DataForSEO' ) || ! MLS_DataForSEO::is_configured() ) {
			return null;
		}
		$identity = class_exists( 'MLS_SameAs' ) ? MLS_SameAs::get_identity() : array();
		$name     = isset( $identity['business_name'] ) && '' !== $identity['business_name'] ? $identity['business_name'] : get_bloginfo( 'name' );
		$keyword  = apply_filters( 'mls_competitor_keyword', 'floor cleaning service' );
		$lat      = isset( $identity['center_lat'] ) && '' !== $identity['center_lat'] ? (float) $identity['center_lat'] : 38.9847;
		$lng      = isset( $identity['center_lng'] ) && '' !== $identity['center_lng'] ? (float) $identity['center_lng'] : -77.0947;

		$competitors = MLS_DataForSEO::get_maps_competitors( $keyword, $lat, $lng, 20 );
		if ( is_wp_error( $competitors ) || ! is_array( $competitors ) || empty( $competitors ) ) {
			return null;
		}

		$needle    = strtolower( $name );
		$mine      = null;
		foreach ( $competitors as $c ) {
			if ( '' !== $needle && isset( $c['title'] ) && false !== strpos( strtolower( $c['title'] ), $needle ) ) {
				$mine = $c;
				break;
			}
		}
		$my_rating = ( $mine && null !== $mine['rating'] ) ? (float) $mine['rating'] : 0;
		$my_votes  = $mine ? (int) $mine['rating_votes'] : 0;

		$lines = array();
		foreach ( $competitors as $c ) {
			if ( $mine && isset( $c['title'] ) && $c['title'] === $mine['title'] ) {
				continue;
			}
			$beats = array();
			if ( null !== $c['rating'] && (float) $c['rating'] > $my_rating ) {
				$beats[] = __( 'rating', 'midland-local-seo' );
			}
			if ( (int) $c['rating_votes'] > $my_votes ) {
				$beats[] = __( 'reviews', 'midland-local-seo' );
			}
			if ( ! empty( $beats ) ) {
				$lines[] = array(
					'text' => sprintf(
						/* translators: 1: competitor name, 2: comma-separated metrics */
						__( '%1$s beats you on %2$s.', 'midland-local-seo' ),
						isset( $c['title'] ) ? $c['title'] : __( '(unknown)', 'midland-local-seo' ),
						implode( ', ', $beats )
					),
					'url'  => admin_url( 'admin.php?page=mls-gmb-competitors' ),
				);
			}
			if ( count( $lines ) >= 5 ) {
				break;
			}
		}
		return $lines;
	}

	/**
	 * Geo-Grid insights — average rank drop vs the previous run. Returns null
	 * when fewer than two completed runs exist.
	 *
	 * @return array|null
	 */
	private static function geogrid_insights() {
		global $wpdb;
		$table = $wpdb->prefix . 'mls_geogrid_runs';
		// Only proceed if the table exists (plugin may be freshly activated).
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $exists !== $table ) {
			return null;
		}
		$runs = $wpdb->get_results( "SELECT avg_rank, created_at FROM {$wpdb->prefix}mls_geogrid_runs WHERE avg_rank IS NOT NULL ORDER BY id DESC LIMIT 2" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $runs ) || count( $runs ) < 2 ) {
			return null;
		}
		$latest = (float) $runs[0]->avg_rank;
		$prev   = (float) $runs[1]->avg_rank;
		$lines  = array();
		// Higher rank number = worse, so a drop is latest > prev.
		if ( $latest > $prev ) {
			$lines[] = array(
				'text' => sprintf(
					/* translators: 1: previous avg rank, 2: latest avg rank */
					__( 'Average rank slipped from %1$s to %2$s vs the previous run — investigate.', 'midland-local-seo' ),
					number_format_i18n( $prev, 2 ),
					number_format_i18n( $latest, 2 )
				),
				'url'  => admin_url( 'admin.php?page=mls-geogrid' ),
			);
		}
		return $lines;
	}

	/**
	 * Render the digest as a clean HTML document.
	 *
	 * @param bool $is_test Whether this is a manual test render.
	 * @return string
	 */
	public static function build_digest_html( $is_test = false ) {
		$sections = self::collect_insights();
		$site     = get_bloginfo( 'name' );

		$html  = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:640px;margin:0 auto;color:#1d2327;">';
		$html .= '<h2 style="margin:0 0 4px;">' . esc_html__( 'Local SEO Insights', 'midland-local-seo' ) . '</h2>';
		$html .= '<p style="margin:0 0 16px;color:#555;">' . esc_html( sprintf(
			/* translators: 1: site name, 2: date */
			__( '%1$s — %2$s', 'midland-local-seo' ),
			$site,
			date_i18n( get_option( 'date_format' ) )
		) ) . '</p>';

		if ( $is_test ) {
			$html .= '<p style="background:#fcf3cd;border:1px solid #dba617;padding:8px 12px;border-radius:4px;">' . esc_html__( 'This is a manual TEST send to your own admin email. The scheduled digest goes to the configured recipient.', 'midland-local-seo' ) . '</p>';
		}

		foreach ( $sections as $section ) {
			$html .= '<h3 style="margin:18px 0 6px;border-bottom:1px solid #e2e4e7;padding-bottom:4px;">' . esc_html( $section['title'] ) . '</h3>';
			if ( empty( $section['lines'] ) ) {
				$empty = isset( $section['empty'] ) ? $section['empty'] : __( 'Nothing to action.', 'midland-local-seo' );
				$html .= '<p style="margin:4px 0;color:#1e7e34;">' . esc_html( $empty ) . '</p>';
				continue;
			}
			$html .= '<ul style="margin:6px 0 0;padding-left:20px;">';
			foreach ( $section['lines'] as $line ) {
				$text = isset( $line['text'] ) ? $line['text'] : '';
				$url  = isset( $line['url'] ) ? $line['url'] : '';
				$html .= '<li style="margin:4px 0;">' . esc_html( $text );
				if ( '' !== $url ) {
					$html .= ' <a href="' . esc_url( $url ) . '" style="color:#2271b1;">' . esc_html__( 'Open ›', 'midland-local-seo' ) . '</a>';
				}
				$html .= '</li>';
			}
			$html .= '</ul>';
		}

		$html .= '<p style="margin-top:24px;font-size:12px;color:#888;">' . esc_html__( 'Sent by Midland Local SEO.', 'midland-local-seo' ) . '</p>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'midland-local-seo' ) );
		}
		$recipient = self::recipient();
		$cadence   = self::cadence();
		$cadences  = self::cadences();
		$next      = wp_next_scheduled( self::CRON_HOOK );
		$user      = wp_get_current_user();
		$last_run  = (int) get_option( self::OPT_LAST_RUN, 0 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Insights Notifications', 'midland-local-seo' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Email an actionable digest of the highest-priority Local SEO opportunities, aggregated from every module. Sent automatically on the cadence below; never on page load.', 'midland-local-seo' ); ?></p>

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Insights settings saved.', 'midland-local-seo' ); ?></p></div>
			<?php endif; ?>
			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php if ( isset( $_GET['tested'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( /* translators: %s: admin email */ __( 'Test digest sent to your email (%s).', 'midland-local-seo' ), $user ? $user->user_email : '' ) ); ?></p></div>
			<?php endif; ?>
			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php if ( isset( $_GET['testfail'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Test send failed — check that your admin account has a valid email and that the site can send mail.', 'midland-local-seo' ); ?></p></div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'mls_save_insights', '_mls_insights_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="mls_insights_recipient"><?php esc_html_e( 'Recipient email', 'midland-local-seo' ); ?></label></th>
						<td>
							<input type="email" id="mls_insights_recipient" name="mls_insights_recipient" class="regular-text" value="<?php echo esc_attr( $recipient ); ?>">
							<p class="description"><?php esc_html_e( 'Where the automatic digest is delivered.', 'midland-local-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="mls_insights_cadence"><?php esc_html_e( 'Cadence', 'midland-local-seo' ); ?></label></th>
						<td>
							<select id="mls_insights_cadence" name="mls_insights_cadence">
								<?php foreach ( $cadences as $val => $label ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $cadence, $val ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php
								if ( $next ) {
									echo esc_html( sprintf( /* translators: %s: date/time */ __( 'Next scheduled: %s', 'midland-local-seo' ), date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next ) ) );
								} else {
									esc_html_e( 'No automatic send scheduled.', 'midland-local-seo' );
								}
								if ( $last_run ) {
									echo ' · ' . esc_html( sprintf( /* translators: %s: date/time */ __( 'Last sent: %s', 'midland-local-seo' ), date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_run ) ) );
								}
								?>
							</p>
						</td>
					</tr>
				</table>
				<p class="submit"><button type="submit" name="mls_save_insights" value="1" class="button button-primary"><?php esc_html_e( 'Save Settings', 'midland-local-seo' ); ?></button></p>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Send a test', 'midland-local-seo' ); ?></h2>
			<p class="description"><?php echo esc_html( sprintf( /* translators: %s: admin email */ __( 'Sends the exact same digest to YOUR email (%s) — not the production recipient — so you can preview it safely.', 'midland-local-seo' ), $user ? $user->user_email : '' ) ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'mls_insights_test', '_mls_insights_test_nonce' ); ?>
				<button type="submit" name="mls_insights_test" value="1" class="button button-secondary"><?php esc_html_e( 'Send test now', 'midland-local-seo' ); ?></button>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Preview', 'midland-local-seo' ); ?></h2>
			<div style="background:#fff;border:1px solid #e2e4e7;border-radius:6px;padding:16px;max-width:680px;">
				<?php echo wp_kses_post( self::build_digest_html( false ) ); ?>
			</div>
		</div>
		<?php
	}
}

MLS_Insights::get_instance();
