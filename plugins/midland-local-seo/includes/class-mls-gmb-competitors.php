<?php
/**
 * GMB Competitor Audit (Pillar 7).
 *
 * Uses MLS_DataForSEO::get_maps_competitors() to pull the top Google Maps rivals
 * for your category + service area, compares them against your own GBP listing,
 * and highlights gaps where a rival beats you (rating, reviews, photos).
 * Degrades gracefully when DataForSEO is not configured.
 *
 * @package Midland_Local_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GMB competitor audit module.
 */
class MLS_GMB_Competitors {

	/**
	 * Singleton instance.
	 *
	 * @var MLS_GMB_Competitors|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return MLS_GMB_Competitors
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
		add_action( 'admin_menu', array( $this, 'add_menu' ), 18 );
	}

	/**
	 * Register the submenu.
	 */
	public function add_menu() {
		add_submenu_page(
			MLS_Plugin::MENU_SLUG,
			__( 'Competitor Audit', 'midland-local-seo' ),
			__( 'Competitor Audit', 'midland-local-seo' ),
			'manage_options',
			'mls-gmb-competitors',
			array( $this, 'render_page' )
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

		// Use a sensible default category/keyword for floor care.
		$keyword = apply_filters( 'mls_competitor_keyword', 'floor cleaning service' );
		$lat     = isset( $identity['center_lat'] ) && '' !== $identity['center_lat'] ? (float) $identity['center_lat'] : 38.9847;
		$lng     = isset( $identity['center_lng'] ) && '' !== $identity['center_lng'] ? (float) $identity['center_lng'] : -77.0947;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'GMB Competitor Audit', 'midland-local-seo' ); ?></h1>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: keyword */
						__( 'Top Google Maps rivals for "%s" in your area, vs. your listing. Gaps where a rival beats you are highlighted.', 'midland-local-seo' ),
						$keyword
					)
				);
				?>
			</p>

			<?php
			if ( ! $configured ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'DataForSEO credentials not configured. Connect DataForSEO on the dashboard to pull live competitor data.', 'midland-local-seo' ) . '</p></div></div>';
				return;
			}

			$competitors = MLS_DataForSEO::get_maps_competitors( $keyword, $lat, $lng, 20 );
			if ( is_wp_error( $competitors ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $competitors->get_error_message() ) . '</p></div></div>';
				return;
			}

			// Find our own listing among the competitors (best title match), or fetch separately.
			$mine   = null;
			$needle = strtolower( $name );
			foreach ( $competitors as $c ) {
				if ( '' !== $needle && false !== strpos( strtolower( $c['title'] ), $needle ) ) {
					$mine = $c;
					break;
				}
			}
			if ( null === $mine ) {
				$listing = MLS_DataForSEO::get_gmb_listing( $name, $lat, $lng );
				if ( ! is_wp_error( $listing ) ) {
					$mine = array(
						'title'        => isset( $listing['title'] ) ? $listing['title'] : $name,
						'rating'       => isset( $listing['rating'] ) ? $listing['rating'] : null,
						'rating_votes' => isset( $listing['rating_votes'] ) ? $listing['rating_votes'] : 0,
						'photos_count' => isset( $listing['photos_count'] ) ? $listing['photos_count'] : 0,
						'category'     => isset( $listing['category'] ) ? $listing['category'] : '',
					);
				}
			}

			$my_rating = ( $mine && null !== $mine['rating'] ) ? (float) $mine['rating'] : 0;
			$my_votes  = $mine ? (int) $mine['rating_votes'] : 0;
			$my_photos = $mine ? (int) $mine['photos_count'] : 0;
			?>
			<?php if ( $mine ) : ?>
				<p>
					<strong><?php esc_html_e( 'Your listing:', 'midland-local-seo' ); ?></strong>
					<?php echo esc_html( $mine['title'] ); ?>
					&mdash; <?php echo esc_html( sprintf( /* translators: 1: rating, 2: reviews, 3: photos */ __( '%1$s★ · %2$d reviews · %3$d photos', 'midland-local-seo' ), $my_rating ? $my_rating : '—', $my_votes, $my_photos ) ); ?>
				</p>
			<?php else : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'Your own listing was not found in the results — comparison columns reflect 0.', 'midland-local-seo' ); ?></p></div>
			<?php endif; ?>

			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Rank', 'midland-local-seo' ); ?></th>
					<th><?php esc_html_e( 'Business', 'midland-local-seo' ); ?></th>
					<th><?php esc_html_e( 'Rating', 'midland-local-seo' ); ?></th>
					<th><?php esc_html_e( 'Reviews', 'midland-local-seo' ); ?></th>
					<th><?php esc_html_e( 'Photos', 'midland-local-seo' ); ?></th>
					<th><?php esc_html_e( 'Category', 'midland-local-seo' ); ?></th>
					<th><?php esc_html_e( 'Beats you on', 'midland-local-seo' ); ?></th>
				</tr></thead>
				<tbody>
					<?php
					foreach ( $competitors as $c ) :
						$is_me = ( $mine && $c['title'] === $mine['title'] );
						$gaps  = array();
						if ( ! $is_me ) {
							if ( null !== $c['rating'] && (float) $c['rating'] > $my_rating ) {
								$gaps[] = __( 'rating', 'midland-local-seo' );
							}
							if ( (int) $c['rating_votes'] > $my_votes ) {
								$gaps[] = __( 'reviews', 'midland-local-seo' );
							}
							if ( (int) $c['photos_count'] > $my_photos ) {
								$gaps[] = __( 'photos', 'midland-local-seo' );
							}
						}
						?>
						<tr<?php echo $is_me ? ' style="background:#eef7ee;"' : ''; ?>>
							<td><?php echo esc_html( (int) $c['rank'] ); ?></td>
							<td><strong><?php echo esc_html( $c['title'] ); ?></strong><?php echo $is_me ? ' <em>(' . esc_html__( 'you', 'midland-local-seo' ) . ')</em>' : ''; ?></td>
							<td><?php echo esc_html( null !== $c['rating'] ? $c['rating'] : '—' ); ?></td>
							<td><?php echo esc_html( (int) $c['rating_votes'] ); ?></td>
							<td><?php echo esc_html( (int) $c['photos_count'] ); ?></td>
							<td><?php echo esc_html( $c['category'] ); ?></td>
							<td>
								<?php if ( $is_me ) : ?>
									<span class="description">&mdash;</span>
								<?php elseif ( ! empty( $gaps ) ) : ?>
									<span style="color:#fff;background:#b32d2e;padding:2px 8px;border-radius:3px;font-size:12px;"><?php echo esc_html( implode( ', ', $gaps ) ); ?></span>
								<?php else : ?>
									<span style="color:#1e7e34;"><?php esc_html_e( 'You lead', 'midland-local-seo' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}

MLS_GMB_Competitors::get_instance();
