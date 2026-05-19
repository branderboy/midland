<?php
/**
 * ActiveCampaign JSON export.
 *
 * Smart CRM's WP-side scope is narrow: receive Smart Forms submissions,
 * tag them as 'new_lead', then make those contacts available as a JSON
 * file the operator uploads to ActiveCampaign. The rest of the segment
 * lifecycle (cold reactivation, plan upsell, plan-active VIP) is run
 * inside AC's automation builder using the new-lead tag as the entry
 * point — WordPress doesn't push those.
 *
 * Why JSON-then-upload instead of live API push:
 *   - No API key to maintain or leak.
 *   - Operator can review the file before importing.
 *   - Works for AC accounts on plans without API access.
 *   - Idempotent — re-uploading the same file is a no-op in AC.
 *
 * The exported JSON matches AC's bulk-import endpoint shape so the file
 * can also be POSTed to /api/3/import/bulk_import if the operator wants
 * to script it later.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SCRM_Pro_AC_Export {

    const PAGE = 'scrm-ac-export';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ), 41 );
        add_action( 'admin_init', array( $this, 'maybe_download' ) );
    }

    public function add_menu() {
        add_submenu_page(
            'smart-crm',
            __( 'AC Export', 'smart-crm-pro' ),
            __( 'AC Export', 'smart-crm-pro' ),
            'manage_options',
            self::PAGE,
            array( $this, 'render' )
        );
    }

    /**
     * Pull every Smart Forms lead from the last N days (default 30) and
     * shape it as ActiveCampaign's bulk-import format. Returns the array
     * — the download handler json_encodes it; the preview UI renders it.
     */
    /**
     * Derive a service-area bucket from the ZIP code so AC automations
     * can route by territory without us shipping a 41,000-row zip lookup.
     * DC = 200xx / 202xx. MD = 206xx-219xx. VA = 220xx-246xx. Anything
     * else falls into 'other' and the operator can drop those from the
     * funnel manually.
     */
    private function area_from_zip( string $zip ): string {
        $z = (int) substr( preg_replace( '/\D/', '', $zip ), 0, 5 );
        if ( $z >= 20000 && $z <= 20099 ) return 'dc';
        if ( $z >= 20200 && $z <= 20599 ) return 'dc';
        if ( $z >= 20600 && $z <= 21999 ) return 'md';
        if ( $z >= 22000 && $z <= 24699 ) return 'va';
        return 'other';
    }

    /**
     * Normalise the free-text "project_type" field into one of the
     * service buckets AC's automation conditions key off. Keeps tag
     * variants and field values consistent regardless of how the
     * form labeled the option (some forms say "Carpet", others
     * "Commercial Carpet Cleaning Services").
     */
    private function service_type( string $raw ): string {
        $s = strtolower( $raw );
        if ( strpos( $s, 'carpet' )    !== false ) return 'carpet';
        if ( strpos( $s, 'tile' )      !== false || strpos( $s, 'grout' )    !== false ) return 'tile-grout';
        if ( strpos( $s, 'strip' )     !== false || strpos( $s, 'wax' )      !== false ) return 'strip-wax';
        if ( strpos( $s, 'concrete' )  !== false || strpos( $s, 'polish' )   !== false ) return 'concrete-polish';
        if ( strpos( $s, 'water' )     !== false || strpos( $s, 'restore' )  !== false ) return 'water-damage';
        if ( strpos( $s, 'upholstery' )!== false )                                       return 'upholstery';
        if ( strpos( $s, 'hardwood' )  !== false || strpos( $s, 'wood' )     !== false ) return 'hardwood';
        return 'other';
    }

    private function collect_contacts( int $days = 30 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfco_leads';
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

        $rows = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT * FROM {$table} WHERE created_at >= %s ORDER BY id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $cutoff
        ) );

        $contacts = array();
        foreach ( $rows as $r ) {
            $email = isset( $r->customer_email ) ? sanitize_email( $r->customer_email ) : '';
            if ( ! is_email( $email ) ) {
                continue;
            }
            $name_parts = array_pad( explode( ' ', trim( (string) ( $r->customer_name ?? '' ) ), 2 ), 2, '' );

            $project_raw  = (string) ( $r->project_type ?? '' );
            $service_type = $this->service_type( $project_raw );
            $is_emergency = ! empty( $r->is_emergency )
                || $service_type === 'water-damage'
                || stripos( $project_raw, 'emergency' ) !== false
                || stripos( $project_raw, 'urgent' )    !== false;
            // Segment now comes from the form's explicit property_type
            // radio (Commercial / Residential). Old leads without the
            // field fall back to a best guess from project_type so
            // historic exports don't lose context.
            $prop = strtolower( (string) ( $r->property_type ?? '' ) );
            if ( '' !== $prop ) {
                $segment = ( 'commercial' === $prop ) ? 'commercial' : 'residential';
            } else {
                $segment = ( stripos( $project_raw, 'commercial' ) !== false ) ? 'commercial' : 'residential';
            }

            // Intent comes from the form's lead_intent radio. Values:
            //   emergency       → urgent on-site response
            //   book_visit      → standard on-site evaluation (default)
            //   future_project  → commercial planning, long horizon
            //   research        → educational drip only, no visit yet
            // Legacy 'book_now' / 'need_quote' are mapped to book_visit
            // since both flows ended in "come see the space first".
            $intent_raw = strtolower( (string) ( $r->lead_intent ?? '' ) );
            if ( in_array( $intent_raw, array( 'book_now', 'need_quote' ), true ) ) {
                $intent_raw = 'book_visit';
            }
            $allowed_intents = array( 'emergency', 'book_visit', 'future_project', 'research' );
            $intent          = in_array( $intent_raw, $allowed_intents, true ) ? $intent_raw : 'unknown';

            // Intent 'emergency' is the strongest emergency signal,
            // stronger than service-type or message-text hints.
            if ( 'emergency' === $intent ) {
                $is_emergency = true;
            }

            $zip  = (string) ( $r->zip_code ?? '' );
            $area = $this->area_from_zip( $zip );

            // Tag stack: each tag drives a separate AC condition. The
            // operator picks one tag per automation. Goal: AC can route
            // by segment, area, service type, and emergency flag from
            // tags alone without scanning custom fields.
            $tags = array(
                'midland-segment-new-lead',
                'midland-segment-new-lead-' . $segment,
                'midland-intent-' . str_replace( '_', '-', $intent ),
                'midland-area-' . $area,
                'midland-service-' . $service_type,
            );
            if ( $is_emergency ) {
                $tags[] = 'midland-segment-new-lead-emergency';
            }

            $contacts[] = array(
                'email'      => $email,
                'first_name' => $name_parts[0],
                'last_name'  => $name_parts[1],
                'phone'      => (string) ( $r->customer_phone ?? '' ),
                'tags'       => $tags,
                // Everything actionable from the form goes into AC custom
                // fields so personalization tokens ({{contact.zip_code}})
                // and conditions ("if service_type = water-damage") work.
                'fields'     => array(
                    array( 'name' => 'service_type',    'value' => $service_type ),
                    array( 'name' => 'project_type',    'value' => $project_raw ),
                    array( 'name' => 'segment',         'value' => $segment ),
                    array( 'name' => 'is_emergency',    'value' => $is_emergency ? 'yes' : 'no' ),
                    array( 'name' => 'area',            'value' => $area ),
                    array( 'name' => 'zip_code',        'value' => $zip ),
                    array( 'name' => 'timeline',        'value' => (string) ( $r->timeline ?? '' ) ),
                    array( 'name' => 'square_footage',  'value' => (string) ( $r->square_footage ?? '' ) ),
                    array( 'name' => 'message',         'value' => (string) ( $r->additional_notes ?? '' ) ),
                    array( 'name' => 'submitted_at',    'value' => (string) ( $r->created_at ?? '' ) ),
                    array( 'name' => 'source_form_id',  'value' => (string) ( $r->form_id ?? '' ) ),
                    array( 'name' => 'midland_lead_id', 'value' => (string) ( $r->id ?? '' ) ),
                ),
            );
        }

        return $contacts;
    }

    public function maybe_download() {
        if ( ! is_admin() || empty( $_GET['scrm_ac_export'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'No.' );
        }
        check_admin_referer( 'scrm_ac_export' );

        $days     = isset( $_GET['days'] ) ? max( 1, min( 365, (int) $_GET['days'] ) ) : 30;
        $contacts = $this->collect_contacts( $days );

        // Full automation flow blueprint: contacts on the bottom, the AC
        // automation recipes the operator should build at the top, plus
        // a meta block so future re-imports know which Midland install
        // produced the file.
        $payload = array(
            'meta' => array(
                'source'         => 'midland-smart-crm',
                'site_url'       => home_url(),
                'generated_at'   => gmdate( 'c' ),
                'lookback_days'  => $days,
                'contact_count'  => count( $contacts ),
            ),
            'automation_blueprint' => $this->automation_blueprint(),
            'contacts'             => $contacts,
        );

        nocache_headers();
        header( 'Content-Type: application/json; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename=midland-automation-flow-' . gmdate( 'Y-m-d' ) . '.json' );
        echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        exit;
    }

    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $days     = isset( $_GET['days'] ) ? max( 1, min( 365, (int) $_GET['days'] ) ) : 30; // phpcs:ignore WordPress.Security.NonceVerification
        $contacts = $this->collect_contacts( $days );
        $download = wp_nonce_url(
            add_query_arg( array( 'page' => self::PAGE, 'days' => $days, 'scrm_ac_export' => 'json' ), admin_url( 'admin.php' ) ),
            'scrm_ac_export'
        );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'ActiveCampaign Export', 'smart-crm-pro' ); ?></h1>
            <p class="description" style="max-width:720px;">
                <?php esc_html_e( 'Download every new lead from the last N days as a JSON file in ActiveCampaign\'s bulk-import format. Upload it to AC via Contacts → Import → JSON. Each contact is pre-tagged with midland-segment-new-lead so your AC welcome automation picks them up automatically.', 'smart-crm-pro' ); ?>
            </p>

            <form method="get" action="" style="margin:16px 0;">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
                <label><?php esc_html_e( 'Lookback window:', 'smart-crm-pro' ); ?>
                    <select name="days" onchange="this.form.submit();">
                        <?php foreach ( array( 7, 14, 30, 60, 90, 180, 365 ) as $n ) : ?>
                            <option value="<?php echo (int) $n; ?>" <?php selected( $days, $n ); ?>><?php echo esc_html( $n . ' days' ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <noscript><button class="button" type="submit"><?php esc_html_e( 'Update', 'smart-crm-pro' ); ?></button></noscript>
            </form>

            <p>
                <a href="<?php echo esc_url( $download ); ?>" class="button button-primary" style="background:#43A94B;border-color:#43A94B;">
                    <?php echo esc_html( sprintf( __( 'Download JSON (%d contacts)', 'smart-crm-pro' ), count( $contacts ) ) ); ?>
                </a>
            </p>

            <h2><?php esc_html_e( 'Preview', 'smart-crm-pro' ); ?></h2>
            <pre style="background:#0F1411;color:#7CCE8E;padding:16px;border-radius:6px;max-height:400px;overflow:auto;font-size:12px;"><?php echo esc_html( wp_json_encode( array( 'contacts' => array_slice( $contacts, 0, 3 ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?>
<?php if ( count( $contacts ) > 3 ) : ?>... (<?php echo (int) ( count( $contacts ) - 3 ); ?> more contacts, full list in the download)<?php endif; ?></pre>

            <h3><?php esc_html_e( 'How to import into ActiveCampaign', 'smart-crm-pro' ); ?></h3>
            <ol style="line-height:1.7;color:#1d2933;max-width:720px;">
                <li><?php esc_html_e( 'Click Download JSON above. The file lands in your downloads folder.', 'smart-crm-pro' ); ?></li>
                <li><?php esc_html_e( 'In ActiveCampaign: Contacts → Import → Choose file → upload the JSON.', 'smart-crm-pro' ); ?></li>
                <li><?php esc_html_e( 'Map the columns AC suggests (email / first_name / etc. should auto-match).', 'smart-crm-pro' ); ?></li>
                <li><?php esc_html_e( 'Confirm — AC creates / updates contacts and applies the midland-segment-new-lead tag.', 'smart-crm-pro' ); ?></li>
                <li><?php esc_html_e( 'Your AC automation triggered by that tag fires for each newly-imported contact.', 'smart-crm-pro' ); ?></li>
            </ol>
        </div>
        <?php
    }
}
