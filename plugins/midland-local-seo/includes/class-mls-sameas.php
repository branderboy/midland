<?php
/**
 * Identity / sameAs module — LocalBusiness JSON-LD output.
 *
 * @package Midland_Local_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SameAs Identity & LocalBusiness Schema.
 *
 * Turns the business from a string into a Knowledge Graph entity. Outputs
 * LocalBusiness JSON-LD on every page with sameAs URLs, @id, and NAP.
 */
class MLS_SameAs {

	const OPTION = 'mls_identity';

	/**
	 * Singleton instance.
	 *
	 * @var MLS_SameAs|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return MLS_SameAs
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
		add_action( 'admin_menu', array( $this, 'add_menu' ), 12 );
		add_action( 'admin_init', array( $this, 'handle_save' ) );
		add_action( 'wp_head', array( $this, 'output_schema' ), 5 );
	}

	/**
	 * Business defaults (Midland Floors) so the schema is useful out of the box.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'business_name'        => 'Midland Floors',
			'business_type'        => 'CleaningService',
			'business_description' => 'Commercial & residential floor and carpet cleaning serving DC, Maryland, and Northern Virginia.',
			'business_phone'       => '(240) 532-9097',
			'business_email'       => 'support@midlandfloors.com',
			'business_url'         => 'https://midlandfloors.com',
			'logo_url'             => 'https://midlandfloors.com/wp-content/uploads/2026/05/midland-small-logo-16.png',
			'price_range'          => '$$',
			'address_street'       => '',
			'address_city'         => '',
			'address_state'        => '',
			'address_zip'          => '',
			'address_country'      => 'US',
			'service_areas'        => "Washington, DC\nBethesda, MD\nRockville, MD\nSilver Spring, MD\nArlington, VA\nAlexandria, VA\nFairfax, VA",
		);
	}

	/**
	 * Register the submenu.
	 */
	public function add_menu() {
		add_submenu_page(
			MLS_Plugin::MENU_SLUG,
			esc_html__( 'sameAs / Identity', 'midland-local-seo' ),
			esc_html__( 'sameAs / Identity', 'midland-local-seo' ),
			'manage_options',
			'mls-sameas',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Persist the identity option.
	 */
	public function handle_save() {
		if ( ! isset( $_POST['mls_save_sameas'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$nonce = isset( $_POST['_mls_sameas_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_mls_sameas_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mls_save_sameas' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'midland-local-seo' ) );
		}

		$text_fields = array(
			'business_name',
			'business_type',
			'business_description',
			'business_phone',
			'business_email',
			'business_url',
			'address_street',
			'address_city',
			'address_state',
			'address_zip',
			'address_country',
			'price_range',
			'area_served',
			'center_lat',
			'center_lng',
			'gmb_url',
			'facebook_url',
			'instagram_url',
			'linkedin_url',
			'yelp_url',
			'bbb_url',
			'nextdoor_url',
			'youtube_url',
			'homeadvisor_url',
			'thumbtack_url',
			'angi_url',
			'apple_maps_url',
			'bing_places_url',
		);

		$data = array();
		foreach ( $text_fields as $field ) {
			$data[ $field ] = sanitize_text_field( wp_unslash( $_POST[ $field ] ?? '' ) );
		}

		// business_type is a controlled vocabulary — accept only schema.org types we present in the dropdown.
		$allowed_types = array(
			'LocalBusiness',
			'HomeAndConstructionBusiness',
			'HousePainter',
			'RoofingContractor',
			'Plumber',
			'Electrician',
			'HVACBusiness',
			'CleaningService',
			'GeneralContractor',
		);
		if ( ! in_array( $data['business_type'], $allowed_types, true ) ) {
			$data['business_type'] = 'CleaningService';
		}

		// URL fields need esc_url_raw.
		$data['logo_url']     = esc_url_raw( wp_unslash( $_POST['logo_url'] ?? '' ) );
		$data['og_image_url'] = esc_url_raw( wp_unslash( $_POST['og_image_url'] ?? '' ) );
		$data['business_url'] = esc_url_raw( wp_unslash( $_POST['business_url'] ?? '' ) );

		// Service areas — one per line.
		$data['service_areas'] = sanitize_textarea_field( wp_unslash( $_POST['service_areas'] ?? '' ) );

		update_option( self::OPTION, $data );

		wp_safe_redirect( admin_url( 'admin.php?page=mls-sameas&saved=1' ) );
		exit;
	}

	/**
	 * Identity merged with defaults — used by other modules too.
	 */
	public static function get_identity() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		// Only backfill defaults when the option has never been saved, so an
		// intentionally-cleared field isn't silently repopulated.
		if ( empty( $stored ) ) {
			return self::defaults();
		}
		return $stored;
	}

	/**
	 * Build the LocalBusiness schema array. Falls back to the Midland defaults
	 * when nothing has been saved so the schema is useful out of the box.
	 */
	public function build_schema() {
		$d = get_option( self::OPTION, array() );
		if ( ! is_array( $d ) || empty( $d ) ) {
			$d = self::defaults();
		}

		if ( empty( $d['business_name'] ) ) {
			$d['business_name'] = get_bloginfo( 'name' );
		}

		$site_url = ! empty( $d['business_url'] ) ? trailingslashit( $d['business_url'] ) : trailingslashit( home_url() );
		$type     = ! empty( $d['business_type'] ) ? $d['business_type'] : 'LocalBusiness';

		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => $type,
			'@id'      => $site_url . '#business',
			'name'     => $d['business_name'],
			'url'      => $site_url,
		);

		if ( ! empty( $d['business_description'] ) ) {
			$schema['description'] = $d['business_description'];
		}
		if ( ! empty( $d['business_phone'] ) ) {
			$schema['telephone'] = $d['business_phone'];
		}
		if ( ! empty( $d['business_email'] ) ) {
			$schema['email'] = $d['business_email'];
		}
		if ( ! empty( $d['address_street'] ) ) {
			$schema['address'] = array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => $d['address_street'],
				'addressLocality' => $d['address_city'] ?? '',
				'addressRegion'   => $d['address_state'] ?? '',
				'postalCode'      => $d['address_zip'] ?? '',
				'addressCountry'  => ! empty( $d['address_country'] ) ? $d['address_country'] : 'US',
			);
		}
		if ( ! empty( $d['price_range'] ) ) {
			$schema['priceRange'] = $d['price_range'];
		}
		if ( ! empty( $d['logo_url'] ) ) {
			$schema['logo'] = array(
				'@type' => 'ImageObject',
				'url'   => $d['logo_url'],
			);
		}
		if ( ! empty( $d['og_image_url'] ) ) {
			$schema['image'] = $d['og_image_url'];
		}

		// Area served from line-by-line list.
		if ( ! empty( $d['service_areas'] ) ) {
			$areas = array_filter( array_map( 'trim', explode( "\n", $d['service_areas'] ) ) );
			if ( $areas ) {
				$schema['areaServed'] = array_values( $areas );
			}
		} elseif ( ! empty( $d['area_served'] ) ) {
			$schema['areaServed'] = $d['area_served'];
		}

		// Build sameAs array from all non-empty social/citation URLs.
		$sameas_keys = array(
			'gmb_url',
			'facebook_url',
			'instagram_url',
			'linkedin_url',
			'yelp_url',
			'bbb_url',
			'nextdoor_url',
			'youtube_url',
			'homeadvisor_url',
			'thumbtack_url',
			'angi_url',
			'apple_maps_url',
			'bing_places_url',
		);

		$sameas = array();
		foreach ( $sameas_keys as $key ) {
			if ( ! empty( $d[ $key ] ) ) {
				$sameas[] = esc_url( $d[ $key ] );
			}
		}
		if ( $sameas ) {
			$schema['sameAs'] = $sameas;
		}

		return $schema;
	}

	/**
	 * Output JSON-LD in <head>. Runs on wp_head priority 5.
	 */
	public function output_schema() {
		$schema = $this->build_schema();
		if ( empty( $schema['name'] ) ) {
			return;
		}
		// Hex-escape <, >, &, ', " so a stray </script> can't break out of the
		// <script> block — schema values are partly user-generated.
		echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Render the admin page.
	 */
	public function render_page() {
		$defaults = self::defaults();
		$stored   = get_option( self::OPTION, array() );
		$d        = is_array( $stored ) && ! empty( $stored ) ? $stored : $defaults;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$saved = isset( $_GET['saved'] );

		$types = array(
			'LocalBusiness'               => 'LocalBusiness (generic)',
			'HomeAndConstructionBusiness' => 'HomeAndConstructionBusiness',
			'HousePainter'                => 'HousePainter',
			'RoofingContractor'           => 'RoofingContractor',
			'Plumber'                     => 'Plumber',
			'Electrician'                 => 'Electrician',
			'HVACBusiness'                => 'HVACBusiness',
			'CleaningService'             => 'CleaningService (for floor/carpet)',
			'GeneralContractor'           => 'GeneralContractor',
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Business Identity & sameAs', 'midland-local-seo' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Connect your business to Google\'s Knowledge Graph. Every profile URL added here becomes a sameAs signal — Google cross-references them to confirm your entity and boost local rankings.', 'midland-local-seo' ); ?>
			</p>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Business identity saved. Schema is now live in your page <head>.', 'midland-local-seo' ); ?></p></div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'mls_save_sameas', '_mls_sameas_nonce' ); ?>

				<h2><?php esc_html_e( 'Business Info', 'midland-local-seo' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="business_name"><?php esc_html_e( 'Business Name', 'midland-local-seo' ); ?></label></th>
						<td><input type="text" id="business_name" name="business_name" class="regular-text" value="<?php echo esc_attr( $d['business_name'] ?? '' ); ?>"></td>
					</tr>
					<tr>
						<th><label for="business_type"><?php esc_html_e( 'Schema Type', 'midland-local-seo' ); ?></label></th>
						<td>
							<select id="business_type" name="business_type">
								<?php foreach ( $types as $val => $label ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $d['business_type'] ?? 'CleaningService', $val ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Choose the most specific type. For floor/carpet cleaning use CleaningService.', 'midland-local-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="business_description"><?php esc_html_e( 'Description', 'midland-local-seo' ); ?></label></th>
						<td><textarea id="business_description" name="business_description" rows="3" class="large-text"><?php echo esc_textarea( $d['business_description'] ?? '' ); ?></textarea></td>
					</tr>
					<tr>
						<th><label for="business_url"><?php esc_html_e( 'Website URL', 'midland-local-seo' ); ?></label></th>
						<td><input type="url" id="business_url" name="business_url" class="large-text" value="<?php echo esc_attr( $d['business_url'] ?? '' ); ?>" placeholder="https://midlandfloors.com"></td>
					</tr>
					<tr>
						<th><label for="business_phone"><?php esc_html_e( 'Phone', 'midland-local-seo' ); ?></label></th>
						<td><input type="text" id="business_phone" name="business_phone" class="regular-text" value="<?php echo esc_attr( $d['business_phone'] ?? '' ); ?>" placeholder="(240) 532-9097"></td>
					</tr>
					<tr>
						<th><label for="business_email"><?php esc_html_e( 'Email', 'midland-local-seo' ); ?></label></th>
						<td><input type="email" id="business_email" name="business_email" class="regular-text" value="<?php echo esc_attr( $d['business_email'] ?? '' ); ?>"></td>
					</tr>
					<tr>
						<th><label for="price_range"><?php esc_html_e( 'Price Range', 'midland-local-seo' ); ?></label></th>
						<td><input type="text" id="price_range" name="price_range" style="width:80px;" value="<?php echo esc_attr( $d['price_range'] ?? '$$' ); ?>" placeholder="$$"></td>
					</tr>
					<tr>
						<th><label for="logo_url"><?php esc_html_e( 'Logo URL', 'midland-local-seo' ); ?></label></th>
						<td><input type="url" id="logo_url" name="logo_url" class="large-text" value="<?php echo esc_attr( $d['logo_url'] ?? '' ); ?>"></td>
					</tr>
					<tr>
						<th><label for="og_image_url"><?php esc_html_e( 'OG Image URL (1200×630)', 'midland-local-seo' ); ?></label></th>
						<td><input type="url" id="og_image_url" name="og_image_url" class="large-text" value="<?php echo esc_attr( $d['og_image_url'] ?? '' ); ?>"></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Address (NAP)', 'midland-local-seo' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Must match your Google Business Profile exactly — Name, Address, Phone consistency is a core local SEO signal.', 'midland-local-seo' ); ?></p>
				<table class="form-table">
					<tr>
						<th><label for="address_street"><?php esc_html_e( 'Street Address', 'midland-local-seo' ); ?></label></th>
						<td><input type="text" id="address_street" name="address_street" class="regular-text" value="<?php echo esc_attr( $d['address_street'] ?? '' ); ?>"></td>
					</tr>
					<tr>
						<th><label for="address_city"><?php esc_html_e( 'City', 'midland-local-seo' ); ?></label></th>
						<td><input type="text" id="address_city" name="address_city" class="regular-text" value="<?php echo esc_attr( $d['address_city'] ?? '' ); ?>"></td>
					</tr>
					<tr>
						<th><label for="address_state"><?php esc_html_e( 'State', 'midland-local-seo' ); ?></label></th>
						<td><input type="text" id="address_state" name="address_state" style="width:80px;" value="<?php echo esc_attr( $d['address_state'] ?? '' ); ?>" placeholder="MD"></td>
					</tr>
					<tr>
						<th><label for="address_zip"><?php esc_html_e( 'ZIP Code', 'midland-local-seo' ); ?></label></th>
						<td><input type="text" id="address_zip" name="address_zip" style="width:100px;" value="<?php echo esc_attr( $d['address_zip'] ?? '' ); ?>"></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Service Areas', 'midland-local-seo' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="service_areas"><?php esc_html_e( 'Service Areas (one per line)', 'midland-local-seo' ); ?></label></th>
						<td>
							<textarea id="service_areas" name="service_areas" rows="8" class="large-text" placeholder="Bethesda, MD&#10;Rockville, MD&#10;Silver Spring, MD&#10;Washington, DC"><?php echo esc_textarea( $d['service_areas'] ?? '' ); ?></textarea>
							<p class="description"><?php esc_html_e( 'These populate the areaServed schema field and appear in AI Overview citations.', 'midland-local-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'sameAs Profiles', 'midland-local-seo' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Add every claimed/verified profile URL. Google cross-references these to verify your business entity and consolidate authority across platforms.', 'midland-local-seo' ); ?></p>
				<table class="form-table">
					<?php
					$profiles = array(
						'gmb_url'         => array( 'Google Business Profile', 'https://g.co/kgs/...' ),
						'apple_maps_url'  => array( 'Apple Maps (Business Connect)', 'https://maps.apple.com/...' ),
						'bing_places_url' => array( 'Bing Places', 'https://www.bing.com/maps?...' ),
						'facebook_url'    => array( 'Facebook Page', 'https://facebook.com/...' ),
						'instagram_url'   => array( 'Instagram', 'https://instagram.com/...' ),
						'linkedin_url'    => array( 'LinkedIn Company', 'https://linkedin.com/company/...' ),
						'yelp_url'        => array( 'Yelp', 'https://yelp.com/biz/...' ),
						'bbb_url'         => array( 'Better Business Bureau', 'https://bbb.org/...' ),
						'nextdoor_url'    => array( 'Nextdoor Business', 'https://nextdoor.com/...' ),
						'youtube_url'     => array( 'YouTube Channel', 'https://youtube.com/@...' ),
						'homeadvisor_url' => array( 'HomeAdvisor / Angi Leads', 'https://homeadvisor.com/...' ),
						'angi_url'        => array( 'Angi', 'https://angi.com/...' ),
						'thumbtack_url'   => array( 'Thumbtack', 'https://thumbtack.com/...' ),
					);
					foreach ( $profiles as $key => $info ) :
						?>
						<tr>
							<th><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $info[0] ); ?></label></th>
							<td><input type="url" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" class="large-text" value="<?php echo esc_attr( $d[ $key ] ?? '' ); ?>" placeholder="<?php echo esc_attr( $info[1] ); ?>"></td>
						</tr>
					<?php endforeach; ?>
				</table>

				<p class="submit">
					<button type="submit" name="mls_save_sameas" value="1" class="button button-primary"><?php esc_html_e( 'Save & Publish Schema', 'midland-local-seo' ); ?></button>
				</p>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Live Schema Preview', 'midland-local-seo' ); ?></h2>
			<pre style="background:#1e1e1e;color:#d4d4d4;padding:16px;overflow:auto;border-radius:4px;font-size:12px;"><?php echo esc_html( wp_json_encode( $this->build_schema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
		</div>
		<?php
	}
}

MLS_SameAs::get_instance();
