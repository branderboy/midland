<?php
/**
 * GMB Mirror (Pillar 5).
 *
 * Reads the Google Business Profile (categories, service areas) and recommends
 * WordPress content to mirror it on-site: service pages, location pages, mirror
 * posts, and FAQ. Each recommendation is cross-checked against existing
 * pages/posts (by title + slug) so it shows "have it" vs "missing", with a
 * one-click "Create draft" action (nonce + capability gated).
 *
 * @package Midland_Local_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GMB mirror module.
 */
class MLS_GMB_Mirror {

	/**
	 * Singleton instance.
	 *
	 * @var MLS_GMB_Mirror|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return MLS_GMB_Mirror
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
		add_action( 'admin_menu', array( $this, 'add_menu' ), 14 );
		add_action( 'admin_post_mls_create_mirror_draft', array( $this, 'handle_create_draft' ) );
	}

	/**
	 * Register the submenu.
	 */
	public function add_menu() {
		add_submenu_page(
			MLS_Plugin::MENU_SLUG,
			__( 'GMB Mirror', 'midland-local-seo' ),
			__( 'GMB Mirror', 'midland-local-seo' ),
			'manage_options',
			'mls-gmb-mirror',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Does a published/draft page or post already exist matching this title/slug?
	 *
	 * @param string $title Title.
	 * @return int Existing post ID, or 0.
	 */
	private function existing_post_id( $title ) {
		$slug  = sanitize_title( $title );
		$query = new WP_Query(
			array(
				'post_type'              => array( 'page', 'post' ),
				'post_status'            => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'title'                  => $title,
			)
		);
		if ( ! empty( $query->posts ) ) {
			return (int) $query->posts[0];
		}
		// Fall back to slug match.
		$by_slug = get_page_by_path( $slug, OBJECT, array( 'page', 'post' ) );
		return $by_slug ? (int) $by_slug->ID : 0;
	}

	/**
	 * Build the recommendation list from identity + (optional) GBP listing.
	 *
	 * @param array      $identity Identity option.
	 * @param array|null $listing  Listing or null.
	 * @return array Groups keyed by section.
	 */
	private function build_recommendations( $identity, $listing ) {
		$recs = array(
			'service'  => array(),
			'location' => array(),
		);

		// Service pages from primary + additional GBP categories.
		$categories = array();
		if ( is_array( $listing ) ) {
			if ( ! empty( $listing['category'] ) ) {
				$categories[] = (string) $listing['category'];
			}
			if ( ! empty( $listing['additional_categories'] ) && is_array( $listing['additional_categories'] ) ) {
				$categories = array_merge( $categories, $listing['additional_categories'] );
			}
		}
		$categories = array_values( array_unique( array_filter( array_map( 'trim', $categories ) ) ) );
		foreach ( $categories as $cat ) {
			$title             = $cat;
			$recs['service'][] = array(
				'type'     => 'page',
				'title'    => $title,
				'existing' => $this->existing_post_id( $title ),
				'stub'     => sprintf(
					/* translators: %s: service name */
					__( 'Professional %s for commercial and residential clients across our service area. (Draft — replace with full service copy.)', 'midland-local-seo' ),
					$cat
				),
			);
		}

		// Location pages from identity service areas.
		$areas = array();
		if ( ! empty( $identity['service_areas'] ) ) {
			$areas = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $identity['service_areas'] ) ) );
		}
		$biz_type = ! empty( $identity['business_name'] ) ? $identity['business_name'] : get_bloginfo( 'name' );
		foreach ( $areas as $area ) {
			$title = sprintf(
				/* translators: 1: business, 2: area */
				__( '%1$s in %2$s', 'midland-local-seo' ),
				$biz_type,
				$area
			);
			$recs['location'][] = array(
				'type'     => 'page',
				'title'    => $title,
				'existing' => $this->existing_post_id( $title ),
				'stub'     => sprintf(
					/* translators: 1: business, 2: area */
					__( '%1$s proudly serves %2$s. (Draft — add local landmarks, services offered, and a call to action.)', 'midland-local-seo' ),
					$biz_type,
					$area
				),
			);
		}

