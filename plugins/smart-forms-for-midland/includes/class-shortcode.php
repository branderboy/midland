<?php
/**
 * Shortcode handler
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFCO_Shortcode {
    
    public function __construct() {
        add_shortcode( 'sfco_quote', array( $this, 'render_form' ) );
    }
    
    public function render_form( $atts ) {
        $atts = shortcode_atts( array(
            'title' => esc_html__( 'Request a Quote', 'smart-forms-for-midland' ),
        ), $atts );
        
        ob_start();
        ?>
        <div class="smart-forms-wrapper">
            <form id="smart-forms-quote-form" class="smart-forms-form" enctype="multipart/form-data">
                <?php wp_nonce_field( 'sfco_submit', '_wpnonce', false ); ?>
                
                <h2><?php echo esc_html( $atts['title'] ); ?></h2>
                
                <div class="form-row">
                    <label for="customer_name"><?php esc_html_e( 'Name', 'smart-forms-for-midland' ); ?> <span class="required">*</span></label>
                    <input type="text" name="customer_name" id="customer_name" required>
                </div>
                
                <div class="form-row">
                    <label for="customer_email"><?php esc_html_e( 'Email', 'smart-forms-for-midland' ); ?> <span class="required">*</span></label>
                    <input type="email" name="customer_email" id="customer_email" required>
                </div>
                
                <div class="form-row">
                    <label for="customer_phone"><?php esc_html_e( 'Phone', 'smart-forms-for-midland' ); ?> <span class="required">*</span></label>
                    <input type="tel" name="customer_phone" id="customer_phone" required>
                </div>
                
                <div class="form-row">
                    <label for="project_type"><?php esc_html_e( 'Project Type', 'smart-forms-for-midland' ); ?> <span class="required">*</span></label>
                    <select name="project_type" id="project_type" required>
                        <option value=""><?php esc_html_e( 'Select...', 'smart-forms-for-midland' ); ?></option>
                        <option value="Residential Carpet Cleaning"><?php esc_html_e( 'Residential Carpet Cleaning', 'smart-forms-for-midland' ); ?></option>
                        <option value="Commercial Carpet Care"><?php esc_html_e( 'Commercial Carpet Care', 'smart-forms-for-midland' ); ?></option>
                        <option value="Commercial Floor Stripping & Wax"><?php esc_html_e( 'Commercial Floor Stripping & Wax', 'smart-forms-for-midland' ); ?></option>
                        <option value="Tile & Grout Cleaning"><?php esc_html_e( 'Tile & Grout Cleaning', 'smart-forms-for-midland' ); ?></option>
                        <option value="Water Damage Restoration"><?php esc_html_e( 'Water Damage Restoration', 'smart-forms-for-midland' ); ?></option>
                        <option value="Concrete Polishing"><?php esc_html_e( 'Concrete Polishing', 'smart-forms-for-midland' ); ?></option>
                    </select>
                </div>
                
                <div class="form-row">
                    <label for="square_footage"><?php esc_html_e( 'Square Footage', 'smart-forms-for-midland' ); ?></label>
                    <input type="number" name="square_footage" id="square_footage" min="1">
                </div>
                
                <div class="form-row">
                    <label for="timeline"><?php esc_html_e( 'Timeline', 'smart-forms-for-midland' ); ?></label>
                    <select name="timeline" id="timeline">
                        <option value=""><?php esc_html_e( 'Select...', 'smart-forms-for-midland' ); ?></option>
                        <option value="ASAP"><?php esc_html_e( 'ASAP', 'smart-forms-for-midland' ); ?></option>
                        <option value="This Week"><?php esc_html_e( 'This Week', 'smart-forms-for-midland' ); ?></option>
                        <option value="Within 2 Weeks"><?php esc_html_e( 'Within 2 Weeks', 'smart-forms-for-midland' ); ?></option>
                        <option value="Next Month"><?php esc_html_e( 'Next Month', 'smart-forms-for-midland' ); ?></option>
                    </select>
                </div>
                
                <div class="form-row">
                    <label for="zip_code"><?php esc_html_e( 'ZIP Code', 'smart-forms-for-midland' ); ?></label>
                    <input type="text" name="zip_code" id="zip_code" maxlength="10">
                </div>
                
                <div class="form-row">
                    <label for="photos"><?php esc_html_e( 'Photos (up to 5, max 5MB each)', 'smart-forms-for-midland' ); ?></label>
                    <input type="file" name="photos[]" id="photos" accept="image/*" multiple>
                </div>
                
                <div class="form-row">
                    <label for="additional_notes"><?php esc_html_e( 'Additional Notes', 'smart-forms-for-midland' ); ?></label>
                    <textarea name="additional_notes" id="additional_notes" rows="4"></textarea>
                </div>
                
                <div class="form-row">
                    <button type="submit" class="submit-button"><?php esc_html_e( 'Get Quote', 'smart-forms-for-midland' ); ?></button>
                </div>
                
                <div id="form-message" class="form-message"></div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
}

new SFCO_Shortcode();
