<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SMSG_WhatsApp_API {

    private static $instance = null;
    private $access_token;
    private $phone_id;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->access_token = get_option( 'smsg_whatsapp_token', '' );
        $this->phone_id     = get_option( 'smsg_whatsapp_phone_id', '' );
    }

    public function is_configured() {
        return ! empty( $this->access_token ) && ! empty( $this->phone_id );
    }

    public function send_template_message( $to, $template_name, $params = array(), $lead_id = null ) {
        if ( ! $this->is_configured() ) {
            return array( 'success' => false, 'error' => __( 'Not configured', 'smart-messages' ) );
        }

        $to = $this->format_phone( $to );
        if ( empty( $to ) ) {
            return array( 'success' => false, 'error' => __( 'Invalid phone', 'smart-messages' ) );
        }

        $components = array();
        if ( ! empty( $params ) ) {
            $parameters = array();
            foreach ( $params as $param ) {
                $parameters[] = array( 'type' => 'text', 'text' => $param );
            }
            $components[] = array( 'type' => 'body', 'parameters' => $parameters );
        }

        $payload = array(
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'template',
            'template'          => array(
                'name'       => $template_name,
                'language'   => array( 'code' => 'en_US' ),
                'components' => $components,
            ),
        );

        $response = wp_remote_post( 'https://graph.facebook.com/v18.0/' . $this->phone_id . '/messages', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->access_token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            $this->log_message( $to, $template_name, array( 'error' => $response->get_error_message() ), $lead_id );
            return array( 'success' => false, 'error' => $response->get_error_message() );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        $this->log_message( $to, $template_name, $data, $lead_id );

        if ( isset( $data['messages'][0]['id'] ) ) {
            return array( 'success' => true, 'message_id' => $data['messages'][0]['id'] );
        }

        return array( 'success' => false, 'error' => $data['error']['message'] ?? __( 'Unknown error', 'smart-messages' ) );
    }

    private function format_phone( $phone ) {
        $phone = preg_replace( '/[^0-9+]/', '', $phone );
        $phone = ltrim( $phone, '+' );
        if ( strlen( $phone ) === 10 ) {
            $phone = '1' . $phone;
        }
        return $phone;
    }

    private function log_message( $to, $template, $response, $lead_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'smsg_messages';

        $this->maybe_create_table();

        $wpdb->insert( $table, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'lead_id'    => $lead_id,
            'phone'      => $to,
            'template'   => $template,
            'status'     => isset( $response['messages'][0]['id'] ) ? 'sent' : 'failed',
            'message_id' => $response['messages'][0]['id'] ?? null,
            'error'      => $response['error']['message'] ?? $response['error'] ?? null,
            'created_at' => current_time( 'mysql' ),
        ) );
    }

    private function maybe_create_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'smsg_messages';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            return;
        }

        $charset = $wpdb->get_charset_collate();
        $sql     = "CREATE TABLE $table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            lead_id bigint(20) DEFAULT NULL,
            phone varchar(20) NOT NULL,
            template varchar(100) DEFAULT NULL,
            status varchar(20) DEFAULT 'pending',
            message_id varchar(100) DEFAULT NULL,
            error text DEFAULT NULL,
            channel varchar(20) DEFAULT 'whatsapp',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY lead_id (lead_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public function get_messages( $lead_id = null, $limit = 50 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'smsg_messages';

        if ( $lead_id ) {
            return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE lead_id = %d ORDER BY created_at DESC LIMIT %d", $lead_id, $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d", $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public function log_sms( $to, $message, $response, $lead_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'smsg_messages';

        $this->maybe_create_table();

        $wpdb->insert( $table, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'lead_id'    => $lead_id,
            'phone'      => $to,
            'template'   => 'SMS: ' . substr( $message, 0, 50 ) . '...',
            'status'     => isset( $response['sid'] ) ? 'sent' : 'failed',
            'message_id' => $response['sid'] ?? null,
            'error'      => $response['message'] ?? null,
            'channel'    => 'sms',
            'created_at' => current_time( 'mysql' ),
        ) );
    }
}