		return $recs;
	}

	/**
	 * One-click create-draft handler.
	 */
	public function handle_create_draft() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'midland-local-seo' ) );
		}
		check_admin_referer( 'mls_create_mirror_draft' );

		$title = isset( $_POST['mirror_title'] ) ? sanitize_text_field( wp_unslash( $_POST['mirror_title'] ) ) : '';
		$stub  = isset( $_POST['mirror_stub'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mirror_stub'] ) ) : '';
		$type  = isset( $_POST['mirror_type'] ) ? sanitize_key( wp_unslash( $_POST['mirror_type'] ) ) : 'page';
		if ( ! in_array( $type, array( 'page', 'post' ), true ) ) {
			$type = 'page';
		}
		if ( '' === $title ) {
			wp_safe_redirect( admin_url( 'admin.php?page=mls-gmb-mirror&error=1' ) );
			exit;
		}

		$post_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_name'    => sanitize_title( $title ),
				'post_content' => $stub,
				'post_status'  => 'draft',
				'post_type'    => $type,
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			wp_safe_redirect( admin_url( 'admin.php?page=mls-gmb-mirror&error=1' ) );
			exit;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=mls-gmb-mirror&created=' . (int) $post_id ) );
		exit;
	}

	/**
	 * Render one recommendation row with have/missing + create-draft button.
	 *
	 * @param array $rec Recommendation.
	 */
	private function render_rec_row( $rec ) {
		?>
		<tr>
			<td><strong><?php echo esc_html( $rec['title'] ); ?></strong></td>
			<td>
				<?php if ( $rec['existing'] ) : ?>
					<span style="color:#1e7e34;">&#10003; <?php esc_html_e( 'Have it', 'midland-local-seo' ); ?></span>
					<a href="<?php echo esc_url( get_edit_post_link( $rec['existing'] ) ); ?>"><?php esc_html_e( 'Edit', 'midland-local-seo' ); ?></a>
				<?php else : ?>
					<span style="color:#b32d2e;">&#43; <?php esc_html_e( 'Missing', 'midland-local-seo' ); ?></span>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( ! $rec['existing'] ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
						<?php wp_nonce_field( 'mls_create_mirror_draft' ); ?>
						<input type="hidden" name="action" value="mls_create_mirror_draft">
						<input type="hidden" name="mirror_type" value="<?php echo esc_attr( $rec['type'] ); ?>">
						<input type="hidden" name="mirror_title" value="<?php echo esc_attr( $rec['title'] ); ?>">
						<input type="hidden" name="mirror_stub" value="<?php echo esc_attr( $rec['stub'] ); ?>">
						<button type="submit" class="button button-secondary"><?php esc_html_e( 'Create draft', 'midland-local-seo' ); ?></button>
					</form>
				<?php else : ?>
					<span class="description">&mdash;</span>
				<?php endif; ?>
			</td>
		</tr>
		<?php
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

		$listing       = null;
		$listing_error = '';
		if ( $configured ) {
			$lat = isset( $identity['center_lat'] ) && '' !== $identity['center_lat'] ? (float) $identity['center_lat'] : null;
			$lng = isset( $identity['center_lng'] ) && '' !== $identity['center_lng'] ? (float) $identity['center_lng'] : null;
			$res = MLS_DataForSEO::get_gmb_listing( $name, $lat, $lng );
			if ( is_wp_error( $res ) ) {
				$listing_error = $res->get_error_message();
			} else {
				$listing = $res;
			}
		}

		$recs = $this->build_recommendations( $identity, $listing );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Mirror Your Google Business Profile', 'midland-local-seo' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Turn your GBP categories and service areas into on-site pages. Anything already on your site is marked "have it"; missing items get a one-click draft.', 'midland-local-seo' ); ?></p>

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php if ( isset( $_GET['created'] ) ) : ?>
				<?php $cid = (int) $_GET['created']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php esc_html_e( 'Draft created.', 'midland-local-seo' ); ?>
					<a href="<?php echo esc_url( get_edit_post_link( $cid ) ); ?>"><?php esc_html_e( 'Edit it →', 'midland-local-seo' ); ?></a>
				</p></div>
			<?php endif; ?>
			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php if ( isset( $_GET['error'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Could not create the draft.', 'midland-local-seo' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $configured ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'DataForSEO is not configured, so GBP categories/posts cannot be pulled. Location-page recommendations from your service areas are still shown below. Connect DataForSEO on the dashboard for full mirroring.', 'midland-local-seo' ); ?></p></div>
			<?php elseif ( '' !== $listing_error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $listing_error ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Service Pages (from GBP categories)', 'midland-local-seo' ); ?></h2>
			<?php if ( empty( $recs['service'] ) ) : ?>
				<p class="description"><?php esc_html_e( 'No GBP categories available. Connect DataForSEO and ensure your business name matches your listing.', 'midland-local-seo' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:900px;">
					<thead><tr><th><?php esc_html_e( 'Recommended page', 'midland-local-seo' ); ?></th><th><?php esc_html_e( 'On site?', 'midland-local-seo' ); ?></th><th><?php esc_html_e( 'Action', 'midland-local-seo' ); ?></th></tr></thead>
					<tbody>
						<?php
						foreach ( $recs['service'] as $rec ) {
							$this->render_rec_row( $rec ); }
						?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Location Pages (from service areas)', 'midland-local-seo' ); ?></h2>
			<?php if ( empty( $recs['location'] ) ) : ?>
				<p class="description"><?php esc_html_e( 'No service areas set. Add them under sameAs / Identity.', 'midland-local-seo' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:900px;">
					<thead><tr><th><?php esc_html_e( 'Recommended page', 'midland-local-seo' ); ?></th><th><?php esc_html_e( 'On site?', 'midland-local-seo' ); ?></th><th><?php esc_html_e( 'Action', 'midland-local-seo' ); ?></th></tr></thead>
					<tbody>
						<?php
						foreach ( $recs['location'] as $rec ) {
							$this->render_rec_row( $rec ); }
						?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}

MLS_GMB_Mirror::get_instance();
