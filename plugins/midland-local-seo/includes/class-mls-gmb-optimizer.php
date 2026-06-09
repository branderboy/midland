<?php
/**
 * GMB Optimizer (Pillar 6).
 *
 * Reads the Google Business Profile via MLS_DataForSEO::get_gmb_listing() and
 * scores it against local-SEO best practices (categories, description length,
 * photos, reviews, posting cadence, claimed, hours). Each check is pass / warn
 * with an actionable fix. Falls back to a best-practice checklist when
 * DataForSEO is not configured.
 *
 * @package Midland_Local_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GMB optimizer module.
 */
class MLS_GMB_Optimizer {

	/**
	 * Singleton instance.
	 *
	 * @var MLS_GMB_Optimizer|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return MLS_GMB_Optimizer
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
		add_action( 'admin_menu', array( $this, 'add_menu' ), 15 );
	}

	/**
	 * Register the submenu.
	 */
	public function add_menu() {
		add_submenu_page(
			MLS_Plugin::MENU_SLUG,
			__( 'GMB Optimizer', 'midland-local-seo' ),
			__( 'GMB Optimizer', 'midland-local-seo' ),
			'manage_options',
			'mls-gmb-optimizer',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Build the scorecard from a parsed listing.
	 *
	 * @param array $listing Listing from get_gmb_listing().
	 * @return array List of { label, state(pass|warn|fail), detail, fix }.
	 */
	public static function build_scorecard( $listing ) {
		$checks = array();

		$primary  = isset( $listing['category'] ) ? (string) $listing['category'] : '';
		$checks[] = array(
			'label'  => __( 'Primary category set', 'midland-local-seo' ),
			'state'  => '' !== $primary ? 'pass' : 'fail',
			'detail' => '' !== $primary ? $primary : __( 'No primary category detected.', 'midland-local-seo' ),
			'fix'    => __( 'Set the single most specific primary category in your Google Business Profile.', 'midland-local-seo' ),
		);

		$additional = isset( $listing['additional_categories'] ) && is_array( $listing['additional_categories'] ) ? $listing['additional_categories'] : array();
		$checks[]   = array(
			'label'  => __( 'Additional categories', 'midland-local-seo' ),
			'state'  => count( $additional ) >= 1 ? 'pass' : 'warn',
			'detail' => count( $additional ) > 0 ? implode( ', ', $additional ) : __( 'None set.', 'midland-local-seo' ),
			'fix'    => __( 'Add every relevant secondary category (e.g. Carpet cleaning service, Floor refinishing service).', 'midland-local-seo' ),
		);

		$desc_len = isset( $listing['description'] ) ? strlen( (string) $listing['description'] ) : 0;
		$checks[] = array(
			'label'  => __( 'Description (≥250 chars)', 'midland-local-seo' ),
			'state'  => $desc_len >= 250 ? 'pass' : 'warn',
			'detail' => sprintf(
				/* translators: %d: characters */
				__( '%d characters.', 'midland-local-seo' ),
				$desc_len
			),
			'fix'    => __( 'Write a 250+ character description featuring your services and service areas.', 'midland-local-seo' ),
		);

		$photos   = isset( $listing['photos_count'] ) ? (int) $listing['photos_count'] : 0;
		$checks[] = array(
			'label'  => __( 'Photos (≥10)', 'midland-local-seo' ),
			'state'  => $photos >= 10 ? 'pass' : 'warn',
			'detail' => sprintf(
				/* translators: %d: photo count */
				__( '%d photos.', 'midland-local-seo' ),
				$photos
			),
			'fix'    => __( 'Upload 10+ real before/after job photos and update them monthly.', 'midland-local-seo' ),
		);

		$rating   = isset( $listing['rating'] ) ? $listing['rating'] : null;
		$checks[] = array(
			'label'  => __( 'Rating present', 'midland-local-seo' ),
			'state'  => null !== $rating && $rating > 0 ? 'pass' : 'warn',
			'detail' => null !== $rating && $rating > 0 ? (string) $rating : __( 'No rating yet.', 'midland-local-seo' ),
			'fix'    => __( 'Earn your first reviews to establish a star rating.', 'midland-local-seo' ),
		);

		$votes    = isset( $listing['rating_votes'] ) ? (int) $listing['rating_votes'] : 0;
		$checks[] = array(
			'label'  => __( 'Reviews (≥25)', 'midland-local-seo' ),
			'state'  => $votes >= 25 ? 'pass' : 'warn',
			'detail' => sprintf(
				/* translators: %d: review count */
				__( '%d reviews.', 'midland-local-seo' ),
				$votes
			),
			'fix'    => __( 'Ask every happy customer for a review; aim for a steady cadence, not a burst.', 'midland-local-seo' ),
		);

		$claimed  = isset( $listing['claimed'] ) ? $listing['claimed'] : null;
		$checks[] = array(
			'label'  => __( 'Profile claimed', 'midland-local-seo' ),
			'state'  => true === $claimed ? 'pass' : ( false === $claimed ? 'fail' : 'warn' ),
			'detail' => true === $claimed ? __( 'Claimed', 'midland-local-seo' ) : ( false === $claimed ? __( 'Unclaimed', 'midland-local-seo' ) : __( 'Unknown', 'midland-local-seo' ) ),
			'fix'    => __( 'Claim and verify the listing so you control the information Google shows.', 'midland-local-seo' ),
		);

		$hours    = ! empty( $listing['work_hours_present'] );
		$checks[] = array(
			'label'  => __( 'Business hours set', 'midland-local-seo' ),
			'state'  => $hours ? 'pass' : 'warn',
			'detail' => $hours ? __( 'Hours present', 'midland-local-seo' ) : __( 'No hours detected.', 'midland-local-seo' ),
			'fix'    => __( 'Set accurate hours (and special/holiday hours) so customers and Google trust the listing.', 'midland-local-seo' ),
		);

		// Posting cadence is not exposed by the listing search; flag as a manual best practice.
		$checks[] = array(
			'label'  => __( 'Recent posts (cadence)', 'midland-local-seo' ),
			'state'  => 'warn',
			'detail' => __( 'Not measurable from the listing API — verify manually.', 'midland-local-seo' ),
			'fix'    => __( 'Publish a GBP update/offer post at least every 7 days.', 'midland-local-seo' ),
		);

		return $checks;
	}

	/**
	 * Best-practice checklist shown when DataForSEO is unconfigured.
	 *
	 * @return array
	 */
	private static function best_practices() {
		return array(
			__( 'Claim and verify the listing.', 'midland-local-seo' ),
			__( 'Set one specific primary category + all relevant secondary categories.', 'midland-local-seo' ),
			__( 'Write a 250+ character description with services and service areas.', 'midland-local-seo' ),
			__( 'Upload 10+ real photos and refresh monthly.', 'midland-local-seo' ),
			__( 'Set accurate hours, including holiday hours.', 'midland-local-seo' ),
			__( 'Earn 25+ reviews at a steady cadence and reply to each.', 'midland-local-seo' ),
			__( 'Post a GBP update at least weekly.', 'midland-local-seo' ),
			__( 'List products/services with descriptions and prices.', 'midland-local-seo' ),
		);
	}

	/**
	 * Render the admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'midland-local-seo' ) );
		}
		$identity   = class_exists( 'MLS_SameAs' ) ? MLS_SameAs::get_identity() : array();
		$name       = isset( $identity['business_name'] ) ? $identity['business_name'] : get_bloginfo( 'name' );
		$configured = class_exists( 'MLS_DataForSEO' ) && MLS_DataForSEO::is_configured();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'GMB Optimizer', 'midland-local-seo' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Scorecard for your Google Business Profile: categories, description, photos, reviews, posts, hours, and claim status.', 'midland-local-seo' ); ?></p>

			<?php
			if ( ! $configured ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'DataForSEO credentials not configured — showing the best-practice checklist. Connect DataForSEO on the dashboard for a live scorecard.', 'midland-local-seo' ) . '</p></div>';
				echo '<h2>' . esc_html__( 'GMB Best-Practice Checklist', 'midland-local-seo' ) . '</h2><ul style="list-style:disc;padding-left:22px;">';
				foreach ( self::best_practices() as $bp ) {
					echo '<li>' . esc_html( $bp ) . '</li>';
				}
				echo '</ul></div>';
				return;
			}

			$listing = MLS_DataForSEO::get_gmb_listing( $name, $this->lat( $identity ), $this->lng( $identity ) );
			if ( is_wp_error( $listing ) ) {
				$msg = $listing->get_error_message();
				if ( false !== stripos( $msg, 'not authorized' ) || false !== stripos( $msg, 'access denied' ) || false !== stripos( $msg, '40301' ) ) {
					echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Live GBP scoring unavailable.', 'midland-local-seo' ) . '</strong> '
						. esc_html__( 'Your DataForSEO plan doesn’t have access to the Google Maps SERP API this needs. Enable it on your account, or use the best-practice checklist below.', 'midland-local-seo' )
						. ' <a href="https://app.dataforseo.com/api-access" target="_blank" rel="noopener noreferrer">' . esc_html__( 'DataForSEO API access', 'midland-local-seo' ) . '</a></p></div>';
				} else {
					echo '<div class="notice notice-warning"><p>' . esc_html( $msg ) . '</p></div>';
				}
				echo '<h2>' . esc_html__( 'GMB Best-Practice Checklist', 'midland-local-seo' ) . '</h2><ul style="list-style:disc;padding-left:22px;">';
				foreach ( self::best_practices() as $bp ) {
					echo '<li>' . esc_html( $bp ) . '</li>';
				}
				echo '</ul></div>';
				return;
			}

			$checks = self::build_scorecard( $listing );
			$passes = 0;
			foreach ( $checks as $c ) {
				if ( 'pass' === $c['state'] ) {
					++$passes;
				}
			}
			$total = count( $checks );
			$pct   = $total > 0 ? (int) round( ( $passes / $total ) * 100 ) : 0;
			?>
			<h2>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: listing title */
						__( 'Listing: %s', 'midland-local-seo' ),
						isset( $listing['title'] ) ? $listing['title'] : $name
					)
				);
				?>
			</h2>
			<div style="max-width:520px;">
				<div style="background:#e2e4e7;border-radius:6px;overflow:hidden;height:26px;">
					<div style="width:<?php echo esc_attr( $pct ); ?>%;background:#46b450;height:26px;line-height:26px;color:#fff;text-align:center;font-weight:700;"><?php echo esc_html( $pct . '%' ); ?></div>
				</div>
				<p class="description"><?php echo esc_html( sprintf( /* translators: 1: passes, 2: total */ __( '%1$d of %2$d checks passing.', 'midland-local-seo' ), $passes, $total ) ); ?></p>
			</div>

