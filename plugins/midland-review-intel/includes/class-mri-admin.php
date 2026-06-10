<?php
/**
 * Admin UI: competitor list, fetch controls, and the intelligence dashboard.
 *
 * Lives under the Midland Local SEO menu; pushes keywords into Midland Smart
 * SEO's cluster tool and creates draft pages via its programmatic generator.
 *
 * @package Midland_Review_Intel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Review Intel admin page.
 */
class MRI_Admin {

	const PAGE_SLUG = 'mri-review-intel';

	/**
	 * Singleton instance.
	 *
	 * @var MRI_Admin|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton.
	 *
	 * @return MRI_Admin
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
		add_action( 'admin_menu', array( $this, 'add_menu' ), 19 );
		add_action( 'admin_post_mri_save_competitors', array( $this, 'handle_save_competitors' ) );
		add_action( 'admin_post_mri_fetch', array( $this, 'handle_fetch' ) );
		add_action( 'admin_post_mri_send_keywords', array( $this, 'handle_send_keywords' ) );
		add_action( 'admin_post_mri_create_page', array( $this, 'handle_create_page' ) );
	}

	/**
	 * Register the submenu under Midland Local SEO.
	 */
	public function add_menu() {
		$parent = class_exists( 'MLS_Plugin' ) && defined( 'MLS_Plugin::MENU_SLUG' ) ? MLS_Plugin::MENU_SLUG : 'options-general.php';
		add_submenu_page(
			$parent,
			__( 'Review Intel', 'midland-review-intel' ),
			__( 'Review Intel', 'midland-review-intel' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Permission + nonce gate for the admin-post handlers.
	 *
	 * @param string $action Nonce action.
	 */
	private function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'midland-review-intel' ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirect back to the dashboard with a message.
	 *
	 * @param string $message Query-arg message key/value.
	 */
	private function back( $message ) {
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'mri_msg' => rawurlencode( $message ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Save the competitor list and review depth.
	 */
	public function handle_save_competitors() {
		$this->guard( 'mri_save_competitors' );

		$raw   = isset( $_POST['mri_competitors'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mri_competitors'] ) ) : '';
		$lines = preg_split( '/\r?\n/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		$list  = array();
		foreach ( $lines as $line ) {
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( '' === $parts[0] ) {
				continue;
			}
			$list[] = array(
				'name'    => $parts[0],
				'query'   => $parts[1] ?? $parts[0],
				'segment' => $parts[2] ?? 'commercial',
			);
		}
		if ( ! empty( $list ) ) {
			MRI_DB::save_competitors( $list );
		}

		$depth = isset( $_POST['mri_depth'] ) ? (int) $_POST['mri_depth'] : 100;
		update_option( MRI_Fetcher::OPT_DEPTH, max( 10, min( 700, $depth ) ), false );

		$this->back( __( 'Target list saved.', 'midland-review-intel' ) );
	}

	/**
	 * Kick off review collection for every company in the list.
	 */
	public function handle_fetch() {
		$this->guard( 'mri_fetch' );

		$result = MRI_Fetcher::fetch_all();
		if ( is_wp_error( $result ) ) {
			$msg = $result->get_error_message();
			if ( MRI_Fetcher::is_plan_error( $msg ) ) {
				$msg = __( 'Your DataForSEO plan does not include the Business Data API (Google Reviews). Enable it in your DataForSEO dashboard, then retry.', 'midland-review-intel' );
			}
			$this->back( $msg );
		}

		$this->back(
			sprintf(
				/* translators: %d: number of queued collection tasks */
				__( '%d review-collection task(s) queued. Results land automatically over the next few minutes — refresh this page.', 'midland-review-intel' ),
				(int) $result['queued']
			)
		);
	}

	/**
	 * Push an opportunity's keywords into Smart SEO's keyword clusters.
	 */
	public function handle_send_keywords() {
		$this->guard( 'mri_send_keywords' );

		$keywords = isset( $_POST['mri_keywords'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mri_keywords'] ) ) : '';
		$keywords = array_filter( array_map( 'trim', preg_split( '/\r?\n|,/', $keywords ) ) );
		if ( empty( $keywords ) ) {
			$this->back( __( 'No keywords to send.', 'midland-review-intel' ) );
		}

		if ( ! class_exists( 'RSSEO_Pro_Clusters' ) ) {
			$this->back( __( 'Midland Smart SEO is not active — activate it to receive keywords.', 'midland-review-intel' ) );
		}

		$existing = (string) get_option( RSSEO_Pro_Clusters::OPT_KEYWORDS, '' );
		$merged   = array_values( array_unique( array_filter( array_merge( preg_split( '/\r?\n/', $existing, -1, PREG_SPLIT_NO_EMPTY ), $keywords ) ) ) );
		update_option( RSSEO_Pro_Clusters::OPT_KEYWORDS, implode( "\n", $merged ), false );
		update_option( RSSEO_Pro_Clusters::OPT_RESULTS, RSSEO_Pro_Clusters::get_instance()->cluster( $merged ), false );

		$this->back(
			sprintf(
				/* translators: %d: number of keywords */
				__( '%d keyword(s) sent to Smart SEO clusters and re-clustered.', 'midland-review-intel' ),
				count( $keywords )
			)
		);
	}

	/**
	 * Create a draft page for an opportunity via Smart SEO's programmatic
	 * generator (Elementor template + schema included).
	 */
	public function handle_create_page() {
		$this->guard( 'mri_create_page' );

		$title = isset( $_POST['mri_page_title'] ) ? sanitize_text_field( wp_unslash( $_POST['mri_page_title'] ) ) : '';
		if ( '' === $title ) {
			$this->back( __( 'No page title given.', 'midland-review-intel' ) );
		}

		if ( ! class_exists( 'RSSEO_Pro_Programmatic' ) ) {
			$this->back( __( 'Midland Smart SEO is not active — activate it to generate pages.', 'midland-review-intel' ) );
		}

		$post_id = RSSEO_Pro_Programmatic::generate_service_page( $title, array( 'status' => 'draft', 'ping' => false ) );
		if ( is_wp_error( $post_id ) ) {
			$this->back( $post_id->get_error_message() );
		}

		$this->back(
			sprintf(
				/* translators: %s: edit link */
				__( 'Draft page created — edit it at %s', 'midland-review-intel' ),
				admin_url( 'post.php?post=' . (int) $post_id . '&action=edit' )
			)
		);
	}

	/**
	 * Render the dashboard.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'midland-review-intel' ) );
		}

		$configured  = class_exists( 'MLS_DataForSEO' ) && MLS_DataForSEO::is_configured();
		$competitors = MRI_DB::get_competitors();
		$depth       = (int) get_option( MRI_Fetcher::OPT_DEPTH, 100 );
		$pending     = MRI_Fetcher::pending_count();
		$log         = get_option( MRI_Fetcher::OPT_ERRORS, array() );
		$analysis    = MRI_Analyzer::analyze();
		$has_data    = ! empty( $analysis['summary'] );

		echo '<div class="wrap"><h1>' . esc_html__( 'Review Intel — Competitor Voice of Customer', 'midland-review-intel' ) . '</h1>';

		if ( isset( $_GET['mri_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['mri_msg'] ) ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( ! $configured ) {
			echo '<div class="notice notice-warning"><p>' .
				wp_kses_post(
					sprintf(
						/* translators: %s: settings link */
						__( 'Connect DataForSEO first in <a href="%s">Midland Local SEO</a> — Review Intel reuses that key.', 'midland-review-intel' ),
						esc_url( admin_url( 'admin.php?page=' . ( class_exists( 'MLS_Plugin' ) ? MLS_Plugin::MENU_SLUG : '' ) ) )
					)
				) . '</p></div>';
		}

		// ── Target list + fetch ───────────────────────────────────────────────
		echo '<h2>' . esc_html__( '1. Targets', 'midland-review-intel' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mri_save_competitors' );
		echo '<input type="hidden" name="action" value="mri_save_competitors" />';
		echo '<p class="description">' . esc_html__( 'One per line: Name | Google Maps query | segment (own / commercial / residential).', 'midland-review-intel' ) . '</p>';
		echo '<textarea name="mri_competitors" rows="10" class="large-text code">';
		foreach ( $competitors as $c ) {
			echo esc_textarea( $c['name'] . ' | ' . $c['query'] . ' | ' . $c['segment'] ) . "\n";
		}
		echo '</textarea>';
		echo '<p><label>' . esc_html__( 'Reviews per company:', 'midland-review-intel' ) . ' <input type="number" name="mri_depth" value="' . esc_attr( $depth ) . '" min="10" max="700" /></label> ';
		submit_button( __( 'Save Targets', 'midland-review-intel' ), 'secondary', 'submit', false );
		echo '</p></form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:12px 0;">';
		wp_nonce_field( 'mri_fetch' );
		echo '<input type="hidden" name="action" value="mri_fetch" />';
		submit_button( __( 'Fetch Competitor Reviews', 'midland-review-intel' ), 'primary', 'submit', false, $configured ? array() : array( 'disabled' => 'disabled' ) );
		if ( $pending > 0 ) {
			echo ' <em>' . esc_html( sprintf( /* translators: %d: pending tasks */ __( '%d collection task(s) still running — refresh in a minute.', 'midland-review-intel' ), $pending ) ) . '</em>';
		}
		echo '</form>';

		if ( ! empty( $log ) && is_array( $log ) ) {
			echo '<details><summary>' . esc_html__( 'Last run log', 'midland-review-intel' ) . '</summary><ul style="margin-left:1.5em;">';
			foreach ( array_slice( $log, -20 ) as $line ) {
				echo '<li>' . esc_html( $line ) . '</li>';
			}
			echo '</ul></details>';
		}

		if ( ! $has_data ) {
			echo '<p><em>' . esc_html__( 'No reviews collected yet. Fetch first — the dashboard below fills in automatically.', 'midland-review-intel' ) . '</em></p></div>';
			return;
		}

		// ── Summary ───────────────────────────────────────────────────────────
		echo '<h2>' . esc_html__( '2. Market Summary', 'midland-review-intel' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Company', 'midland-review-intel' ) . '</th><th>' . esc_html__( 'Segment', 'midland-review-intel' ) . '</th><th>' . esc_html__( 'Reviews', 'midland-review-intel' ) . '</th><th>' . esc_html__( 'Avg ★', 'midland-review-intel' ) . '</th><th>' . esc_html__( 'Negative (≤3★)', 'midland-review-intel' ) . '</th></tr></thead><tbody>';
		foreach ( $analysis['summary'] as $row ) {
			echo '<tr><td>' . esc_html( $row['company'] ) . '</td><td>' . esc_html( $row['segment'] ) . '</td><td>' . esc_html( $row['reviews'] ) . '</td><td>' . esc_html( $row['avg_rating'] ) . '</td><td>' . esc_html( $row['negative'] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		// ── Language bank ─────────────────────────────────────────────────────
		echo '<h2>' . esc_html__( '3. Language Bank — the market\'s own words', 'midland-review-intel' ) . '</h2>';
		echo '<div style="display:flex;gap:24px;flex-wrap:wrap;">';
		foreach ( array( 'positive' => __( 'What they love (4–5★ phrases)', 'midland-review-intel' ), 'negative' => __( 'What they hate (1–3★ phrases)', 'midland-review-intel' ) ) as $key => $label ) {
			echo '<div style="flex:1;min-width:320px;"><h3>' . esc_html( $label ) . '</h3><table class="widefat striped"><tbody>';
			foreach ( $analysis['language_bank'][ $key ] as $phrase => $count ) {
				echo '<tr><td>&ldquo;' . esc_html( $phrase ) . '&rdquo;</td><td style="width:60px;">' . esc_html( $count ) . '&times;</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '</div>';

		// ── Discontent map ────────────────────────────────────────────────────
		echo '<h2>' . esc_html__( '4. Discontent Map — negative mentions by theme', 'midland-review-intel' ) . '</h2>';
		$themes = array_keys( MRI_Analyzer::THEME_LABELS );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Company', 'midland-review-intel' ) . '</th>';
		foreach ( $themes as $theme ) {
			echo '<th>' . esc_html( MRI_Analyzer::THEME_LABELS[ $theme ] ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $analysis['discontent_map'] as $company => $company_themes ) {
			echo '<tr><td>' . esc_html( $company ) . '</td>';
			foreach ( $themes as $theme ) {
				$neg = (int) ( $company_themes[ $theme ][1] ?? 0 );
				$bg  = $neg >= 5 ? '#fbeaea' : ( $neg > 0 ? '#fdf6e3' : 'transparent' );
				echo '<td style="background:' . esc_attr( $bg ) . ';">' . esc_html( $neg > 0 ? $neg : '—' ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';

		// ── Opportunities ─────────────────────────────────────────────────────
		echo '<h2>' . esc_html__( '5. Opportunities — competitor pain → Midland pages', 'midland-review-intel' ) . '</h2>';
		foreach ( $analysis['opportunities'] as $opp ) {
			echo '<div class="postbox" style="padding:12px 16px;margin-bottom:12px;">';
			echo '<h3 style="margin-top:0;">' . esc_html( $opp['label'] ) . ' — ' . esc_html( sprintf( /* translators: %d: count */ __( '%d negative mentions across competitors', 'midland-review-intel' ), $opp['negatives'] ) ) . '</h3>';
			echo '<p>' . esc_html( $opp['pitch'] ) . '</p>';

			if ( ! empty( $opp['worst'] ) ) {
				$worst = array();
				foreach ( $opp['worst'] as $name => $count ) {
					$worst[] = $name . ' (' . $count . ')';
				}
				echo '<p><strong>' . esc_html__( 'Most exposed:', 'midland-review-intel' ) . '</strong> ' . esc_html( implode( ', ', $worst ) ) . '</p>';
			}
			foreach ( array_slice( $opp['quotes'], 0, 3 ) as $quote ) {
				echo '<blockquote style="border-left:3px solid #d63638;margin:4px 0;padding-left:10px;color:#50575e;">&ldquo;' . esc_html( $quote['quote'] ) . '&rdquo; <em>— ' . esc_html( $quote['company'] ) . '</em></blockquote>';
			}

			echo '<p><strong>' . esc_html__( 'Suggested page:', 'midland-review-intel' ) . '</strong> ' . esc_html( $opp['page'] ) . '<br />';
			echo '<strong>' . esc_html__( 'Keywords:', 'midland-review-intel' ) . '</strong> ' . esc_html( implode( ', ', $opp['keywords'] ) ) . '</p>';

			echo '<div style="display:flex;gap:8px;">';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'mri_send_keywords' );
			echo '<input type="hidden" name="action" value="mri_send_keywords" />';
			echo '<input type="hidden" name="mri_keywords" value="' . esc_attr( implode( "\n", $opp['keywords'] ) ) . '" />';
			submit_button( __( 'Send Keywords to Smart SEO', 'midland-review-intel' ), 'secondary', 'submit', false );
			echo '</form>';

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'mri_create_page' );
			echo '<input type="hidden" name="action" value="mri_create_page" />';
			echo '<input type="hidden" name="mri_page_title" value="' . esc_attr( $opp['page'] ) . '" />';
			submit_button( __( 'Create Draft Page', 'midland-review-intel' ), 'secondary', 'submit', false );
			echo '</form>';
			echo '</div></div>';
		}

		echo '</div>';
	}
}
