<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DPJP_Meta_Fields {

    const FIELDS = [
        'dpjp_trade'             => [ 'label' => 'Trade / Skill',         'type' => 'text',     'placeholder' => 'e.g. Drywall, Framing' ],
        'dpjp_location'          => [ 'label' => 'Job Location',           'type' => 'text',     'placeholder' => 'e.g. Washington DC Metro Area' ],
        'dpjp_pay'               => [ 'label' => 'Pay',                    'type' => 'text',     'placeholder' => 'e.g. $25–$35/hr' ],
        'dpjp_employment_type'   => [ 'label' => 'Employment Type',        'type' => 'select',   'options' => [ 'full-time', 'part-time', 'contract', 'seasonal' ] ],
        'dpjp_requirements'      => [ 'label' => 'Requirements',           'type' => 'textarea', 'placeholder' => "One per line:\n2+ years experience\nReliable transportation" ],
        'dpjp_contact_name'      => [ 'label' => 'Contact Name',           'type' => 'text',     'placeholder' => 'e.g. Mike' ],
        'dpjp_contact_phone'     => [ 'label' => 'Contact Phone',          'type' => 'tel',      'placeholder' => '240-555-0100' ],
        'dpjp_contact_email'     => [ 'label' => 'Contact Email',          'type' => 'email',    'placeholder' => 'hr@example.com' ],
        'dpjp_call_to_action'    => [ 'label' => 'Call to Action',         'type' => 'text',     'placeholder' => 'Call or text us today — we respond fast.' ],
        'dpjp_valid_through'     => [ 'label' => 'Listing Expires',        'type' => 'date',     'placeholder' => '' ],
        'dpjp_craigslist_region' => [ 'label' => 'Craigslist Region',      'type' => 'text',     'placeholder' => 'washingtondc' ],
    ];

    public static function register(): void {
        add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_box' ] );
        add_action( 'save_post_dpjp_job', [ __CLASS__, 'save' ], 10, 2 );
    }

    public static function add_meta_box(): void {
        add_meta_box( 'dpjp_job_details', 'Job Details', [ __CLASS__, 'render' ], 'dpjp_job', 'normal', 'high' );
    }

    public static function render( WP_Post $post ): void {
        wp_nonce_field( 'dpjp_save_meta', 'dpjp_nonce' );
        $values = self::get( $post->ID );
        ?>
        <style>
            .dpjp-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px 24px; }
            .dpjp-field { display:flex; flex-direction:column; gap:4px; }
            .dpjp-field label { font-weight:600; font-size:13px; color:#1d2327; }
            .dpjp-field input, .dpjp-field select, .dpjp-field textarea {
                border:1px solid #8c8f94; border-radius:4px; padding:7px 10px; font-size:13px; width:100%; box-sizing:border-box;
            }
            .dpjp-field textarea { min-height:100px; resize:vertical; font-family:inherit; }
            .dpjp-full { grid-column: 1 / -1; }
            .dpjp-section { grid-column:1/-1; font-size:13px; font-weight:700; color:#2271b1;
                border-bottom:2px solid #2271b1; padding-bottom:4px; margin-top:8px; }
        </style>
        <div class="dpjp-grid">
            <p class="dpjp-section">Job Details</p>
            <?php self::field( 'dpjp_trade', $values ); ?>
            <?php self::field( 'dpjp_location', $values ); ?>
            <?php self::field( 'dpjp_pay', $values ); ?>
            <?php self::field( 'dpjp_employment_type', $values ); ?>
            <?php self::field( 'dpjp_valid_through', $values ); ?>
            <?php self::field( 'dpjp_craigslist_region', $values ); ?>
            <div class="dpjp-full"><?php self::field( 'dpjp_requirements', $values ); ?></div>
            <p class="dpjp-section">Contact Info</p>
            <?php self::field( 'dpjp_contact_name', $values ); ?>
            <?php self::field( 'dpjp_contact_phone', $values ); ?>
            <?php self::field( 'dpjp_contact_email', $values ); ?>
            <div class="dpjp-full"><?php self::field( 'dpjp_call_to_action', $values ); ?></div>
        </div>
        <?php
    }

    private static function field( string $key, array $values ): void {
        $cfg   = self::FIELDS[ $key ];
        $label = esc_html( $cfg['label'] );
        $val   = $values[ $key ] ?? '';
        echo '<div class="dpjp-field">';
        if ( $cfg['type'] === 'select' ) {
            echo "<label for='{$key}'>{$label}</label><select id='{$key}' name='{$key}'>";
            foreach ( $cfg['options'] as $opt ) {
                $sel = selected( $val, $opt, false );
                echo "<option value='" . esc_attr( $opt ) . "' {$sel}>" . esc_html( ucfirst( $opt ) ) . "</option>";
            }
            echo "</select>";
        } elseif ( $cfg['type'] === 'textarea' ) {
            $ph = esc_attr( $cfg['placeholder'] ?? '' );
            echo "<label for='{$key}'>{$label}</label><textarea id='{$key}' name='{$key}' placeholder='{$ph}'>" . esc_textarea( $val ) . "</textarea>";
        } else {
            $type = esc_attr( $cfg['type'] );
            $ph   = esc_attr( $cfg['placeholder'] ?? '' );
            echo "<label for='{$key}'>{$label}</label><input type='{$type}' id='{$key}' name='{$key}' value='" . esc_attr( $val ) . "' placeholder='{$ph}'>";
        }
        echo '</div>';
    }

    public static function save( int $post_id ): void {
        if ( ! isset( $_POST['dpjp_nonce'] ) || ! wp_verify_nonce( $_POST['dpjp_nonce'], 'dpjp_save_meta' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;
        foreach ( self::FIELDS as $key => $cfg ) {
            update_post_meta( $post_id, $key, sanitize_textarea_field( wp_unslash( $_POST[ $key ] ?? '' ) ) );
        }
    }

    public static function get( int $post_id ): array {
        $data = [];
        foreach ( array_keys( self::FIELDS ) as $key ) {
            $data[ $key ] = get_post_meta( $post_id, $key, true );
        }
        return $data;
    }
}
