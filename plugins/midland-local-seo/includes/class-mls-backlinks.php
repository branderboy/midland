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

	const OPTION      = 'mls_backlink_targets';
	const SEEDED_FLAG = 'mls_backlinks_seeded';

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
		return array(
			'category'  => isset( $row['category'] ) ? sanitize_text_field( $row['category'] ) : '',
			'org'       => isset( $row['org'] ) ? sanitize_text_field( $row['org'] ) : '',
			'url'       => isset( $row['url'] ) ? esc_url_raw( $row['url'] ) : '',
			'how_to'    => isset( $row['how_to'] ) ? sanitize_text_field( $row['how_to'] ) : '',
			'paid_free' => isset( $row['paid_free'] ) ? sanitize_text_field( $row['paid_free'] ) : '',
			'priority'  => isset( $row['priority'] ) ? sanitize_text_field( $row['priority'] ) : '',
			'notes'     => isset( $row['notes'] ) ? sanitize_text_field( $row['notes'] ) : '',
			'status'    => $status,
		);
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
	 * Priority sort weight — lower sorts first. "High authority / Low effort"
	 * and similar high-leverage tags rank ahead of generic ones.
	 *
	 * @param string $priority Priority label.
	 * @return int
	 */
	private static function priority_weight( $priority ) {
		$p = strtolower( $priority );
		if ( false !== strpos( $p, 'high authority' ) && false !== strpos( $p, 'low effort' ) ) {
			return 0;
		}
		if ( false !== strpos( $p, 'high authority' ) ) {
			return 1;
		}
		if ( false !== strpos( $p, 'high trust' ) || false !== strpos( $p, 'high roi' ) ) {
			return 2;
		}
		if ( false !== strpos( $p, 'high' ) ) {
			return 3;
		}
		if ( false !== strpos( $p, 'medium' ) ) {
			return 5;
		}
		return 4;
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
	 * Render the admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'midland-local-seo' ) );
		}

		$rows       = self::get_targets();
		$target     = preg_replace( '#^https?://#', '', rtrim( home_url(), '/' ) );
		$configured = class_exists( 'MLS_DataForSEO' ) && MLS_DataForSEO::is_configured();

		$summary     = null;
		$ref_domains = array();
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
				}
			}
		}

		// Cross-reference + group by category, sorting each group by priority.
		$groups = array();
		foreach ( $rows as $row ) {
			$row['domain']  = self::domain_from_url( $row['url'] );
			$row['linking'] = isset( $ref_domains[ $row['domain'] ] );
			$groups[ $row['category'] ][] = $row;
		}
		foreach ( $groups as &$group ) {
			usort(
				$group,
				static function ( $a, $b ) {
					$wa = self::priority_weight( $a['priority'] );
					$wb = self::priority_weight( $b['priority'] );
					if ( $wa === $wb ) {
						return strcasecmp( $a['org'], $b['org'] );
					}
					return ( $wa < $wb ) ? -1 : 1;
				}
			);
		}
		unset( $group );

		$statuses    = self::statuses();
		$refresh_url = wp_nonce_url( admin_url( 'admin.php?page=mls-backlinks&mls_backlinks_refresh=1' ), 'mls_backlinks_refresh' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Local Backlinks', 'midland-local-seo' ); ?></h1>
			<p class="description"><?php esc_html_e( 'A curated list of local link prospects — grouped by category, highest-leverage first. Track outreach status and see which already link to you (live, via DataForSEO).', 'midland-local-seo' ); ?></p>

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

			<form method="post">
				<?php wp_nonce_field( 'mls_save_backlinks', '_mls_backlinks_nonce' ); ?>
				<?php foreach ( $groups as $category => $group ) : ?>
					<h2><?php echo esc_html( $category ); ?></h2>
					<table class="widefat striped">
						<thead><tr>
							<th><?php esc_html_e( 'Organization / Event', 'midland-local-seo' ); ?></th>
							<th><?php esc_html_e( 'Priority', 'midland-local-seo' ); ?></th>
							<th><?php esc_html_e( 'Paid / Free', 'midland-local-seo' ); ?></th>
							<th><?php esc_html_e( 'How to Get Listed', 'midland-local-seo' ); ?></th>
							<th><?php esc_html_e( 'Link Status', 'midland-local-seo' ); ?></th>
							<th><?php esc_html_e( 'Status', 'midland-local-seo' ); ?></th>
						</tr></thead>
						<tbody>
							<?php foreach ( $group as $row ) : ?>
								<?php $key = md5( $row['url'] . '|' . $row['org'] ); ?>
								<tr>
									<td>
										<?php if ( $row['url'] ) : ?>
											<a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener noreferrer"><strong><?php echo esc_html( $row['org'] ); ?></strong></a>
										<?php else : ?>
											<strong><?php echo esc_html( $row['org'] ); ?></strong>
										<?php endif; ?>
										<?php if ( $row['notes'] ) : ?>
											<br><span class="description"><?php echo esc_html( $row['notes'] ); ?></span>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( $row['priority'] ); ?></td>
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
				<?php endforeach; ?>

				<?php if ( empty( $groups ) ) : ?>
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
