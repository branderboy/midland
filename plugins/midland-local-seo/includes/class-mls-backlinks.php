<?php
/**
 * Local Backlinks (Pillar 4).
 *
 * Tracks backlink targets (rows: domain | type | notes) and cross-references
 * them against the live referring-domains profile pulled from DataForSEO,
 * marking which targets already link to you vs. which are still needed. Shows a
 * progress score and DataForSEO summary stats.
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

	const OPTION = 'mls_backlink_targets';

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
		add_action( 'admin_init', array( $this, 'handle_save' ) );
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
	 * Allowed target types.
	 *
	 * @return array
	 */
	private static function types() {
		return array( 'target', 'outreach', 'live', 'rejected' );
	}

	/**
	 * Persist the raw textarea of targets.
	 */
	public function handle_save() {
		if ( ! isset( $_POST['mls_save_backlinks'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$nonce = isset( $_POST['_mls_backlinks_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_mls_backlinks_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mls_save_backlinks' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'midland-local-seo' ) );
		}

		// Multi-line input — sanitize_textarea_field preserves newlines.
		$raw = isset( $_POST['mls_backlink_targets'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mls_backlink_targets'] ) ) : '';
		update_option( self::OPTION, $raw );
		wp_safe_redirect( admin_url( 'admin.php?page=mls-backlinks&saved=1' ) );
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
	 * Parse the textarea into structured rows.
	 *
	 * Format per line: "domain | type | notes".
	 *
	 * @return array List of { domain, type, notes }.
	 */
	public static function parse_targets() {
		$raw = get_option( self::OPTION, '' );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array();
		}
		$types = self::types();
		$rows  = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts  = array_map( 'trim', explode( '|', $line ) );
			$domain = strtolower( preg_replace( '#^https?://#', '', isset( $parts[0] ) ? $parts[0] : '' ) );
			$domain = preg_replace( '#^www\.#', '', $domain );
			$domain = preg_replace( '#/.*$#', '', $domain );
			if ( '' === $domain ) {
				continue;
			}
			$type = isset( $parts[1] ) ? strtolower( $parts[1] ) : 'target';
			if ( ! in_array( $type, $types, true ) ) {
				$type = 'target';
			}
			$rows[] = array(
				'domain' => $domain,
				'type'   => $type,
				'notes'  => isset( $parts[2] ) ? $parts[2] : '',
			);
		}
		return $rows;
	}

	/**
	 * Progress score: live / total targets.
	 *
	 * @param array $rows Parsed rows, optionally already cross-referenced.
	 * @return array { score:int, live:int, total:int }
	 */
	public static function score( $rows ) {
		$total = count( $rows );
		$live  = 0;
		foreach ( $rows as $r ) {
			if ( ! empty( $r['linking'] ) || ( isset( $r['type'] ) && 'live' === $r['type'] ) ) {
				++$live;
			}
		}
		return array(
			'score' => $total > 0 ? (int) round( ( $live / $total ) * 100 ) : 0,
			'live'  => $live,
			'total' => $total,
		);
	}

	/**
	 * Render the admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'midland-local-seo' ) );
		}
		$raw  = get_option( self::OPTION, '' );
		$rows = self::parse_targets();

		$target     = preg_replace( '#^https?://#', '', rtrim( home_url(), '/' ) );
		$configured = class_exists( 'MLS_DataForSEO' ) && MLS_DataForSEO::is_configured();

		$summary     = null;
		$referring   = array();
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

		// Cross-reference each target against the live referring set.
		foreach ( $rows as &$r ) {
			$r['linking'] = isset( $ref_domains[ $r['domain'] ] );
		}
		unset( $r );

		$score       = self::score( $rows );
		$refresh_url = wp_nonce_url( admin_url( 'admin.php?page=mls-backlinks&mls_backlinks_refresh=1' ), 'mls_backlinks_refresh' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Local Backlinks', 'midland-local-seo' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Track the local link targets you are pursuing and cross-reference them with the domains already linking to you (live, via DataForSEO).', 'midland-local-seo' ); ?></p>

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Backlink targets saved.', 'midland-local-seo' ); ?></p></div>
			<?php endif; ?>
			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php if ( isset( $_GET['refreshed'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Refreshed from DataForSEO.', 'midland-local-seo' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $configured ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'DataForSEO credentials not configured. You can still track targets below; live cross-referencing and summary stats appear once DataForSEO is connected on the dashboard.', 'midland-local-seo' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Link Acquisition Progress', 'midland-local-seo' ); ?></h2>
			<div style="max-width:520px;">
				<div style="background:#e2e4e7;border-radius:6px;overflow:hidden;height:26px;">
					<div style="width:<?php echo esc_attr( $score['score'] ); ?>%;background:#46b450;height:26px;line-height:26px;color:#fff;text-align:center;font-weight:700;"><?php echo esc_html( $score['score'] . '%' ); ?></div>
				</div>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: live count, 2: total count */
							__( '%1$d of %2$d target domains are already linking to you.', 'midland-local-seo' ),
							$score['live'],
							$score['total']
						)
					);
					?>
				</p>
			</div>

			<?php if ( is_array( $summary ) ) : ?>
				<h2><?php esc_html_e( 'Backlink Profile (live)', 'midland-local-seo' ); ?></h2>
				<table class="widefat striped" style="max-width:760px;">
					<tbody>
						<tr><th><?php esc_html_e( 'Domain Rank', 'midland-local-seo' ); ?></th><td><?php echo esc_html( (int) $summary['rank'] ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Total Backlinks', 'midland-local-seo' ); ?></th><td><?php echo esc_html( number_format_i18n( $summary['backlinks'] ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Referring Domains', 'midland-local-seo' ); ?></th><td><?php echo esc_html( number_format_i18n( $summary['referring_domains'] ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Broken Backlinks', 'midland-local-seo' ); ?></th><td><?php echo esc_html( number_format_i18n( $summary['broken_backlinks'] ) ); ?></td></tr>
					</tbody>
				</table>
			<?php endif; ?>
			<?php if ( $configured ) : ?>
				<p><a class="button" href="<?php echo esc_url( $refresh_url ); ?>"><?php esc_html_e( 'Refresh from DataForSEO', 'midland-local-seo' ); ?></a></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Target List', 'midland-local-seo' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'mls_save_backlinks', '_mls_backlinks_nonce' ); ?>
				<p class="description"><?php esc_html_e( 'One per line: domain | type | notes. Type = target, outreach, live, or rejected.', 'midland-local-seo' ); ?></p>
				<textarea name="mls_backlink_targets" rows="10" class="large-text code" placeholder="bethesdamagazine.com | outreach | sponsor local feature&#10;dcchamber.org | target | join chamber"><?php echo esc_textarea( is_string( $raw ) ? $raw : '' ); ?></textarea>
				<p class="submit"><button type="submit" name="mls_save_backlinks" value="1" class="button button-primary"><?php esc_html_e( 'Save Targets', 'midland-local-seo' ); ?></button></p>
			</form>

			<?php if ( ! empty( $rows ) ) : ?>
				<h2><?php esc_html_e( 'Targets vs. Live Links', 'midland-local-seo' ); ?></h2>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Domain', 'midland-local-seo' ); ?></th>
						<th><?php esc_html_e( 'Type', 'midland-local-seo' ); ?></th>
						<th><?php esc_html_e( 'Status', 'midland-local-seo' ); ?></th>
						<th><?php esc_html_e( 'Notes', 'midland-local-seo' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $rows as $r ) : ?>
							<tr>
								<td><?php echo esc_html( $r['domain'] ); ?></td>
								<td><?php echo esc_html( $r['type'] ); ?></td>
								<td>
									<?php if ( $r['linking'] ) : ?>
										<span style="color:#fff;background:#1e7e34;padding:2px 8px;border-radius:3px;font-size:12px;"><?php esc_html_e( 'Already linking', 'midland-local-seo' ); ?></span>
									<?php elseif ( $configured ) : ?>
										<span style="color:#fff;background:#b32d2e;padding:2px 8px;border-radius:3px;font-size:12px;"><?php esc_html_e( 'Needed', 'midland-local-seo' ); ?></span>
									<?php else : ?>
										<span class="description">&mdash;</span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $r['notes'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}

MLS_Backlinks::get_instance();
