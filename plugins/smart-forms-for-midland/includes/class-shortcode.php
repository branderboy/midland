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
                <div style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;" aria-hidden="true"><label>Leave this field blank<input type="text" name="sfco_hp_token" tabindex="-1" autocomplete="off"></label></div>
                
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
                    <label><?php esc_html_e( 'Commercial or residential?', 'smart-forms-for-midland' ); ?> <span class="required">*</span></label>
                    <div style="display:flex;gap:18px;margin-top:4px;">
                        <label style="font-weight:400;"><input type="radio" name="property_type" value="commercial" required> <?php esc_html_e( 'Commercial', 'smart-forms-for-midland' ); ?></label>
                        <label style="font-weight:400;"><input type="radio" name="property_type" value="residential"> <?php esc_html_e( 'Residential', 'smart-forms-for-midland' ); ?></label>
                    </div>
                </div>

                <div class="form-row">
                    <label><?php esc_html_e( 'What brings you to Midland?', 'smart-forms-for-midland' ); ?> <span class="required">*</span></label>
                    <div style="display:flex;flex-direction:column;gap:6px;margin-top:4px;">
                        <label style="font-weight:400;"><input type="radio" name="lead_intent" value="emergency" required> <?php esc_html_e( 'Emergency — I need help now', 'smart-forms-for-midland' ); ?></label>
                        <label style="font-weight:400;"><input type="radio" name="lead_intent" value="book_visit" checked> <?php esc_html_e( 'Book a visit — come see the space and quote', 'smart-forms-for-midland' ); ?></label>
                        <label style="font-weight:400;"><input type="radio" name="lead_intent" value="future_project"> <?php esc_html_e( 'Planning a future project (commercial)', 'smart-forms-for-midland' ); ?></label>
                        <label style="font-weight:400;"><input type="radio" name="lead_intent" value="research"> <?php esc_html_e( 'Just researching for now', 'smart-forms-for-midland' ); ?></label>
                    </div>
                </div>

                <p class="form-helper sfco-helper-residential" style="margin:-6px 0 14px;color:#6b7280;font-size:13px;line-height:1.5;display:none;"><?php esc_html_e( 'For residential: submit your contact info + property type. We get the rest of the details when we call to confirm the visit.', 'smart-forms-for-midland' ); ?></p>

                <div class="form-row">
                    <label for="property_subtype"><?php esc_html_e( 'Property type', 'smart-forms-for-midland' ); ?> <span class="required">*</span></label>
                    <select name="property_subtype" id="property_subtype" required>
                        <option value="" data-segment="both"><?php esc_html_e( 'Select...', 'smart-forms-for-midland' ); ?></option>
                        <!-- Residential -->
                        <option value="House"          data-segment="residential"><?php esc_html_e( 'House',          'smart-forms-for-midland' ); ?></option>
                        <option value="Townhouse"      data-segment="residential"><?php esc_html_e( 'Townhouse',      'smart-forms-for-midland' ); ?></option>
                        <option value="Condo"          data-segment="residential"><?php esc_html_e( 'Condo',          'smart-forms-for-midland' ); ?></option>
                        <option value="Apartment"      data-segment="residential"><?php esc_html_e( 'Apartment',      'smart-forms-for-midland' ); ?></option>
                        <!-- Commercial -->
                        <option value="Office"         data-segment="commercial"><?php esc_html_e( 'Office',          'smart-forms-for-midland' ); ?></option>
                        <option value="Retail"         data-segment="commercial"><?php esc_html_e( 'Retail / Storefront', 'smart-forms-for-midland' ); ?></option>
                        <option value="Medical"        data-segment="commercial"><?php esc_html_e( 'Medical / Dental', 'smart-forms-for-midland' ); ?></option>
                        <option value="School"         data-segment="commercial"><?php esc_html_e( 'School / Education','smart-forms-for-midland' ); ?></option>
                        <option value="Hotel"          data-segment="commercial"><?php esc_html_e( 'Hotel / Hospitality','smart-forms-for-midland' ); ?></option>
                        <option value="Restaurant"     data-segment="commercial"><?php esc_html_e( 'Restaurant',      'smart-forms-for-midland' ); ?></option>
                        <option value="Warehouse"      data-segment="commercial"><?php esc_html_e( 'Warehouse / Industrial','smart-forms-for-midland' ); ?></option>
                        <option value="Government"     data-segment="commercial"><?php esc_html_e( 'Government / Municipal','smart-forms-for-midland' ); ?></option>
                        <option value="Property Management" data-segment="commercial"><?php esc_html_e( 'Property Management','smart-forms-for-midland' ); ?></option>
                        <option value="Other"          data-segment="both"><?php esc_html_e( 'Other', 'smart-forms-for-midland' ); ?></option>
                    </select>
                </div>

                <div class="form-row sfco-row-optional-residential">
                    <label for="project_type"><?php esc_html_e( 'What service?', 'smart-forms-for-midland' ); ?> <span class="required sfco-req-mark">*</span></label>
                    <select name="project_type" id="project_type" required>
                        <option value="" data-segment="both"><?php esc_html_e( 'Select...', 'smart-forms-for-midland' ); ?></option>
                        <!-- Residential services (carpet cleaning + carpet installation only). -->
                        <option value="Carpet Cleaning" data-segment="both"><?php esc_html_e( 'Carpet Cleaning', 'smart-forms-for-midland' ); ?></option>
                        <option value="Carpet Installation" data-segment="residential"><?php esc_html_e( 'Carpet Installation', 'smart-forms-for-midland' ); ?></option>
                        <!-- Commercial-only services. -->
                        <option value="Tile & Grout Cleaning"    data-segment="commercial"><?php esc_html_e( 'Tile &amp; Grout Cleaning',    'smart-forms-for-midland' ); ?></option>
                        <option value="Floor Stripping & Wax"     data-segment="commercial"><?php esc_html_e( 'Floor Stripping &amp; Wax',     'smart-forms-for-midland' ); ?></option>
                        <option value="Hardwood Floor Care"       data-segment="commercial"><?php esc_html_e( 'Hardwood Floor Care',         'smart-forms-for-midland' ); ?></option>
                        <option value="Concrete Polishing"        data-segment="commercial"><?php esc_html_e( 'Concrete Polishing',          'smart-forms-for-midland' ); ?></option>
                        <option value="Upholstery Cleaning"       data-segment="commercial"><?php esc_html_e( 'Upholstery Cleaning',         'smart-forms-for-midland' ); ?></option>
                        <option value="Water Damage Restoration"  data-segment="commercial"><?php esc_html_e( 'Water Damage Restoration',    'smart-forms-for-midland' ); ?></option>
                        <option value="Other / Not sure"          data-segment="both"><?php esc_html_e( 'Other / Not sure', 'smart-forms-for-midland' ); ?></option>
                    </select>
                </div>

                <script>
                /* Two jobs:
                   (1) Filter the service dropdown by selected property
                       type. Residential = Carpet Cleaning + Carpet
                       Installation; commercial = full list.
                   (2) For residential, hide square footage / timeline
                       and drop the required flag on service — we
                       collect those details on the phone, not in the
                       form. Commercial keeps the full intake.
                */
                (function () {
                    var form = document.getElementById('smart-forms-quote-form');
                    if (!form) return;
                    var serviceSel = form.querySelector('select[name="project_type"]');
                    var subtypeSel = form.querySelector('select[name="property_subtype"]');
                    var commRows   = form.querySelectorAll('.sfco-row-commercial-only');
                    var resHelper  = form.querySelector('.sfco-helper-residential');
                    var serviceRow = form.querySelector('.sfco-row-optional-residential');
                    var reqMark    = serviceRow ? serviceRow.querySelector('.sfco-req-mark') : null;

                    function filterSelect(sel, seg) {
                        if (!sel) return;
                        sel.querySelectorAll('option').forEach(function (opt) {
                            var allowed = opt.getAttribute('data-segment') || 'both';
                            var show = (allowed === 'both') || (allowed === seg) || !seg;
                            opt.hidden   = !show;
                            opt.disabled = !show;
                            if (!show && sel.value === opt.value && opt.value !== '') {
                                sel.value = '';
                            }
                        });
                    }

                    function apply() {
                        var checked = form.querySelector('input[name="property_type"]:checked');
                        var seg     = checked ? checked.value : '';
                        var isRes   = seg === 'residential';

                        filterSelect(serviceSel, seg);
                        filterSelect(subtypeSel, seg);

                        commRows.forEach(function (row) { row.style.display = isRes ? 'none' : ''; });
                        if (resHelper)  resHelper.style.display = isRes ? 'block' : 'none';
                        if (serviceSel) serviceSel.required = !isRes;
                        if (reqMark)    reqMark.style.visibility = isRes ? 'hidden' : '';
                    }
                    form.addEventListener('change', function (e) {
                        if (e.target && e.target.name === 'property_type') apply();
                    });
                    apply();
                })();
                </script>

                <div class="form-row sfco-row-commercial-only">
                    <label for="square_footage"><?php esc_html_e( 'Square footage (approx.)', 'smart-forms-for-midland' ); ?></label>
                    <input type="number" name="square_footage" id="square_footage" min="1" placeholder="<?php esc_attr_e( 'Skip if you\'re not sure', 'smart-forms-for-midland' ); ?>">
                </div>

                <div class="form-row sfco-row-commercial-only">
                    <label for="timeline"><?php esc_html_e( 'How soon?', 'smart-forms-for-midland' ); ?></label>
                    <select name="timeline" id="timeline">
                        <option value=""><?php esc_html_e( 'Select...', 'smart-forms-for-midland' ); ?></option>
                        <option value="ASAP"><?php esc_html_e( 'ASAP (this week)', 'smart-forms-for-midland' ); ?></option>
                        <option value="This Month"><?php esc_html_e( 'This month', 'smart-forms-for-midland' ); ?></option>
                        <option value="Within 3 Months"><?php esc_html_e( 'Within 3 months', 'smart-forms-for-midland' ); ?></option>
                        <option value="Just Exploring"><?php esc_html_e( 'Just exploring', 'smart-forms-for-midland' ); ?></option>
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
                <div style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;" aria-hidden="true"><label>Leave this field blank<input type="text" name="sfco_hp_token" tabindex="-1" autocomplete="off"></label></div>
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
