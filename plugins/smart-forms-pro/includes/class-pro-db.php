<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFCO_Pro_DB {

    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $automations_table = $wpdb->prefix . 'sfco_automations';
        $auto_logs_table   = $wpdb->prefix . 'sfco_automation_logs';
        $crm_sync_table    = $wpdb->prefix . 'sfco_crm_sync';
        $team_table        = $wpdb->prefix . 'sfco_team_members';

        $sql = "CREATE TABLE {$automations_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            trigger_event varchar(100) NOT NULL,
            steps longtext NOT NULL,
            active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) {$charset_collate};

        CREATE TABLE {$auto_logs_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            automation_id bigint(20) unsigned NOT NULL,
            lead_id bigint(20) unsigned NOT NULL,
            step_index int(11) NOT NULL,
            step_type varchar(50) NOT NULL,
            status varchar(20) NOT NULL,
            error_msg text DEFAULT NULL,
            executed_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY automation_id (automation_id),
            KEY lead_id (lead_id)
        ) {$charset_collate};

        CREATE TABLE {$crm_sync_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            crm_type varchar(50) NOT NULL,
            api_key_encrypted text NOT NULL,
            field_mapping longtext DEFAULT NULL,
            active tinyint(1) DEFAULT 1,
            last_sync_at datetime DEFAULT NULL,
            sync_count int(11) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY crm_type (crm_type)
        ) {$charset_collate};

        CREATE TABLE {$team_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned DEFAULT NULL,
            email varchar(255) NOT NULL,
            name varchar(255) DEFAULT NULL,
            role varchar(50) DEFAULT 'sales',
            invite_token varchar(64) DEFAULT NULL,
            invite_expires datetime DEFAULT NULL,
            accepted_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY email (email)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'sfco_pro_version', SFCO_PRO_VERSION );
    }
}
