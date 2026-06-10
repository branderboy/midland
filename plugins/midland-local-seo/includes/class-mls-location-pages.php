<?php
/**
 * Location Pages (self-contained).
 *
 * Generates city and city-by-service pages by CLONING a template page the
 * operator already designed in Elementor (or the block/classic editor), then
 * swapping placeholders. Because it copies your real page, every generated page
 * matches your site design exactly. No dependency on any other plugin.
 *
 * Template placeholders (use these in the template page's content and Elementor
 * text): [CITY] [STATE] [CITYSTATE] [SERVICE] [BUSINESS] [PHONE]
 *
 * @package Midland_Local_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Location pages module.
 */
class MLS_Location_Pages {

	const TEMPLATE_OPTION = 'mls_locpages_template';

	/**
	 * Singleton instance.
	 *
	 * @var MLS_Location_Pages|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return MLS_Location_Pages
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
		add_action( 'admin_post_mls_save_locpages_template', array( $this, 'handle_save_template' ) );
		add_action( 'admin_post_mls_generate_locpages', array( $this, 'handle_generate' ) );
	}

	/**
	 * Register the submenu.
	 */
	public function add_menu() {
		add_submenu_page(
			MLS_Plugin::MENU_SLUG,
			__( 'Location Pages', 'midland-local-seo' ),
			__( 'Location Pages', 'midland-local-seo' ),
			'manage_options',
			'mls-location-pages',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Service areas from the Profile, each as { city, state, label }.
	 *
	 * @return array
	 */
	private function areas() {
		$identity = class_exists( 'MLS_SameAs' ) ? MLS_SameAs::get_identity() : array();
		$raw      = isset( $identity['service_areas'] ) ? (string) $identity['service_areas'] : '';
		$out      = array();
		foreach ( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw ) ) ) as $line ) {
			$parts = array_map( 'trim', explode( ',', $line, 2 ) );
			$out[] = array(
				'city'  => isset( $parts[0] ) ? $parts[0] : $line,
				'state' => isset( $parts[1] ) ? $parts[1] : '',
				'label' => $line,
			);
		}
		return $out;
	}

	/**
	 * Services from the GMB Mirror category list (falls back to a sensible set).
	 *
	 * @return array
	 */
	private function services() {
		if ( class_exists( 'MLS_GMB_Mirror' ) && method_exists( 'MLS_GMB_Mirror', 'get_categories' ) ) {
			return MLS_GMB_Mirror::get_categories();
		}
		return array( 'Carpet Cleaning', 'Hardwood Floor Cleaning', 'Tile Cleaning' );
	}

	/**
	 * Save the chosen template page.
	 */
	public function handle_save_template() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'midland-local-seo' ) );
		}
		check_admin_referer( 'mls_save_locpages_template' );
		$id = isset( $_POST['template_id'] ) ? (int) $_POST['template_id'] : 0;
		update_option( self::TEMPLATE_OPTION, $id );
		wp_safe_redirect( admin_url( 'admin.php?page=mls-location-pages&saved=1' ) );
		exit;
	}

	/**
	 * Generate pages by cloning the template and swapping placeholders.
	 */
	public function handle_generate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'midland-local-seo' ) );
		}
		check_admin_referer( 'mls_generate_locpages' );

		$template_id = (int) get_option( self::TEMPLATE_OPTION, 0 );
		$mode        = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'city';
		$service_sel = isset( $_POST['service'] ) ? sanitize_text_field( wp_unslash( $_POST['service'] ) ) : '';

		$template = $template_id ? get_post( $template_id ) : null;
		if ( ! $template ) {
			wp_safe_redirect( admin_url( 'admin.php?page=mls-location-pages&error=notemplate' ) );
			exit;
		}

		$identity = class_exists( 'MLS_SameAs' ) ? MLS_SameAs::get_identity() : array();
		$business = ! empty( $identity['business_name'] ) ? $identity['business_name'] : get_bloginfo( 'name' );
		$phone    = ! empty( $identity['business_phone'] ) ? $identity['business_phone'] : '';

		$el_data  = get_post_meta( $template_id, '_elementor_data', true );
		$has_el   = is_string( $el_data ) && '' !== $el_data;

		$created = 0;
		foreach ( $this->areas() as $area ) {
			// In city x service mode, one page per selected service; else one per city.
			$service   = ( 'service' === $mode && '' !== $service_sel ) ? $service_sel : '';
			$citystate = trim( $area['city'] . ( '' !== $area['state'] ? ', ' . $area['state'] : '' ) );
			$title     = '' !== $service
				? $service . ' in ' . $citystate
				: $business . ' in ' . $citystate;

			$slug = sanitize_title( $title );
			if ( get_page_by_path( $slug, OBJECT, 'page' ) ) {
				continue; // already exists.
			}

			$map = array(
				'[CITY]'      => $area['city'],
				'[STATE]'     => $area['state'],
				'[CITYSTATE]' => $citystate,
				'[SERVICE]'   => '' !== $service ? $service : 'Floor Care',
				'[BUSINESS]'  => $business,
				'[PHONE]'     => $phone,
			);
			$find    = array_keys( $map );
			$replace = array_values( $map );

			$content = str_replace( $find, $replace, (string) $template->post_content );

			$new_id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_title'   => $title,
					'post_name'    => $slug,
					'post_status'  => 'draft',
					'post_content' => $content,
					'post_author'  => get_current_user_id(),
				),
				true
			);
			if ( is_wp_error( $new_id ) || ! $new_id ) {
				continue;
			}

			// Clone the Elementor layout (with placeholders swapped) so the page
			// is identical to your designed template.
			if ( $has_el ) {
				$new_el = str_replace( $find, $replace, $el_data );
				update_post_meta( $new_id, '_elementor_data', wp_slash( $new_el ) );
				update_post_meta( $new_id, '_elementor_edit_mode', 'builder' );
				update_post_meta( $new_id, '_elementor_template_type', 'wp-page' );
				$ver = get_post_meta( $template_id, '_elementor_version', true );
				update_post_meta( $new_id, '_elementor_version', $ver ? $ver : '3.21.0' );
				$tpl = get_post_meta( $template_id, '_wp_page_template', true );
				if ( $tpl ) {
					update_post_meta( $new_id, '_wp_page_template', $tpl );
				}
			} else {
				$page_tpl = get_post_meta( $template_id, '_wp_page_template', true );
				if ( $page_tpl ) {
					update_post_meta( $new_id, '_wp_page_template', $page_tpl );
				}
			}
			++$created;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=mls-location-pages&generated=' . (int) $created ) );
		exit;
	}

	/**
	 * Render the admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'midland-local-seo' ) );
		}
		$template_id = (int) get_option( self::TEMPLATE_OPTION, 0 );
		$template    = $template_id ? get_post( $template_id ) : null;
		$has_el      = $template && '' !== get_post_meta( $template_id, '_elementor_data', true );
		$pages       = get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => array( 'publish', 'draft', 'private' ),
				'numberposts' => 200,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Location Pages', 'midland-local-seo' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Clone a page you already designed and spin up one page per service area. The generated pages copy your exact layout (including Elementor), so they match your site. No other plugin required.', 'midland-local-seo' ); ?></p>

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Template page saved.', 'midland-local-seo' ); ?></p></div>
			<?php endif; ?>
			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php if ( isset( $_GET['generated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					/* translators: %d: number of pages */
					echo esc_html( sprintf( _n( '%d location page draft created (cloned from your template).', '%d location page drafts created (cloned from your template).', (int) $_GET['generated'], 'midland-local-seo' ), (int) $_GET['generated'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					?>
				</p></div>
			<?php endif; ?>
			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php if ( isset( $_GET['error'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Pick a template page first.', 'midland-local-seo' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Step 1 — Choose your template page', 'midland-local-seo' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Design one page exactly how you want it (in Elementor or the editor) and use these placeholders where the location/service should change:', 'midland-local-seo' ); ?>
				<code>[CITY]</code> <code>[STATE]</code> <code>[CITYSTATE]</code> <code>[SERVICE]</code> <code>[BUSINESS]</code> <code>[PHONE]</code>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'mls_save_locpages_template' ); ?>
				<input type="hidden" name="action" value="mls_save_locpages_template">
				<select name="template_id">
					<option value="0"><?php esc_html_e( '— Select a page —', 'midland-local-seo' ); ?></option>
					<?php foreach ( $pages as $p ) : ?>
						<option value="<?php echo esc_attr( $p->ID ); ?>" <?php selected( $template_id, $p->ID ); ?>><?php echo esc_html( $p->post_title ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Save template', 'midland-local-seo' ); ?></button>
			</form>

			<?php if ( $template ) : ?>
				<p>
					<?php
					/* translators: %s: template page title */
					echo esc_html( sprintf( __( 'Template: %s', 'midland-local-seo' ), $template->post_title ) );
					echo $has_el ? ' <strong style="color:#1e7e34;">(Elementor layout will be cloned)</strong>' : ' <em>(editor content will be cloned)</em>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
					<a href="<?php echo esc_url( get_edit_post_link( $template_id ) ); ?>"><?php esc_html_e( 'Edit template', 'midland-local-seo' ); ?></a>
				</p>

				<h2><?php esc_html_e( 'Step 2 — Generate', 'midland-local-seo' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'mls_generate_locpages' ); ?>
					<input type="hidden" name="action" value="mls_generate_locpages">
					<p>
						<label><input type="radio" name="mode" value="city" checked> <?php esc_html_e( 'One page per service area', 'midland-local-seo' ); ?></label><br>
						<label><input type="radio" name="mode" value="service"> <?php esc_html_e( 'One page per service area, for this service:', 'midland-local-seo' ); ?></label>
						<select name="service">
							<?php foreach ( $this->services() as $svc ) : ?>
								<option value="<?php echo esc_attr( $svc ); ?>"><?php echo esc_html( $svc ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p class="description">
						<?php
						$count = count( $this->areas() );
						/* translators: %d: number of service areas */
						echo esc_html( sprintf( _n( 'Will create up to %d page (one per service area). Existing pages are skipped.', 'Will create up to %d pages (one per service area). Existing pages are skipped.', $count, 'midland-local-seo' ), $count ) );
						?>
					</p>
					<button type="submit" class="button button-primary" onclick="return confirm('Generate location pages by cloning your template?');"><?php esc_html_e( '⚡ Generate location pages', 'midland-local-seo' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}

MLS_Location_Pages::get_instance();
