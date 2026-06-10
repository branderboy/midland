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
	const CATEGORIES_OPTION = 'mls_gmb_categories';

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 14 );
		add_action( 'admin_post_mls_create_mirror_draft', array( $this, 'handle_create_draft' ) );
		add_action( 'admin_post_mls_create_all_services', array( $this, 'handle_create_all_services' ) );
		add_action( 'admin_post_mls_create_all_locations', array( $this, 'handle_create_all_locations' ) );
		add_action( 'admin_post_mls_rewrite_page', array( $this, 'handle_rewrite_page' ) );
		add_action( 'admin_post_mls_rewrite_all_thin', array( $this, 'handle_rewrite_all_thin' ) );
		add_action( 'admin_init', array( $this, 'handle_save_categories' ) );
		add_action( 'admin_init', array( $this, 'maybe_ensure_hubs' ) );
		add_action( 'admin_post_mls_save_links_menu', array( $this, 'handle_save_links_menu' ) );
	}

	/**
	 * Bulk-create a full draft page for every service category that does not yet
	 * have one. One click instead of clicking each row.
	 */
	public function handle_create_all_services() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'midland-local-seo' ) );
		}
		check_admin_referer( 'mls_create_all_services' );

		$created = 0;
		foreach ( self::get_categories() as $service ) {
			$service = trim( (string) $service );
			if ( '' === $service || $this->existing_post_id( $service ) ) {
				continue;
			}
			$content = $this->build_service_content( $service );
			$post_id = wp_insert_post(
				array(
					'post_title'   => $service,
					'post_name'    => sanitize_title( $service ),
					'post_content' => $content,
					'post_status'  => 'draft',
					'post_type'    => 'page',
				),
				true
			);
			if ( ! is_wp_error( $post_id ) && $post_id ) {
				$this->apply_template( (int) $post_id, $service, false, '', '', $content );
				++$created;
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=mls-gmb-mirror&bulk_created=' . (int) $created ) );
		exit;
	}

	/**
	 * Bulk-create a location page (city x service) for every service area that does
	 * not yet have one, through the Smart SEO programmatic engine so each renders in
	 * the Elementor template. Falls back to a plain draft when the engine is off.
	 */
	public function handle_create_all_locations() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'midland-local-seo' ) );
		}
		check_admin_referer( 'mls_create_all_locations' );

		$identity = class_exists( 'MLS_SameAs' ) ? MLS_SameAs::get_identity() : array();
		$biz      = ! empty( $identity['business_name'] ) ? $identity['business_name'] : get_bloginfo( 'name' );
		$areas    = array();
		if ( ! empty( $identity['service_areas'] ) ) {
			$areas = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $identity['service_areas'] ) ) );
		}

		$created = 0;
		foreach ( $areas as $area ) {
			$parts = array_map( 'trim', explode( ',', $area, 2 ) );
			$city  = isset( $parts[0] ) ? $parts[0] : $area;
			$state = isset( $parts[1] ) ? $parts[1] : '';
			$title = sprintf( '%1$s in %2$s', $biz, $area );
			if ( '' === $city || $this->existing_post_id( $title ) ) {
				continue;
			}
			$content = $this->build_location_content( $title, $city, $state );
			$post_id = wp_insert_post(
				array(
					'post_title'   => $title,
					'post_name'    => sanitize_title( $title ),
					'post_content' => $content,
					'post_status'  => 'draft',
					'post_type'    => 'page',
				),
				true
			);
			if ( ! is_wp_error( $post_id ) && $post_id ) {
				$this->apply_template( (int) $post_id, $title, true, $city, $state, $content );
				++$created;
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=mls-gmb-mirror&bulk_loc_created=' . (int) $created ) );
		exit;
	}

	/**
	 * Midland Floor Care's actual Google Business Profile categories (primary +
	 * additional). Used so Service Page recommendations reflect the real listing,
	 * never API guesses. Operator-editable on the Mirror page.
	 *
	 * @return array
	 */
	public static function default_categories() {
		// Real, page-worthy services (the generic "Contractor"/"Flooring contractor"
		// GBP categories are dropped — they don't make a useful service page).
		return array(
			'Carpet cleaning service',
			'Carpet installation',
			'Hardwood floor cleaning',
			'Tile cleaning service',
			'Floor refinishing service',
			'Wood floor refinishing service',
			'Upholstery cleaning service',
			'Janitorial service',
		);
	}

	/**
	 * Stored GBP categories (newline list), falling back to the real defaults so
	 * the list is never empty.
	 *
	 * @return array
	 */
	public static function get_categories() {
		$raw = get_option( self::CATEGORIES_OPTION, '' );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return self::default_categories();
		}
		$cats = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw ) ) );
		return $cats ? array_values( $cats ) : self::default_categories();
	}

	/**
	 * Persist the operator-edited GBP category list.
	 */
	public function handle_save_categories() {
		if ( ! isset( $_POST['mls_save_gmb_categories'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$nonce = isset( $_POST['_mls_gmb_cats_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_mls_gmb_cats_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mls_save_gmb_categories' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'midland-local-seo' ) );
		}
		update_option( self::CATEGORIES_OPTION, sanitize_textarea_field( wp_unslash( $_POST['gmb_categories'] ?? '' ) ) );
		wp_safe_redirect( admin_url( 'admin.php?page=mls-gmb-mirror&cats_saved=1' ) );
		exit;
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
	 * Detect an existing location page. The Smart SEO engine creates an
	 * mfc_location post titled "City, State" with _mfc_city / _mfc_state meta, so
	 * we match on that, then fall back to the rec title / a "City, State" page.
	 *
	 * @param string $city      City.
	 * @param string $state     State.
	 * @param string $rec_title The recommendation title (e.g. "Business in City, State").
	 * @return int Existing post ID, or 0.
	 */
	private function existing_location_id( $city, $state, $rec_title = '' ) {
		if ( '' === $city ) {
			return 0;
		}
		$q = new WP_Query(
			array(
				'post_type'              => 'any',
				'post_status'            => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'             => array(
					'relation' => 'AND',
					array( 'key' => '_mfc_city', 'value' => $city, 'compare' => '=' ),
					array( 'key' => '_mfc_state', 'value' => $state, 'compare' => '=' ),
				),
			)
		);
		if ( ! empty( $q->posts ) ) {
			return (int) $q->posts[0];
		}
		// Fall back to a page/post titled "City, State" or the rec title.
		$by = get_page_by_path( sanitize_title( $city . ' ' . $state ), OBJECT, array( 'page', 'post', 'mfc_location' ) );
		if ( $by ) {
			return (int) $by->ID;
		}
		return '' !== $rec_title ? $this->existing_post_id( $rec_title ) : 0;
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

		// Service pages from your REAL GBP categories (stored/editable), merged with
		// anything the live listing returns — real data, never made up.
		$categories = self::get_categories();
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
					__( 'Professional %s for commercial and residential clients across our service area. (Draft. Replace with full service copy.)', 'midland-local-seo' ),
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
			// Split "Bethesda, MD" into city + state so the Real Smart SEO
			// programmatic engine (when active) can build a true mfc_location page.
			$area_parts = array_map( 'trim', explode( ',', $area, 2 ) );
			$loc_city   = isset( $area_parts[0] ) ? $area_parts[0] : $area;
			$loc_state  = isset( $area_parts[1] ) ? $area_parts[1] : '';
			$recs['location'][] = array(
				'type'        => 'page',
				'is_location' => true,
				'city'        => $loc_city,
				'state'       => $loc_state,
				'title'       => $title,
				'existing'    => $this->existing_post_id( $title ),
				'stub'        => sprintf(
					/* translators: 1: business, 2: area */
					__( '%1$s proudly serves %2$s. (Draft. Add local landmarks, services offered, and a call to action.)', 'midland-local-seo' ),
					$biz_type,
					$area
				),
			);
		}

		return $recs;
	}

	/**
	 * Build a full, well-structured service page (H2 sections, process, FAQ, no
	 * dashes) for a service across the business service areas — not a thin stub.
	 *
	 * @param string $service Service name.
	 * @return string HTML content.
	 */
	private function build_service_content( $service ) {
		// Smart SEO ships the done-for-you, service-specific copy library.
		if ( class_exists( 'RSSEO_Service_Copy' ) ) {
			return RSSEO_Service_Copy::body_for( $service );
		}
		$identity = class_exists( 'MLS_SameAs' ) ? MLS_SameAs::get_identity() : array();
		$business = ! empty( $identity['business_name'] ) ? $identity['business_name'] : get_bloginfo( 'name' );
		$phone    = ! empty( $identity['business_phone'] ) ? $identity['business_phone'] : '';
		$areas    = array();
		if ( ! empty( $identity['service_areas'] ) ) {
			$areas = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $identity['service_areas'] ) ) );
		}
		$area_str  = $areas ? implode( ', ', $areas ) : 'Washington DC, Maryland and Northern Virginia';
		$s         = esc_html( $service );
		$b         = esc_html( $business );
		$cta_phone = '' !== $phone ? ' or call ' . esc_html( $phone ) : '';

		$h   = array();
		$h[] = '<h2>Professional ' . $s . ' in the DMV</h2>';
		$h[] = '<p>' . $b . ' provides expert ' . $s . ' for homes and businesses across ' . esc_html( $area_str ) . '. Our trained technicians use professional grade equipment and safe, effective products to deliver clean, refreshed results you can see and feel.</p>';
		$h[] = '<h2>Why Choose ' . $b . ' for ' . $s . '?</h2>';
		$h[] = '<ul><li>Years of trusted floor care experience across the DMV.</li><li>Commercial grade equipment and eco friendly products that are safe for your family and staff.</li><li>Same day and next day service available in most areas.</li><li>Reliable, efficient, and affordable, with clear pricing and no surprises.</li></ul>';
		$h[] = '<h2>Our ' . $s . ' Process</h2>';
		$h[] = '<ol><li>We evaluate your space and recommend the right approach.</li><li>We prep the area and protect your surroundings.</li><li>We deep clean and restore using proven methods.</li><li>We walk the finished job with you to make sure you are happy.</li></ol>';
		$h[] = '<h2>Areas We Serve</h2>';
		$h[] = '<p>' . $b . ' provides ' . $s . ' throughout ' . esc_html( $area_str ) . '. If you do not see your area listed, call us. We likely cover it.</p>';
		$h[] = '<h2>Frequently Asked Questions</h2>';
		$h[] = '<h3>Do you serve both homes and businesses?</h3><p>Yes. We provide ' . $s . ' for residential and commercial properties, from single homes to offices, retail, and shopping centers.</p>';
		$h[] = '<h3>How soon can you schedule?</h3><p>Same day or next day service is available in most of the DMV. Call us to check current availability.</p>';
		$h[] = '<h2>Get a Free ' . $s . ' Quote</h2>';
		$h[] = '<p>Ready to get started? <a href="/contact/">Request a free quote</a>' . $cta_phone . '. ' . $b . ' serves Washington DC, Maryland, and Northern Virginia.</p>';
		return implode( "\n", $h );
	}

	/**
	 * Build a full, well-structured location page (city + service area focus, H2
	 * sections, FAQ, no dashes). Self-contained.
	 *
	 * @param string $title Page title (e.g. "Business in City, State").
	 * @param string $city  City.
	 * @param string $state State.
	 * @return string HTML content.
	 */
	private function build_location_content( $title, $city, $state ) {
		$identity = class_exists( 'MLS_SameAs' ) ? MLS_SameAs::get_identity() : array();
		$business = ! empty( $identity['business_name'] ) ? $identity['business_name'] : get_bloginfo( 'name' );
		$phone    = ! empty( $identity['business_phone'] ) ? $identity['business_phone'] : '';
		$services = self::get_categories();

		$place     = esc_html( trim( $city . ( '' !== $state ? ', ' . $state : '' ) ) );
		$b         = esc_html( $business );
		$cta_phone = '' !== $phone ? ' or call ' . esc_html( $phone ) : '';

		$list = '';
		foreach ( array_slice( $services, 0, 8 ) as $svc ) {
			$list .= '<li>' . esc_html( $svc ) . '</li>';
		}

		$h   = array();
		$h[] = '<h2>Floor Care in ' . $place . '</h2>';
		$h[] = '<p>' . $b . ' provides commercial and residential floor care for homes and businesses in ' . $place . ' and the surrounding area. Our trained technicians bring professional grade equipment and safe, effective products to every job.</p>';
		$h[] = '<h2>Services We Offer in ' . $place . '</h2>';
		$h[] = '<ul>' . $list . '</ul>';
		$h[] = '<h2>Why ' . $place . ' Chooses ' . $b . '</h2>';
		$h[] = '<ul><li>Local crews who know the ' . $place . ' area.</li><li>Commercial grade equipment and eco friendly products.</li><li>Same day and next day service available.</li><li>Reliable scheduling, clear pricing, and no surprises.</li></ul>';
		$h[] = '<h2>Our Process</h2>';
		$h[] = '<ol><li>We evaluate your space and recommend the right approach.</li><li>We prep and protect the area.</li><li>We deep clean and restore using proven methods.</li><li>We walk the finished job with you.</li></ol>';
		$h[] = '<h2>Frequently Asked Questions</h2>';
		$h[] = '<h3>Do you serve ' . $place . '?</h3><p>Yes. ' . $b . ' proudly serves ' . $place . ' and every surrounding neighborhood.</p>';
		$h[] = '<h3>Do you handle homes and businesses?</h3><p>Yes. We work with homeowners as well as offices, retail, medical, and property managers.</p>';
		$h[] = '<h2>Get a Free Quote in ' . $place . '</h2>';
		$h[] = '<p>Ready to get started? <a href="/contact/">Request a free quote</a>' . $cta_phone . '. ' . $b . ' serves ' . $place . ' and the entire DMV.</p>';
		return implode( "\n", $h );
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

		$is_location = ! empty( $_POST['mirror_is_location'] );
		$city        = isset( $_POST['mirror_city'] ) ? sanitize_text_field( wp_unslash( $_POST['mirror_city'] ) ) : '';
		$state       = isset( $_POST['mirror_state'] ) ? sanitize_text_field( wp_unslash( $_POST['mirror_state'] ) ) : '';

		if ( '' === $title ) {
			wp_safe_redirect( admin_url( 'admin.php?page=mls-gmb-mirror&error=1' ) );
			exit;
		}

		// Full content + the plugin's own Elementor template. Self-contained.
		$full = $is_location
			? $this->build_location_content( $title, $city, $state )
			: $this->build_service_content( $title );
		if ( '' !== trim( $full ) ) {
			$stub = $full;
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
		if ( ! is_wp_error( $post_id ) && $post_id ) {
			$this->apply_template( (int) $post_id, $title, $is_location, $city, $state, $stub );
		}

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			wp_safe_redirect( admin_url( 'admin.php?page=mls-gmb-mirror&error=1' ) );
			exit;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=mls-gmb-mirror&created=' . (int) $post_id . '&via=draft' ) );
		exit;
	}

	/**
	 * Whether the page already has Elementor layout data.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function has_template( $post_id ) {
		return '' !== (string) get_post_meta( (int) $post_id, '_elementor_data', true );
	}

	/**
	 * A page is "thin" when it still carries the old one-line stub or has
	 * almost no body text.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function is_thin( $post_id ) {
		$content = (string) get_post_field( 'post_content', $post_id );
		if ( false !== strpos( $content, '(Draft' ) ) {
			return true;
		}
		// Elementor pages keep their real copy in the layout, not post_content.
		// Measure whichever holds more text, or hand-built pages get nuked.
		$text    = trim( wp_strip_all_tags( $content ) );
		$el_text = $this->elementor_text( $post_id );
		if ( false !== strpos( $el_text, '(Draft' ) ) {
			return true;
		}
		return max( strlen( $text ), strlen( $el_text ) ) < 400;
	}

	/**
	 * All human-readable text inside a page's Elementor layout.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function elementor_text( $post_id ) {
		$raw = (string) get_post_meta( $post_id, '_elementor_data', true );
		if ( '' === $raw ) {
			return '';
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return '';
		}
		$text  = '';
		$stack = $data;
		while ( $stack ) {
			$node = array_pop( $stack );
			if ( ! is_array( $node ) ) {
				continue;
			}
			foreach ( array( 'editor', 'title', 'text', 'description_text', 'item_description' ) as $key ) {
				if ( ! empty( $node['settings'][ $key ] ) && is_string( $node['settings'][ $key ] ) ) {
					$text .= ' ' . wp_strip_all_tags( $node['settings'][ $key ] );
				}
			}
			if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
				foreach ( $node['elements'] as $child ) {
					$stack[] = $child;
				}
			}
		}
		return trim( $text );
	}

	/**
	 * Whether the Mirror generated/last wrote this page. Hand-built pages do
	 * not carry the marker and are never auto-rewritten.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function is_mirror_page( $post_id ) {
		return (bool) get_post_meta( (int) $post_id, '_mls_mirror_generated', true );
	}

	/**
	 * Replace a page's content with full done-for-you copy and re-apply the
	 * Elementor template so it renders in the site design.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $title       Service name or location title.
	 * @param bool   $is_location Whether this is a location page.
	 * @param string $city        City (locations).
	 * @param string $state       State (locations).
	 */
	private function rewrite_post_full( $post_id, $title, $is_location, $city = '', $state = '' ) {
		// Hand-built page with its own Elementor layout and real content:
		// leave it completely alone.
		if ( ! $this->is_mirror_page( $post_id ) && ! $this->is_thin( $post_id ) && $this->has_template( $post_id ) ) {
			return;
		}
		if ( $this->is_thin( $post_id ) ) {
			// Stub or near-empty: replace with full done-for-you copy.
			$content = $is_location
				? $this->build_location_content( $title, $city, $state )
				: $this->build_service_content( $title );

			wp_update_post(
				array(
					'ID'           => (int) $post_id,
					'post_content' => $content,
				)
			);
		} else {
			// Real copy already on the page: keep it, just apply the template.
			$content = (string) get_post_field( 'post_content', $post_id );
		}

		$this->apply_template( (int) $post_id, $title, $is_location, $city, $state, $content );
	}

	/**
	 * Wrap a page in the plugin's own Elementor template with the full copy in
	 * the content section. Always available; no other plugin involved.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $title       Service name or location title.
	 * @param bool   $is_location Whether this is a location page.
	 * @param string $city        City (locations).
	 * @param string $state       State (locations).
	 * @param string $body_html   Full page copy.
	 */
	private function apply_template( $post_id, $title, $is_location, $city, $state, $body_html ) {
		if ( ! class_exists( 'MLS_Elementor' ) ) {
			require_once MLS_PATH . 'includes/class-mls-elementor.php';
		}
		$identity = class_exists( 'MLS_SameAs' ) ? MLS_SameAs::get_identity() : array();
		$business = ! empty( $identity['business_name'] ) ? $identity['business_name'] : get_bloginfo( 'name' );

		if ( $is_location ) {
			$place = trim( $city . ( '' !== $state ? ', ' . $state : '' ) );
			$args  = array(
				'hero_kicker' => __( 'Service Area', 'midland-local-seo' ),
				'hero_title'  => '' !== $place ? 'Floor Care in ' . $place : $title,
				'intro'       => sprintf( '%1$s serves %2$s with commercial and residential floor care, on time and on budget.', $business, '' !== $place ? $place : 'the DMV' ),
				'body_html'   => $body_html,
			);
		} else {
			$args = array(
				'hero_kicker' => __( 'Our Services', 'midland-local-seo' ),
				'hero_title'  => $title,
				'intro'       => sprintf( '%1$s provides professional %2$s for homes and businesses across the DMV.', $business, strtolower( $title ) ),
				'body_html'   => $body_html,
			);
		}

		// Inner linking: every generated page links to the rest of the set.
		$args['links_html'] = $this->build_links_html( (int) $post_id );

		MLS_Elementor::apply( (int) $post_id, $args );
		update_post_meta( (int) $post_id, '_mls_mirror_generated', 1 );

		// SEO meta (Yoast fields). Never clobbers a hand-written value.
		$phone = ! empty( $identity['business_phone'] ) ? $identity['business_phone'] : '(240) 532-9097';
		if ( $is_location ) {
			$place      = trim( $city . ( '' !== $state ? ', ' . $state : '' ) );
			$meta_title = sprintf( '%1$s in %2$s | Floor Care & Flooring', $business, '' !== $place ? $place : 'the DMV' );
			$meta_desc  = sprintf( '%1$s provides commercial and residential floor care in %2$s. Carpet, hardwood, tile and janitorial. On time crews and clear pricing. Call %3$s.', $business, '' !== $place ? $place : 'the DMV', $phone );
		} else {
			$meta_title = sprintf( '%1$s in Washington DC, MD & VA | %2$s', $title, $business );
			$meta_desc  = sprintf( 'Professional %1$s for homes and businesses across Washington DC, Maryland and Northern Virginia. On time crews, clear pricing, free quotes. Call %2$s.', strtolower( $title ), $phone );
		}
		$this->set_seo_meta( (int) $post_id, $meta_title, $meta_desc );

		// Group under the right parent so the pages live under /services/ or
		// /service-areas/ instead of floating at the root.
		$this->assign_parent( (int) $post_id, $is_location );

		// Keep the footer "Service Links" menu in sync.
		$this->sync_links_menu();
	}

	/**
	 * All mirror-managed pages: service pages + location pages that exist.
	 *
	 * @return array { services: [id => title], locations: [id => title] }
	 */
	public function link_targets() {
		$targets = array(
			'services'  => array(),
			'locations' => array(),
		);
		foreach ( self::get_categories() as $service ) {
			$id = $this->existing_post_id( $service );
			if ( $id ) {
				$targets['services'][ $id ] = $service;
			}
		}
		$identity = class_exists( 'MLS_SameAs' ) ? MLS_SameAs::get_identity() : array();
		$biz      = ! empty( $identity['business_name'] ) ? $identity['business_name'] : get_bloginfo( 'name' );
		$areas    = array();
		if ( ! empty( $identity['service_areas'] ) ) {
			$areas = array_filter( array_map( 'trim', preg_split( '/
|
|
/', (string) $identity['service_areas'] ) ) );
		}
		foreach ( $areas as $area ) {
			$title = sprintf( '%1$s in %2$s', $biz, $area );
			$id    = $this->existing_post_id( $title );
			if ( $id ) {
				$targets['locations'][ $id ] = $area;
			}
		}
		// Location pages built as the Smart SEO location type count too.
		if ( post_type_exists( 'mfc_location' ) ) {
			$cpt = get_posts(
				array(
					'post_type'   => 'mfc_location',
					'post_status' => array( 'publish', 'draft' ),
					'numberposts' => 100,
					'fields'      => 'ids',
				)
			);
			foreach ( $cpt as $id ) {
				if ( ! isset( $targets['locations'][ $id ] ) ) {
					$targets['locations'][ $id ] = get_the_title( $id );
				}
			}
		}
		return $targets;
	}

	/**
	 * Pretty permalink that is correct even while the page is still a draft.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function page_url( $post_id ) {
		// Published posts: the REAL permalink. Works for pages AND custom post
		// types (location pages). Building URLs by hand from the page URI
		// produced wrong, 404ing links for CPT locations.
		if ( 'publish' === get_post_status( $post_id ) ) {
			$link = get_permalink( $post_id );
			if ( $link ) {
				return $link;
			}
		}
		// Drafts (only ever linked in admin previews): predicted pretty URL.
		$uri = get_page_uri( $post_id );
		return $uri ? home_url( user_trailingslashit( $uri ) ) : (string) get_permalink( $post_id );
	}

	/**
	 * Two-column link hub HTML (services + areas), excluding the page itself.
	 *
	 * @param int $exclude_id Page being rendered.
	 * @return string
	 */
	private function build_links_html( $exclude_id = 0 ) {
		$targets = $this->link_targets();
		$is_location = isset( $targets['locations'][ $exclude_id ] );
		$group       = $is_location ? 'locations' : 'services';

		// Core hub links: the 3 pages that link to everything else.
		$items = '';
		foreach ( $this->core_pages() as $url => $label ) {
			$items .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
		}

		// Plus up to 3 sibling pages for crawl depth, not the whole site.
		// Published only: a draft link is a 404 for visitors.
		$count = 0;
		foreach ( $targets[ $group ] as $id => $label ) {
			if ( (int) $id === (int) $exclude_id || $count >= 3 || 'publish' !== get_post_status( $id ) ) {
				continue;
			}
			$text   = 'locations' === $group ? 'Floor Care in ' . $label : $label;
			$items .= '<li><a href="' . esc_url( $this->page_url( $id ) ) . '">' . esc_html( $text ) . '</a></li>';
			++$count;
		}

		return '' === $items ? '' : '<h3>' . esc_html__( 'Explore', 'midland-local-seo' ) . '</h3><ul>' . $items . '</ul>';
	}

	/**
	 * The 3-4 core pages that link out to everything else (hub-and-spoke).
	 *
	 * @return array url => label.
	 */
	public function core_pages() {
		$core = array();

		$services_hub = get_page_by_path( 'services', OBJECT, 'page' );
		if ( $services_hub ) {
			$core[ $this->page_url( $services_hub->ID ) ] = __( 'Our Services', 'midland-local-seo' );
		}
		$areas_hub = get_page_by_path( 'service-areas', OBJECT, 'page' );
		if ( $areas_hub ) {
			$core[ $this->page_url( $areas_hub->ID ) ] = __( 'Areas We Serve', 'midland-local-seo' );
		}
		// Quote/contact page: first match wins, /contact/ as the fallback.
		$quote = null;
		foreach ( array( 'quote', 'contact', 'reach-us', 'schedule-a-visit' ) as $slug ) {
			$quote = get_page_by_path( $slug, OBJECT, 'page' );
			if ( $quote ) {
				break;
			}
		}
		$core[ $quote ? $this->page_url( $quote->ID ) : home_url( '/contact/' ) ] = __( 'Get a Free Quote', 'midland-local-seo' );

		return $core;
	}

	/**
	 * Write Yoast title/description, only into empty fields.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $title   Meta title.
	 * @param string $desc    Meta description.
	 */
	private function set_seo_meta( $post_id, $title, $desc ) {
		if ( '' === (string) get_post_meta( $post_id, '_yoast_wpseo_title', true ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_title', sanitize_text_field( $title ) );
		}
		if ( '' === (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', sanitize_text_field( $desc ) );
		}
	}

	/**
	 * Parent the page under "Our Services" (/services/) or "Service Areas"
	 * (/service-areas/), creating the hub page if needed. WordPress 301s the
	 * old top-level URL to the new nested one automatically.
	 *
	 * @param int  $post_id     Page ID.
	 * @param bool $is_location Whether this is a location page.
	 */
	private function assign_parent( $post_id, $is_location ) {
		if ( 'page' !== get_post_type( $post_id ) ) {
			return; // CPT location pages keep their own structure.
		}
		$parent_id = $this->ensure_hub_page( $is_location );
		if ( $parent_id && (int) get_post_field( 'post_parent', $post_id ) !== (int) $parent_id ) {
			wp_update_post(
				array(
					'ID'          => (int) $post_id,
					'post_parent' => (int) $parent_id,
				)
			);
		}
	}

	/**
	 * Create (or fetch) a hub page: /services/ or /service-areas/. Published,
	 * lists its children via shortcode, never added to the header nav.
	 *
	 * @param bool $is_location Service Areas hub when true, Services hub when false.
	 * @return int Hub page ID, or 0.
	 */
	private function ensure_hub_page( $is_location ) {
		$slug  = $is_location ? 'service-areas' : 'services';
		$title = $is_location ? __( 'Service Areas', 'midland-local-seo' ) : __( 'Our Services', 'midland-local-seo' );

		$parent = get_page_by_path( $slug, OBJECT, 'page' );
		if ( $parent ) {
			return (int) $parent->ID;
		}

		// Never let WP's "automatically add new top-level pages" menu option
		// put the hub pages into the header nav.
		add_filter( 'option_nav_menu_options', array( $this, 'suppress_menu_auto_add' ) );
		$parent_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '[' . ( $is_location ? 'mls_location_links' : 'mls_service_links' ) . ']',
			)
		);
		remove_filter( 'option_nav_menu_options', array( $this, 'suppress_menu_auto_add' ) );

		if ( ! is_wp_error( $parent_id ) && $parent_id ) {
			$identity = class_exists( 'MLS_SameAs' ) ? MLS_SameAs::get_identity() : array();
			$business = ! empty( $identity['business_name'] ) ? $identity['business_name'] : get_bloginfo( 'name' );
			if ( $is_location ) {
				$this->set_seo_meta( (int) $parent_id, 'Service Areas | ' . $business, 'Every city and neighborhood ' . $business . ' serves across Washington DC, Maryland and Northern Virginia. Find floor care near you.' );
			} else {
				$this->set_seo_meta( (int) $parent_id, 'Commercial & Residential Floor Care Services | ' . $business, 'All floor care services from ' . $business . ': carpet cleaning, hardwood refinishing, tile and grout, janitorial and more across the DMV. Free quotes.' );
			}
		}

		return is_wp_error( $parent_id ) ? 0 : (int) $parent_id;
	}

	/**
	 * Run ensure_hubs once per plugin version on any wp-admin visit.
	 */
	public function maybe_ensure_hubs() {
		if ( get_option( 'mls_hubs_checked' ) !== MLS_VERSION ) {
			$this->ensure_hubs();
			$this->sync_links_menu();
			update_option( 'mls_hubs_checked', MLS_VERSION, false );
		}
	}

	/**
	 * Make sure both hub pages exist when there is anything for them to list.
	 */
	public function ensure_hubs() {
		$targets = $this->link_targets();
		if ( ! empty( $targets['services'] ) ) {
			$this->ensure_hub_page( false );
		}
		if ( ! empty( $targets['locations'] ) ) {
			$this->ensure_hub_page( true );
		}
	}

	/**
	 * Filter callback: disable menu auto-add while mirror pages are created.
	 *
	 * @param array $options nav_menu_options.
	 * @return array
	 */
	public function suppress_menu_auto_add( $options ) {
		if ( is_array( $options ) ) {
			$options['auto_add'] = array();
		}
		return $options;
	}

	/**
	 * Maintain a "Service Links" nav menu with every mirror page, ready to
	 * assign to a footer menu location or an Elementor nav-menu widget.
	 */
	private function sync_links_menu() {
		$this->ensure_hubs();
		$menu_id = (int) get_option( 'mls_links_menu_id', 0 );
		if ( ! $menu_id || ! wp_get_nav_menu_object( $menu_id ) ) {
			return; // No menu chosen: only the automatic footer bar runs.
		}

		// Remove only items THIS PLUGIN added; user items are never touched.
		foreach ( (array) wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'any' ) ) as $item ) {
			if ( get_post_meta( $item->ID, '_mls_menu_item', true ) ) {
				wp_delete_post( $item->ID, true );
			}
		}
		foreach ( $this->core_pages() as $url => $label ) {
			$item_id = wp_update_nav_menu_item(
				$menu_id,
				0,
				array(
					'menu-item-title'  => $label,
					'menu-item-url'    => $url,
					'menu-item-type'   => 'custom',
					'menu-item-status' => 'publish',
				)
			);
			if ( ! is_wp_error( $item_id ) && $item_id ) {
				update_post_meta( $item_id, '_mls_menu_item', 1 );
			}
		}
	}

	/**
	 * Save which of the user's menus receives the core links.
	 */
	public function handle_save_links_menu() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'midland-local-seo' ) );
		}
		check_admin_referer( 'mls_save_links_menu' );

		$old_menu = (int) get_option( 'mls_links_menu_id', 0 );
		$new_menu = isset( $_POST['mls_links_menu_id'] ) ? (int) $_POST['mls_links_menu_id'] : 0;

		// Clean our items out of the previously selected menu.
		if ( $old_menu && $old_menu !== $new_menu && wp_get_nav_menu_object( $old_menu ) ) {
			foreach ( (array) wp_get_nav_menu_items( $old_menu, array( 'post_status' => 'any' ) ) as $item ) {
				if ( get_post_meta( $item->ID, '_mls_menu_item', true ) ) {
					wp_delete_post( $item->ID, true );
				}
			}
		}

		update_option( 'mls_links_menu_id', $new_menu, false );
		$this->sync_links_menu();

		wp_safe_redirect( add_query_arg( array( 'page' => 'mls-gmb-mirror', 'menu_saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Rewrite ONE existing page with full copy + Elementor template.
	 */
	public function handle_rewrite_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'midland-local-seo' ) );
		}
		check_admin_referer( 'mls_rewrite_page' );

		$post_id     = isset( $_POST['mirror_post_id'] ) ? (int) $_POST['mirror_post_id'] : 0;
		$title       = isset( $_POST['mirror_title'] ) ? sanitize_text_field( wp_unslash( $_POST['mirror_title'] ) ) : '';
		$is_location = ! empty( $_POST['mirror_is_location'] );
		$city        = isset( $_POST['mirror_city'] ) ? sanitize_text_field( wp_unslash( $_POST['mirror_city'] ) ) : '';
		$state       = isset( $_POST['mirror_state'] ) ? sanitize_text_field( wp_unslash( $_POST['mirror_state'] ) ) : '';

		if ( $post_id && '' !== $title ) {
			$this->rewrite_post_full( $post_id, $title, $is_location, $city, $state );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=mls-gmb-mirror&rewritten=' . (int) $post_id ) );
		exit;
	}

	/**
	 * Rewrite EVERY existing thin/stub page in one click.
	 */
	public function handle_rewrite_all_thin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'midland-local-seo' ) );
		}
		check_admin_referer( 'mls_rewrite_all_thin' );

		$identity = class_exists( 'MLS_SameAs' ) ? MLS_SameAs::get_identity() : array();
		$recs     = $this->build_recommendations( $identity, null );
		$count    = 0;

		foreach ( $recs['service'] as $rec ) {
			if ( $rec['existing'] && ( $this->is_mirror_page( $rec['existing'] ) || $this->is_thin( $rec['existing'] ) || ! $this->has_template( $rec['existing'] ) ) ) {
				$this->rewrite_post_full( (int) $rec['existing'], $rec['title'], false );
				++$count;
			}
		}
		foreach ( $recs['location'] as $rec ) {
			if ( $rec['existing'] && ( $this->is_mirror_page( $rec['existing'] ) || $this->is_thin( $rec['existing'] ) || ! $this->has_template( $rec['existing'] ) ) ) {
				$this->rewrite_post_full( (int) $rec['existing'], $rec['title'], true, $rec['city'], $rec['state'] );
				++$count;
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=mls-gmb-mirror&bulk_rewritten=' . (int) $count ) );
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
						<?php if ( ! empty( $rec['is_location'] ) ) : ?>
							<input type="hidden" name="mirror_is_location" value="1">
							<input type="hidden" name="mirror_city" value="<?php echo esc_attr( $rec['city'] ); ?>">
							<input type="hidden" name="mirror_state" value="<?php echo esc_attr( $rec['state'] ); ?>">
						<?php endif; ?>
						<button type="submit" class="button button-secondary"><?php esc_html_e( 'Create draft', 'midland-local-seo' ); ?></button>
					</form>
				<?php elseif ( $this->is_thin( $rec['existing'] ) || ! $this->has_template( $rec['existing'] ) || $this->is_mirror_page( $rec['existing'] ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
						<?php wp_nonce_field( 'mls_rewrite_page' ); ?>
						<input type="hidden" name="action" value="mls_rewrite_page">
						<input type="hidden" name="mirror_post_id" value="<?php echo esc_attr( $rec['existing'] ); ?>">
						<input type="hidden" name="mirror_title" value="<?php echo esc_attr( $rec['title'] ); ?>">
						<?php if ( ! empty( $rec['is_location'] ) ) : ?>
							<input type="hidden" name="mirror_is_location" value="1">
							<input type="hidden" name="mirror_city" value="<?php echo esc_attr( $rec['city'] ); ?>">
							<input type="hidden" name="mirror_state" value="<?php echo esc_attr( $rec['state'] ); ?>">
						<?php endif; ?>
						<button type="submit" class="button button-secondary" onclick="return confirm('Apply your Elementor template to this page<?php echo $this->is_thin( $rec['existing'] ) ? ' and replace the thin content with full copy' : ' (keeps the current copy)'; ?>?');">
							<?php
							if ( $this->is_thin( $rec['existing'] ) ) {
								esc_html_e( 'Rewrite with full copy', 'midland-local-seo' );
							} elseif ( $this->is_mirror_page( $rec['existing'] ) ) {
								esc_html_e( 'Refresh copy + links', 'midland-local-seo' );
							} else {
								esc_html_e( 'Apply Elementor template', 'midland-local-seo' );
							}
							?>
						</button>
					</form>
				<?php else : ?>
					<span class="description"><?php esc_html_e( 'Looks good', 'midland-local-seo' ); ?></span>
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
				<?php
				$cid = (int) $_GET['created']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$via = isset( $_GET['via'] ) ? sanitize_key( wp_unslash( $_GET['via'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					if ( 'engine' === $via ) {
						esc_html_e( 'Location page created via the Real Smart SEO programmatic engine (mfc_location).', 'midland-local-seo' );
					} else {
						esc_html_e( 'Draft created.', 'midland-local-seo' );
					}
					?>
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
				<?php $is_auth = ( false !== stripos( $listing_error, 'not authorized' ) || false !== stripos( $listing_error, 'access denied' ) || false !== stripos( $listing_error, '40301' ) ); ?>
				<div class="notice notice-warning"><p>
					<?php if ( $is_auth ) : ?>
						<strong><?php esc_html_e( 'Live GBP data unavailable.', 'midland-local-seo' ); ?></strong>
						<?php esc_html_e( 'Your DataForSEO plan doesn’t have access to the Maps API this needs. Enable it in your DataForSEO API access — or ignore this, the location-page recommendations below work without it.', 'midland-local-seo' ); ?>
						<a href="https://app.dataforseo.com/api-access" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'DataForSEO API access', 'midland-local-seo' ); ?></a>
					<?php else : ?>
						<?php /* translators: %s: error message */ echo esc_html( sprintf( __( 'GBP data could not be pulled: %s. Recommendations below still work.', 'midland-local-seo' ), $listing_error ) ); ?>
					<?php endif; ?>
				</p></div>
			<?php endif; ?>

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['cats_saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'GBP categories saved.', 'midland-local-seo' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Your GBP Categories', 'midland-local-seo' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Your real Google Business Profile categories, one per line. These drive the Service Page recommendations below, so they match your listing exactly. Pre-filled with your current categories — edit anytime.', 'midland-local-seo' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'mls_save_gmb_categories', '_mls_gmb_cats_nonce' ); ?>
				<textarea name="gmb_categories" rows="8" class="large-text" style="max-width:600px;"><?php echo esc_textarea( implode( "\n", self::get_categories() ) ); ?></textarea>
				<p><button type="submit" name="mls_save_gmb_categories" value="1" class="button"><?php esc_html_e( 'Save categories', 'midland-local-seo' ); ?></button></p>
			</form>

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['bulk_created'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					/* translators: %d: number of pages created */
					echo esc_html( sprintf( _n( '%d service page draft created.', '%d service page drafts created.', (int) $_GET['bulk_created'], 'midland-local-seo' ), (int) $_GET['bulk_created'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					?>
				</p></div>
			<?php endif; ?>

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['bulk_rewritten'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					/* translators: %d: number of pages rewritten */
					echo esc_html( sprintf( _n( '%d thin page rewritten with full copy in your Elementor template.', '%d thin pages rewritten with full copy in your Elementor template.', (int) $_GET['bulk_rewritten'], 'midland-local-seo' ), (int) $_GET['bulk_rewritten'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					?>
				</p></div>
			<?php endif; ?>
			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['rewritten'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php esc_html_e( 'Page rewritten with full copy in your Elementor template.', 'midland-local-seo' ); ?>
					<a href="<?php echo esc_url( get_edit_post_link( (int) $_GET['rewritten'] ) ); ?>"><?php esc_html_e( 'Review it', 'midland-local-seo' ); ?></a> <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				</p></div>
			<?php endif; ?>

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['menu_saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Links menu saved and synced.', 'midland-local-seo' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 12px;">
				<?php wp_nonce_field( 'mls_save_links_menu' ); ?>
				<input type="hidden" name="action" value="mls_save_links_menu">
				<label><strong><?php esc_html_e( 'Add core links to your menu:', 'midland-local-seo' ); ?></strong>
					<select name="mls_links_menu_id">
						<option value="0"><?php esc_html_e( 'None (automatic footer bar only)', 'midland-local-seo' ); ?></option>
						<?php $mls_selected = (int) get_option( 'mls_links_menu_id', 0 ); ?>
						<?php foreach ( wp_get_nav_menus() as $mls_menu ) : ?>
							<option value="<?php echo esc_attr( $mls_menu->term_id ); ?>" <?php selected( $mls_selected, (int) $mls_menu->term_id ); ?>><?php echo esc_html( $mls_menu->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<button type="submit" class="button button-secondary"><?php esc_html_e( 'Save', 'midland-local-seo' ); ?></button>
				<span class="description" style="margin-left:8px;"><?php esc_html_e( 'Adds the 3 core links to the menu you pick and keeps them updated. Your own menu items are never touched.', 'midland-local-seo' ); ?></span>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 12px;">
				<?php wp_nonce_field( 'mls_rewrite_all_thin' ); ?>
				<input type="hidden" name="action" value="mls_rewrite_all_thin">
				<button type="submit" class="button button-primary" onclick="return confirm('Apply your Elementor template to every page missing it? Thin stub pages also get full done-for-you copy; pages with real copy keep their copy.');"><?php esc_html_e( '⚡ Apply template + full copy to all pages', 'midland-local-seo' ); ?></button>
				<span class="description" style="margin-left:8px;"><?php esc_html_e( 'Every page renders in your Elementor template. Stubs get rewritten; real copy is kept.', 'midland-local-seo' ); ?></span>
			</form>

			<h2><?php esc_html_e( 'Service Pages (from GBP categories)', 'midland-local-seo' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 12px;">
				<?php wp_nonce_field( 'mls_create_all_services' ); ?>
				<input type="hidden" name="action" value="mls_create_all_services">
				<button type="submit" class="button button-primary" onclick="return confirm('Create a draft page for every missing service?');"><?php esc_html_e( '⚡ Create all missing service pages', 'midland-local-seo' ); ?></button>
				<span class="description" style="margin-left:8px;"><?php esc_html_e( 'One click. Each renders in your Elementor template when Smart SEO is active.', 'midland-local-seo' ); ?></span>
			</form>
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

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['bulk_loc_created'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					/* translators: %d: number of pages */
					echo esc_html( sprintf( _n( '%d location page draft created.', '%d location page drafts created.', (int) $_GET['bulk_loc_created'], 'midland-local-seo' ), (int) $_GET['bulk_loc_created'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					?>
				</p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Location Pages (from service areas)', 'midland-local-seo' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 12px;">
				<?php wp_nonce_field( 'mls_create_all_locations' ); ?>
				<input type="hidden" name="action" value="mls_create_all_locations">
				<button type="submit" class="button button-primary" onclick="return confirm('Create a location page for every service area?');"><?php esc_html_e( '⚡ Create all location pages', 'midland-local-seo' ); ?></button>
				<span class="description" style="margin-left:8px;"><?php esc_html_e( 'One click. Each builds in your Elementor template via the Smart SEO engine.', 'midland-local-seo' ); ?></span>
			</form>
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
