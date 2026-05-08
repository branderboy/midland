<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * sameAs Identity & LocalBusiness Schema.
 * Turns the business from a string into a Knowledge Graph entity.
 * Outputs LocalBusiness JSON-LD on every page with sameAs URLs, @id, and NAP.
 */
class RSSEO_Pro_SameAs {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ), 25 );
        add_action( 'admin_init', array( $this, 'handle_save' ) );
        add_action( 'wp_head',    array( $this, 'output_schema' ), 5 );
    }

    public function add_menu() {
        add_submenu_page(
            'rsseo-pro',
            esc_html__( 'Business Identity', 'real-smart-seo-pro' ),
            esc_html__( 'Business Identity', 'real-smart-seo-pro' ),
            'manage_options',
            'rsseo-sameas',
            array( $this, 'render_page' )
        );
    }

    public function handle_save() {
        if ( ! isset( $_POST['rsseo_save_sameas'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_POST['_rsseo_sameas_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_rsseo_sameas_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'rsseo_save_sameas' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'real-smart-seo-pro' ) );
        }

        $text_fields = array(
            'business_name', 'business_type', 'business_description',
            'business_phone', 'business_email',
            'address_street', 'address_city', 'address_state',
            'address_zip', 'address_country',
            'price_range', 'area_served',
            'gmb_url', 'facebook_url', 'instagram_url', 'linkedin_url',
            'yelp_url', 'bbb_url', 'nextdoor_url', 'youtube_url',
            'homeadvisor_url', 'thumbtack_url', 'angi_url',
        );

        $data = array();
        foreach ( $text_fields as $field ) {
            $data[ $field ] = sanitize_text_field( wp_unslash( $_POST[ $field ] ?? '' ) );
        }

        // business_type is a controlled vocabulary — accept only schema.org types we present in the dropdown.
        $allowed_types = array(
            'LocalBusiness', 'HomeAndConstructionBusiness', 'HousePainter',
            'RoofingContractor', 'Plumber', 'Electrician', 'HVACBusiness',
            'CleaningService', 'GeneralContractor',
        );
        if ( ! in_array( $data['business_type'], $allowed_types, true ) ) {
            $data['business_type'] = 'LocalBusiness';
        }

        // Logo URL needs esc_url_raw.
        $data['logo_url']    = esc_url_raw( wp_unslash( $_POST['logo_url'] ?? '' ) );
        $data['og_image_url'] = esc_url_raw( wp_unslash( $_POST['og_image_url'] ?? '' ) );

        // Service areas — one per line.
        $data['service_areas'] = sanitize_textarea_field( wp_unslash( $_POST['service_areas'] ?? '' ) );

        update_option( 'rsseo_sameas_identity', $data );

        wp_safe_redirect( admin_url( 'admin.php?page=rsseo-sameas&saved=1' ) );
        exit;
    }

    /**
     * Build the LocalBusiness schema array.
     */
    public function build_schema() {
        $d = get_option( 'rsseo_sameas_identity', array() );

        if ( empty( $d['business_name'] ) ) {
            $d['business_name'] = get_bloginfo( 'name' );
        }

        $site_url = trailingslashit( home_url() );
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
                'addressCountry'  => $d['address_country'] ?: 'US',
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
            'gmb_url', 'facebook_url', 'instagram_url', 'linkedin_url',
            'yelp_url', 'bbb_url', 'nextdoor_url', 'youtube_url',
            'homeadvisor_url', 'thumbtack_url', 'angi_url',
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
     * Output JSON-LD in <head>. Runs on wp_head priority 5 (before other head content).
     */
    public function output_schema() {
        $schema = $this->build_schema();
        if ( empty( $schema['name'] ) ) {
            return;
        }
        echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function render_page() {
        $d = get_option( 'rsseo_sameas_identity', array() );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $saved = isset( $_GET['saved'] );

        $types = array(
            'LocalBusiness'      => 'LocalBusiness (generic)',
            'HomeAndConstructionBusiness' => 'HomeAndConstructionBusiness',
            'HousePainter'       => 'HousePainter',
            'RoofingContractor'  => 'RoofingContractor',
            'Plumber'            => 'Plumber',
            'Electrician'        => 'Electrician',
            'HVACBusiness'       => 'HVACBusiness',
            'CleaningService'    => 'CleaningService (for floor/carpet)',
            'GeneralContractor'  => 'GeneralContractor',
        );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Business Identity & sameAs', 'real-smart-seo-pro' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Connect your business to Google\'s Knowledge Graph. Every profile URL added here becomes a sameAs signal — Google cross-references them to confirm your entity and boost local rankings.', 'real-smart-seo-pro' ); ?>
            </p>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Business identity saved. Schema is now live in your page <head>.', 'real-smart-seo-pro' ); ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'rsseo_save_sameas', '_rsseo_sameas_nonce' ); ?>

                <h2><?php esc_html_e( 'Business Info', 'real-smart-seo-pro' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="business_name"><?php esc_html_e( 'Business Name', 'real-smart-seo-pro' ); ?></label></th>
                        <td><input type="text" id="business_name" name="business_name" class="regular-text" value="<?php echo esc_attr( $d['business_name'] ?? '' ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="business_type"><?php esc_html_e( 'Schema Type', 'real-smart-seo-pro' ); ?></label></th>
                        <td>
                            <select id="business_type" name="business_type">
                                <?php foreach ( $types as $val => $label ) : ?>
                                    <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $d['business_type'] ?? 'LocalBusiness', $val ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'Choose the most specific type. For floor/carpet cleaning use CleaningService.', 'real-smart-seo-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="business_description"><?php esc_html_e( 'Description', 'real-smart-seo-pro' ); ?></label></th>
                        <td><textarea id="business_description" name="business_description" rows="3" class="large-text"><?php echo esc_textarea( $d['business_description'] ?? '' ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label for="business_phone"><?php esc_html_e( 'Phone', 'real-smart-seo-pro' ); ?></label></th>
                        <td><input type="text" id="business_phone" name="business_phone" class="regular-text" value="<?php echo esc_attr( $d['business_phone'] ?? '' ); ?>" placeholder="+1-301-555-0100"></td>
                    </tr>
                    <tr>
                        <th><label for="business_email"><?php esc_html_e( 'Email', 'real-smart-seo-pro' ); ?></label></th>
                        <td><input type="email" id="business_email" name="business_email" class="regular-text" value="<?php echo esc_attr( $d['business_email'] ?? '' ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="price_range"><?php esc_html_e( 'Price Range', 'real-smart-seo-pro' ); ?></label></th>
                        <td><input type="text" id="price_range" name="price_range" style="width:80px;" value="<?php echo esc_attr( $d['price_range'] ?? '$$' ); ?>" placeholder="$$"></td>
                    </tr>
                    <tr>
                        <th><label for="logo_url"><?php esc_html_e( 'Logo URL', 'real-smart-seo-pro' ); ?></label></th>
                        <td><input type="url" id="logo_url" name="logo_url" class="large-text" value="<?php echo esc_attr( $d['logo_url'] ?? '' ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="og_image_url"><?php esc_html_e( 'OG Image URL (1200×630)', 'real-smart-seo-pro' ); ?></label></th>
                        <td><input type="url" id="og_image_url" name="og_image_url" class="large-text" value="<?php echo esc_attr( $d['og_image_url'] ?? '' ); ?>"></td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Address (NAP)', 'real-smart-seo-pro' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Must match your Google Business Profile exactly — Name, Address, Phone consistency is a core local SEO signal.', 'real-smart-seo-pro' ); ?></p>
                <table class="form-table">
                    <tr>
                        <th><label for="address_street"><?php esc_html_e( 'Street Address', 'real-smart-seo-pro' ); ?></label></th>
                        <td><input type="text" id="address_street" name="address_street" class="regular-text" value="<?php echo esc_attr( $d['address_street'] ?? '' ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="address_city"><?php esc_html_e( 'City', 'real-smart-seo-pro' ); ?></label></th>
                        <td><input type="text" id="address_city" name="address_city" class="regular-text" value="<?php echo esc_attr( $d['address_city'] ?? '' ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="address_state"><?php esc_html_e( 'State', 'real-smart-seo-pro' ); ?></label></th>
                        <td><input type="text" id="address_state" name="address_state" style="width:80px;" value="<?php echo esc_attr( $d['address_state'] ?? '' ); ?>" placeholder="MD"></td>
                    </tr>
                    <tr>
                        <th><label for="address_zip"><?php esc_html_e( 'ZIP Code', 'real-smart-seo-pro' ); ?></label></th>
                        <td><input type="text" id="address_zip" name="address_zip" style="width:100px;" value="<?php echo esc_attr( $d['address_zip'] ?? '' ); ?>"></td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Service Areas', 'real-smart-seo-pro' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="service_areas"><?php esc_html_e( 'Service Areas (one per line)', 'real-smart-seo-pro' ); ?></label></th>
                        <td>
                            <textarea id="service_areas" name="service_areas" rows="8" class="large-text" placeholder="Bethesda, MD&#10;Rockville, MD&#10;Silver Spring, MD&#10;Washington, DC"><?php echo esc_textarea( $d['service_areas'] ?? '' ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'These populate the areaServed schema field and appear in AI Overview citations.', 'real-smart-seo-pro' ); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'sameAs Profiles', 'real-smart-seo-pro' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Add every claimed/verified profile URL. Google cross-references these to verify your business entity and consolidate authority across platforms.', 'real-smart-seo-pro' ); ?></p>
                <table class="form-table">
                    <?php
                    $profiles = array(
                        'gmb_url'         => array( 'Google Business Profile', 'https://g.co/kgs/...' ),
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
                    <button type="submit" name="rsseo_save_sameas" value="1" class="button button-primary"><?php esc_html_e( 'Save & Publish Schema', 'real-smart-seo-pro' ); ?></button>
                </p>
            </form>

            <?php if ( get_option( 'rsseo_sameas_identity' ) ) : ?>
            <hr>
            <h2><?php esc_html_e( 'Live Schema Preview', 'real-smart-seo-pro' ); ?></h2>
            <pre style="background:#1e1e1e;color:#d4d4d4;padding:16px;overflow:auto;border-radius:4px;font-size:12px;"><?php echo esc_html( wp_json_encode( $this->build_schema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
            <?php endif; ?>
        </div>
        <?php
    }
}

RSSEO_Pro_SameAs::get_instance();
