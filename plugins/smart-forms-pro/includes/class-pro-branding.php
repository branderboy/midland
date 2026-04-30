<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFCO_Pro_Branding {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ), 35 );
        add_action( 'admin_init', array( $this, 'handle_save' ) );
        add_action( 'wp_head', array( $this, 'inject_custom_css' ) );
    }

    public function add_menu() {
        add_submenu_page(
            'sfco-forms',
            esc_html__( 'Branding', 'smart-forms-pro' ),
            esc_html__( 'Branding', 'smart-forms-pro' ),
            'manage_options',
            'sfco-branding',
            array( $this, 'render_page' )
        );
    }

    public function handle_save() {
        if ( ! isset( $_POST['sfco_save_branding'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_POST['_sfco_brand_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_sfco_brand_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'sfco_save_branding' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'smart-forms-pro' ) );
        }

        $branding = array(
            'primary_color'   => isset( $_POST['brand_primary'] ) ? sanitize_hex_color( $_POST['brand_primary'] ) : '#0073aa',
            'button_color'    => isset( $_POST['brand_button'] ) ? sanitize_hex_color( $_POST['brand_button'] ) : '#0073aa',
            'text_color'      => isset( $_POST['brand_text'] ) ? sanitize_hex_color( $_POST['brand_text'] ) : '#333333',
            'logo_url'        => isset( $_POST['brand_logo'] ) ? esc_url_raw( wp_unslash( $_POST['brand_logo'] ) ) : '',
            'company_name'    => isset( $_POST['brand_company'] ) ? sanitize_text_field( wp_unslash( $_POST['brand_company'] ) ) : '',
            'custom_css'      => isset( $_POST['brand_css'] ) ? wp_strip_all_tags( wp_unslash( $_POST['brand_css'] ) ) : '',
        );

        update_option( 'sfco_pro_branding', $branding );
        wp_safe_redirect( admin_url( 'admin.php?page=sfco-branding&saved=1' ) );
        exit;
    }

    /**
     * Inject custom brand colors into frontend forms.
     */
    public function inject_custom_css() {
        if ( ! SFCO_Pro_License::is_valid() ) {
            return;
        }

        $branding = get_option( 'sfco_pro_branding', array() );
        if ( empty( $branding ) ) {
            return;
        }

        $css = '';

        if ( ! empty( $branding['primary_color'] ) ) {
            $css .= ".smart-forms-form h2 { color: {$branding['primary_color']}; }";
        }
        if ( ! empty( $branding['button_color'] ) ) {
            $css .= ".smart-forms-form .submit-button { background: {$branding['button_color']}; }";
            $css .= ".smart-forms-form .submit-button:hover { opacity: 0.9; }";
        }
        if ( ! empty( $branding['text_color'] ) ) {
            $css .= ".smart-forms-form label { color: {$branding['text_color']}; }";
        }
        if ( ! empty( $branding['custom_css'] ) ) {
            $css .= $branding['custom_css'];
        }

        if ( $css ) {
            echo '<style id="sfco-pro-branding">' . wp_strip_all_tags( $css ) . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS is sanitized via wp_strip_all_tags and sanitize_hex_color.
        }
    }

    public function render_page() {
        if ( ! SFCO_Pro_License::is_valid() ) {
            echo '<div class="wrap"><div class="notice notice-warning"><p>' . esc_html__( 'Please activate your PRO license.', 'smart-forms-pro' ) . '</p></div></div>';
            return;
        }

        $branding = get_option( 'sfco_pro_branding', array() );
        $primary  = $branding['primary_color'] ?? '#0073aa';
        $button   = $branding['button_color'] ?? '#0073aa';
        $text     = $branding['text_color'] ?? '#333333';
        $logo     = $branding['logo_url'] ?? '';
        $company  = $branding['company_name'] ?? '';
        $css      = $branding['custom_css'] ?? '';

        wp_enqueue_media();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Custom Branding', 'smart-forms-pro' ); ?></h1>

            <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Branding saved.', 'smart-forms-pro' ); ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'sfco_save_branding', '_sfco_brand_nonce' ); ?>

                <table class="form-table">
                    <tr>
                        <th><label for="brand_company"><?php esc_html_e( 'Company Name', 'smart-forms-pro' ); ?></label></th>
                        <td><input type="text" name="brand_company" id="brand_company" class="regular-text" value="<?php echo esc_attr( $company ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="brand_logo"><?php esc_html_e( 'Logo URL', 'smart-forms-pro' ); ?></label></th>
                        <td>
                            <input type="url" name="brand_logo" id="brand_logo" class="regular-text" value="<?php echo esc_attr( $logo ); ?>">
                            <button type="button" class="button" id="sfco-upload-logo"><?php esc_html_e( 'Upload', 'smart-forms-pro' ); ?></button>
                            <?php if ( $logo ) : ?>
                                <br><img src="<?php echo esc_url( $logo ); ?>" alt="" style="max-height:60px;margin-top:8px;">
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="brand_primary"><?php esc_html_e( 'Primary Color', 'smart-forms-pro' ); ?></label></th>
                        <td><input type="color" name="brand_primary" id="brand_primary" value="<?php echo esc_attr( $primary ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="brand_button"><?php esc_html_e( 'Button Color', 'smart-forms-pro' ); ?></label></th>
                        <td><input type="color" name="brand_button" id="brand_button" value="<?php echo esc_attr( $button ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="brand_text"><?php esc_html_e( 'Text Color', 'smart-forms-pro' ); ?></label></th>
                        <td><input type="color" name="brand_text" id="brand_text" value="<?php echo esc_attr( $text ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="brand_css"><?php esc_html_e( 'Custom CSS', 'smart-forms-pro' ); ?></label></th>
                        <td>
                            <textarea name="brand_css" id="brand_css" class="large-text code" rows="6"><?php echo esc_textarea( $css ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Advanced: add custom CSS for your forms.', 'smart-forms-pro' ); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="sfco_save_branding" value="1" class="button button-primary"><?php esc_html_e( 'Save Branding', 'smart-forms-pro' ); ?></button>
                </p>
            </form>
        </div>
        <?php
    }
}

new SFCO_Pro_Branding();
