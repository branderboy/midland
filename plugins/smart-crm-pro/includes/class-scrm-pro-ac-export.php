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

            $is_emergency = ! empty( $r->is_emergency ) || ( isset( $r->project_type ) && stripos( (string) $r->project_type, 'water' ) !== false );
            $segment      = ( isset( $r->project_type ) && stripos( (string) $r->project_type, 'commercial' ) !== false ) ? 'commercial' : 'residential';

            $tags = array( 'midland-segment-new-lead', 'midland-segment-new-lead-' . $segment );
            if ( $is_emergency ) {
                $tags[] = 'midland-segment-new-lead-emergency';
            }

            $contacts[] = array(
                'email'      => $email,
                'first_name' => $name_parts[0],
                'last_name'  => $name_parts[1],
                'phone'      => (string) ( $r->customer_phone ?? '' ),
                'tags'       => $tags,
                'fields'     => array(
                    array( 'name' => 'project_type',   'value' => (string) ( $r->project_type ?? '' ) ),
                    array( 'name' => 'timeline',       'value' => (string) ( $r->timeline ?? '' ) ),
                    array( 'name' => 'zip_code',       'value' => (string) ( $r->zip_code ?? '' ) ),
                    array( 'name' => 'square_footage', 'value' => (string) ( $r->square_footage ?? '' ) ),
                    array( 'name' => 'submitted_at',   'value' => (string) ( $r->created_at ?? '' ) ),
                    array( 'name' => 'midland_lead_id','value' => (string) ( $r->id ?? '' ) ),
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
        $payload  = array( 'contacts' => $contacts );

        nocache_headers();
        header( 'Content-Type: application/json; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename=midland-new-leads-' . gmdate( 'Y-m-d' ) . '.json' );
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
