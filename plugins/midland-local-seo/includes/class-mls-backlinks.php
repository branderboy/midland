<?php
/**
 * Local Backlinks (Pillar 4).
 *
 * Tracks a curated list of local backlink prospects (seeded from the bundled
 * baseline in data-local-backlinks.php) and cross-references them against the
 * live referring-domains profile pulled from DataForSEO, flagging which targets
 * already link to you. Targets are grouped by category and sorted so the
 * highest-leverage opportunities (high authority / low effort) appear first.
 *
 * @package Midland_Local_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backlinks module.
 */
class MLS_Backlinks {

	const OPTION          = 'mls_backlink_targets';
	const SEEDED_FLAG     = 'mls_backlinks_seeded';
	const COMPETITORS_OPT = 'mls_backlink_competitors';

	/**
	 * ROI-weighted Priority Score weights. Multiply each 1-5 sub-score by its
	 * weight; the five products sum to a 0-100 score (5 * (5+4+6+3+2) = 100).
	 * Tunable via the 'mls_backlink_weights' filter.
	 *
	 * @var array
	 */
	const WEIGHTS = array(
		'authority' => 5,
		'relevance' => 4,
		'roi'       => 6,
		'ease'      => 3,
		'free'      => 2,
	);

	/** Default sub-score used when a row supplies none (mid value). */
	const DEFAULT_SUBSCORE = 3;

