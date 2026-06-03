<?php
/**
 * CSV / JSON job importer. Upload a file to bulk-create job listings.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class DPJP_Importer {

    public static function register(): void {
        add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
        add_action( 'admin_post_jmp_import_jobs', [ __CLASS__, 'handle_upload' ] );
    }

    public static function menu(): void {
        add_submenu_page(
            'edit.php?post_type=dpjp_job',
            __( 'Import Jobs', 'job-manager-pro' ),
            __( 'Import Jobs', 'job-manager-pro' ),
            'manage_options',
            'jmp-import',
            [ __CLASS__, 'render' ]
        );
    }

    public static function render(): void {
        $existing = get_posts( [ 'post_type' => 'dpjp_job', 'posts_per_page' => -1, 'fields' => 'ids', 'post_status' => 'any' ] );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Import Job Listings', 'job-manager-pro' ); ?></h1>

            <?php if ( isset( $_GET['imported'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php printf( esc_html__( 'Imported %d jobs. Skipped %d duplicates. %d errors.', 'job-manager-pro' ),
                        (int) $_GET['imported'], (int) ( $_GET['skipped'] ?? 0 ), (int) ( $_GET['errors'] ?? 0 ) ); ?>
                </p></div>
            <?php endif; ?>

            <p><?php esc_html_e( 'Currently published jobs:', 'job-manager-pro' ); ?> <strong><?php echo count( $existing ); ?></strong></p>

            <h2><?php esc_html_e( 'Upload CSV or JSON', 'job-manager-pro' ); ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field( 'jmp_import_jobs', 'jmp_import_nonce' ); ?>
                <input type="hidden" name="action" value="jmp_import_jobs">
                <table class="form-table">
                    <tr>
                        <th><label for="jmp-file"><?php esc_html_e( 'File', 'job-manager-pro' ); ?></label></th>
                        <td>
                            <input type="file" name="jobs_file" id="jmp-file" accept=".csv,.json" required>
                            <p class="description"><?php esc_html_e( 'CSV or JSON. See format below.', 'job-manager-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Duplicates', 'job-manager-pro' ); ?></th>
                        <td><label><input type="checkbox" name="skip_existing" value="1" checked> <?php esc_html_e( 'Skip jobs with a title that already exists', 'job-manager-pro' ); ?></label></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Status', 'job-manager-pro' ); ?></th>
                        <td>
                            <select name="post_status">
                                <option value="publish"><?php esc_html_e( 'Publish', 'job-manager-pro' ); ?></option>
                                <option value="draft"><?php esc_html_e( 'Draft', 'job-manager-pro' ); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button( esc_html__( 'Import Jobs', 'job-manager-pro' ), 'primary' ); ?>
            </form>

            <hr>

            <h2><?php esc_html_e( 'CSV Format', 'job-manager-pro' ); ?></h2>
            <p><?php esc_html_e( 'First row must be headers. Only "title" is required. All other columns are optional.', 'job-manager-pro' ); ?></p>
            <pre style="background:#f6f7f7;padding:15px;border:1px solid #dcdcde;overflow-x:auto;">title,trade,location,pay,employment_type,requirements,contact_name,contact_phone,contact_email,call_to_action,valid_through,description
Senior Plumber,Plumbing,"New York, NY",$30-$50/hr,full-time,"Licensed plumber|5+ years experience|Own tools",Jane Doe,555-123-4567,jane@example.com,"Apply today!",2026-12-31,"Full job description here..."
Apprentice Electrician,Electrical,"Boston, MA",$18-$25/hr,part-time,"High school diploma|Willing to learn",,,,"",,"Entry level position..."</pre>
            <p><strong><?php esc_html_e( 'Notes:', 'job-manager-pro' ); ?></strong></p>
            <ul style="list-style:disc;padding-left:20px;">
                <li><code>employment_type</code>: <code>full-time</code>, <code>part-time</code>, <code>contract</code>, or <code>seasonal</code></li>
                <li><code>requirements</code>: separate multiple requirements with <code>|</code> (pipe)</li>
                <li><code>valid_through</code>: date in YYYY-MM-DD format</li>
                <li><code>description</code>: the main body of the job posting</li>
            </ul>

            <h2><?php esc_html_e( 'JSON Format', 'job-manager-pro' ); ?></h2>
            <pre style="background:#f6f7f7;padding:15px;border:1px solid #dcdcde;overflow-x:auto;">[
  {
    "title": "Senior Plumber",
    "description": "Full job description here...",
    "trade": "Plumbing",
    "location": "New York, NY",
    "pay": "$30-$50/hr",
    "employment_type": "full-time",
    "requirements": ["Licensed plumber", "5+ years experience", "Own tools"],
    "contact_name": "Jane Doe",
    "contact_phone": "555-123-4567",
    "contact_email": "jane@example.com",
    "call_to_action": "Apply today!",
    "valid_through": "2026-12-31"
  }
]</pre>

            <h2><?php esc_html_e( 'Download Template', 'job-manager-pro' ); ?></h2>
            <p>
                <a href="<?php echo esc_url( admin_url( 'admin-post.php?action=jmp_download_template&type=csv' ) ); ?>" class="button"><?php esc_html_e( 'Download CSV Template', 'job-manager-pro' ); ?></a>
                <a href="<?php echo esc_url( admin_url( 'admin-post.php?action=jmp_download_template&type=json' ) ); ?>" class="button"><?php esc_html_e( 'Download JSON Template', 'job-manager-pro' ); ?></a>
            </p>
        </div>
        <?php
    }

    public static function handle_upload(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Permission denied.', 'job-manager-pro' ) );
        check_admin_referer( 'jmp_import_jobs', 'jmp_import_nonce' );

        if ( empty( $_FILES['jobs_file']['tmp_name'] ) ) {
            wp_die( esc_html__( 'No file uploaded.', 'job-manager-pro' ) );
        }

        $tmp      = $_FILES['jobs_file']['tmp_name'];
        $name     = $_FILES['jobs_file']['name'];
        $ext      = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
        $skip_dup = ! empty( $_POST['skip_existing'] );
        $status   = in_array( $_POST['post_status'] ?? '', [ 'publish', 'draft' ], true ) ? $_POST['post_status'] : 'publish';

        $jobs = [];
        if ( $ext === 'json' ) {
            $raw  = file_get_contents( $tmp );
            $data = json_decode( $raw, true );
            if ( is_array( $data ) ) $jobs = $data;
        } elseif ( $ext === 'csv' ) {
            $jobs = self::parse_csv( $tmp );
        } else {
            wp_die( esc_html__( 'Unsupported file type. Use CSV or JSON.', 'job-manager-pro' ) );
        }

        $imported = $skipped = $errors = 0;
        foreach ( $jobs as $job ) {
            if ( empty( $job['title'] ) ) { $errors++; continue; }
            if ( $skip_dup && get_page_by_title( $job['title'], OBJECT, 'dpjp_job' ) ) { $skipped++; continue; }

            $reqs = $job['requirements'] ?? '';
            if ( is_array( $reqs ) ) $reqs = implode( "\n", $reqs );
            elseif ( is_string( $reqs ) && strpos( $reqs, '|' ) !== false ) $reqs = str_replace( '|', "\n", $reqs );

            $post_id = wp_insert_post( [
                'post_title'   => sanitize_text_field( $job['title'] ),
                'post_content' => wp_kses_post( $job['description'] ?? '' ),
                'post_status'  => $status,
                'post_type'    => 'dpjp_job',
                'post_author'  => get_current_user_id(),
            ], true );

            if ( is_wp_error( $post_id ) || ! $post_id ) { $errors++; continue; }

            $meta_map = [
                'dpjp_trade'             => $job['trade']           ?? '',
                'dpjp_location'          => $job['location']        ?? '',
                'dpjp_pay'               => $job['pay']             ?? '',
                'dpjp_employment_type'   => $job['employment_type'] ?? 'full-time',
                'dpjp_requirements'      => $reqs,
                'dpjp_contact_name'      => $job['contact_name']    ?? '',
                'dpjp_contact_phone'     => $job['contact_phone']   ?? '',
                'dpjp_contact_email'     => $job['contact_email']   ?? '',
                'dpjp_call_to_action'    => $job['call_to_action']  ?? '',
                'dpjp_valid_through'     => $job['valid_through']   ?? '',
                'dpjp_craigslist_region' => $job['craigslist_region'] ?? '',
            ];
            foreach ( $meta_map as $k => $v ) update_post_meta( $post_id, $k, sanitize_textarea_field( $v ) );

            if ( class_exists( 'DPJP_Elementor' ) ) {
                DPJP_Elementor::maybe_apply_template( $post_id, get_post( $post_id ) );
            }

            $imported++;
        }

        wp_safe_redirect( add_query_arg(
            [ 'post_type' => 'dpjp_job', 'page' => 'jmp-import', 'imported' => $imported, 'skipped' => $skipped, 'errors' => $errors ],
            admin_url( 'edit.php' )
        ) );
        exit;
    }

    private static function parse_csv( string $path ): array {
        $jobs = [];
        if ( ( $fh = fopen( $path, 'r' ) ) === false ) return $jobs;
        $headers = fgetcsv( $fh );
        if ( ! $headers ) { fclose( $fh ); return $jobs; }
        $headers = array_map( function( $h ) { return strtolower( trim( $h ) ); }, $headers );
        while ( ( $row = fgetcsv( $fh ) ) !== false ) {
            if ( count( $row ) < 1 ) continue;
            $job = [];
            foreach ( $headers as $i => $h ) {
                $job[ $h ] = $row[ $i ] ?? '';
            }
            $jobs[] = $job;
        }
        fclose( $fh );
        return $jobs;
    }
}

// Template download handler
add_action( 'admin_post_jmp_download_template', function() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die();
    $type = ( $_GET['type'] ?? 'csv' ) === 'json' ? 'json' : 'csv';
    if ( $type === 'csv' ) {
        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment; filename="jobs-template.csv"' );
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, [ 'title', 'trade', 'location', 'pay', 'employment_type', 'requirements', 'contact_name', 'contact_phone', 'contact_email', 'call_to_action', 'valid_through', 'description' ] );
        fputcsv( $out, [ 'Example Job Title', 'Trade Name', 'City, ST', '$20-$30/hr', 'full-time', 'Requirement 1|Requirement 2|Requirement 3', 'Hiring Manager', '555-123-4567', 'hr@example.com', 'Apply today!', '2026-12-31', 'Job description goes here.' ] );
        fclose( $out );
    } else {
        header( 'Content-Type: application/json' );
        header( 'Content-Disposition: attachment; filename="jobs-template.json"' );
        echo wp_json_encode( [ [
            'title'           => 'Example Job Title',
            'description'     => 'Job description goes here.',
            'trade'           => 'Trade Name',
            'location'        => 'City, ST',
            'pay'             => '$20-$30/hr',
            'employment_type' => 'full-time',
            'requirements'    => [ 'Requirement 1', 'Requirement 2', 'Requirement 3' ],
            'contact_name'    => 'Hiring Manager',
            'contact_phone'   => '555-123-4567',
            'contact_email'   => 'hr@example.com',
            'call_to_action'  => 'Apply today!',
            'valid_through'   => '2026-12-31',
        ] ], JSON_PRETTY_PRINT );
    }
    exit;
} );