			<table class="widefat striped" style="margin-top:16px;">
				<thead><tr>
					<th><?php esc_html_e( 'Check', 'midland-local-seo' ); ?></th>
					<th><?php esc_html_e( 'Status', 'midland-local-seo' ); ?></th>
					<th><?php esc_html_e( 'Detail', 'midland-local-seo' ); ?></th>
					<th><?php esc_html_e( 'Fix', 'midland-local-seo' ); ?></th>
				</tr></thead>
				<tbody>
					<?php
					foreach ( $checks as $c ) :
						if ( 'pass' === $c['state'] ) {
							$bg    = '#1e7e34';
							$label = __( 'Pass', 'midland-local-seo' );
						} elseif ( 'fail' === $c['state'] ) {
							$bg    = '#b32d2e';
							$label = __( 'Fail', 'midland-local-seo' );
						} else {
							$bg    = '#dba617';
							$label = __( 'Warn', 'midland-local-seo' );
						}
						?>
						<tr>
							<td><strong><?php echo esc_html( $c['label'] ); ?></strong></td>
							<td><span style="color:#fff;background:<?php echo esc_attr( $bg ); ?>;padding:2px 8px;border-radius:3px;font-size:12px;"><?php echo esc_html( $label ); ?></span></td>
							<td><?php echo esc_html( $c['detail'] ); ?></td>
							<td><?php echo 'pass' === $c['state'] ? '<span class="description">&mdash;</span>' : esc_html( $c['fix'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Best-effort latitude from identity (returns null if absent).
	 *
	 * @param array $identity Identity option.
	 * @return float|null
	 */
	private function lat( $identity ) {
		return isset( $identity['center_lat'] ) && '' !== $identity['center_lat'] ? (float) $identity['center_lat'] : null;
	}

	/**
	 * Best-effort longitude from identity (returns null if absent).
	 *
	 * @param array $identity Identity option.
	 * @return float|null
	 */
	private function lng( $identity ) {
		return isset( $identity['center_lng'] ) && '' !== $identity['center_lng'] ? (float) $identity['center_lng'] : null;
	}
}

MLS_GMB_Optimizer::get_instance();