	/**
	 * Singleton instance.
	 *
	 * @var MLS_Backlinks|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return MLS_Backlinks
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Bind hooks.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 17 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_seed' ), 2 );
		add_action( 'admin_init', array( $this, 'handle_save' ) );
		add_action( 'admin_init', array( $this, 'handle_reset' ) );
		add_action( 'admin_init', array( $this, 'handle_refresh' ) );
		add_action( 'admin_init', array( $this, 'handle_discover' ) );
	}

	/**
	 * Register the submenu.
	 */
	public function add_menu() {
		add_submenu_page(
			MLS_Plugin::MENU_SLUG,
			__( 'Backlinks', 'midland-local-seo' ),
			__( 'Backlinks', 'midland-local-seo' ),
			'manage_options',
			'mls-backlinks',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Allowed prospect statuses.
	 *
	 * @return array status_key => label.
	 */
	public static function statuses() {
		return array(
			'target'   => __( 'Target', 'midland-local-seo' ),
			'outreach' => __( 'Outreach sent', 'midland-local-seo' ),
			'live'     => __( 'Live link', 'midland-local-seo' ),
			'rejected' => __( 'Rejected', 'midland-local-seo' ),
		);
	}

	/**
	 * Load the bundled baseline prospect list.
	 *
	 * @return array
	 */
	public static function baseline() {
		$file = MLS_PATH . 'includes/data-local-backlinks.php';
		if ( ! file_exists( $file ) ) {
			return array();
		}
		$rows = include $file;
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Normalize one raw baseline/stored row into the canonical shape, defaulting
	 * status to 'target'.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	private static function normalize_row( $row ) {
		$status = isset( $row['status'] ) ? sanitize_key( $row['status'] ) : 'target';
		if ( ! array_key_exists( $status, self::statuses() ) ) {
			$status = 'target';
		}
		$source = isset( $row['source'] ) ? sanitize_key( $row['source'] ) : 'seed';
		if ( 'discovered' !== $source ) {
			$source = 'seed';
		}
		return array(
			'category'  => isset( $row['category'] ) ? sanitize_text_field( $row['category'] ) : '',
			'org'       => isset( $row['org'] ) ? sanitize_text_field( $row['org'] ) : '',
			'url'       => isset( $row['url'] ) ? esc_url_raw( $row['url'] ) : '',
			'how_to'    => isset( $row['how_to'] ) ? sanitize_text_field( $row['how_to'] ) : '',
			'paid_free' => isset( $row['paid_free'] ) ? sanitize_text_field( $row['paid_free'] ) : '',
			'priority'  => isset( $row['priority'] ) ? sanitize_text_field( $row['priority'] ) : '',
			'notes'     => isset( $row['notes'] ) ? sanitize_text_field( $row['notes'] ) : '',
			'status'    => $status,
			'source'    => $source,
			'scores'    => self::normalize_scores( isset( $row['scores'] ) ? $row['scores'] : array() ),
		);
	}

	/**
	 * Clamp the five 1-5 sub-scores into the canonical shape.
	 *
	 * @param mixed $scores Raw scores array.
	 * @return array
	 */
	private static function normalize_scores( $scores ) {
		$out = array();
		foreach ( array( 'authority', 'relevance', 'roi', 'ease', 'free' ) as $k ) {
			$v = is_array( $scores ) && isset( $scores[ $k ] ) ? (int) $scores[ $k ] : self::DEFAULT_SUBSCORE;
			$out[ $k ] = max( 1, min( 5, $v ) );
		}
		return $out;
	}

	/**
	 * The ROI weights, filterable so the client can re-tune them.
	 *
	 * @return array
	 */
	public static function weights() {
		$weights = apply_filters( 'mls_backlink_weights', self::WEIGHTS );
		// Keep only known keys with sane integer weights; fall back per-key.
		$out = array();
		foreach ( self::WEIGHTS as $k => $default ) {
			$out[ $k ] = ( is_array( $weights ) && isset( $weights[ $k ] ) && is_numeric( $weights[ $k ] ) )
				? (int) $weights[ $k ]
				: $default;
		}
		return $out;
	}

	/**
	 * Map a DataForSEO domain rank (0-1000) to a 1-5 authority bucket.
	 *
	 * @param int $rank DFS rank.
	 * @return int 1-5.
	 */
	public static function rank_to_authority( $rank ) {
		$rank = (int) $rank;
		if ( $rank >= 600 ) {
			return 5;
		}
		if ( $rank >= 400 ) {
			return 4;
		}
		if ( $rank >= 250 ) {
			return 3;
		}
		if ( $rank >= 100 ) {
			return 2;
		}
		return 1;
	}

	/**
	 * Compute the 0-100 Priority Score from a row's five sub-scores. When
	 * $authority_override is non-null (live DFS rank bucket), it replaces the
	 * seeded authority sub-score.
	 *
	 * @param array    $scores             Five 1-5 sub-scores.
	 * @param int|null $authority_override Optional 1-5 authority from live data.
	 * @return int 0-100.
	 */
	public static function compute_score( $scores, $authority_override = null ) {
		$scores  = self::normalize_scores( $scores );
		$weights = self::weights();
		if ( null !== $authority_override ) {
			$scores['authority'] = max( 1, min( 5, (int) $authority_override ) );
		}
		$total = 0;
		foreach ( $weights as $k => $w ) {
			$sub    = isset( $scores[ $k ] ) ? (int) $scores[ $k ] : self::DEFAULT_SUBSCORE;
			$total += $sub * $w;
		}
		return (int) max( 0, min( 100, $total ) );
	}

	/**
	 * Tier letter for a Priority Score: A >= 80, B 60-79, C < 60.
	 *
	 * @param int $score 0-100.
	 * @return string A|B|C.
	 */
	public static function tier( $score ) {
		$score = (int) $score;
		if ( $score >= 80 ) {
			return 'A';
		}
		if ( $score >= 60 ) {
			return 'B';
		}
		return 'C';
	}

	/**
	 * First-load seed: populate the targets option from the bundled baseline when
	 * it has never been seeded. Guarded by a flag so operator edits are never
	 * clobbered on subsequent loads.
	 */
	public static function maybe_seed() {
		if ( get_option( self::SEEDED_FLAG ) ) {
			return;
		}
		$stored = get_option( self::OPTION );
		// Only seed when there is no structured data yet (a legacy string option
		// from a prior version counts as "empty" for the structured list).
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			self::seed_from_baseline();
		}
		update_option( self::SEEDED_FLAG, 1 );
	}

	/**
	 * Replace the stored list with the bundled baseline (normalized).
	 */
	public static function seed_from_baseline() {
		$rows = array();
		foreach ( self::baseline() as $row ) {
			$rows[] = self::normalize_row( $row );
		}
		update_option( self::OPTION, $rows );
	}

	/**
	 * Get the structured target rows.
	 *
	 * @return array
	 */
	public static function get_targets() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$rows = array();
		foreach ( $stored as $row ) {
			if ( is_array( $row ) ) {
				$rows[] = self::normalize_row( $row );
			}
		}
		return $rows;
	}

	/**
	 * Score every prospect and sort by Priority Score (desc). Each row gains
	 * 'domain', 'score', 'tier', and (when overridden) 'authority_live'.
	 *
	 * @param array $rank_map Optional map of domain => DFS rank (0-1000) used to
	 *                        override the authority sub-score with live data.
	 * @return array
	 */
	public static function get_scored_targets( $rank_map = array() ) {
		$rows = self::get_targets();
		$out  = array();
		foreach ( $rows as $row ) {
			$domain   = self::domain_from_url( $row['url'] );
			$override = null;
			if ( is_array( $rank_map ) && '' !== $domain && isset( $rank_map[ $domain ] ) ) {
				$override = self::rank_to_authority( $rank_map[ $domain ] );
			}
			$row['domain']         = $domain;
			$row['authority_live'] = $override;
			$row['score']          = self::compute_score( $row['scores'], $override );
			$row['tier']           = self::tier( $row['score'] );
			$out[]                 = $row;
		}
		usort(
			$out,
			static function ( $a, $b ) {
				if ( $a['score'] === $b['score'] ) {
					return strcasecmp( $a['org'], $b['org'] );
				}
				return ( $a['score'] > $b['score'] ) ? -1 : 1;
			}
		);
		return $out;
	}

	/**
	 * Top Tier-A prospects still in 'target' status (for the Insights digest).
	 *
	 * @param int   $limit    Max prospects.
	 * @param array $rank_map Optional DFS rank map for live re-scoring.
	 * @return array
	 */
	public static function top_tier_a_targets( $limit = 5, $rank_map = array() ) {
		$out = array();
		foreach ( self::get_scored_targets( $rank_map ) as $row ) {
			if ( 'A' === $row['tier'] && 'target' === $row['status'] ) {
				$out[] = $row;
			}
			if ( count( $out ) >= (int) $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Extract a bare registrable-ish domain from a URL for cross-referencing.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function domain_from_url( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			$host = preg_replace( '#^https?://#', '', (string) $url );
			$host = preg_replace( '#/.*$#', '', $host );
		}
		$host = strtolower( (string) $host );
		return preg_replace( '#^www\.#', '', $host );
	}

	/**
	 * Persist a status change for a single prospect (matched by URL).
	 */
	public function handle_save() {
		if ( ! isset( $_POST['mls_save_backlink_status'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$nonce = isset( $_POST['_mls_backlinks_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_mls_backlinks_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mls_save_backlinks' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'midland-local-seo' ) );
		}

		$posted = isset( $_POST['status'] ) && is_array( $_POST['status'] ) ? wp_unslash( $_POST['status'] ) : array();
		$valid  = self::statuses();

		$rows = self::get_targets();
		foreach ( $rows as $i => $row ) {
			$key = md5( $row['url'] . '|' . $row['org'] );
			if ( isset( $posted[ $key ] ) ) {
				$new = sanitize_key( $posted[ $key ] );
				if ( array_key_exists( $new, $valid ) ) {
					$rows[ $i ]['status'] = $new;
				}
			}
		}
		update_option( self::OPTION, $rows );

		wp_safe_redirect( admin_url( 'admin.php?page=mls-backlinks&saved=1' ) );
		exit;
	}

	/**
	 * Reset the prospect list back to the bundled baseline.
	 */
	public function handle_reset() {
		if ( ! isset( $_POST['mls_reset_backlinks'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$nonce = isset( $_POST['_mls_backlinks_reset_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_mls_backlinks_reset_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mls_reset_backlinks' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'midland-local-seo' ) );
		}
		self::seed_from_baseline();
		update_option( self::SEEDED_FLAG, 1 );
		wp_safe_redirect( admin_url( 'admin.php?page=mls-backlinks&reset=1' ) );
		exit;
	}

	/**
	 * Clear cached DataForSEO data so the next render refetches.
	 */
	public function handle_refresh() {
		if ( ! isset( $_GET['mls_backlinks_refresh'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mls_backlinks_refresh' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'midland-local-seo' ) );
		}
		$target = preg_replace( '#^https?://#', '', rtrim( home_url(), '/' ) );
		delete_transient( 'mls_backlinks_summary_' . md5( $target ) );
		delete_transient( 'mls_referring_' . md5( $target . '|100' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=mls-backlinks&refreshed=1' ) );
		exit;
	}

	/**
	 * Gather competitor domains from the stored GMB competitor option (if any) and
	 * the operator-editable text list, normalized + de-duped.
	 *
	 * @return array
	 */
	public static function competitor_domains() {
		$domains = array();

		// Operator-editable list (one host per line).
		$raw = (string) get_option( self::COMPETITORS_OPT, '' );
		foreach ( preg_split( '/[\r\n,]+/', $raw ) as $line ) {
			$h = class_exists( 'MLS_DataForSEO' ) ? MLS_DataForSEO::normalize_host( $line ) : strtolower( trim( $line ) );
			if ( '' !== $h ) {
				$domains[ $h ] = true;
			}
		}

		// Any stored competitor data from the GMB Competitors module (forward-safe;
		// the module fetches live today but may persist domains later).
		$stored = get_option( 'mls_gmb_competitors_data', array() );
		if ( is_array( $stored ) ) {
			foreach ( $stored as $row ) {
				$cand = '';
				if ( is_array( $row ) && isset( $row['domain'] ) ) {
					$cand = $row['domain'];
				} elseif ( is_string( $row ) ) {
					$cand = $row;
				}
				$h = class_exists( 'MLS_DataForSEO' ) ? MLS_DataForSEO::normalize_host( $cand ) : strtolower( trim( $cand ) );
				if ( '' !== $h ) {
					$domains[ $h ] = true;
				}
			}
		}

		return array_keys( $domains );
	}

	/**
	 * "Find more with DataForSEO": run link-gap discovery against competitor
	 * domains and MERGE the discovered prospects into the targets option, deduped
	 * against existing seeds/landed domains and tagged source='discovered'.
	 */
	public function handle_discover() {
		if ( ! isset( $_POST['mls_backlinks_discover'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$nonce = isset( $_POST['_mls_backlinks_discover_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_mls_backlinks_discover_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mls_backlinks_discover' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'midland-local-seo' ) );
		}

		// Persist the edited competitor list first (multi-line => textarea sanitize).
		$list = isset( $_POST['mls_backlink_competitors'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mls_backlink_competitors'] ) ) : '';
		update_option( self::COMPETITORS_OPT, $list );

		if ( ! class_exists( 'MLS_DataForSEO' ) || ! MLS_DataForSEO::is_configured() ) {
			wp_safe_redirect( admin_url( 'admin.php?page=mls-backlinks&discover=nokey' ) );
			exit;
		}

		$competitors = self::competitor_domains();
		if ( empty( $competitors ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=mls-backlinks&discover=nodomains' ) );
			exit;
		}

		$gap   = MLS_DataForSEO::discover_link_prospects( $competitors, 50 );
		$added = self::merge_discovered( is_array( $gap ) ? $gap : array() );

		wp_safe_redirect( admin_url( 'admin.php?page=mls-backlinks&discover=1&added=' . (int) $added ) );
		exit;
	}

	/**
	 * Merge discovered gap domains into the targets option. Dedupes against every
	 * existing seed + any already-landed domain (normalized host). Each new row is
	 * tagged source='discovered', status='target', with auto sub-scores (authority
	 * from DFS rank → 1-5; relevance/roi/ease/free default 3).
	 *
	 * @param array $gap Map of domain => { rank, competitors }.
	 * @return int Number of new prospects added.
	 */
	public static function merge_discovered( $gap ) {
		$rows = self::get_targets();

		// Build the existing-domain set for dedupe.
		$existing = array();
		foreach ( $rows as $row ) {
			$d = self::domain_from_url( $row['url'] );
			if ( '' !== $d ) {
				$existing[ $d ] = true;
			}
		}

		$added = 0;
		foreach ( $gap as $domain => $info ) {
			$host = class_exists( 'MLS_DataForSEO' ) ? MLS_DataForSEO::normalize_host( $domain ) : strtolower( trim( $domain ) );
			if ( '' === $host || isset( $existing[ $host ] ) ) {
				continue;
			}
			$rank      = isset( $info['rank'] ) ? (int) $info['rank'] : 0;
			$authority = self::rank_to_authority( $rank );
			$comps     = isset( $info['competitors'] ) && is_array( $info['competitors'] ) ? $info['competitors'] : array();

			$rows[] = self::normalize_row(
				array(
					'category'  => __( 'Discovered (link gap)', 'midland-local-seo' ),
					'org'       => $host,
					'url'       => 'https://' . $host . '/',
					'how_to'    => sprintf(
						/* translators: %s: comma-separated competitor domains */
						__( 'Links to competitor(s): %s. Pitch the same placement.', 'midland-local-seo' ),
						implode( ', ', array_slice( $comps, 0, 5 ) )
					),
					'paid_free' => __( 'Unknown', 'midland-local-seo' ),
					'priority'  => __( 'Link gap', 'midland-local-seo' ),
					'notes'     => sprintf(
						/* translators: %d: DataForSEO domain rank */
						__( 'DataForSEO rank %d.', 'midland-local-seo' ),
						$rank
					),
					'status'    => 'target',
					'source'    => 'discovered',
					'scores'    => array(
						'authority' => $authority,
						'relevance' => 3,
						'roi'       => 3,
						'ease'      => 3,
						'free'      => 3,
					),
				)
			);
			$existing[ $host ] = true;
			++$added;
		}

		if ( $added > 0 ) {
			update_option( self::OPTION, $rows );
		}
		return $added;
	}

	/**
	 * Render the admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'midland-local-seo' ) );
		}

		$target     = preg_replace( '#^https?://#', '', rtrim( home_url(), '/' ) );
		$configured = class_exists( 'MLS_DataForSEO' ) && MLS_DataForSEO::is_configured();

		$summary     = null;
		$ref_domains = array();
		$rank_map    = array();
		if ( $configured ) {
			$summary = MLS_DataForSEO::get_backlinks_summary( $target );
			if ( is_wp_error( $summary ) ) {
				$summary = null;
			}
			$referring = MLS_DataForSEO::get_referring_domains( $target, 100 );
			if ( is_wp_error( $referring ) ) {
				$referring = array();
			}
			foreach ( $referring as $r ) {
				$d = strtolower( preg_replace( '#^www\.#', '', isset( $r['domain'] ) ? $r['domain'] : '' ) );
				if ( '' !== $d ) {
					$ref_domains[ $d ] = true;
					$rank_map[ $d ]    = isset( $r['rank'] ) ? (int) $r['rank'] : 0;
				}
			}
		}

		// Score every prospect (authority overridden by live DFS rank when
		// available) and sort by Priority Score descending.
		$scored = self::get_scored_targets( $rank_map );
		foreach ( $scored as $i => $row ) {
			$scored[ $i ]['linking'] = isset( $ref_domains[ $row['domain'] ] );
		}

		$statuses    = self::statuses();
		$refresh_url = wp_nonce_url( admin_url( 'admin.php?page=mls-backlinks&mls_backlinks_refresh=1' ), 'mls_backlinks_refresh' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Local Backlinks', 'midland-local-seo' ); ?></h1>
			<p class="description"><?php esc_html_e( 'A curated list of local link prospects ranked by a transparent 0-100 Priority Score (authority, relevance, ROI, ease, free), highest first. Track outreach status and see which already link to you (live, via DataForSEO).', 'midland-local-seo' ); ?></p>

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Status updates saved.', 'midland-local-seo' ); ?></p></div>
			<?php endif; ?>
			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php if ( isset( $_GET['reset'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Prospect list reset to the bundled baseline.', 'midland-local-seo' ); ?></p></div>
			<?php endif; ?>
			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php if ( isset( $_GET['refreshed'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Refreshed from DataForSEO.', 'midland-local-seo' ); ?></p></div>
			<?php endif; ?>
			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php if ( isset( $_GET['discover'] ) && '1' === $_GET['discover'] ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$added = isset( $_GET['added'] ) ? absint( $_GET['added'] ) : 0;
					echo esc_html(
						sprintf(
							/* translators: %d: number of discovered prospects */
							_n( 'Discovery complete — %d new prospect added.', 'Discovery complete — %d new prospects added.', $added, 'midland-local-seo' ),
							$added
						)
					);
					?>
				</p></div>
			<?php endif; ?>
			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php if ( isset( $_GET['discover'] ) && 'nokey' === $_GET['discover'] ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Connect DataForSEO on the dashboard to run link-gap discovery. Your curated prospects below still work.', 'midland-local-seo' ); ?></p></div>
			<?php endif; ?>
			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php if ( isset( $_GET['discover'] ) && 'nodomains' === $_GET['discover'] ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Add at least one competitor domain before running discovery.', 'midland-local-seo' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $configured ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'DataForSEO credentials not configured. You can still track prospects below; live cross-referencing and summary stats appear once DataForSEO is connected on the dashboard.', 'midland-local-seo' ); ?></p></div>
			<?php endif; ?>

			<?php if ( is_array( $summary ) ) : ?>
				<h2><?php esc_html_e( 'Backlink Profile (live)', 'midland-local-seo' ); ?></h2>
				<table class="widefat striped" style="max-width:760px;">
					<tbody>
						<tr><th><?php esc_html_e( 'Domain Rank', 'midland-local-seo' ); ?></th><td><?php echo esc_html( (int) $summary['rank'] ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Total Backlinks', 'midland-local-seo' ); ?></th><td><?php echo esc_html( number_format_i18n( $summary['backlinks'] ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Referring Domains', 'midland-local-seo' ); ?></th><td><?php echo esc_html( number_format_i18n( $summary['referring_domains'] ) ); ?></td></tr>
					</tbody>
				</table>
			<?php endif; ?>
			<?php if ( $configured ) : ?>
				<p><a class="button" href="<?php echo esc_url( $refresh_url ); ?>"><?php esc_html_e( 'Refresh from DataForSEO', 'midland-local-seo' ); ?></a></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Find more with DataForSEO (link-gap)', 'midland-local-seo' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Enter competitor domains (one per line). Discovery finds domains that link to a competitor but not to you, and merges them in as "Discovered" prospects.', 'midland-local-seo' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'mls_backlinks_discover', '_mls_backlinks_discover_nonce' ); ?>
				<textarea name="mls_backlink_competitors" rows="3" class="large-text code" placeholder="competitor-one.com&#10;competitor-two.com"><?php echo esc_textarea( (string) get_option( self::COMPETITORS_OPT, '' ) ); ?></textarea>
				<p class="submit">
					<button type="submit" name="mls_backlinks_discover" value="1" class="button button-secondary"><?php esc_html_e( 'Find more with DataForSEO', 'midland-local-seo' ); ?></button>
					<?php if ( ! $configured ) : ?>
						<span class="description" style="margin-left:8px;"><?php esc_html_e( 'DataForSEO not connected — discovery is disabled until you add credentials on the dashboard.', 'midland-local-seo' ); ?></span>
					<?php endif; ?>
				</p>
			</form>

			<p class="description"><?php esc_html_e( 'Tiers: 🟢 A (≥80) · 🟡 B (60-79) · 🔴 C (<60). Click "Sub-scores" to see the five 1-5 factors behind each score.', 'midland-local-seo' ); ?></p>

			<form method="post">
				<?php wp_nonce_field( 'mls_save_backlinks', '_mls_backlinks_nonce' ); ?>
				<table class="widefat striped">
					<thead><tr>
						<th style="width:64px;"><?php esc_html_e( 'Score', 'midland-local-seo' ); ?></th>
						<th style="width:54px;"><?php esc_html_e( 'Tier', 'midland-local-seo' ); ?></th>
						<th><?php esc_html_e( 'Organization / Event', 'midland-local-seo' ); ?></th>
						<th><?php esc_html_e( 'Category', 'midland-local-seo' ); ?></th>
						<th><?php esc_html_e( 'Paid / Free', 'midland-local-seo' ); ?></th>
						<th><?php esc_html_e( 'How to Get Listed', 'midland-local-seo' ); ?></th>
						<th><?php esc_html_e( 'Link Status', 'midland-local-seo' ); ?></th>
						<th><?php esc_html_e( 'Status', 'midland-local-seo' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $scored as $row ) : ?>
							<?php
							$key = md5( $row['url'] . '|' . $row['org'] );
							if ( 'A' === $row['tier'] ) {
								$chip_bg = '#1e7e34';
								$chip    = '🟢 A';
							} elseif ( 'B' === $row['tier'] ) {
								$chip_bg = '#dba617';
								$chip    = '🟡 B';
							} else {
								$chip_bg = '#b32d2e';
								$chip    = '🔴 C';
							}
							?>
							<tr>
								<td><strong style="font-size:15px;"><?php echo esc_html( (int) $row['score'] ); ?></strong></td>
								<td><span style="color:#fff;background:<?php echo esc_attr( $chip_bg ); ?>;padding:2px 8px;border-radius:3px;font-size:12px;white-space:nowrap;"><?php echo esc_html( $chip ); ?></span></td>
								<td>
									<?php if ( $row['url'] ) : ?>
										<a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener noreferrer"><strong><?php echo esc_html( $row['org'] ); ?></strong></a>
									<?php else : ?>
										<strong><?php echo esc_html( $row['org'] ); ?></strong>
									<?php endif; ?>
									<?php if ( 'discovered' === $row['source'] ) : ?>
										<span style="color:#fff;background:#3858e9;padding:1px 6px;border-radius:3px;font-size:11px;margin-left:4px;"><?php esc_html_e( 'Discovered', 'midland-local-seo' ); ?></span>
									<?php endif; ?>
									<?php if ( $row['notes'] ) : ?>
										<br><span class="description"><?php echo esc_html( $row['notes'] ); ?></span>
									<?php endif; ?>
									<details style="margin-top:4px;">
										<summary style="cursor:pointer;font-size:12px;color:#2271b1;"><?php esc_html_e( 'Sub-scores', 'midland-local-seo' ); ?></summary>
										<span class="description" style="font-size:12px;">
											<?php
											echo esc_html(
												sprintf(
													/* translators: 1: authority, 2: relevance, 3: roi, 4: ease, 5: free (each 1-5) */
													__( 'Authority %1$d · Relevance %2$d · ROI %3$d · Ease %4$d · Free %5$d', 'midland-local-seo' ),
													(int) $row['scores']['authority'],
													(int) $row['scores']['relevance'],
													(int) $row['scores']['roi'],
													(int) $row['scores']['ease'],
													(int) $row['scores']['free']
												)
											);
											?>
											<?php if ( null !== $row['authority_live'] ) : ?>
												<br><em><?php esc_html_e( 'Authority overridden by live DataForSEO domain rank.', 'midland-local-seo' ); ?></em>
											<?php endif; ?>
										</span>
									</details>
								</td>
								<td><span class="description"><?php echo esc_html( $row['category'] ); ?></span></td>
								<td><?php echo esc_html( $row['paid_free'] ); ?></td>
								<td><span class="description"><?php echo esc_html( $row['how_to'] ); ?></span></td>
								<td>
									<?php if ( $row['linking'] ) : ?>
										<span style="color:#fff;background:#1e7e34;padding:2px 8px;border-radius:3px;font-size:12px;"><?php esc_html_e( 'Already linking', 'midland-local-seo' ); ?></span>
									<?php elseif ( $configured ) : ?>
										<span style="color:#fff;background:#b32d2e;padding:2px 8px;border-radius:3px;font-size:12px;"><?php esc_html_e( 'Needed', 'midland-local-seo' ); ?></span>
									<?php else : ?>
										<span class="description">&mdash;</span>
									<?php endif; ?>
								</td>
								<td>
									<select name="status[<?php echo esc_attr( $key ); ?>]">
										<?php foreach ( $statuses as $sk => $slabel ) : ?>
											<option value="<?php echo esc_attr( $sk ); ?>" <?php selected( $row['status'], $sk ); ?>><?php echo esc_html( $slabel ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( empty( $scored ) ) : ?>
					<p><?php esc_html_e( 'No prospects yet.', 'midland-local-seo' ); ?></p>
				<?php endif; ?>

				<p class="submit"><button type="submit" name="mls_save_backlink_status" value="1" class="button button-primary"><?php esc_html_e( 'Save Status', 'midland-local-seo' ); ?></button></p>
			</form>

			<hr>
			<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Reset the prospect list to the bundled baseline? Your status edits will be lost.', 'midland-local-seo' ) ); ?>');">
				<?php wp_nonce_field( 'mls_reset_backlinks', '_mls_backlinks_reset_nonce' ); ?>
				<button type="submit" name="mls_reset_backlinks" value="1" class="button button-secondary"><?php esc_html_e( 'Reset to baseline', 'midland-local-seo' ); ?></button>
			</form>
		</div>
		<?php
	}
}

MLS_Backlinks::get_instance();
