<?php
/**
 * Single unified application form.
 *
 * ONE form on ONE /apply/ page. "Apply Now" buttons from job cards and
 * individual job pages all link to /apply/?job=ID which auto-selects
 * the job in the dropdown. All submissions land in one place.
 *
 * Usage:
 *   [dpjp_apply_form]  — Place on /apply/ page
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class DPJP_Application {

    public static function register(): void {
        add_action( 'init',        [ __CLASS__, 'register_post_type' ] );
        add_shortcode( 'dpjp_apply_form', [ __CLASS__, 'render_form' ] );
        add_action( 'admin_post_dpjp_submit_application', [ __CLASS__, 'handle_submission' ] );
        add_action( 'admin_post_nopriv_dpjp_submit_application', [ __CLASS__, 'handle_submission' ] );
        add_action( 'admin_menu',  [ __CLASS__, 'menu' ] );
        add_action( 'admin_post_dpjp_save_form_settings', [ __CLASS__, 'save_settings' ] );
        add_action( 'wp_head',     [ __CLASS__, 'inject_css_vars' ] );

        // List columns for applications
        add_filter( 'manage_dpjp_application_posts_columns',       [ __CLASS__, 'columns' ] );
        add_action( 'manage_dpjp_application_posts_custom_column', [ __CLASS__, 'column_content' ], 10, 2 );
    }

    public static function menu(): void {
        add_submenu_page(
            'edit.php?post_type=dpjp_job',
            'Form Settings',
            'Form Settings',
            'manage_options',
            'dpjp-form-settings',
            [ __CLASS__, 'render_settings_page' ]
        );
    }

    /**
     * Get a form setting value (with default).
     */
    public static function get_setting( string $key, $default = '' ) {
        $settings = get_option( 'dpjp_form_settings', [] );
        return $settings[ $key ] ?? $default;
    }

    private static function defaults(): array {
        return [
            'color_primary'    => '#0073aa',
            'color_text'       => '#ffffff',
            'color_bg'         => '#f8f9fa',
            'color_accent'     => '#2a7f2a',
            'form_title'       => __( 'Apply for a Position', 'job-manager-pro' ),
            'form_intro'       => __( 'Fill out the form below — we respond fast.', 'job-manager-pro' ),
            'success_title'    => __( '✓ Application Received!', 'job-manager-pro' ),
            'success_message'  => __( "Thanks for applying. We'll review your application and get back to you soon.", 'job-manager-pro' ),
            'notification_email' => '',
            'contact_phone'    => '',
            'require_resume'   => '0',
            'show_cover'       => '1',
            'show_certs'       => '1',
            'show_experience'  => '1',
            'show_message'     => '1',
        ];
    }

    public static function inject_css_vars(): void {
        $s = wp_parse_args( get_option( 'dpjp_form_settings', [] ), self::defaults() );
        $primary = sanitize_hex_color( $s['color_primary'] ) ?: '#0073aa';
        $text    = sanitize_hex_color( $s['color_text'] )    ?: '#ffffff';
        $bg      = sanitize_hex_color( $s['color_bg'] )      ?: '#f8f9fa';
        $accent  = sanitize_hex_color( $s['color_accent'] )  ?: '#2a7f2a';
        echo "<style>:root{--dpjp-primary:{$primary};--dpjp-text:{$text};--dpjp-bg:{$bg};--dpjp-accent:{$accent};}</style>\n";
    }

    public static function render_settings_page(): void {
        $s = wp_parse_args( get_option( 'dpjp_form_settings', [] ), self::defaults() );
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Application Form Settings', 'job-manager-pro' ); ?></h1>
            <?php if ( isset( $_GET['saved'] ) ) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'job-manager-pro' ) . '</p></div>'; ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'dpjp_form_settings', 'dpjp_form_nonce' ); ?>
                <input type="hidden" name="action" value="dpjp_save_form_settings">

                <h2><?php esc_html_e( 'Colors', 'job-manager-pro' ); ?></h2>
                <table class="form-table">
                    <tr><th><label><?php esc_html_e( 'Primary Color', 'job-manager-pro' ); ?></label></th><td>
                        <input type="text" name="color_primary" value="<?php echo esc_attr( $s['color_primary'] ); ?>" class="jmp-color" data-default-color="#0073aa">
                        <p class="description"><?php esc_html_e( 'Used for buttons, borders, and headings.', 'job-manager-pro' ); ?></p>
                    </td></tr>
                    <tr><th><label><?php esc_html_e( 'Button Text Color', 'job-manager-pro' ); ?></label></th><td>
                        <input type="text" name="color_text" value="<?php echo esc_attr( $s['color_text'] ); ?>" class="jmp-color" data-default-color="#ffffff">
                    </td></tr>
                    <tr><th><label><?php esc_html_e( 'Form Background', 'job-manager-pro' ); ?></label></th><td>
                        <input type="text" name="color_bg" value="<?php echo esc_attr( $s['color_bg'] ); ?>" class="jmp-color" data-default-color="#f8f9fa">
                    </td></tr>
                    <tr><th><label><?php esc_html_e( 'Accent Color (Pay/Success)', 'job-manager-pro' ); ?></label></th><td>
                        <input type="text" name="color_accent" value="<?php echo esc_attr( $s['color_accent'] ); ?>" class="jmp-color" data-default-color="#2a7f2a">
                    </td></tr>
                </table>
                <script>jQuery(function($){ $('.jmp-color').wpColorPicker(); });</script>

                <h2><?php esc_html_e( 'Form Text', 'job-manager-pro' ); ?></h2>
                <table class="form-table">
                    <tr><th><label><?php esc_html_e( 'Form Title', 'job-manager-pro' ); ?></label></th><td><input type="text" name="form_title" value="<?php echo esc_attr( $s['form_title'] ); ?>" class="regular-text"></td></tr>
                    <tr><th><label><?php esc_html_e( 'Form Intro', 'job-manager-pro' ); ?></label></th><td><textarea name="form_intro" rows="2" class="large-text"><?php echo esc_textarea( $s['form_intro'] ); ?></textarea></td></tr>
                    <tr><th><label><?php esc_html_e( 'Success Title', 'job-manager-pro' ); ?></label></th><td><input type="text" name="success_title" value="<?php echo esc_attr( $s['success_title'] ); ?>" class="regular-text"></td></tr>
                    <tr><th><label><?php esc_html_e( 'Success Message', 'job-manager-pro' ); ?></label></th><td><textarea name="success_message" rows="2" class="large-text"><?php echo esc_textarea( $s['success_message'] ); ?></textarea></td></tr>
                </table>

                <h2><?php esc_html_e( 'Form Fields', 'job-manager-pro' ); ?></h2>
                <table class="form-table">
                    <tr><th><label>Years of Experience</label></th><td><label><input type="checkbox" name="show_experience" value="1" <?php checked( $s['show_experience'], '1' ); ?>> Show field</label></td></tr>
                    <tr><th><label>Certifications / Licenses</label></th><td><label><input type="checkbox" name="show_certs" value="1" <?php checked( $s['show_certs'], '1' ); ?>> Show field</label></td></tr>
                    <tr><th><label>"Tell us about yourself"</label></th><td><label><input type="checkbox" name="show_message" value="1" <?php checked( $s['show_message'], '1' ); ?>> Show field</label></td></tr>
                    <tr><th><label>Cover Letter Upload</label></th><td><label><input type="checkbox" name="show_cover" value="1" <?php checked( $s['show_cover'], '1' ); ?>> Show cover letter upload</label></td></tr>
                    <tr><th><label>Require Resume</label></th><td><label><input type="checkbox" name="require_resume" value="1" <?php checked( $s['require_resume'], '1' ); ?>> Resume is mandatory</label></td></tr>
                </table>

                <h2><?php esc_html_e( 'Contact', 'job-manager-pro' ); ?></h2>
                <table class="form-table">
                    <tr><th><label><?php esc_html_e( 'Phone Number (shown on form)', 'job-manager-pro' ); ?></label></th><td>
                        <input type="text" name="contact_phone" value="<?php echo esc_attr( $s['contact_phone'] ); ?>" class="regular-text" placeholder="(555) 123-4567">
                        <p class="description"><?php esc_html_e( 'Optional. Shown below the submit button so people can call instead.', 'job-manager-pro' ); ?></p>
                    </td></tr>
                    <tr><th><label><?php esc_html_e( 'Override Notification Email', 'job-manager-pro' ); ?></label></th><td>
                        <input type="email" name="notification_email" value="<?php echo esc_attr( $s['notification_email'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( "Leave empty to use job's contact email", 'job-manager-pro' ); ?>">
                        <p class="description"><?php esc_html_e( "If set, all applications go here. Otherwise email goes to the contact email set on each job.", 'job-manager-pro' ); ?></p>
                    </td></tr>
                </table>

                <?php submit_button( esc_html__( 'Save Form Settings', 'job-manager-pro' ) ); ?>
            </form>

            <hr>
            <h2><?php esc_html_e( 'Managing Applications', 'job-manager-pro' ); ?></h2>
            <p><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=dpjp_application' ) ); ?>" class="button button-primary"><?php esc_html_e( 'View All Applications →', 'job-manager-pro' ); ?></a></p>
        </div>
        <?php
    }

    public static function save_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No.' );
        check_admin_referer( 'dpjp_form_settings', 'dpjp_form_nonce' );

        $input = $_POST;
        $save = [];
        foreach ( [ 'color_primary', 'color_text', 'color_bg', 'color_accent' ] as $k ) {
            $save[ $k ] = sanitize_hex_color( $input[ $k ] ?? '' ) ?: self::defaults()[ $k ];
        }
        foreach ( [ 'form_title', 'success_title' ] as $k ) {
            $save[ $k ] = sanitize_text_field( wp_unslash( $input[ $k ] ?? '' ) );
        }
        foreach ( [ 'form_intro', 'success_message' ] as $k ) {
            $save[ $k ] = sanitize_textarea_field( wp_unslash( $input[ $k ] ?? '' ) );
        }
        $save['notification_email'] = sanitize_email( $input['notification_email'] ?? '' );
        $save['contact_phone']      = sanitize_text_field( $input['contact_phone'] ?? '' );
        foreach ( [ 'require_resume', 'show_cover', 'show_certs', 'show_experience', 'show_message' ] as $k ) {
            $save[ $k ] = isset( $input[ $k ] ) ? '1' : '0';
        }

        update_option( 'dpjp_form_settings', $save );
        wp_safe_redirect( admin_url( 'edit.php?post_type=dpjp_job&page=dpjp-form-settings&saved=1' ) );
        exit;
    }

    public static function register_post_type(): void {
        register_post_type( 'dpjp_application', [
            'labels' => [
                'name'          => 'Applications',
                'singular_name' => 'Application',
                'menu_name'     => 'Applications',
            ],
            'public'          => false,
            'show_ui'         => true,
            'show_in_menu'    => 'edit.php?post_type=dpjp_job',
            'supports'        => [ 'title' ],
            'capability_type' => 'post',
            'capabilities'    => [ 'create_posts' => 'do_not_allow' ],
            'map_meta_cap'    => true,
        ] );
    }

    /**
     * Get the apply page URL, building it with optional ?job=ID.
     */
    public static function apply_url( int $job_id = 0 ): string {
        $page_id = (int) get_option( 'dpjp_apply_page_id', 0 );
        $base    = $page_id ? get_permalink( $page_id ) : home_url( '/apply/' );
        return $job_id ? add_query_arg( 'job', $job_id, $base ) : $base;
    }

    public static function render_form( $atts ): string {
        $submitted = isset( $_GET['applied'] ) && $_GET['applied'] === 'success';
        $preselect = isset( $_GET['job'] ) ? (int) $_GET['job'] : 0;

        $s = wp_parse_args( get_option( 'dpjp_form_settings', [] ), self::defaults() );
        $jobs = get_posts( [
            'post_type'      => 'dpjp_job',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );

        ob_start();
        ?>
        <div class="dpjp-apply-form" style="background:var(--dpjp-bg,<?php echo esc_attr( $s['color_bg'] ); ?>);border:2px solid var(--dpjp-primary,<?php echo esc_attr( $s['color_primary'] ); ?>);border-radius:8px;padding:30px;margin:30px 0;max-width:700px;">
            <?php if ( $submitted ) : ?>
                <h2 style="color:var(--dpjp-accent,<?php echo esc_attr( $s['color_accent'] ); ?>);margin-top:0;"><?php echo esc_html( $s['success_title'] ); ?></h2>
                <p><?php echo esc_html( $s['success_message'] ); ?></p>
                <p><a href="<?php echo esc_url( self::apply_url() ); ?>">Apply for another position →</a></p>
            <?php else : ?>
                <h2 style="color:var(--dpjp-primary,<?php echo esc_attr( $s['color_primary'] ); ?>);margin-top:0;"><?php echo esc_html( $s['form_title'] ); ?></h2>
                <p style="margin-bottom:20px;"><?php echo esc_html( $s['form_intro'] ); ?></p>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="dpjp_submit_application">
                    <?php wp_nonce_field( 'dpjp_apply_form', 'dpjp_apply_nonce' ); ?>

                    <!-- Honeypot -->
                    <div style="position:absolute;left:-9999px;" aria-hidden="true">
                        <input type="text" name="website" tabindex="-1" autocomplete="off">
                    </div>

                    <div style="margin-bottom:15px;">
                        <label style="display:block;font-weight:bold;margin-bottom:5px;">Position Applying For <span style="color:#e00;">*</span></label>
                        <select name="job_id" required style="width:100%;padding:10px;border:1px solid #ccc;border-radius:4px;">
                            <option value="">Select a position...</option>
                            <?php foreach ( $jobs as $job ) :
                                $meta = DPJP_Meta_Fields::get( $job->ID );
                                $pay  = $meta['dpjp_pay'] ?? '';
                                ?>
                                <option value="<?php echo esc_attr( $job->ID ); ?>" <?php selected( $preselect, $job->ID ); ?>>
                                    <?php echo esc_html( $job->post_title ); ?><?php echo $pay ? ' — ' . esc_html( $pay ) : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:15px;">
                        <div>
                            <label style="display:block;font-weight:bold;margin-bottom:5px;">Full Name <span style="color:#e00;">*</span></label>
                            <input type="text" name="applicant_name" required style="width:100%;padding:10px;border:1px solid #ccc;border-radius:4px;">
                        </div>
                        <div>
                            <label style="display:block;font-weight:bold;margin-bottom:5px;">Phone <span style="color:#e00;">*</span></label>
                            <input type="tel" name="applicant_phone" required placeholder="(xxx) xxx-xxxx" style="width:100%;padding:10px;border:1px solid #ccc;border-radius:4px;">
                        </div>
                    </div>

                    <div style="margin-bottom:15px;">
                        <label style="display:block;font-weight:bold;margin-bottom:5px;">Email <span style="color:#e00;">*</span></label>
                        <input type="email" name="applicant_email" required style="width:100%;padding:10px;border:1px solid #ccc;border-radius:4px;">
                    </div>

                    <?php if ( $s['show_experience'] === '1' || $s['show_certs'] === '1' ) : ?>
                    <div style="display:grid;grid-template-columns:<?php echo ( $s['show_experience'] === '1' && $s['show_certs'] === '1' ) ? '1fr 1fr' : '1fr'; ?>;gap:15px;margin-bottom:15px;">
                        <?php if ( $s['show_experience'] === '1' ) : ?>
                        <div>
                            <label style="display:block;font-weight:bold;margin-bottom:5px;">Years of Experience</label>
                            <select name="applicant_experience" style="width:100%;padding:10px;border:1px solid #ccc;border-radius:4px;">
                                <option value="">Select...</option>
                                <option value="0-1">Less than 1 year</option>
                                <option value="1-3">1–3 years</option>
                                <option value="3-5">3–5 years</option>
                                <option value="5-10">5–10 years</option>
                                <option value="10+">10+ years</option>
                            </select>
                        </div>
                        <?php endif; ?>
                        <?php if ( $s['show_certs'] === '1' ) : ?>
                        <div>
                            <label style="display:block;font-weight:bold;margin-bottom:5px;">Certifications / Licenses</label>
                            <input type="text" name="applicant_certs" placeholder="e.g. EPA 608, MHIC #12345" style="width:100%;padding:10px;border:1px solid #ccc;border-radius:4px;">
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ( $s['show_message'] === '1' ) : ?>
                    <div style="margin-bottom:15px;">
                        <label style="display:block;font-weight:bold;margin-bottom:5px;">Tell us about yourself</label>
                        <textarea name="applicant_message" rows="4" placeholder="Briefly describe your experience and why you'd be a good fit..." style="width:100%;padding:10px;border:1px solid #ccc;border-radius:4px;"></textarea>
                    </div>
                    <?php endif; ?>

                    <div style="margin-bottom:15px;">
                        <label style="display:block;font-weight:bold;margin-bottom:5px;">Resume <?php echo $s['require_resume'] === '1' ? '<span style="color:#e00;">*</span>' : '(optional)'; ?></label>
                        <input type="file" name="applicant_resume" accept=".pdf,.doc,.docx" <?php echo $s['require_resume'] === '1' ? 'required' : ''; ?>>
                        <p style="font-size:12px;color:#666;margin:5px 0 0;">PDF or Word, max 5MB</p>
                    </div>

                    <?php if ( $s['show_cover'] === '1' ) : ?>
                    <div style="margin-bottom:20px;">
                        <label style="display:block;font-weight:bold;margin-bottom:5px;">Cover Letter (optional)</label>
                        <input type="file" name="applicant_cover" accept=".pdf,.doc,.docx,.txt">
                        <p style="font-size:12px;color:#666;margin:5px 0 0;">PDF, Word, or TXT, max 5MB</p>
                    </div>
                    <?php endif; ?>

                    <button type="submit" style="background:var(--dpjp-primary,<?php echo esc_attr( $s['color_primary'] ); ?>);color:var(--dpjp-text,<?php echo esc_attr( $s['color_text'] ); ?>);border:none;padding:14px 30px;font-size:16px;font-weight:bold;border-radius:4px;cursor:pointer;">Submit Application</button>
                    <?php $contact_phone = self::get_setting( 'contact_phone', '' ); if ( $contact_phone ) : ?>
                    <p style="margin:15px 0 0;font-size:13px;color:#666;"><?php esc_html_e( 'Prefer to call?', 'job-manager-pro' ); ?> <strong><a href="tel:<?php echo esc_attr( preg_replace( '/[^+\d]/', '', $contact_phone ) ); ?>"><?php echo esc_html( $contact_phone ); ?></a></strong></p>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function handle_submission(): void {
        if ( ! wp_verify_nonce( $_POST['dpjp_apply_nonce'] ?? '', 'dpjp_apply_form' ) ) {
            wp_die( 'Invalid submission.' );
        }
        if ( ! empty( $_POST['website'] ) ) {  // honeypot
            wp_safe_redirect( self::apply_url() . '?applied=success' );
            exit;
        }

        $job_id = (int) ( $_POST['job_id'] ?? 0 );
        $job    = $job_id ? get_post( $job_id ) : null;
        if ( ! $job || $job->post_type !== 'dpjp_job' ) wp_die( 'Please select a valid position.' );

        $name  = sanitize_text_field( wp_unslash( $_POST['applicant_name']  ?? '' ) );
        $phone = sanitize_text_field( wp_unslash( $_POST['applicant_phone'] ?? '' ) );
        $email = sanitize_email(      wp_unslash( $_POST['applicant_email'] ?? '' ) );
        $exp   = sanitize_text_field( wp_unslash( $_POST['applicant_experience'] ?? '' ) );
        $certs = sanitize_text_field( wp_unslash( $_POST['applicant_certs'] ?? '' ) );
        $msg   = sanitize_textarea_field( wp_unslash( $_POST['applicant_message'] ?? '' ) );

        if ( ! $name || ! $email || ! $phone ) {
            wp_die( 'Missing required fields. Please go back and complete the form.' );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $allowed = [
            'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'txt'  => 'text/plain',
        ];

        $resume_url = '';
        if ( ! empty( $_FILES['applicant_resume']['name'] ) ) {
            $uploaded = wp_handle_upload( $_FILES['applicant_resume'], [ 'test_form' => false, 'mimes' => $allowed ] );
            if ( ! empty( $uploaded['url'] ) ) $resume_url = $uploaded['url'];
        }

        $cover_url = '';
        if ( ! empty( $_FILES['applicant_cover']['name'] ) ) {
            $uploaded = wp_handle_upload( $_FILES['applicant_cover'], [ 'test_form' => false, 'mimes' => $allowed ] );
            if ( ! empty( $uploaded['url'] ) ) $cover_url = $uploaded['url'];
        }

        $app_id = wp_insert_post( [
            'post_type'   => 'dpjp_application',
            'post_status' => 'publish',
            'post_title'  => $name . ' — ' . get_the_title( $job ),
            'post_content' => $msg,
        ] );

        if ( $app_id && ! is_wp_error( $app_id ) ) {
            update_post_meta( $app_id, 'dpjp_app_job_id', $job_id );
            update_post_meta( $app_id, 'dpjp_app_name',   $name );
            update_post_meta( $app_id, 'dpjp_app_phone',  $phone );
            update_post_meta( $app_id, 'dpjp_app_email',  $email );
            update_post_meta( $app_id, 'dpjp_app_exp',    $exp );
            update_post_meta( $app_id, 'dpjp_app_certs',  $certs );
            update_post_meta( $app_id, 'dpjp_app_resume', $resume_url );
            update_post_meta( $app_id, 'dpjp_app_cover',  $cover_url );
        }

        $s    = wp_parse_args( get_option( 'dpjp_form_settings', [] ), self::defaults() );
        $meta = DPJP_Meta_Fields::get( $job_id );
        $to   = ! empty( $s['notification_email'] ) ? $s['notification_email'] : ( $meta['dpjp_contact_email'] ?? get_option( 'admin_email' ) );
        $subject = sprintf( 'New application: %s — %s', get_the_title( $job ), $name );
        $body  = "New application received.\n\n";
        $body .= "Position:   " . get_the_title( $job ) . "\n";
        $body .= "Name:       {$name}\n";
        $body .= "Phone:      {$phone}\n";
        $body .= "Email:      {$email}\n";
        $body .= "Experience: {$exp}\n";
        $body .= "Certs:      {$certs}\n";
        if ( $resume_url ) $body .= "Resume:     {$resume_url}\n";
        if ( $cover_url )  $body .= "Cover:      {$cover_url}\n";
        $body .= "\nMessage:\n{$msg}\n\n";
        $body .= "---\nView in WP admin: " . admin_url( 'edit.php?post_type=dpjp_application' );

        wp_mail( $to, $subject, $body, [ 'Reply-To: ' . $name . ' <' . $email . '>' ] );

        wp_safe_redirect( self::apply_url() . '?applied=success' );
        exit;
    }

    public static function columns( array $cols ): array {
        return [
            'cb'                => $cols['cb'] ?? '',
            'title'             => 'Applicant',
            'dpjp_app_job'      => 'Position',
            'dpjp_app_contact'  => 'Contact',
            'dpjp_app_exp'      => 'Experience',
            'dpjp_app_files'    => 'Files',
            'date'              => 'Applied',
        ];
    }

    public static function column_content( string $col, int $id ): void {
        switch ( $col ) {
            case 'dpjp_app_job':
                $job_id = (int) get_post_meta( $id, 'dpjp_app_job_id', true );
                if ( $job_id && ( $job = get_post( $job_id ) ) ) {
                    echo '<a href="' . esc_url( get_edit_post_link( $job_id ) ) . '">' . esc_html( $job->post_title ) . '</a>';
                } else echo '—';
                break;
            case 'dpjp_app_contact':
                $phone = get_post_meta( $id, 'dpjp_app_phone', true );
                $email = get_post_meta( $id, 'dpjp_app_email', true );
                echo $email ? '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a><br>' : '';
                echo $phone ? '<a href="tel:' . esc_attr( $phone ) . '">' . esc_html( $phone ) . '</a>' : '';
                break;
            case 'dpjp_app_exp':
                echo esc_html( get_post_meta( $id, 'dpjp_app_exp', true ) ?: '—' );
                break;
            case 'dpjp_app_files':
                $resume = get_post_meta( $id, 'dpjp_app_resume', true );
                $cover  = get_post_meta( $id, 'dpjp_app_cover',  true );
                if ( $resume ) echo '<a href="' . esc_url( $resume ) . '" target="_blank">📄 Resume</a><br>';
                if ( $cover )  echo '<a href="' . esc_url( $cover )  . '" target="_blank">📝 Cover Letter</a>';
                if ( ! $resume && ! $cover ) echo '—';
                break;
        }
    }
}
