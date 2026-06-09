<?php
/**
 * Citation Audit (Pillar 1).
 *
 * Tracks NAP citations across a curated registry of local directories. Records
 * status + URL + the NAP exactly as listed, scores citation coverage, and flags
 * Name/Phone inconsistencies against the canonical identity (mls_identity).
 *
 * @package Midland_Local_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Citation audit module.
 */
class MLS_Citations {

	const OPTION = 'mls_citations';

	/**
	 * Singleton instance.
	 *
	 * @var MLS_Citations|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return MLS_Citations
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
		add_action( 'admin_menu', array( $this, 'add_menu' ), 11 );
		add_action( 'admin_init', array( $this, 'handle_save' ) );
	}

	/**
	 * Curated directory registry: slug => label, category, manage URL.
	 *
	 * @return array
	 */
	public static function registry() {
		return array(
			'google_business' => array( 'Google Business Profile', 'Core', 'https://business.google.com/' ),
			'bing_places'     => array( 'Bing Places', 'Core', 'https://www.bingplaces.com/' ),
			'apple_maps'      => array( 'Apple Maps (Business Connect)', 'Core', 'https://businessconnect.apple.com/' ),
			'facebook'        => array( 'Facebook', 'Social', 'https://facebook.com/' ),
			'yelp'            => array( 'Yelp', 'Reviews', 'https://biz.yelp.com/' ),
			'bbb'             => array( 'Better Business Bureau', 'Trust', 'https://www.bbb.org/' ),
			'nextdoor'        => array( 'Nextdoor', 'Local', 'https://business.nextdoor.com/' ),
			'foursquare'      => array( 'Foursquare', 'Data', 'https://foursquare.com/venue/claim' ),
			'data_axle'       => array( 'Data Axle', 'Data', 'https://www.data-axle.com/' ),
			'angi'            => array( 'Angi', 'Home Services', 'https://www.angi.com/' ),
			'homeadvisor'     => array( 'HomeAdvisor', 'Home Services', 'https://www.homeadvisor.com/' ),
			'thumbtack'       => array( 'Thumbtack', 'Home Services', 'https://www.thumbtack.com/' ),
			'houzz'           => array( 'Houzz', 'Home Services', 'https://www.houzz.com/' ),
			'porch'           => array( 'Porch', 'Home Services', 'https://porch.com/' ),
			'yellowpages'     => array( 'YellowPages', 'Directory', 'https://www.yellowpages.com/' ),
			'manta'           => array( 'Manta', 'Directory', 'https://www.manta.com/' ),
			'hotfrog'         => array( 'Hotfrog', 'Directory', 'https://www.hotfrog.com/' ),
			'chamber'         => array( 'Local Chamber of Commerce', 'Trust', '' ),
			'linkedin'        => array( 'LinkedIn Company', 'Social', 'https://www.linkedin.com/company/' ),
			'instagram'       => array( 'Instagram', 'Social', 'https://www.instagram.com/' ),
		);
	}

	/**
	 * Status vocabulary.
	 *
	 * @return array
	 */
	public static function statuses() {
		return array(
			''             => __( 'Not listed', 'midland-local-seo' ),
			'listed'       => __( 'Listed', 'midland-local-seo' ),
			'verified'     => __( 'Verified', 'midland-local-seo' ),
			'inconsistent' => __( 'Inconsistent', 'midland-local-seo' ),
		);
	}

