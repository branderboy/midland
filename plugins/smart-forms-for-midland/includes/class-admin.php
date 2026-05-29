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
        add_action( 'wp_ajax_sfco_save_form_fields', array( $this, 'ajax_save_form_fields' ) );
        add_action( 'admin_init', array( $this, 'maybe_export_csv' ) );
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
            esc_html__( 'New Form', 'smart-forms-for-midland' ),
            esc_html__( 'New Form', 'smart-forms-for-midland' ),
            'manage_options',
            'smart-forms-new',
            array( $this, 'handle_new_form' )
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
            null, // Tracking has its own fields in Smart Forms → Settings now; the page stays alive at ?page=smart-forms-tracking for the old bookmark.
            esc_html__( 'Tracking (Ad Pixels)', 'smart-forms-for-midland' ),
            esc_html__( 'Tracking', 'smart-forms-for-midland' ),
            'manage_options',
            'smart-forms-tracking',
            array( $this, 'render_tracking_page' )
        );
        add_submenu_page(
            'smart-forms',
            esc_html__( 'Integration Log', 'smart-forms-for-midland' ),
            esc_html__( 'Log', 'smart-forms-for-midland' ),
            'manage_options',
            'smart-forms-log',
            array( $this, 'render_log_page' )
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

        if ( ! class_exists( 'SFCO_Forms_List_Table' ) ) {
            require_once SFCO_PLUGIN_DIR . 'includes/class-forms-list-table.php';
        }

        $this->handle_form_actions();

        $table = new SFCO_Forms_List_Table();
        $table->prepare_items();

        $seed_url   = wp_nonce_url( admin_url( 'admin.php?page=smart-forms&sfco_action=seed' ), 'sfco_forms_action' );
        $cur_status = isset( $_REQUEST['status'] ) ? sanitize_key( $_REQUEST['status'] ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Smart Forms', 'smart-forms-for-midland' ); ?></h1>
            <a href="<?php echo esc_url( $seed_url ); ?>" class="page-title-action"><?php esc_html_e( 'Re-seed Midland templates', 'smart-forms-for-midland' ); ?></a>
            <hr class="wp-header-end">

            <?php $table->views(); ?>
            <form method="post">
                <input type="hidden" name="page" value="smart-forms">
                <input type="hidden" name="status" value="<?php echo esc_attr( $cur_status ); ?>">
                <?php
                $table->search_box( __( 'Search Forms', 'smart-forms-for-midland' ), 'sfco-form' );
                $table->display();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Process single-row (GET link) and bulk (POST) actions for the forms list,
     * then redirect back to the list so the screen never re-submits on refresh.
     */
    private function handle_form_actions() {
        // Single-row actions via the row-action links.
        if ( isset( $_GET['sfco_action'] ) && check_admin_referer( 'sfco_forms_action' ) ) {
            $action = sanitize_key( $_GET['sfco_action'] );
            $id     = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;

            if ( 'toggle' === $action && $id ) {
                $form = SFCO_Database::get_form( $id );
                if ( $form ) {
                    SFCO_Database::update_form( $id, array( 'status' => ( 'active' === $form->status ) ? 'inactive' : 'active' ) );
                }
            } elseif ( 'trash' === $action && $id ) {
                SFCO_Database::update_form( $id, array( 'status' => 'trash' ) );
            } elseif ( 'restore' === $action && $id ) {
                SFCO_Database::update_form( $id, array( 'status' => 'active' ) );
            } elseif ( 'delete' === $action && $id ) {
                SFCO_Database::delete_form( $id );
            } elseif ( 'seed' === $action ) {
                SFCO_Database::seed_templates();
            } elseif ( 'duplicate' === $action && $id ) {
                // Clone an existing form: same fields/settings, marked inactive,
                // title prefixed with "Copy of" so the two are easy to tell apart.
                $src = SFCO_Database::get_form( $id );
                if ( $src ) {
                    $new_id = SFCO_Database::create_form( array(
                        'title'         => 'Copy of ' . $src->title,
                        'slug'          => sanitize_title( $src->title ) . '-copy-' . wp_generate_password( 4, false, false ),
                        'status'        => 'inactive',
                        'fields_json'   => $src->fields_json,
                        'settings_json' => $src->settings_json,
                    ) );
                    if ( $new_id ) {
                        wp_safe_redirect( admin_url( 'admin.php?page=smart-forms-edit-form&form_id=' . $new_id ) );
                        exit;
                    }
                }
            }

            wp_safe_redirect( $this->forms_redirect_url() );
            exit;
        }

        // Bulk actions posted from the list table.
        $bulk = '';
        if ( isset( $_REQUEST['action'] ) && '-1' !== $_REQUEST['action'] ) {
            $bulk = sanitize_key( wp_unslash( $_REQUEST['action'] ) );
        } elseif ( isset( $_REQUEST['action2'] ) && '-1' !== $_REQUEST['action2'] ) {
            $bulk = sanitize_key( wp_unslash( $_REQUEST['action2'] ) );
        }

        if ( $bulk && ! empty( $_REQUEST['form'] ) ) {
            check_admin_referer( 'bulk-forms' );
            $ids = array_map( 'absint', (array) $_REQUEST['form'] );
            foreach ( $ids as $id ) {
                if ( ! $id ) continue;
                switch ( $bulk ) {
                    case 'activate':   SFCO_Database::update_form( $id, array( 'status' => 'active' ) ); break;
                    case 'deactivate': SFCO_Database::update_form( $id, array( 'status' => 'inactive' ) ); break;
                    case 'trash':      SFCO_Database::update_form( $id, array( 'status' => 'trash' ) ); break;
                    case 'restore':    SFCO_Database::update_form( $id, array( 'status' => 'active' ) ); break;
                    case 'delete':     SFCO_Database::delete_form( $id ); break;
                }
            }
            wp_safe_redirect( $this->forms_redirect_url() );
            exit;
        }
    }

    private function forms_redirect_url() {
        $args = array( 'page' => 'smart-forms' );
        $status = isset( $_REQUEST['status'] ) ? sanitize_key( $_REQUEST['status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( $status && 'all' !== $status ) {
            $args['status'] = $status;
        }
        return add_query_arg( $args, admin_url( 'admin.php' ) );
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
    /**
     * Form editor: Gravity-Forms-style three-tab page (Editor / Settings /
     * Shortcode). The Editor tab is a real field builder — type palette on
     * the left, sortable field cards in the middle with inline settings
     * panels, AJAX save. Settings tab keeps the form-level options
     * (notification email, confirmation message, CRM push). Shortcode tab
     * shows the embed code.
     */
    public function render_edit_form_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
        $form    = $form_id ? SFCO_Database::get_form( $form_id ) : null;
        if ( ! $form ) {
            echo '<div class="wrap"><h1>Form not found</h1></div>';
            return;
        }

        // Form-level Settings tab save (POST). Field saves happen via
        // AJAX from the builder — see ajax_save_form_fields().
        if ( isset( $_POST['sfco_save_form'] ) && check_admin_referer( 'sfco_save_form' ) ) {
            $settings = json_decode( $form->settings_json ?: '{}', true );
            if ( ! is_array( $settings ) ) $settings = array();
            $settings['notify_email']      = sanitize_email( wp_unslash( $_POST['notify_email'] ?? '' ) );
            $settings['confirmation']      = wp_kses_post( wp_unslash( $_POST['confirmation'] ?? '' ) );
            $settings['confirmation_type'] = in_array( ( $_POST['confirmation_type'] ?? 'message' ), array( 'message', 'redirect', 'page' ), true ) ? sanitize_text_field( wp_unslash( $_POST['confirmation_type'] ) ) : 'message';
            $settings['redirect_url']      = esc_url_raw( wp_unslash( $_POST['redirect_url'] ?? '' ) );
            $settings['redirect_page_id']  = absint( $_POST['redirect_page_id'] ?? 0 );
            // Per-form booking link (Calendly etc.). Only forms that should send
            // people to schedule a visit set this — most forms leave it blank.
            $settings['booking_url']       = esc_url_raw( wp_unslash( $_POST['booking_url'] ?? '' ) );
            $settings['honeypot']          = isset( $_POST['honeypot'] ) ? 1 : 0;
            $settings['crm_push']          = isset( $_POST['crm_push'] );
            $settings['webhook']      = array(
                'url'    => esc_url_raw( wp_unslash( $_POST['webhook_url'] ?? '' ) ),
                'method' => in_array( ( $_POST['webhook_method'] ?? 'POST' ), array( 'POST', 'PUT', 'PATCH', 'GET', 'DELETE' ), true ) ? sanitize_text_field( wp_unslash( $_POST['webhook_method'] ) ) : 'POST',
                'format' => ( $_POST['webhook_format'] ?? 'json' ) === 'form' ? 'form' : 'json',
            );

            // Gravity-Forms-parity form settings persisted alongside the
            // existing fields. Each maps to a render-time decision later
            // (the shortcode + form-handler classes read settings_json
            // and apply the chosen behavior).
            $settings['description']      = sanitize_textarea_field( wp_unslash( $_POST['form_description'] ?? '' ) );
            $settings['label_placement']  = in_array( ( $_POST['label_placement'] ?? 'top' ), array( 'top', 'left', 'right', 'hidden' ), true ) ? sanitize_text_field( wp_unslash( $_POST['label_placement'] ) ) : 'top';
            $settings['required_marker']  = in_array( ( $_POST['required_marker'] ?? 'asterisk' ), array( 'asterisk', 'required', 'custom' ), true ) ? sanitize_text_field( wp_unslash( $_POST['required_marker'] ) ) : 'asterisk';
            $settings['required_custom']  = sanitize_text_field( wp_unslash( $_POST['required_custom'] ?? '' ) );
            $settings['form_css_class']   = sanitize_html_class( (string) wp_unslash( $_POST['form_css_class'] ?? '' ) );
            $settings['submit_text']      = sanitize_text_field( wp_unslash( $_POST['submit_text'] ?? '' ) );
            $settings['submit_css_class'] = sanitize_html_class( (string) wp_unslash( $_POST['submit_css_class'] ?? '' ) );
            $settings['recaptcha_site']   = sanitize_text_field( wp_unslash( $_POST['recaptcha_site'] ?? '' ) );
            $settings['recaptcha_secret'] = sanitize_text_field( wp_unslash( $_POST['recaptcha_secret'] ?? '' ) );
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

        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'editor';
        if ( ! in_array( $tab, array( 'editor', 'settings', 'shortcode' ), true ) ) {
            $tab = 'editor';
        }
        $base_url = add_query_arg( array( 'page' => 'smart-forms-edit-form', 'form_id' => $form_id ), admin_url( 'admin.php' ) );

        // Field-type palette — same shape the renderer/AJAX accept.
        $palette = array(
            array( 'type' => 'text',     'icon' => 'editor-textcolor', 'label' => 'Text' ),
            array( 'type' => 'email',    'icon' => 'email-alt',        'label' => 'Email' ),
            array( 'type' => 'tel',      'icon' => 'phone',            'label' => 'Phone' ),
            array( 'type' => 'number',   'icon' => 'editor-ol-rtl',    'label' => 'Number' ),
            array( 'type' => 'textarea', 'icon' => 'editor-paragraph', 'label' => 'Paragraph' ),
            array( 'type' => 'select',   'icon' => 'menu',             'label' => 'Dropdown' ),
            array( 'type' => 'radio',    'icon' => 'marker',           'label' => 'Radio' ),
            array( 'type' => 'checkbox', 'icon' => 'yes',              'label' => 'Checkboxes' ),
            array( 'type' => 'date',     'icon' => 'calendar-alt',     'label' => 'Date' ),
            array( 'type' => 'file',     'icon' => 'upload',           'label' => 'File Upload' ),
            array( 'type' => 'hidden',   'icon' => 'hidden',           'label' => 'Hidden' ),
            array( 'type' => 'html',     'icon' => 'editor-code',      'label' => 'HTML' ),
        );

        // Localize state for the builder JS — picked up only on the
        // Editor tab, but cheap enough to localize on every tab.
        wp_enqueue_script( 'jquery-ui-sortable' );
        wp_localize_script( 'sfco-admin', 'sfcoBuilder', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'sfco_builder' ),
            'formId'  => $form_id,
            'fields'  => $fields,
            'palette' => $palette,
        ) );
        ?>
        <div class="wrap sfco-builder-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Edit form:', 'smart-forms-for-midland' ); ?> <?php echo esc_html( $form->title ); ?></h1>
            <a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=smart-forms' ) ); ?>">← <?php esc_html_e( 'All Forms', 'smart-forms-for-midland' ); ?></a>

            <h2 class="nav-tab-wrapper" style="margin-top:14px;">
                <a class="nav-tab <?php echo 'editor'    === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'editor',    $base_url ) ); ?>"><?php esc_html_e( 'Editor',    'smart-forms-for-midland' ); ?></a>
                <a class="nav-tab <?php echo 'settings'  === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'settings',  $base_url ) ); ?>"><?php esc_html_e( 'Settings',  'smart-forms-for-midland' ); ?></a>
                <a class="nav-tab <?php echo 'shortcode' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'shortcode', $base_url ) ); ?>"><?php esc_html_e( 'Shortcode', 'smart-forms-for-midland' ); ?></a>
            </h2>

            <?php if ( 'editor' === $tab ) : ?>
                <div class="sfco-builder" style="display:grid;grid-template-columns:220px 1fr;gap:18px;margin-top:18px;">
                    <aside class="sfco-builder-palette" style="background:#fff;border:1px solid #d6e6dc;border-radius:8px;padding:14px;">
                        <h3 style="margin:0 0 10px;font-size:13px;text-transform:uppercase;letter-spacing:.5px;color:#2F8137;font-weight:800;"><?php esc_html_e( 'Add a field', 'smart-forms-for-midland' ); ?></h3>
                        <div id="sfco-palette">
                            <?php foreach ( $palette as $p ) : ?>
                                <button type="button" class="sfco-palette-btn" data-type="<?php echo esc_attr( $p['type'] ); ?>" style="display:flex;align-items:center;gap:8px;width:100%;text-align:left;padding:8px 10px;margin-bottom:4px;border:1px solid #e0e0e0;border-radius:5px;background:#fafafa;cursor:pointer;font-size:13px;">
                                    <span class="dashicons dashicons-<?php echo esc_attr( $p['icon'] ); ?>" style="color:#2F8137;"></span>
                                    <?php echo esc_html( $p['label'] ); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <p style="margin:14px 0 0;font-size:12px;color:#6b8278;line-height:1.4;"><?php esc_html_e( 'Click a field type to add it. Drag the handle on any field to reorder.', 'smart-forms-for-midland' ); ?></p>
                    </aside>

                    <section class="sfco-builder-canvas">
                        <div style="display:flex;justify-content:space-between;align-items:center;background:#fff;border:1px solid #d6e6dc;border-radius:8px;padding:12px 16px;margin-bottom:14px;">
                            <strong style="color:#0F1411;"><?php esc_html_e( 'Form fields', 'smart-forms-for-midland' ); ?></strong>
                            <span>
                                <span id="sfco-builder-status" style="margin-right:12px;color:#6b8278;font-size:13px;"></span>
                                <button type="button" id="sfco-builder-save" class="button button-primary" style="background:#43A94B;border-color:#43A94B;font-weight:700;"><?php esc_html_e( 'Save Fields', 'smart-forms-for-midland' ); ?></button>
                            </span>
                        </div>

                        <ol id="sfco-fields-list" style="list-style:none;padding:0;margin:0;min-height:80px;">
                            <!-- Field cards rendered by JS from sfcoBuilder.fields -->
                        </ol>
                        <p id="sfco-empty-state" style="display:none;padding:32px;background:#fff;border:1px dashed #cbd5d0;border-radius:8px;text-align:center;color:#6b8278;">
                            <?php esc_html_e( 'No fields yet. Click a field type on the left to add one.', 'smart-forms-for-midland' ); ?>
                        </p>
                    </section>
                </div>

                <template id="sfco-field-tpl">
                    <li class="sfco-field-card" data-index="" style="background:#fff;border:1px solid #d6e6dc;border-left:4px solid #2F8137;border-radius:6px;margin-bottom:10px;">
                        <header class="sfco-field-head" style="display:flex;align-items:center;gap:10px;padding:10px 14px;cursor:pointer;">
                            <span class="dashicons dashicons-menu sfco-handle" style="cursor:move;color:#6b8278;"></span>
                            <strong class="sfco-field-label" style="flex:1;color:#0F1411;"></strong>
                            <code class="sfco-field-type" style="background:#F3FCF4;color:#2F8137;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:800;text-transform:uppercase;"></code>
                            <span class="sfco-field-required" style="display:none;color:#b32d2e;font-weight:700;">*</span>
                            <button type="button" class="button-link sfco-field-toggle" title="Edit settings"><span class="dashicons dashicons-arrow-down-alt2"></span></button>
                            <button type="button" class="button-link sfco-field-delete" title="Delete" style="color:#b32d2e;"><span class="dashicons dashicons-trash"></span></button>
                        </header>
                        <div class="sfco-field-body" style="display:none;padding:8px 16px 16px;border-top:1px solid #ecf3ef;">
                            <table class="form-table" role="presentation" style="margin-top:0;">
                                <tr><th>Label</th><td><input type="text" data-prop="label" class="regular-text"></td></tr>
                                <tr><th>Field key</th><td><input type="text" data-prop="key" class="regular-text" pattern="[a-z0-9_]+"></td></tr>
                                <tr class="sfco-row-placeholder"><th>Placeholder</th><td><input type="text" data-prop="placeholder" class="regular-text"></td></tr>
                                <tr class="sfco-row-description"><th>Help text</th><td><input type="text" data-prop="description" class="regular-text"></td></tr>
                                <tr class="sfco-row-required"><th>Required</th><td><label><input type="checkbox" data-prop="required"> Field must be filled in</label></td></tr>
                                <tr class="sfco-row-default"><th>Default value</th><td><input type="text" data-prop="default" class="regular-text"></td></tr>
                                <tr class="sfco-row-options" style="display:none;"><th>Options</th><td><textarea data-prop="options" rows="4" class="large-text" placeholder="One per line"></textarea></td></tr>
                                <tr class="sfco-row-rows" style="display:none;"><th>Rows</th><td><input type="number" data-prop="rows" min="1" max="30" class="small-text"></td></tr>
                                <tr class="sfco-row-minmax" style="display:none;">
                                    <th>Min / Max</th>
                                    <td><input type="number" data-prop="min" class="small-text" placeholder="min"> &nbsp; <input type="number" data-prop="max" class="small-text" placeholder="max"></td>
                                </tr>
                                <tr class="sfco-row-accept" style="display:none;"><th>Accepted file types</th><td><input type="text" data-prop="accept" class="regular-text" placeholder=".pdf,.doc,.docx,image/*"></td></tr>
                                <tr class="sfco-row-html" style="display:none;"><th>HTML</th><td><textarea data-prop="html" rows="4" class="large-text"></textarea></td></tr>
                            </table>
                        </div>
                    </li>
                </template>

            <?php elseif ( 'settings' === $tab ) : ?>
                <form method="post" style="max-width:780px;margin-top:18px;">
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
                                <p class="description"><?php esc_html_e( 'Per-form override. Global notifications live in Smart Forms → Settings.', 'smart-forms-for-midland' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'After submit', 'smart-forms-for-midland' ); ?></th>
                            <td>
                                <?php $ctype = $settings['confirmation_type'] ?? 'message'; ?>
                                <label style="display:block;margin-bottom:6px;"><input type="radio" name="confirmation_type" value="message" <?php checked( $ctype, 'message' ); ?>> <?php esc_html_e( 'Show a confirmation message', 'smart-forms-for-midland' ); ?></label>
                                <label style="display:block;margin-bottom:6px;"><input type="radio" name="confirmation_type" value="redirect" <?php checked( $ctype, 'redirect' ); ?>> <?php esc_html_e( 'Redirect to a URL', 'smart-forms-for-midland' ); ?></label>
                                <label style="display:block;"><input type="radio" name="confirmation_type" value="page" <?php checked( $ctype, 'page' ); ?>> <?php esc_html_e( 'Send to a WordPress page', 'smart-forms-for-midland' ); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Confirmation message', 'smart-forms-for-midland' ); ?></th>
                            <td>
                                <textarea name="confirmation" rows="3" class="large-text"><?php echo esc_textarea( $settings['confirmation'] ?? '' ); ?></textarea>
                                <p class="description"><?php esc_html_e( 'Shown when "Show a confirmation message" is selected. HTML allowed.', 'smart-forms-for-midland' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="redirect_url"><?php esc_html_e( 'Redirect URL', 'smart-forms-for-midland' ); ?></label></th>
                            <td>
                                <input type="url" id="redirect_url" name="redirect_url" class="regular-text" value="<?php echo esc_attr( $settings['redirect_url'] ?? '' ); ?>" placeholder="https://midlandfloors.com/thank-you/">
                                <p class="description"><?php esc_html_e( 'Used when "Redirect to a URL" is selected.', 'smart-forms-for-midland' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="booking_url"><?php esc_html_e( 'Booking link (Calendly)', 'smart-forms-for-midland' ); ?></label></th>
                            <td>
                                <input type="url" id="booking_url" name="booking_url" class="regular-text" value="<?php echo esc_attr( $settings['booking_url'] ?? '' ); ?>" placeholder="https://calendly.com/justinc-mfc">
                                <p class="description"><?php esc_html_e( 'Optional, per form. If set, this form offers a "Pick a time" booking button (e.g. in the chat) after the visitor is ready. Leave blank for forms that should not send people to schedule a visit.', 'smart-forms-for-midland' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="redirect_page_id"><?php esc_html_e( 'Thank-you page', 'smart-forms-for-midland' ); ?></label></th>
                            <td>
                                <?php wp_dropdown_pages( array(
                                    'name'              => 'redirect_page_id',
                                    'id'                => 'redirect_page_id',
                                    'selected'          => $settings['redirect_page_id'] ?? 0,
                                    'show_option_none'  => __( '— Select a page —', 'smart-forms-for-midland' ),
                                    'option_none_value' => 0,
                                ) ); ?>
                                <p class="description"><?php esc_html_e( 'Used when "Send to a WordPress page" is selected.', 'smart-forms-for-midland' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Spam protection', 'smart-forms-for-midland' ); ?></th>
                            <td>
                                <label><input type="checkbox" name="honeypot" <?php checked( ! empty( $settings['honeypot'] ) ); ?>> <?php esc_html_e( 'Enable honeypot (silently rejects bots that fill the hidden field)', 'smart-forms-for-midland' ); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Push to Smart CRM Pro', 'smart-forms-for-midland' ); ?></th>
                            <td>
                                <label><input type="checkbox" name="crm_push" <?php checked( ! empty( $settings['crm_push'] ) ); ?>> <?php esc_html_e( 'Auto-create a contact + lead in Smart CRM Pro on every submission', 'smart-forms-for-midland' ); ?></label>
                            </td>
                        </tr>
                    </table>

                    <h3 style="margin-top:32px;"><?php esc_html_e( 'Form Basics', 'smart-forms-for-midland' ); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th><label for="form_description"><?php esc_html_e( 'Form description', 'smart-forms-for-midland' ); ?></label></th>
                            <td><textarea name="form_description" id="form_description" rows="3" class="large-text"><?php echo esc_textarea( $settings['description'] ?? '' ); ?></textarea>
                                <p class="description"><?php esc_html_e( 'Shown above the first field, e.g. "Fill this out and we\'ll be in touch within one business day."', 'smart-forms-for-midland' ); ?></p></td>
                        </tr>
                    </table>

                    <h3 style="margin-top:32px;"><?php esc_html_e( 'Form Layout', 'smart-forms-for-midland' ); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th><label for="label_placement"><?php esc_html_e( 'Label placement', 'smart-forms-for-midland' ); ?></label></th>
                            <td>
                                <select name="label_placement" id="label_placement">
                                    <?php $lp = $settings['label_placement'] ?? 'top'; ?>
                                    <option value="top"    <?php selected( $lp, 'top' ); ?>><?php esc_html_e( 'Top aligned (default)', 'smart-forms-for-midland' ); ?></option>
                                    <option value="left"   <?php selected( $lp, 'left' ); ?>><?php esc_html_e( 'Left aligned', 'smart-forms-for-midland' ); ?></option>
                                    <option value="right"  <?php selected( $lp, 'right' ); ?>><?php esc_html_e( 'Right aligned', 'smart-forms-for-midland' ); ?></option>
                                    <option value="hidden" <?php selected( $lp, 'hidden' ); ?>><?php esc_html_e( 'Hidden (placeholder only)', 'smart-forms-for-midland' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Required field indicator', 'smart-forms-for-midland' ); ?></th>
                            <td>
                                <?php $rm = $settings['required_marker'] ?? 'asterisk'; ?>
                                <label style="display:block;"><input type="radio" name="required_marker" value="asterisk" <?php checked( $rm, 'asterisk' ); ?>> <?php esc_html_e( 'Asterisk: *', 'smart-forms-for-midland' ); ?></label>
                                <label style="display:block;"><input type="radio" name="required_marker" value="required" <?php checked( $rm, 'required' ); ?>> <?php esc_html_e( 'Text: (Required)', 'smart-forms-for-midland' ); ?></label>
                                <label style="display:block;"><input type="radio" name="required_marker" value="custom" <?php checked( $rm, 'custom' ); ?>> <?php esc_html_e( 'Custom:', 'smart-forms-for-midland' ); ?> <input type="text" name="required_custom" class="regular-text" value="<?php echo esc_attr( $settings['required_custom'] ?? '' ); ?>" placeholder="e.g. (must fill)"></label>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="form_css_class"><?php esc_html_e( 'Form CSS class', 'smart-forms-for-midland' ); ?></label></th>
                            <td><input type="text" name="form_css_class" id="form_css_class" class="regular-text" value="<?php echo esc_attr( $settings['form_css_class'] ?? '' ); ?>" placeholder="midland-quote-form">
                                <p class="description"><?php esc_html_e( 'Added to the form\'s wrapper for custom CSS.', 'smart-forms-for-midland' ); ?></p></td>
                        </tr>
                    </table>

                    <h3 style="margin-top:32px;"><?php esc_html_e( 'Submit Button', 'smart-forms-for-midland' ); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th><label for="submit_text"><?php esc_html_e( 'Button text', 'smart-forms-for-midland' ); ?></label></th>
                            <td><input type="text" name="submit_text" id="submit_text" class="regular-text" value="<?php echo esc_attr( $settings['submit_text'] ?? '' ); ?>" placeholder="Get Quote"></td>
                        </tr>
                        <tr>
                            <th><label for="submit_css_class"><?php esc_html_e( 'Button CSS class', 'smart-forms-for-midland' ); ?></label></th>
                            <td><input type="text" name="submit_css_class" id="submit_css_class" class="regular-text" value="<?php echo esc_attr( $settings['submit_css_class'] ?? '' ); ?>"></td>
                        </tr>
                    </table>

                    <h3 style="margin-top:32px;"><?php esc_html_e( 'Spam Detection', 'smart-forms-for-midland' ); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th><label for="recaptcha_site"><?php esc_html_e( 'reCAPTCHA v3 site key', 'smart-forms-for-midland' ); ?></label></th>
                            <td><input type="text" name="recaptcha_site" id="recaptcha_site" class="regular-text" value="<?php echo esc_attr( $settings['recaptcha_site'] ?? '' ); ?>">
                                <p class="description"><?php esc_html_e( 'google.com/recaptcha/admin → create a v3 key for midlandfloors.com. Honeypot above is always on regardless.', 'smart-forms-for-midland' ); ?></p></td>
                        </tr>
                        <tr>
                            <th><label for="recaptcha_secret"><?php esc_html_e( 'reCAPTCHA v3 secret', 'smart-forms-for-midland' ); ?></label></th>
                            <td><input type="password" name="recaptcha_secret" id="recaptcha_secret" class="regular-text" value="<?php echo esc_attr( $settings['recaptcha_secret'] ?? '' ); ?>" autocomplete="off"></td>
                        </tr>
                    </table>

                    <h3 style="margin-top:32px;"><?php esc_html_e( 'Outbound webhook', 'smart-forms-for-midland' ); ?></h3>
                    <p class="description"><?php esc_html_e( 'Fire an HTTP request to a URL of your choice when this form is submitted. Useful for Zapier, Make, n8n, or a custom backend. Lead fields are sent as the request body.', 'smart-forms-for-midland' ); ?></p>
                    <?php $webhook = is_array( $settings['webhook'] ?? null ) ? $settings['webhook'] : array(); ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="webhook_url"><?php esc_html_e( 'Webhook URL', 'smart-forms-for-midland' ); ?></label></th>
                            <td>
                                <input type="url" id="webhook_url" name="webhook_url" class="regular-text" value="<?php echo esc_attr( $webhook['url'] ?? '' ); ?>" placeholder="https://hooks.zapier.com/...">
                                <p class="description"><?php esc_html_e( 'Leave blank to disable.', 'smart-forms-for-midland' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="webhook_method"><?php esc_html_e( 'Method', 'smart-forms-for-midland' ); ?></label></th>
                            <td>
                                <select id="webhook_method" name="webhook_method">
                                    <?php $cur_method = $webhook['method'] ?? 'POST'; ?>
                                    <?php foreach ( array( 'POST', 'PUT', 'PATCH', 'GET', 'DELETE' ) as $m ) : ?>
                                        <option value="<?php echo esc_attr( $m ); ?>" <?php selected( $cur_method, $m ); ?>><?php echo esc_html( $m ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="webhook_format"><?php esc_html_e( 'Body format', 'smart-forms-for-midland' ); ?></label></th>
                            <td>
                                <select id="webhook_format" name="webhook_format">
                                    <?php $cur_format = $webhook['format'] ?? 'json'; ?>
                                    <option value="json" <?php selected( $cur_format, 'json' ); ?>>JSON</option>
                                    <option value="form" <?php selected( $cur_format, 'form' ); ?>>Form-encoded</option>
                                </select>
                                <p class="description"><?php esc_html_e( 'Most modern services (Zapier, Make, n8n, Discord) expect JSON. Choose form-encoded for legacy PHP endpoints.', 'smart-forms-for-midland' ); ?></p>
                            </td>
                        </tr>
                    </table>

                    <p><button type="submit" name="sfco_save_form" class="button button-primary"><?php esc_html_e( 'Save Settings', 'smart-forms-for-midland' ); ?></button></p>
                </form>

            <?php else : // shortcode tab ?>
                <div style="background:#fff;border:1px solid #d6e6dc;border-radius:8px;padding:22px;margin-top:18px;max-width:780px;">
                    <h2 style="margin-top:0;"><?php esc_html_e( 'Embed this form', 'smart-forms-for-midland' ); ?></h2>
                    <p><?php esc_html_e( 'Drop this shortcode into any page, post, or Elementor Shortcode widget:', 'smart-forms-for-midland' ); ?></p>
                    <pre style="background:#0F1411;color:#7CCE8E;padding:18px;border-radius:6px;font-size:15px;user-select:all;">[sfco_form id="<?php echo (int) $form->id; ?>"]</pre>
                    <p class="description"><?php esc_html_e( 'The form picks up its Notifications and CRM behavior from the global Smart Forms → Settings page; per-form overrides live on the Settings tab here.', 'smart-forms-for-midland' ); ?></p>
                </div>
            <?php endif; ?>
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
        <?php
        $csv_url = wp_nonce_url( admin_url( 'admin.php?page=smart-forms-leads&sfco_export=csv' ), 'sfco_export_csv' );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'All Entries', 'smart-forms-for-midland' ); ?></h1>
            <a href="<?php echo esc_url( $csv_url ); ?>" class="page-title-action"><?php esc_html_e( 'Export CSV', 'smart-forms-for-midland' ); ?></a>
            <hr class="wp-header-end">

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

    /**
     * "New Form" submenu — creates a blank draft form and drops the
     * operator straight into the builder. Gravity's New Form opens a
     * modal; we skip the modal because the editor's first tab IS the
     * field builder, so they're one step away from adding fields.
     */
    public function handle_new_form() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'No.' );
        }
        $form_id = SFCO_Database::create_form( array(
            'title'         => 'Untitled Form',
            'slug'          => 'form-' . wp_generate_password( 6, false, false ),
            'status'        => 'inactive',
            'fields_json'   => '[]',
            'settings_json' => wp_json_encode( array(
                'confirmation_type' => 'message',
                'confirmation'      => 'Thanks! We received your message and will respond within one business day.',
                'redirect_url'      => '',
                'redirect_page_id'  => 0,
                'honeypot'          => 1,
            ) ),
        ) );
        if ( ! $form_id ) {
            wp_die( 'Could not create form.' );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=smart-forms-edit-form&form_id=' . $form_id ) );
        exit;
    }

    /**
     * CSV export of entries. Hooked off an explicit GET on the leads
     * page so the operator can pull a flat file for spreadsheets /
     * external tools / a manual CRM dump.
     */
    public function maybe_export_csv() {
        if ( ! is_admin() || empty( $_GET['sfco_export'] ) || 'csv' !== $_GET['sfco_export'] ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'No.' );
        }
        check_admin_referer( 'sfco_export_csv' );

        global $wpdb;
        $table = $wpdb->prefix . 'sfco_leads';
        $rows  = (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 5000", ARRAY_A ); // phpcs:ignore

        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename=smart-forms-entries-' . gmdate( 'Y-m-d' ) . '.csv' );

        $out = fopen( 'php://output', 'w' );
        if ( $rows ) {
            fputcsv( $out, array_keys( $rows[0] ) );
            foreach ( $rows as $r ) {
                fputcsv( $out, $r );
            }
        } else {
            fputcsv( $out, array( 'no entries yet' ) );
        }
        fclose( $out );
        exit;
    }

    /**
     * Integration log page — last 100 outbound calls from any
     * integration (Resend, CRM, Webhooks). Operator's first stop
     * for "why didn't this lead reach my CRM / inbox / Zapier?".
     */
    public function render_log_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $rows = class_exists( 'SFCO_Pro_Log' ) ? SFCO_Pro_Log::recent( 100 ) : array();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Integration Log', 'smart-forms-for-midland' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Every outbound call from Resend, CRM, or a per-form Webhook is recorded here. Shows the 100 most recent events.', 'smart-forms-for-midland' ); ?></p>
            <?php if ( empty( $rows ) ) : ?>
                <p style="background:#fff;border:1px dashed #cbd5d0;padding:18px;border-radius:6px;color:#6b8278;"><?php esc_html_e( 'No integration events yet. Submit a form once an integration is configured and they will appear here.', 'smart-forms-for-midland' ); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width:160px;"><?php esc_html_e( 'When', 'smart-forms-for-midland' ); ?></th>
                            <th style="width:90px;"><?php esc_html_e( 'Service', 'smart-forms-for-midland' ); ?></th>
                            <th style="width:80px;"><?php esc_html_e( 'Status', 'smart-forms-for-midland' ); ?></th>
                            <th style="width:80px;"><?php esc_html_e( 'Form / Lead', 'smart-forms-for-midland' ); ?></th>
                            <th><?php esc_html_e( 'Message', 'smart-forms-for-midland' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $rows as $r ) :
                            $is_ok = ( 'ok' === $r->status );
                            ?>
                            <tr>
                                <td><?php echo esc_html( $r->created_at ); ?> UTC</td>
                                <td><code><?php echo esc_html( $r->integration ); ?></code></td>
                                <td><span style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:800;text-transform:uppercase;<?php echo $is_ok ? 'background:#F3FCF4;color:#2F8137;border:1px solid #7CCE8E;' : ( 'skipped' === $r->status ? 'background:#fff8e5;color:#7a5b00;border:1px solid #e6c75e;' : 'background:#fdecec;color:#7a1d1d;border:1px solid #f1b4b4;' ); ?>"><?php echo esc_html( $r->status ); ?></span></td>
                                <td><?php echo $r->form_id ? esc_html( '#' . $r->form_id ) : '—'; ?><?php echo $r->lead_id ? '<br><small>lead ' . esc_html( $r->lead_id ) . '</small>' : ''; ?></td>
                                <td><?php echo esc_html( $r->message ?: '—' ); ?>
                                    <?php if ( ! empty( $r->response ) ) : ?>
                                        <details style="margin-top:4px;"><summary style="cursor:pointer;color:#2F8137;font-size:12px;">view response</summary><pre style="background:#0F1411;color:#7CCE8E;padding:10px;border-radius:4px;font-size:11px;max-height:200px;overflow:auto;"><?php echo esc_html( $r->response ); ?></pre></details>
                                    <?php endif; ?>
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
     * AJAX endpoint for the field builder. Receives the serialised field
     * tree from the editor and persists it to fields_json.
     */
    public function ajax_save_form_fields() {
        check_ajax_referer( 'sfco_builder', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'forbidden', 403 );
        }
        $form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
        if ( ! $form_id ) {
            wp_send_json_error( 'missing form_id' );
        }
        $fields_raw = isset( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : '[]';
        $fields = json_decode( (string) $fields_raw, true );
        if ( ! is_array( $fields ) ) {
            wp_send_json_error( 'fields must be a JSON array' );
        }

        // Whitelist field types so the editor can't inject arbitrary
        // values that the renderer would have to defend against.
        $allowed_types = array( 'text', 'email', 'tel', 'number', 'textarea', 'select', 'checkbox', 'radio', 'file', 'date', 'hidden', 'html' );
        $clean = array();
        foreach ( $fields as $i => $f ) {
            if ( ! is_array( $f ) ) {
                continue;
            }
            $type = isset( $f['type'] ) && in_array( $f['type'], $allowed_types, true ) ? $f['type'] : 'text';
            $key  = sanitize_key( (string) ( $f['key'] ?? 'field_' . ( $i + 1 ) ) );
            if ( '' === $key ) {
                $key = 'field_' . ( $i + 1 );
            }
            $clean[] = array(
                'key'         => $key,
                'type'        => $type,
                'label'       => sanitize_text_field( (string) ( $f['label'] ?? '' ) ),
                'placeholder' => sanitize_text_field( (string) ( $f['placeholder'] ?? '' ) ),
                'description' => sanitize_text_field( (string) ( $f['description'] ?? '' ) ),
                'required'    => ! empty( $f['required'] ),
                'default'     => sanitize_text_field( (string) ( $f['default'] ?? '' ) ),
                'options'     => array_values( array_map( 'sanitize_text_field', (array) ( $f['options'] ?? array() ) ) ),
                'rows'        => max( 1, min( 30, (int) ( $f['rows'] ?? 4 ) ) ),
                'min'         => isset( $f['min'] ) && '' !== $f['min'] ? (string) sanitize_text_field( (string) $f['min'] ) : '',
                'max'         => isset( $f['max'] ) && '' !== $f['max'] ? (string) sanitize_text_field( (string) $f['max'] ) : '',
                'accept'      => sanitize_text_field( (string) ( $f['accept'] ?? '' ) ),
                'html'        => wp_kses_post( (string) ( $f['html'] ?? '' ) ),
            );
        }

        SFCO_Database::update_form( $form_id, array( 'fields_json' => wp_json_encode( $clean ) ) );
        wp_send_json_success( array( 'count' => count( $clean ) ) );
    }
}

new SFCO_Admin();
