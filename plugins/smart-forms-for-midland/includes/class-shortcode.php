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
        // One universal job application form, intentionally separate from the
        // quote form so the operator can drop it on a /careers/apply/ page
        // (or anywhere) and link every job's Apply Now button to that single
        // URL. Reads ?job=<slug> off the query string and pre fills the
        // position field so applicants always know which role they applied
        // for and the operator can route by position in the inbox.
        add_shortcode( 'sfco_apply', array( $this, 'render_apply_form' ) );
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

    /**
     * The single job application form. Lives in the same plugin (and posts
     * to the same backend) as the quote form so the operator gets every
     * submission in one inbox. Pre fills the "Position applying for" field
     * from a ?job=<slug> query parameter so a single /apply page serves
     * every job listing.
     */
    public function render_apply_form( $atts ) {
        $atts = shortcode_atts( array(
            'title' => esc_html__( 'Apply for a Position', 'smart-forms-for-midland' ),
        ), $atts );

        // Pre fill the position field. Three resolution paths, in order:
        //   1. Explicit ?job=<slug> in the URL (e.g. when the Apply button
        //      on a job card sends the visitor to /apply/?job=carpet-tech).
        //   2. The current post when this shortcode is embedded directly
        //      inside a dpjp_job single page (the embed-into-templates flow).
        //   3. Empty, so the visitor types it themselves.
        $position = '';
        if ( isset( $_GET['job'] ) ) {
            $slug = sanitize_title( wp_unslash( $_GET['job'] ) );
            if ( $slug && function_exists( 'get_page_by_path' ) ) {
                $job_post = get_page_by_path( $slug, OBJECT, 'dpjp_job' );
                if ( $job_post instanceof WP_Post ) {
                    $position = $job_post->post_title;
                }
            }
            if ( '' === $position && $slug ) {
                $position = ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
            }
        }
        if ( '' === $position && function_exists( 'get_post' ) ) {
            $current = get_post();
            if ( $current instanceof WP_Post && 'dpjp_job' === $current->post_type ) {
                $position = $current->post_title;
            }
        }

        ob_start();
        ?>
        <div class="smart-forms-wrapper">
            <form id="smart-forms-quote-form" class="smart-forms-form" enctype="multipart/form-data">
                <?php wp_nonce_field( 'sfco_submit', '_wpnonce', false ); ?>
                <input type="hidden" name="form_type" value="job_application">

                <h2><?php echo esc_html( $atts['title'] ); ?></h2>

                <div class="form-row">
                    <label for="customer_name"><?php esc_html_e( 'Full Name', 'smart-forms-for-midland' ); ?> <span class="required">*</span></label>
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
                    <label for="project_type"><?php esc_html_e( 'Position Applying For', 'smart-forms-for-midland' ); ?> <span class="required">*</span></label>
                    <select name="project_type" id="project_type" required>
                        <option value=""><?php esc_html_e( 'Select a position...', 'smart-forms-for-midland' ); ?></option>
                        <?php
                        // Dropdown is the source of truth: list every currently
                        // published dpjp_job. If the visitor came from a single
                        // job page (or /apply/?job=<slug>), $position above
                        // already holds the title, so we pre select it here.
                        $job_posts = function_exists( 'get_posts' )
                            ? get_posts( array(
                                'post_type'      => 'dpjp_job',
                                'post_status'    => 'publish',
                                'posts_per_page' => -1,
                                'orderby'        => 'title',
                                'order'          => 'ASC',
                            ) )
                            : array();
                        $found = false;
                        foreach ( $job_posts as $jp ) {
                            $sel = ( $position === $jp->post_title ) ? ' selected' : '';
                            if ( $sel ) {
                                $found = true;
                            }
                            echo '<option value="' . esc_attr( $jp->post_title ) . '"' . $sel . '>' . esc_html( $jp->post_title ) . '</option>';
                        }
                        // If we have a pre fill that didn't match any current
                        // job (rare, e.g. position was removed), still surface
                        // it as an option so the form doesn't lose the value.
                        if ( '' !== $position && ! $found ) {
                            echo '<option value="' . esc_attr( $position ) . '" selected>' . esc_html( $position ) . '</option>';
                        }
                        ?>
                        <option value="Other / General Inquiry"<?php echo ( 'Other / General Inquiry' === $position ) ? ' selected' : ''; ?>><?php esc_html_e( 'Other / General Inquiry', 'smart-forms-for-midland' ); ?></option>
                    </select>
                </div>

                <div class="form-row">
                    <label for="zip_code"><?php esc_html_e( 'ZIP Code', 'smart-forms-for-midland' ); ?></label>
                    <input type="text" name="zip_code" id="zip_code" maxlength="10">
                </div>

                <div class="form-row">
                    <label for="photos"><?php esc_html_e( 'Resume (PDF, DOC, or DOCX)', 'smart-forms-for-midland' ); ?></label>
                    <input type="file" name="photos[]" id="photos" accept=".pdf,.doc,.docx">
                </div>

                <div class="form-row">
                    <label for="additional_notes"><?php esc_html_e( 'Why are you a fit for this role?', 'smart-forms-for-midland' ); ?></label>
                    <textarea name="additional_notes" id="additional_notes" rows="5" placeholder="<?php esc_attr_e( 'Tell us about your experience, availability, and anything else we should know.', 'smart-forms-for-midland' ); ?>"></textarea>
                </div>

                <div class="form-row">
                    <button type="submit" class="submit-button"><?php esc_html_e( 'Submit Application', 'smart-forms-for-midland' ); ?></button>
                </div>

                <div id="form-message" class="form-message"></div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
}

new SFCO_Shortcode();
