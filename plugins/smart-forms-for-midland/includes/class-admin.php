<?php
/**
 * Admin dashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFCO_Admin {
    
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
    }
    
    public function add_menu_pages() {
        add_menu_page(
            esc_html__( 'Smart Forms', 'smart-forms-for-midland' ),
            esc_html__( 'Smart Forms', 'smart-forms-for-midland' ),
            'manage_options',
            'smart-forms',
            array( $this, 'render_forms_page' ),
            'dashicons-forms',
            30
        );
        add_submenu_page(
            'smart-forms',
            esc_html__( 'Forms', 'smart-forms-for-midland' ),
            esc_html__( 'Forms', 'smart-forms-for-midland' ),
            'manage_options',
            'smart-forms',
            array( $this, 'render_forms_page' )
        );
        add_submenu_page(
            'smart-forms',
            esc_html__( 'All Entries', 'smart-forms-for-midland' ),
            esc_html__( 'All Entries', 'smart-forms-for-midland' ),
            'manage_options',
            'smart-forms-leads',
            array( $this, 'render_leads_page' )
        );
        add_submenu_page(
            'smart-forms',
            esc_html__( 'Shortcodes', 'smart-forms-for-midland' ),
            esc_html__( 'Shortcodes', 'smart-forms-for-midland' ),
            'manage_options',
            'smart-forms-shortcodes',
            array( $this, 'render_shortcodes_page' )
        );
        add_submenu_page(
            'smart-forms',
            esc_html__( 'Tracking (Ad Pixels)', 'smart-forms-for-midland' ),
            esc_html__( 'Tracking', 'smart-forms-for-midland' ),
            'manage_options',
            'smart-forms-tracking',
            array( $this, 'render_tracking_page' )
        );
        add_submenu_page(
            null, // hidden — accessed by clicking a form row
            esc_html__( 'Form Entries', 'smart-forms-for-midland' ),
            '',
            'manage_options',
            'smart-forms-form-entries',
            array( $this, 'render_form_entries_page' )
        );
        add_submenu_page(
            null, // hidden — accessed by clicking a form row
            esc_html__( 'Edit Form', 'smart-forms-for-midland' ),
            '',
            'manage_options',
            'smart-forms-edit-form',
            array( $this, 'render_edit_form_page' )
        );
    }

    /**
     * Forms list — Gravity-style table with Status / Title / ID / Entries /
     * Views / Conversion columns.
     */
    public function render_forms_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        // Handle form toggle/delete/seed actions
        if ( isset( $_GET['sfco_action'] ) && check_admin_referer( 'sfco_forms_action' ) ) {
            $action = sanitize_key( $_GET['sfco_action'] );
            $id     = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
            if ( 'toggle' === $action && $id ) {
                $form = SFCO_Database::get_form( $id );
                if ( $form ) {
                    $new = ( 'active' === $form->status ) ? 'inactive' : 'active';
                    SFCO_Database::update_form( $id, array( 'status' => $new ) );
                }
            } elseif ( 'delete' === $action && $id ) {
                SFCO_Database::delete_form( $id );
            } elseif ( 'seed' === $action ) {
                SFCO_Database::seed_templates();
            }
            wp_safe_redirect( admin_url( 'admin.php?page=smart-forms' ) );
            exit;
        }

        $forms = SFCO_Database::get_forms();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Smart Forms', 'smart-forms-for-midland' ); ?></h1>
            <?php
            $seed_url = wp_nonce_url( admin_url( 'admin.php?page=smart-forms&sfco_action=seed' ), 'sfco_forms_action' );
            ?>
            <a href="<?php echo esc_url( $seed_url ); ?>" class="page-title-action"><?php esc_html_e( '+ Re-seed Midland templates', 'smart-forms-for-midland' ); ?></a>
            <hr class="wp-header-end">

            <?php if ( empty( $forms ) ) : ?>
                <div style="background:#fff;border:1px dashed #c3c4c7;border-radius:6px;padding:32px;max-width:760px;margin:24px 0;">
                    <h2 style="margin-top:0;"><?php esc_html_e( 'No forms yet.', 'smart-forms-for-midland' ); ?></h2>
                    <p><?php esc_html_e( 'Seed the Midland floor-care template library — five pre-built forms ready to embed.', 'smart-forms-for-midland' ); ?></p>
                    <p><a href="<?php echo esc_url( $seed_url ); ?>" class="button button-primary button-large"><?php esc_html_e( 'Seed Midland templates', 'smart-forms-for-midland' ); ?></a></p>
                </div>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped" style="margin-top:12px;">
                    <thead>
                        <tr>
                            <th style="width:90px;"><?php esc_html_e( 'Status', 'smart-forms-for-midland' ); ?></th>
                            <th><?php esc_html_e( 'Title', 'smart-forms-for-midland' ); ?></th>
                            <th style="width:60px;"><?php esc_html_e( 'ID', 'smart-forms-for-midland' ); ?></th>
                            <th style="width:80px;"><?php esc_html_e( 'Entries', 'smart-forms-for-midland' ); ?></th>
                            <th style="width:80px;"><?php esc_html_e( 'Views', 'smart-forms-for-midland' ); ?></th>
                            <th style="width:90px;"><?php esc_html_e( 'Conversion', 'smart-forms-for-midland' ); ?></th>
                            <th><?php esc_html_e( 'Shortcode', 'smart-forms-for-midland' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $forms as $form ) :
                        $stats     = SFCO_Database::get_form_stats( $form->id );
                        $entries_url = admin_url( 'admin.php?page=smart-forms-form-entries&form_id=' . $form->id );
                        $edit_url    = admin_url( 'admin.php?page=smart-forms-edit-form&form_id=' . $form->id );
                        $toggle_url  = wp_nonce_url( admin_url( 'admin.php?page=smart-forms&sfco_action=toggle&form_id=' . $form->id ), 'sfco_forms_action' );
                        $is_active   = ( 'active' === $form->status );
                        ?>
                        <tr>
                            <td>
                                <span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:600;<?php echo $is_active ? 'background:#d1fae5;color:#065f46;' : 'background:#f3f4f6;color:#6b7280;'; ?>">
                                    <?php echo $is_active ? '● ' . esc_html__( 'Active', 'smart-forms-for-midland' ) : '○ ' . esc_html__( 'Inactive', 'smart-forms-for-midland' ); ?>
                                </span>
                            </td>
                            <td>
                                <strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $form->title ); ?></a></strong>
                                <div class="row-actions">
                                    <span><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'smart-forms-for-midland' ); ?></a> | </span>
                                    <span><a href="<?php echo esc_url( $entries_url ); ?>"><?php esc_html_e( 'Entries', 'smart-forms-for-midland' ); ?></a> | </span>
                                    <span><a href="<?php echo esc_url( $toggle_url ); ?>"><?php echo $is_active ? esc_html__( 'Deactivate', 'smart-forms-for-midland' ) : esc_html__( 'Activate', 'smart-forms-for-midland' ); ?></a></span>
                                </div>
                            </td>
                            <td><?php echo (int) $form->id; ?></td>
                            <td><a href="<?php echo esc_url( $entries_url ); ?>"><?php echo (int) $stats['entries']; ?></a></td>
                            <td><?php echo (int) $stats['views']; ?></td>
                            <td><?php echo esc_html( $stats['conversion'] ); ?>%</td>
                            <td><code style="background:#f6f7f7;padding:3px 8px;border-radius:3px;">[sfco_form id="<?php echo (int) $form->id; ?>"]</code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Entries for a single form.
     */
    public function render_form_entries_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
        $form    = $form_id ? SFCO_Database::get_form( $form_id ) : null;
        if ( ! $form ) {
            echo '<div class="wrap"><h1>Form not found</h1><p><a href="' . esc_url( admin_url( 'admin.php?page=smart-forms' ) ) . '">← Back to Forms</a></p></div>';
            return;
        }
        $leads = SFCO_Database::get_leads( array( 'form_id' => $form_id, 'limit' => 100 ) );
        $stats = SFCO_Database::get_form_stats( $form_id );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( $form->title ); ?> — <?php esc_html_e( 'Entries', 'smart-forms-for-midland' ); ?></h1>
            <p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=smart-forms' ) ); ?>">← <?php esc_html_e( 'All Forms', 'smart-forms-for-midland' ); ?></a>
                &nbsp;|&nbsp;
                <strong><?php echo (int) $stats['entries']; ?></strong> <?php esc_html_e( 'entries', 'smart-forms-for-midland' ); ?>,
                <strong><?php echo (int) $stats['views']; ?></strong> <?php esc_html_e( 'views', 'smart-forms-for-midland' ); ?>,
                <strong><?php echo esc_html( $stats['conversion'] ); ?>%</strong> <?php esc_html_e( 'conversion', 'smart-forms-for-midland' ); ?>
            </p>

            <?php if ( empty( $leads ) ) : ?>
                <p style="background:#fff;padding:16px;border:1px dashed #c3c4c7;border-radius:6px;">
                    <?php esc_html_e( 'No entries yet for this form.', 'smart-forms-for-midland' ); ?>
                    <code style="background:#f6f7f7;padding:3px 8px;border-radius:3px;margin-left:6px;">[sfco_form id="<?php echo (int) $form->id; ?>"]</code>
                </p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:60px;">ID</th>
                            <th><?php esc_html_e( 'Customer', 'smart-forms-for-midland' ); ?></th>
                            <th><?php esc_html_e( 'Contact', 'smart-forms-for-midland' ); ?></th>
                            <th><?php esc_html_e( 'Project', 'smart-forms-for-midland' ); ?></th>
                            <th style="width:80px;"><?php esc_html_e( 'Priority', 'smart-forms-for-midland' ); ?></th>
                            <th style="width:70px;"><?php esc_html_e( 'Area', 'smart-forms-for-midland' ); ?></th>
                            <th><?php esc_html_e( 'When', 'smart-forms-for-midland' ); ?></th>
                            <th style="width:220px;"><?php esc_html_e( 'Actions', 'smart-forms-for-midland' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $leads as $lead ) :
                        $pri = $lead->priority ?? '';
                        $pri_color = 'Hot' === $pri ? 'background:#fee2e2;color:#991b1b;'
                                  : ( 'Warm' === $pri ? 'background:#fef3c7;color:#92400e;'
                                  : ( 'Cool' === $pri ? 'background:#dbeafe;color:#1e40af;' : 'background:#f3f4f6;color:#6b7280;' ) );
                        $sm8_pushed = ! empty( $lead->job_id );
                        ?>
                        <tr>
                            <td><?php echo (int) $lead->id; ?></td>
                            <td><strong><?php echo esc_html( $lead->customer_name ?? '' ); ?></strong></td>
                            <td>
                                <a href="mailto:<?php echo esc_attr( $lead->customer_email ?? '' ); ?>"><?php echo esc_html( $lead->customer_email ?? '' ); ?></a><br>
                                <a href="tel:<?php echo esc_attr( $lead->customer_phone ?? '' ); ?>"><?php echo esc_html( $lead->customer_phone ?? '' ); ?></a>
                            </td>
                            <td><?php echo esc_html( $lead->project_type ?? '' ); ?></td>
                            <td>
                                <?php if ( $pri ) : ?>
                                    <span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;<?php echo esc_attr( $pri_color ); ?>"><?php echo esc_html( $pri ); ?></span>
                                <?php else : ?>
                                    <span style="color:#9ca3af;">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $lead->area ?? '—' ); ?></td>
                            <td><?php echo esc_html( date_i18n( 'M j, Y g:i a', strtotime( $lead->created_at ?? 'now' ) ) ); ?></td>
                            <td>
                                <?php
                                /**
                                 * Action-button injection point. Smart CRM hooks this to render
                                 * "Push to ServiceM8" + "Resend Reminder" + "Mark Won/Lost"
                                 * per-lead. Anything else can hook the same action too.
                                 */
                                do_action( 'sfco_render_entry_actions', $lead );
                                if ( $sm8_pushed ) {
                                    echo '<div style="font-size:11px;color:#1e7e34;margin-top:4px;">SM8: ' . esc_html( substr( $lead->job_id, 0, 8 ) ) . '…</div>';
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Edit a form — title, status, notification email, confirmation message.
     * (Field builder coming in Phase 2; for now you can edit metadata.)
     */
    public function render_edit_form_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
        $form    = $form_id ? SFCO_Database::get_form( $form_id ) : null;
        if ( ! $form ) {
            echo '<div class="wrap"><h1>Form not found</h1></div>';
            return;
        }

        if ( isset( $_POST['sfco_save_form'] ) && check_admin_referer( 'sfco_save_form' ) ) {
            $settings = json_decode( $form->settings_json ?: '{}', true );
            if ( ! is_array( $settings ) ) $settings = array();
            $settings['notify_email'] = sanitize_email( wp_unslash( $_POST['notify_email'] ?? '' ) );
            $settings['confirmation'] = sanitize_textarea_field( wp_unslash( $_POST['confirmation'] ?? '' ) );
            $settings['crm_push']     = isset( $_POST['crm_push'] );
            SFCO_Database::update_form( $form_id, array(
                'title'         => sanitize_text_field( wp_unslash( $_POST['title'] ?? $form->title ) ),
                'status'        => isset( $_POST['active'] ) ? 'active' : 'inactive',
                'settings_json' => wp_json_encode( $settings ),
            ) );
            $form = SFCO_Database::get_form( $form_id );
            echo '<div class="notice notice-success is-dismissible"><p>✓ Saved.</p></div>';
        }

        $settings = json_decode( $form->settings_json ?: '{}', true );
        if ( ! is_array( $settings ) ) $settings = array();
        $fields = json_decode( $form->fields_json ?: '[]', true );
        if ( ! is_array( $fields ) ) $fields = array();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Edit form:', 'smart-forms-for-midland' ); ?> <?php echo esc_html( $form->title ); ?></h1>
            <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=smart-forms' ) ); ?>">← <?php esc_html_e( 'All Forms', 'smart-forms-for-midland' ); ?></a></p>

            <form method="post">
                <?php wp_nonce_field( 'sfco_save_form' ); ?>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Title', 'smart-forms-for-midland' ); ?></th>
                        <td><input type="text" name="title" value="<?php echo esc_attr( $form->title ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Active', 'smart-forms-for-midland' ); ?></th>
                        <td><label><input type="checkbox" name="active" <?php checked( 'active', $form->status ); ?>> <?php esc_html_e( 'Form is live and accepting submissions', 'smart-forms-for-midland' ); ?></label></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Notification email', 'smart-forms-for-midland' ); ?></th>
                        <td>
                            <input type="email" name="notify_email" value="<?php echo esc_attr( $settings['notify_email'] ?? '' ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
                            <p class="description"><?php esc_html_e( 'Leave blank to use the site admin email.', 'smart-forms-for-midland' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Confirmation message', 'smart-forms-for-midland' ); ?></th>
                        <td>
                            <textarea name="confirmation" rows="3" class="large-text"><?php echo esc_textarea( $settings['confirmation'] ?? '' ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Shown to the visitor after they submit. HTML allowed.', 'smart-forms-for-midland' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Push to Smart CRM Pro', 'smart-forms-for-midland' ); ?></th>
                        <td>
                            <label><input type="checkbox" name="crm_push" <?php checked( ! empty( $settings['crm_push'] ) ); ?>> <?php esc_html_e( 'Auto-create a contact + lead in Smart CRM Pro on every submission', 'smart-forms-for-midland' ); ?></label>
                            <p class="description"><?php esc_html_e( 'Requires Smart CRM Pro to be installed and active.', 'smart-forms-for-midland' ); ?></p>
                        </td>
                    </tr>
                </table>
                <p><button type="submit" name="sfco_save_form" class="button button-primary"><?php esc_html_e( 'Save Form', 'smart-forms-for-midland' ); ?></button></p>
            </form>

            <h2><?php esc_html_e( 'Fields', 'smart-forms-for-midland' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Drag-drop field builder coming soon. For now, fields are defined in the template seed. Current fields:', 'smart-forms-for-midland' ); ?></p>
            <ul style="background:#fff;border:1px solid #c3c4c7;padding:12px 24px;border-radius:6px;max-width:700px;">
                <?php foreach ( $fields as $f ) : ?>
                    <li>
                        <strong><?php echo esc_html( $f['label'] ?? $f['key'] ); ?></strong>
                        <code style="background:#f6f7f7;padding:1px 6px;border-radius:3px;font-size:11px;"><?php echo esc_html( $f['type'] ); ?></code>
                        <?php if ( ! empty( $f['required'] ) ) echo '<span style="color:#b32d2e;font-size:12px;"> *</span>'; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <h2><?php esc_html_e( 'Shortcode', 'smart-forms-for-midland' ); ?></h2>
            <p><code style="background:#f6f7f7;padding:6px 12px;border-radius:3px;display:inline-block;">[sfco_form id="<?php echo (int) $form->id; ?>"]</code></p>
        </div>
        <?php
    }

    /**
     * Tracking / Ad-pixel settings page. Three platforms, all client-side
     * (server-side Conversions API can come later). The frontend JS fires
     * the matching event on every successful AJAX submit — gtag conversion
     * for Google Ads, fbq for Meta, ttq for TikTok — but only when both
     * the ID is set here AND the platform's base tag script is already on
     * the page (we don't inject them; the operator manages that via
     * GTM / theme / Site Kit).
     */
    public function render_tracking_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $tracking = get_option( 'sfco_tracking', array() );
        if ( ! is_array( $tracking ) ) $tracking = array();

        if ( isset( $_POST['sfco_save_tracking'] ) && check_admin_referer( 'sfco_save_tracking' ) ) {
            $tracking = array(
                'google_ads_send_to'  => sanitize_text_field( wp_unslash( $_POST['google_ads_send_to']  ?? '' ) ),
                'google_ads_value'    => sanitize_text_field( wp_unslash( $_POST['google_ads_value']    ?? '' ) ),
                'google_ads_currency' => sanitize_text_field( wp_unslash( $_POST['google_ads_currency'] ?? 'USD' ) ),
                'facebook_pixel_id'   => sanitize_text_field( wp_unslash( $_POST['facebook_pixel_id']   ?? '' ) ),
                'facebook_event'      => sanitize_text_field( wp_unslash( $_POST['facebook_event']      ?? 'Lead' ) ),
                'tiktok_pixel_id'     => sanitize_text_field( wp_unslash( $_POST['tiktok_pixel_id']     ?? '' ) ),
                'tiktok_event'        => sanitize_text_field( wp_unslash( $_POST['tiktok_event']        ?? 'SubmitForm' ) ),
            );
            update_option( 'sfco_tracking', $tracking );
            echo '<div class="notice notice-success is-dismissible"><p>✓ Tracking settings saved.</p></div>';
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Tracking — Ad Conversion Pixels', 'smart-forms-for-midland' ); ?></h1>
            <p><?php esc_html_e( 'Fire a conversion event into each ad platform every time a Smart Forms form is submitted. Each pixel is enabled by entering its ID below — leave blank to skip.', 'smart-forms-for-midland' ); ?></p>
            <p style="color:#475569;font-size:13px;">
                <?php esc_html_e( 'Note: this only fires the conversion EVENT. The platform\'s base tag script (gtag.js / fbevents.js / ttq) must already be loaded on the page via your theme, GTM, Site Kit, etc. We don\'t inject the base tags to avoid duplicate-firing if you already have them.', 'smart-forms-for-midland' ); ?>
            </p>

            <form method="post">
                <?php wp_nonce_field( 'sfco_save_tracking' ); ?>

                <h2 style="margin-top:24px;">🔵 Google Ads</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="google_ads_send_to"><?php esc_html_e( 'Conversion send_to', 'smart-forms-for-midland' ); ?></label></th>
                        <td>
                            <input type="text" id="google_ads_send_to" name="google_ads_send_to" value="<?php echo esc_attr( $tracking['google_ads_send_to'] ?? '' ); ?>" class="regular-text" placeholder="AW-1234567890/abcDEFghi">
                            <p class="description"><?php esc_html_e( 'Google Ads → Tools → Conversions → click your conversion → Tag setup → "Use Google tag" → copy the send_to value (looks like AW-XXX/labelXXX).', 'smart-forms-for-midland' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="google_ads_value"><?php esc_html_e( 'Conversion value', 'smart-forms-for-midland' ); ?></label></th>
                        <td>
                            <input type="number" step="0.01" id="google_ads_value" name="google_ads_value" value="<?php echo esc_attr( $tracking['google_ads_value'] ?? '' ); ?>" class="small-text">
                            <input type="text" id="google_ads_currency" name="google_ads_currency" value="<?php echo esc_attr( $tracking['google_ads_currency'] ?? 'USD' ); ?>" class="small-text" maxlength="3" style="width:80px;">
                            <p class="description"><?php esc_html_e( 'Optional. Estimated revenue per lead — used for Google Ads bid optimization. Leave 0 if you score conversions equally.', 'smart-forms-for-midland' ); ?></p>
                        </td>
                    </tr>
                </table>

                <h2>🟦 Facebook / Meta</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="facebook_pixel_id"><?php esc_html_e( 'Pixel ID', 'smart-forms-for-midland' ); ?></label></th>
                        <td>
                            <input type="text" id="facebook_pixel_id" name="facebook_pixel_id" value="<?php echo esc_attr( $tracking['facebook_pixel_id'] ?? '' ); ?>" class="regular-text" placeholder="1234567890123456">
                            <p class="description"><?php esc_html_e( 'Setting this flips on the fbq("track","...") call. We don\'t inject the base pixel.', 'smart-forms-for-midland' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="facebook_event"><?php esc_html_e( 'Event name', 'smart-forms-for-midland' ); ?></label></th>
                        <td>
                            <select id="facebook_event" name="facebook_event">
                                <?php foreach ( array( 'Lead', 'CompleteRegistration', 'Schedule', 'SubmitApplication', 'Contact' ) as $ev ) : ?>
                                    <option value="<?php echo esc_attr( $ev ); ?>" <?php selected( $tracking['facebook_event'] ?? 'Lead', $ev ); ?>><?php echo esc_html( $ev ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( '"Lead" is the standard for form submissions in Meta Events Manager.', 'smart-forms-for-midland' ); ?></p>
                        </td>
                    </tr>
                </table>

                <h2>⬛ TikTok</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="tiktok_pixel_id"><?php esc_html_e( 'Pixel ID', 'smart-forms-for-midland' ); ?></label></th>
                        <td>
                            <input type="text" id="tiktok_pixel_id" name="tiktok_pixel_id" value="<?php echo esc_attr( $tracking['tiktok_pixel_id'] ?? '' ); ?>" class="regular-text" placeholder="CXXXXXXXXXXXXX">
                            <p class="description"><?php esc_html_e( 'TikTok Ads Manager → Events → Web Events. We call ttq.track() on submit when this is set.', 'smart-forms-for-midland' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tiktok_event"><?php esc_html_e( 'Event name', 'smart-forms-for-midland' ); ?></label></th>
                        <td>
                            <select id="tiktok_event" name="tiktok_event">
                                <?php foreach ( array( 'SubmitForm', 'CompleteRegistration', 'Contact', 'Subscribe' ) as $ev ) : ?>
                                    <option value="<?php echo esc_attr( $ev ); ?>" <?php selected( $tracking['tiktok_event'] ?? 'SubmitForm', $ev ); ?>><?php echo esc_html( $ev ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( '"SubmitForm" maps to TikTok\'s lead optimization objective.', 'smart-forms-for-midland' ); ?></p>
                        </td>
                    </tr>
                </table>

                <p><button type="submit" name="sfco_save_tracking" class="button button-primary"><?php esc_html_e( 'Save Tracking Settings', 'smart-forms-for-midland' ); ?></button></p>
            </form>
        </div>
        <?php
    }

    public function render_shortcodes_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Smart Forms Shortcodes', 'smart-forms-for-midland' ); ?></h1>
            <p><?php esc_html_e( 'Drop one of these into any page, post, or Elementor Shortcode widget to render the quote form.', 'smart-forms-for-midland' ); ?></p>

            <style>
                .sfco-sc-row { background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:18px 20px;margin-bottom:14px; }
                .sfco-sc-code { background:#f6f7f7;border:1px solid #dcdcde;padding:8px 14px;font-family:Consolas,Monaco,monospace;font-size:14px;border-radius:3px;display:inline-block;margin:0 8px 0 0; }
                .sfco-sc-copy { background:#2271b1;color:#fff;border:0;padding:6px 14px;border-radius:3px;cursor:pointer;font-size:12px;vertical-align:middle; }
                .sfco-sc-copy:hover { background:#135e96; }
                .sfco-sc-desc { color:#555;font-size:13px;margin:8px 0 0; }
            </style>

            <h2><?php esc_html_e( 'Main shortcode', 'smart-forms-for-midland' ); ?></h2>
            <div class="sfco-sc-row">
                <code class="sfco-sc-code" id="sc1">[sfco_quote]</code>
                <button class="sfco-sc-copy" data-target="sc1"><?php esc_html_e( 'Copy', 'smart-forms-for-midland' ); ?></button>
                <p class="sfco-sc-desc"><?php esc_html_e( 'Renders the full lead-capture form: name, email, phone, service type, square footage, timeline, photo upload, and submit.', 'smart-forms-for-midland' ); ?></p>
            </div>

            <h2><?php esc_html_e( 'How to use', 'smart-forms-for-midland' ); ?></h2>
            <ol>
                <li><?php esc_html_e( 'Edit any page or post.', 'smart-forms-for-midland' ); ?></li>
                <li><?php esc_html_e( 'In Elementor: drag a Shortcode widget; in Gutenberg: add a Shortcode block.', 'smart-forms-for-midland' ); ?></li>
                <li><?php esc_html_e( 'Paste [sfco_quote] and update.', 'smart-forms-for-midland' ); ?></li>
            </ol>

            <h2><?php esc_html_e( 'Live preview', 'smart-forms-for-midland' ); ?></h2>
            <div style="border:2px dashed #ccc;padding:20px;background:#fafafa;max-width:800px;">
                <?php echo do_shortcode( '[sfco_quote]' ); ?>
            </div>

            <script>
            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('sfco-sc-copy')) {
                    var el = document.getElementById(e.target.dataset.target);
                    if (el && navigator.clipboard) {
                        navigator.clipboard.writeText(el.textContent).then(function() {
                            var orig = e.target.textContent;
                            e.target.textContent = '<?php echo esc_js( __( 'Copied!', 'smart-forms-for-midland' ) ); ?>';
                            setTimeout(function() { e.target.textContent = orig; }, 1500);
                        });
                    }
                }
            });
            </script>
        </div>
        <?php
    }
    
    public function enqueue_admin_assets( $hook ) {
        if ( strpos( $hook, 'smart-forms' ) === false ) {
            return;
        }
        
        wp_enqueue_style( 'sfco-admin', SFCO_PLUGIN_URL . 'assets/css/admin.css', array(), SFCO_VERSION );
        wp_enqueue_script( 'sfco-admin', SFCO_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), SFCO_VERSION, true );
    }
    
    public function render_leads_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        // Defensive: if SFCO_Database isn't loaded for any reason, fail loud
        // instead of blank-screen.
        if ( ! class_exists( 'SFCO_Database' ) ) {
            echo '<div class="wrap"><h1>Midland Smart Forms</h1><div class="notice notice-error"><p>SFCO_Database class is missing. Reinstall the plugin.</p></div></div>';
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $filter_status   = isset( $_GET['status'] )   ? sanitize_text_field( wp_unslash( $_GET['status'] ) )   : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $filter_timeline = isset( $_GET['timeline'] ) ? sanitize_text_field( wp_unslash( $_GET['timeline'] ) ) : '';

        $leads = SFCO_Database::get_leads( array(
            'status'   => $filter_status,
            'timeline' => $filter_timeline,
            'limit'    => 50,
        ) );
        $leads = is_array( $leads ) ? $leads : array();

        $shortcode_url = admin_url( 'admin.php?page=smart-forms-shortcodes' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Midland Smart Forms — Leads', 'smart-forms-for-midland' ); ?></h1>

            <?php if ( empty( $leads ) ) : ?>
                <div style="background:#fff;border:1px dashed #c3c4c7;border-radius:6px;padding:32px 28px;max-width:760px;margin:24px 0;">
                    <h2 style="margin-top:0;"><?php esc_html_e( '📭 No leads yet.', 'smart-forms-for-midland' ); ?></h2>
                    <p style="font-size:14px;line-height:1.6;"><?php esc_html_e( 'Add the quote form to your site so visitors can submit project details. Drop this shortcode into any page or post:', 'smart-forms-for-midland' ); ?></p>
                    <p>
                        <code style="background:#f6f7f7;border:1px solid #dcdcde;padding:8px 14px;font-size:14px;border-radius:3px;display:inline-block;">[sfco_quote]</code>
                    </p>
                    <p style="font-size:14px;line-height:1.6;">
                        <?php esc_html_e( 'In Elementor: drag a Shortcode widget. In Gutenberg: add a Shortcode block. Paste, update, done.', 'smart-forms-for-midland' ); ?>
                    </p>
                    <p>
                        <a class="button button-primary" href="<?php echo esc_url( $shortcode_url ); ?>"><?php esc_html_e( 'View shortcode reference + live preview →', 'smart-forms-for-midland' ); ?></a>
                    </p>
                </div>
                <?php return; ?>
            <?php endif; ?>

            <div class="smart-forms-filters" style="margin:10px 0 14px;">
                <select name="status" onchange="location = this.value;">
                    <option value="<?php echo esc_url( admin_url( 'admin.php?page=smart-forms-leads' ) ); ?>"><?php esc_html_e( 'All Statuses', 'smart-forms-for-midland' ); ?></option>
                    <option value="<?php echo esc_url( add_query_arg( 'status', 'new' ) ); ?>" <?php selected( $filter_status, 'new' ); ?>><?php esc_html_e( 'New', 'smart-forms-for-midland' ); ?></option>
                    <option value="<?php echo esc_url( add_query_arg( 'status', 'contacted' ) ); ?>" <?php selected( $filter_status, 'contacted' ); ?>><?php esc_html_e( 'Contacted', 'smart-forms-for-midland' ); ?></option>
                    <option value="<?php echo esc_url( add_query_arg( 'status', 'quoted' ) ); ?>" <?php selected( $filter_status, 'quoted' ); ?>><?php esc_html_e( 'Quoted', 'smart-forms-for-midland' ); ?></option>
                    <option value="<?php echo esc_url( add_query_arg( 'status', 'won' ) ); ?>" <?php selected( $filter_status, 'won' ); ?>><?php esc_html_e( 'Won', 'smart-forms-for-midland' ); ?></option>
                    <option value="<?php echo esc_url( add_query_arg( 'status', 'lost' ) ); ?>" <?php selected( $filter_status, 'lost' ); ?>><?php esc_html_e( 'Lost', 'smart-forms-for-midland' ); ?></option>
                </select>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'ID', 'smart-forms-for-midland' ); ?></th>
                        <th><?php esc_html_e( 'Customer', 'smart-forms-for-midland' ); ?></th>
                        <th><?php esc_html_e( 'Contact', 'smart-forms-for-midland' ); ?></th>
                        <th><?php esc_html_e( 'Project', 'smart-forms-for-midland' ); ?></th>
                        <th><?php esc_html_e( 'Timeline', 'smart-forms-for-midland' ); ?></th>
                        <th><?php esc_html_e( 'Estimate', 'smart-forms-for-midland' ); ?></th>
                        <th><?php esc_html_e( 'Date', 'smart-forms-for-midland' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $leads as $lead ) :
                    $timeline_class = 'timeline-normal';
                    if ( 'ASAP' === ( $lead->timeline ?? '' ) )      $timeline_class = 'timeline-urgent';
                    elseif ( 'This Week' === ( $lead->timeline ?? '' ) ) $timeline_class = 'timeline-soon';
                    ?>
                    <tr>
                        <td><?php echo esc_html( $lead->id ); ?></td>
                        <td><strong><?php echo esc_html( $lead->customer_name ?? '' ); ?></strong></td>
                        <td>
                            <a href="mailto:<?php echo esc_attr( $lead->customer_email ?? '' ); ?>"><?php echo esc_html( $lead->customer_email ?? '' ); ?></a><br>
                            <a href="tel:<?php echo esc_attr( $lead->customer_phone ?? '' ); ?>"><?php echo esc_html( $lead->customer_phone ?? '' ); ?></a>
                        </td>
                        <td>
                            <?php echo esc_html( $lead->project_type ?? '' ); ?><br>
                            <small><?php echo esc_html( number_format( (int) ( $lead->square_footage ?? 0 ) ) ); ?> sq ft</small>
                        </td>
                        <td>
                            <span class="timeline-badge <?php echo esc_attr( $timeline_class ); ?>">
                                <?php echo esc_html( $lead->timeline ?? '' ); ?>
                            </span>
                        </td>
                        <td>
                            <?php
                            if ( ! empty( $lead->estimated_cost_min ) && ! empty( $lead->estimated_cost_max ) ) {
                                echo esc_html( '$' . number_format( $lead->estimated_cost_min ) . ' - $' . number_format( $lead->estimated_cost_max ) );
                            } else {
                                echo esc_html__( 'N/A', 'smart-forms-for-midland' );
                            }
                            ?>
                        </td>
                        <td><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $lead->created_at ?? 'now' ) ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}

new SFCO_Admin();
