<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFCO_Pro_Automations {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ), 30 );
        add_action( 'admin_init', array( $this, 'handle_save' ) );
        add_action( 'wp_ajax_sfco_submit', array( $this, 'after_lead_created' ), 99 );

        // Cron for time-based triggers.
        add_action( 'sfco_pro_cron_automations', array( $this, 'process_scheduled' ) );
        if ( ! wp_next_scheduled( 'sfco_pro_cron_automations' ) ) {
            wp_schedule_event( time(), 'hourly', 'sfco_pro_cron_automations' );
        }
    }

    public function add_menu() {
        add_submenu_page(
            'sfco-forms',
            esc_html__( 'Automations', 'smart-forms-pro' ),
            esc_html__( 'Automations', 'smart-forms-pro' ),
            'manage_options',
            'sfco-automations',
            array( $this, 'render_page' )
        );
    }

    public function handle_save() {
        if ( ! isset( $_POST['sfco_save_automation'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_POST['_sfco_auto_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_sfco_auto_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'sfco_save_automation' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'smart-forms-pro' ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sfco_automations';

        $auto_id = isset( $_POST['automation_id'] ) ? absint( $_POST['automation_id'] ) : 0;
        $name    = isset( $_POST['auto_name'] ) ? sanitize_text_field( wp_unslash( $_POST['auto_name'] ) ) : '';
        $trigger = isset( $_POST['auto_trigger'] ) ? sanitize_key( $_POST['auto_trigger'] ) : '';
        $active  = isset( $_POST['auto_active'] ) ? 1 : 0;

        // Parse steps.
        $steps = array();
        $step_types    = isset( $_POST['step_type'] ) ? array_map( 'sanitize_key', $_POST['step_type'] ) : array();
        $step_delays   = isset( $_POST['step_delay'] ) ? array_map( 'absint', $_POST['step_delay'] ) : array();
        $step_subjects = isset( $_POST['step_subject'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['step_subject'] ?? array() ) ) : array();
        $step_bodies   = isset( $_POST['step_body'] ) ? array_map( 'wp_kses_post', wp_unslash( $_POST['step_body'] ?? array() ) ) : array();

        for ( $i = 0; $i < count( $step_types ); $i++ ) {
            $steps[] = array(
                'type'         => $step_types[ $i ] ?? 'email',
                'delay_hours'  => $step_delays[ $i ] ?? 0,
                'subject'      => $step_subjects[ $i ] ?? '',
                'body'         => $step_bodies[ $i ] ?? '',
            );
        }

        $data = array(
            'name'          => $name,
            'trigger_event' => $trigger,
            'steps'         => wp_json_encode( $steps ),
            'active'        => $active,
            'updated_at'    => current_time( 'mysql' ),
        );

        if ( $auto_id > 0 ) {
            $wpdb->update( $table, $data, array( 'id' => $auto_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        } else {
            $data['created_at'] = current_time( 'mysql' );
            $wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $auto_id = $wpdb->insert_id;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=sfco-automations&saved=1' ) );
        exit;
    }

    /**
     * Fire "new_lead" automations after a lead is saved by the free plugin.
     */
    public function after_lead_created() {
        if ( ! SFCO_Pro_License::is_valid() ) {
            return;
        }

        // The free plugin already handled the response. We just trigger automations.
        // Hook into the lead creation from the free plugin's AJAX handler.
        global $wpdb;
        $leads_table = $wpdb->prefix . 'sfco_leads';

        // Get the most recently created lead.
        $lead = $wpdb->get_row( "SELECT * FROM {$leads_table} ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( ! $lead ) {
            return;
        }

        $this->run_trigger( 'new_lead', $lead );
    }

    /**
     * Run all automations matching a trigger.
     */
    private function run_trigger( $trigger_event, $lead ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sfco_automations';
        $logs  = $wpdb->prefix . 'sfco_automation_logs';

        $automations = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT * FROM {$table} WHERE trigger_event = %s AND active = 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $trigger_event
        ) );

        foreach ( $automations as $auto ) {
            $steps = json_decode( $auto->steps, true );
            if ( ! is_array( $steps ) ) {
                continue;
            }

            // Check if already processed.
            $existing = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                "SELECT id FROM {$logs} WHERE automation_id = %d AND lead_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $auto->id,
                $lead->id
            ) );
            if ( $existing ) {
                continue;
            }

            foreach ( $steps as $i => $step ) {
                $this->execute_step( $auto->id, $lead, $i, $step );
            }
        }
    }

    private function execute_step( $automation_id, $lead, $step_index, $step ) {
        global $wpdb;
        $logs = $wpdb->prefix . 'sfco_automation_logs';

        $tags = array(
            '{name}'         => $lead->customer_name ?? '',
            '{email}'        => $lead->customer_email ?? '',
            '{phone}'        => $lead->customer_phone ?? '',
            '{project_type}' => $lead->project_type ?? '',
            '{timeline}'     => $lead->timeline ?? '',
        );

        if ( 'email' === $step['type'] && ! empty( $lead->customer_email ) ) {
            $subject = str_replace( array_keys( $tags ), array_values( $tags ), $step['subject'] ?? '' );
            $body    = str_replace( array_keys( $tags ), array_values( $tags ), $step['body'] ?? '' );

            $sent = wp_mail( $lead->customer_email, $subject, $body );

            $wpdb->insert( $logs, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                'automation_id' => $automation_id,
                'lead_id'       => $lead->id,
                'step_index'    => $step_index,
                'step_type'     => 'email',
                'status'        => $sent ? 'success' : 'error',
                'error_msg'     => $sent ? null : 'wp_mail failed',
                'executed_at'   => current_time( 'mysql' ),
            ) );
        }
    }

    /**
     * Cron: process time-based triggers (no_response_3_days, no_response_7_days).
     */
    public function process_scheduled() {
        if ( ! SFCO_Pro_License::is_valid() ) {
            return;
        }

        global $wpdb;
        $leads_table = $wpdb->prefix . 'sfco_leads';

        // 3-day no response.
        $leads_3d = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT * FROM {$leads_table} WHERE status = 'new' AND created_at < DATE_SUB(NOW(), INTERVAL 3 DAY) AND created_at > DATE_SUB(NOW(), INTERVAL 4 DAY)" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );
        foreach ( $leads_3d as $lead ) {
            $this->run_trigger( 'no_response_3_days', $lead );
        }

        // 7-day no response.
        $leads_7d = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT * FROM {$leads_table} WHERE status = 'new' AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) AND created_at > DATE_SUB(NOW(), INTERVAL 8 DAY)" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );
        foreach ( $leads_7d as $lead ) {
            $this->run_trigger( 'no_response_7_days', $lead );
        }
    }

    public function render_page() {
        if ( ! SFCO_Pro_License::is_valid() ) {
            echo '<div class="wrap"><div class="notice notice-warning"><p>' . esc_html__( 'Please activate your PRO license to use automations.', 'smart-forms-pro' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=sfco-license' ) ) . '">' . esc_html__( 'Enter License Key', 'smart-forms-pro' ) . '</a></p></div></div>';
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sfco_automations';

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $action  = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $auto_id = isset( $_GET['automation_id'] ) ? absint( $_GET['automation_id'] ) : 0;

        if ( 'edit' === $action || 'new' === $action ) {
            $this->render_editor( $auto_id );
            return;
        }

        // Delete.
        if ( 'delete' === $action && $auto_id > 0 ) {
            $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( wp_verify_nonce( $nonce, 'sfco_delete_auto_' . $auto_id ) ) {
                $wpdb->delete( $table, array( 'id' => $auto_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            }
        }

        $automations = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Automations', 'smart-forms-pro' ); ?></h1>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfco-automations&action=new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'smart-forms-pro' ); ?></a>
            <hr class="wp-header-end">

            <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Automation saved.', 'smart-forms-pro' ); ?></p></div>
            <?php endif; ?>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Name', 'smart-forms-pro' ); ?></th>
                        <th><?php esc_html_e( 'Trigger', 'smart-forms-pro' ); ?></th>
                        <th><?php esc_html_e( 'Steps', 'smart-forms-pro' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'smart-forms-pro' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'smart-forms-pro' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $automations ) ) : ?>
                        <tr><td colspan="5"><?php esc_html_e( 'No automations yet. Create your first one!', 'smart-forms-pro' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $automations as $auto ) : ?>
                            <?php $steps = json_decode( $auto->steps, true ); ?>
                            <tr>
                                <td><a href="<?php echo esc_url( admin_url( 'admin.php?page=sfco-automations&action=edit&automation_id=' . $auto->id ) ); ?>"><strong><?php echo esc_html( $auto->name ); ?></strong></a></td>
                                <td><?php echo esc_html( $this->trigger_label( $auto->trigger_event ) ); ?></td>
                                <td><?php echo esc_html( is_array( $steps ) ? count( $steps ) : 0 ); ?></td>
                                <td>
                                    <?php if ( $auto->active ) : ?>
                                        <span class="sfco-status-badge sfco-status-active"><?php esc_html_e( 'Active', 'smart-forms-pro' ); ?></span>
                                    <?php else : ?>
                                        <span class="sfco-status-badge sfco-status-draft"><?php esc_html_e( 'Inactive', 'smart-forms-pro' ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfco-automations&action=edit&automation_id=' . $auto->id ) ); ?>"><?php esc_html_e( 'Edit', 'smart-forms-pro' ); ?></a>
                                    |
                                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=sfco-automations&action=delete&automation_id=' . $auto->id ), 'sfco_delete_auto_' . $auto->id ) ); ?>" class="sfco-delete-link"><?php esc_html_e( 'Delete', 'smart-forms-pro' ); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_editor( $auto_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sfco_automations';

        $auto  = null;
        $steps = array();

        if ( $auto_id > 0 ) {
            $auto = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $auto_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ( $auto ) {
                $steps = json_decode( $auto->steps, true ) ?: array();
            }
        }

        $name    = $auto ? $auto->name : '';
        $trigger = $auto ? $auto->trigger_event : 'new_lead';
        $active  = $auto ? (bool) $auto->active : true;

        $triggers = array(
            'new_lead'              => __( 'New lead submitted', 'smart-forms-pro' ),
            'no_response_3_days'    => __( 'No response after 3 days', 'smart-forms-pro' ),
            'no_response_7_days'    => __( 'No response after 7 days', 'smart-forms-pro' ),
            'status_change'         => __( 'Lead status changed', 'smart-forms-pro' ),
        );
        ?>
        <div class="wrap">
            <h1><?php echo $auto_id ? esc_html__( 'Edit Automation', 'smart-forms-pro' ) : esc_html__( 'New Automation', 'smart-forms-pro' ); ?></h1>

            <form method="post">
                <?php wp_nonce_field( 'sfco_save_automation', '_sfco_auto_nonce' ); ?>
                <input type="hidden" name="automation_id" value="<?php echo esc_attr( $auto_id ); ?>">

                <table class="form-table">
                    <tr>
                        <th><label for="auto_name"><?php esc_html_e( 'Name', 'smart-forms-pro' ); ?></label></th>
                        <td><input type="text" name="auto_name" id="auto_name" class="regular-text" value="<?php echo esc_attr( $name ); ?>" required></td>
                    </tr>
                    <tr>
                        <th><label for="auto_trigger"><?php esc_html_e( 'Trigger', 'smart-forms-pro' ); ?></label></th>
                        <td>
                            <select name="auto_trigger" id="auto_trigger">
                                <?php foreach ( $triggers as $val => $label ) : ?>
                                    <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $trigger, $val ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Active', 'smart-forms-pro' ); ?></th>
                        <td><label><input type="checkbox" name="auto_active" value="1" <?php checked( $active ); ?>> <?php esc_html_e( 'Enable this automation', 'smart-forms-pro' ); ?></label></td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Steps', 'smart-forms-pro' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Tags: {name}, {email}, {phone}, {project_type}, {timeline}', 'smart-forms-pro' ); ?></p>

                <div id="sfco-auto-steps">
                    <?php if ( empty( $steps ) ) : ?>
                        <?php $this->render_step_row( 0 ); ?>
                    <?php else : ?>
                        <?php foreach ( $steps as $i => $step ) : ?>
                            <?php $this->render_step_row( $i, $step ); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <p><button type="button" class="button" id="sfco-add-step"><?php esc_html_e( '+ Add Step', 'smart-forms-pro' ); ?></button></p>

                <p class="submit">
                    <button type="submit" name="sfco_save_automation" value="1" class="button button-primary"><?php esc_html_e( 'Save Automation', 'smart-forms-pro' ); ?></button>
                </p>
            </form>
        </div>
        <?php
    }

    private function render_step_row( $index, $step = array() ) {
        $type    = $step['type'] ?? 'email';
        $delay   = $step['delay_hours'] ?? 0;
        $subject = $step['subject'] ?? '';
        $body    = $step['body'] ?? '';
        ?>
        <div class="sfco-auto-step sfco-card" style="margin-bottom:12px;">
            <div style="display:flex;gap:12px;align-items:center;margin-bottom:10px;">
                <select name="step_type[]">
                    <option value="email" <?php selected( $type, 'email' ); ?>><?php esc_html_e( 'Send Email', 'smart-forms-pro' ); ?></option>
                    <option value="wait" <?php selected( $type, 'wait' ); ?>><?php esc_html_e( 'Wait', 'smart-forms-pro' ); ?></option>
                </select>
                <label><?php esc_html_e( 'Delay (hours):', 'smart-forms-pro' ); ?> <input type="number" name="step_delay[]" value="<?php echo esc_attr( $delay ); ?>" min="0" style="width:70px;"></label>
                <button type="button" class="button sfco-remove-step">&times;</button>
            </div>
            <div>
                <input type="text" name="step_subject[]" class="large-text" value="<?php echo esc_attr( $subject ); ?>" placeholder="<?php esc_attr_e( 'Email subject...', 'smart-forms-pro' ); ?>" style="margin-bottom:6px;">
                <textarea name="step_body[]" rows="4" class="large-text" placeholder="<?php esc_attr_e( 'Email body (HTML supported)...', 'smart-forms-pro' ); ?>"><?php echo esc_textarea( $body ); ?></textarea>
            </div>
        </div>
        <?php
    }

    private function trigger_label( $trigger ) {
        $labels = array(
            'new_lead'              => __( 'New lead submitted', 'smart-forms-pro' ),
            'no_response_3_days'    => __( 'No response - 3 days', 'smart-forms-pro' ),
            'no_response_7_days'    => __( 'No response - 7 days', 'smart-forms-pro' ),
            'status_change'         => __( 'Status changed', 'smart-forms-pro' ),
        );
        return $labels[ $trigger ] ?? $trigger;
    }
}

new SFCO_Pro_Automations();