	/**
	 * Register the submenu.
	 */
	public function add_menu() {
		add_submenu_page(
			MLS_Plugin::MENU_SLUG,
			__( 'Citation Audit', 'midland-local-seo' ),
			__( 'Citation Audit', 'midland-local-seo' ),
			'manage_options',
			'mls-citations',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Persist the citation table.
	 */
	public function handle_save() {
		if ( ! isset( $_POST['mls_save_citations'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$nonce = isset( $_POST['_mls_citations_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_mls_citations_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mls_save_citations' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'midland-local-seo' ) );
		}

		$registry = self::registry();
		$statuses = self::statuses();
		// The raw array is unslashed here; every scalar leaf is sanitized
		// individually in the loop below (status/url/nap).
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw   = isset( $_POST['citation'] ) && is_array( $_POST['citation'] ) ? wp_unslash( $_POST['citation'] ) : array();
		$clean = array();

		foreach ( $registry as $slug => $info ) {
			$row    = isset( $raw[ $slug ] ) && is_array( $raw[ $slug ] ) ? $raw[ $slug ] : array();
			$status = isset( $row['status'] ) ? sanitize_text_field( $row['status'] ) : '';
			if ( ! array_key_exists( $status, $statuses ) ) {
				$status = '';
			}
			$url = isset( $row['url'] ) ? esc_url_raw( $row['url'] ) : '';
			// If a listing URL was entered but the status was left blank, mark it
			// Listed automatically — a URL means the listing exists.
			if ( '' !== $url && '' === $status ) {
				$status = 'listed';
			}
			$clean[ $slug ] = array(
				'status' => $status,
				'url'    => $url,
				'nap'    => isset( $row['nap'] ) ? sanitize_text_field( $row['nap'] ) : '',
			);
		}

		update_option( self::OPTION, $clean );
		wp_safe_redirect( admin_url( 'admin.php?page=mls-citations&saved=1' ) );
		exit;
	}

	/**
	 * Stored citations keyed by slug.
	 *
	 * @return array
	 */
	public static function get_citations() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return self::default_citations();
		}
		return $stored;
	}

	/**
	 * Midland Floor Care's known citations, so the audit is populated out of the
	 * box instead of showing an empty table. Operator edits (saved option) win.
	 *
	 * @return array slug => { status, url, nap }
	 */
	public static function default_citations() {
		$nap = 'Midland Floor Care | (240) 532-9097';
		return array(
			'google_business' => array( 'status' => 'verified', 'url' => 'https://www.google.com/maps/place/?q=place_id:ChIJ59SJ6ue7t4kRIVMYpQVYY6Y', 'nap' => $nap ),
			'bing_places'     => array( 'status' => 'listed', 'url' => 'https://www.bing.com/maps?ss=ypid.YNB7AD81AE2B17BE92', 'nap' => $nap ),
			'apple_maps'      => array( 'status' => 'listed', 'url' => 'https://maps.apple.com/place?place-id=IAC53429102E051F4&name=Midland+Floor+Care', 'nap' => $nap ),
			'facebook'        => array( 'status' => 'listed', 'url' => 'https://www.facebook.com/midlandfloorcare/', 'nap' => $nap ),
			'yelp'            => array( 'status' => 'listed', 'url' => 'https://www.yelp.com/biz/midland-floor-care-temple-hills', 'nap' => $nap ),
			'nextdoor'        => array( 'status' => 'listed', 'url' => 'https://nextdoor.com/pages/midland-floor-care', 'nap' => $nap ),
			'angi'            => array( 'status' => 'listed', 'url' => 'https://www.angi.com/companylist/us/md/temple-hills/midland-floor-care-llc-reviews-1.htm', 'nap' => $nap ),
			'homeadvisor'     => array( 'status' => 'listed', 'url' => 'https://www.homeadvisor.com/rated.MidlandFloorCareLLC.88023128.html', 'nap' => $nap ),
			'yellowpages'     => array( 'status' => 'inconsistent', 'url' => 'https://www.yellowpages.com/temple-hills-md/mip/midland-floor-care-502822336', 'nap' => 'Midland Floor Care | (240) 455-6495' ),
			'manta'           => array( 'status' => 'listed', 'url' => 'https://www.manta.com/c/mhkty5k/midland-floor-care', 'nap' => $nap ),
			'chamber'         => array( 'status' => 'listed', 'url' => 'https://pgcocmd.chambermaster.com/list/member/midland-floor-care-llc-2875', 'nap' => 'Midland Floor Care, LLC | (240) 532-9097' ),
			'linkedin'        => array( 'status' => 'listed', 'url' => 'https://www.linkedin.com/company/midland-floor-care-llc/', 'nap' => $nap ),
			'instagram'       => array( 'status' => 'verified', 'url' => 'https://www.instagram.com/midlandfloors/', 'nap' => 'Midland Floors | (240) 532-9097' ),
		);
	}

	/**
	 * Citation score: percentage of directories Listed or Verified.
	 *
	 * @return array { score:int, listed:int, total:int }
	 */
	public static function score() {
		$registry = self::registry();
		$cites    = self::get_citations();
		$total    = count( $registry );
		$listed   = 0;
		foreach ( $registry as $slug => $info ) {
			$status = isset( $cites[ $slug ]['status'] ) ? $cites[ $slug ]['status'] : '';
			$url    = isset( $cites[ $slug ]['url'] ) ? trim( (string) $cites[ $slug ]['url'] ) : '';
			// Count it as covered if marked Listed/Verified OR if a listing URL has
			// been added (you can't have a listing URL without being listed there).
			if ( 'listed' === $status || 'verified' === $status || '' !== $url ) {
				++$listed;
			}
		}
		return array(
			'score'  => $total > 0 ? (int) round( ( $listed / $total ) * 100 ) : 0,
			'listed' => $listed,
			'total'  => $total,
		);
	}

	/**
	 * Normalize a name for comparison: lowercase, collapse whitespace.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private static function norm_name( $name ) {
		return trim( preg_replace( '/\s+/', ' ', strtolower( (string) $name ) ) );
	}

	/**
	 * Normalize a phone for comparison: digits only.
	 *
	 * @param string $phone Phone.
	 * @return string
	 */
	private static function norm_phone( $phone ) {
		return preg_replace( '/\D+/', '', (string) $phone );
	}

	/**
	 * Render the admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'midland-local-seo' ) );
		}
		$registry = self::registry();
		$statuses = self::statuses();
		$cites    = self::get_citations();
		$score    = self::score();

		$identity     = class_exists( 'MLS_SameAs' ) ? MLS_SameAs::get_identity() : array();
		$canon_name   = isset( $identity['business_name'] ) ? $identity['business_name'] : '';
		$canon_phone  = isset( $identity['business_phone'] ) ? $identity['business_phone'] : '';
		$canon_name_n = self::norm_name( $canon_name );
		$canon_ph_n   = self::norm_phone( $canon_phone );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Citation Audit', 'midland-local-seo' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Track your NAP citations across local directories. Consistent Name, Address, and Phone across these sites is a core local ranking signal.', 'midland-local-seo' ); ?></p>

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Citations saved.', 'midland-local-seo' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Citation Score', 'midland-local-seo' ); ?></h2>
			<div style="max-width:520px;">
				<div style="background:#e2e4e7;border-radius:6px;overflow:hidden;height:26px;">
					<div style="width:<?php echo esc_attr( $score['score'] ); ?>%;background:#46b450;height:26px;line-height:26px;color:#fff;text-align:center;font-weight:700;">
						<?php echo esc_html( $score['score'] . '%' ); ?>
					</div>
				</div>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: listed count, 2: total count */
							__( '%1$d of %2$d directories listed or verified.', 'midland-local-seo' ),
							$score['listed'],
							$score['total']
						)
					);
					?>
				</p>
			</div>

			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: business name, 2: phone */
						__( 'Canonical NAP from Identity — Name: %1$s | Phone: %2$s', 'midland-local-seo' ),
						'' !== $canon_name ? $canon_name : '(set in sameAs / Identity)',
						'' !== $canon_phone ? $canon_phone : '(set in sameAs / Identity)'
					)
				);
				?>
			</p>

			<form method="post">
				<?php wp_nonce_field( 'mls_save_citations', '_mls_citations_nonce' ); ?>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Directory', 'midland-local-seo' ); ?></th>
						<th><?php esc_html_e( 'Category', 'midland-local-seo' ); ?></th>
						<th><?php esc_html_e( 'Status', 'midland-local-seo' ); ?></th>
						<th><?php esc_html_e( 'Listing URL', 'midland-local-seo' ); ?></th>
						<th><?php esc_html_e( 'NAP as listed (Name | Phone)', 'midland-local-seo' ); ?></th>
						<th><?php esc_html_e( 'Consistency', 'midland-local-seo' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $registry as $slug => $info ) : ?>
							<?php
							$row     = isset( $cites[ $slug ] ) ? $cites[ $slug ] : array();
							$status  = isset( $row['status'] ) ? $row['status'] : '';
							$url     = isset( $row['url'] ) ? $row['url'] : '';
							$nap     = isset( $row['nap'] ) ? $row['nap'] : '';
							$flag    = '';
							$flag_bg = '';
							if ( '' !== $nap && ( '' !== $canon_name_n || '' !== $canon_ph_n ) ) {
								$nap_name_ok  = '' === $canon_name_n || false !== strpos( self::norm_name( $nap ), $canon_name_n );
								$nap_phone_ok = '' === $canon_ph_n || false !== strpos( self::norm_phone( $nap ), $canon_ph_n );
								if ( $nap_name_ok && $nap_phone_ok ) {
									$flag    = __( 'Match', 'midland-local-seo' );
									$flag_bg = '#1e7e34';
								} else {
									$flag    = __( 'Mismatch', 'midland-local-seo' );
									$flag_bg = '#b32d2e';
								}
							}
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $info[0] ); ?></strong>
									<?php if ( ! empty( $info[2] ) ) : ?>
										<br><a href="<?php echo esc_url( $info[2] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Manage', 'midland-local-seo' ); ?></a>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $info[1] ); ?></td>
								<td>
									<select name="citation[<?php echo esc_attr( $slug ); ?>][status]">
										<?php foreach ( $statuses as $val => $label ) : ?>
											<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $status, $val ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td><input type="url" name="citation[<?php echo esc_attr( $slug ); ?>][url]" value="<?php echo esc_attr( $url ); ?>" class="regular-text" placeholder="https://..."></td>
								<td><input type="text" name="citation[<?php echo esc_attr( $slug ); ?>][nap]" value="<?php echo esc_attr( $nap ); ?>" class="regular-text" placeholder="Midland Floors | (240) 532-9097"></td>
								<td>
									<?php if ( '' !== $flag ) : ?>
										<span style="color:#fff;background:<?php echo esc_attr( $flag_bg ); ?>;padding:2px 8px;border-radius:3px;font-size:12px;"><?php echo esc_html( $flag ); ?></span>
									<?php else : ?>
										<span class="description">&mdash;</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="submit">
					<button type="submit" name="mls_save_citations" value="1" class="button button-primary"><?php esc_html_e( 'Save Citations', 'midland-local-seo' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}
}

MLS_Citations::get_instance();
