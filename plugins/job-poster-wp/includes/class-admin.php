<?php
/**
 * Admin UI — sidebar action panel + settings page.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class DPJP_Admin {

    public static function register(): void {
        add_action( 'add_meta_boxes',    [ __CLASS__, 'add_action_box' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'admin_menu',        [ __CLASS__, 'settings_page' ] );
        add_action( 'admin_post_dpjp_save_settings', [ __CLASS__, 'save_settings' ] );
        add_filter( 'manage_dpjp_job_posts_columns',       [ __CLASS__, 'columns' ] );
        add_action( 'manage_dpjp_job_posts_custom_column', [ __CLASS__, 'column_content' ], 10, 2 );
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    public static function enqueue_assets( string $hook ): void {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ] ) ) return;
        if ( get_post_type() !== 'dpjp_job' ) return;
        wp_enqueue_script( 'dpjp-admin', DPJP_URL . 'assets/admin.js', [ 'jquery' ], DPJP_VERSION, true );
        wp_localize_script( 'dpjp-admin', 'dpjp', [
            'nonce'   => wp_create_nonce( 'dpjp_post_action' ),
            'ajax'    => admin_url( 'admin-ajax.php' ),
            'post_id' => get_the_ID(),
        ] );
    }

    // ── Action panel (sidebar) ────────────────────────────────────────────────

    public static function add_action_box(): void {
        add_meta_box( 'dpjp_actions', 'Post to Platforms', [ __CLASS__, 'render_action_box' ], 'dpjp_job', 'side', 'high' );
    }

    public static function render_action_box( WP_Post $post ): void {
        if ( $post->post_status === 'auto-draft' ) {
            echo '<p style="color:#666;font-size:13px;">Save the job first.</p>';
            return;
        }

        $meta        = DPJP_Meta_Fields::get( $post->ID );
        $fb_token    = get_option( 'dpjp_fb_page_token', '' );
        $indeed_id   = get_option( 'dpjp_indeed_client_id', '' );
        $fb_posted   = get_post_meta( $post->ID, 'dpjp_fb_posted_at', true );
        $in_posted   = get_post_meta( $post->ID, 'dpjp_indeed_posted_at', true );
        $cl          = DPJP_Content::for_craigslist( $post, $meta );
        $cl_region   = $meta['dpjp_craigslist_region'] ?? 'washingtondc';
        ?>
        <style>
            .dpjp-btn { display:block;width:100%;padding:8px;margin-bottom:8px;border:none;border-radius:4px;
                font-size:13px;font-weight:600;cursor:pointer;text-align:center;text-decoration:none; }
            .dpjp-btn-fb   { background:#1877f2;color:#fff; }
            .dpjp-btn-in   { background:#2164f3;color:#fff; }
            .dpjp-btn-copy { background:#f0f0f1;color:#1d2327;border:1px solid #c3c4c7; }
            .dpjp-btn-open { background:#00a32a;color:#fff; }
            .dpjp-btn:hover { opacity:.88; }
            .dpjp-status { font-size:11px;color:#2ea44f;margin:-4px 0 8px; }
            .dpjp-section-label { font-size:11px;font-weight:700;color:#646970;text-transform:uppercase;
                letter-spacing:.5px;margin:12px 0 6px; border-top:1px solid #dcdcde;padding-top:10px; }
            .dpjp-copy-area { width:100%;box-sizing:border-box;font-size:11px;padding:6px;
                border:1px solid #c3c4c7;border-radius:3px;background:#f6f7f7;resize:vertical;min-height:60px; }
            #dpjp-fb-result, #dpjp-in-result { font-size:12px;margin-bottom:6px;padding:4px 8px;border-radius:3px; }
        </style>

        <!-- FACEBOOK -->
        <p class="dpjp-section-label">Facebook</p>
        <?php if ( $fb_token ) : ?>
            <button class="dpjp-btn dpjp-btn-fb" id="dpjp-post-fb">Post to Facebook Page</button>
            <div id="dpjp-fb-result"></div>
            <?php if ( $fb_posted ) echo '<p class="dpjp-status">✓ Last posted: ' . esc_html( $fb_posted ) . '</p>'; ?>
            <p style="font-size:12px;color:#666;margin:10px 0 6px;">Or copy + paste manually:</p>
        <?php else : ?>
            <p style="font-size:12px;color:#666;margin-bottom:6px;">API not configured — copy post → open Facebook → paste.</p>
        <?php endif; ?>
        <textarea class="dpjp-copy-area" id="dpjp-fb-text" readonly><?php echo esc_textarea( DPJP_Content::for_facebook( $post, $meta ) ); ?></textarea>
        <button class="dpjp-btn dpjp-btn-copy" data-copy="dpjp-fb-text">Copy Facebook Post</button>
        <a class="dpjp-btn dpjp-btn-open" href="https://business.facebook.com/latest/home" target="_blank" rel="noopener">Open Meta Business Suite →</a>

        <!-- INDEED -->
        <p class="dpjp-section-label">Indeed</p>
        <?php if ( $indeed_id ) : ?>
            <button class="dpjp-btn dpjp-btn-in" id="dpjp-post-indeed">Post to Indeed</button>
            <div id="dpjp-in-result"></div>
            <?php if ( $in_posted ) echo '<p class="dpjp-status">✓ Last posted: ' . esc_html( $in_posted ) . '</p>'; ?>
        <?php else : ?>
            <p style="font-size:12px;color:#b32d2e;">⚠ Indeed not configured. <a href='<?php echo esc_url( admin_url( 'edit.php?post_type=dpjp_job&page=dpjp-settings' ) ); ?>'>Settings →</a></p>
        <?php endif; ?>

        <!-- NEXTDOOR -->
        <p class="dpjp-section-label">Nextdoor</p>
        <p style="font-size:12px;color:#666;margin-bottom:6px;">Copy post → open Nextdoor → paste.</p>
        <textarea class="dpjp-copy-area" id="dpjp-nd-text" readonly><?php echo esc_textarea( DPJP_Content::for_nextdoor( $post, $meta ) ); ?></textarea>
        <button class="dpjp-btn dpjp-btn-copy" data-copy="dpjp-nd-text">Copy Nextdoor Post</button>
        <?php
        $nd_url = get_option( 'dpjp_nextdoor_page_url', '' );
        $nd_url = ! empty( $nd_url ) ? esc_url( $nd_url ) : 'https://business.nextdoor.com/';
        ?>
        <a class="dpjp-btn dpjp-btn-open" href="<?php echo $nd_url; ?>" target="_blank" rel="noopener">Open Nextdoor →</a>

        <!-- CRAIGSLIST -->
        <p class="dpjp-section-label">Craigslist</p>
        <p style="font-size:12px;color:#666;margin-bottom:6px;">Copy title + body → open Craigslist → paste.</p>
        <label style="font-size:11px;font-weight:600;">Title:</label>
        <textarea class="dpjp-copy-area" id="dpjp-cl-title" rows="2" readonly><?php echo esc_textarea( $cl['title'] ); ?></textarea>
        <button class="dpjp-btn dpjp-btn-copy" data-copy="dpjp-cl-title" style="margin-bottom:6px;">Copy Title</button>
        <label style="font-size:11px;font-weight:600;">Body:</label>
        <textarea class="dpjp-copy-area" id="dpjp-cl-body" rows="4" readonly><?php echo esc_textarea( $cl['body'] ); ?></textarea>
        <button class="dpjp-btn dpjp-btn-copy" data-copy="dpjp-cl-body" style="margin-bottom:6px;">Copy Body</button>
        <?php
        $cl_url = get_option( 'dpjp_craigslist_post_url', '' );
        $cl_url = ! empty( $cl_url ) ? esc_url( $cl_url ) : 'https://post.craigslist.org/';
        ?>
        <a class="dpjp-btn dpjp-btn-open" href="<?php echo $cl_url; ?>" target="_blank" rel="noopener">Open Craigslist Post →</a>
        <?php
    }

    // ── Settings page ─────────────────────────────────────────────────────────

    public static function settings_page(): void {
        add_submenu_page( 'edit.php?post_type=dpjp_job', 'Settings', 'Settings', 'manage_options', 'dpjp-settings', [ __CLASS__, 'render_settings' ] );
    }

    public static function render_settings(): void {
        $fields = [
            'dpjp_fb_page_id'            => [ 'label' => 'Facebook Page ID',           'type' => 'text',     'desc' => 'Numeric ID of your Facebook Page. Find it in Page → About → Page Transparency.' ],
            'dpjp_fb_page_token'         => [ 'label' => 'Facebook Page Access Token', 'type' => 'password', 'desc' => 'Generate at developers.facebook.com → Your App → Graph API Explorer. Needs pages_manage_posts permission.' ],
            'dpjp_indeed_client_id'      => [ 'label' => 'Indeed Client ID',           'type' => 'text',     'desc' => 'From Indeed Employer dashboard → Integrations.' ],
            'dpjp_indeed_client_secret'  => [ 'label' => 'Indeed Client Secret',       'type' => 'password', 'desc' => 'From Indeed Employer dashboard → Integrations.' ],
            'dpjp_indeed_employer_id'    => [ 'label' => 'Indeed Employer ID',         'type' => 'text',     'desc' => 'Your Indeed employer account ID.' ],
            'dpjp_indeed_company_name'   => [ 'label' => 'Indeed Company Name',        'type' => 'text',     'desc' => 'Exact company name shown on Indeed.' ],
            'dpjp_nextdoor_page_url'     => [ 'label' => 'Nextdoor Page Admin URL',    'type' => 'url',      'desc' => 'Paste your page-admin URL from Nextdoor (e.g., https://nextdoor.com/page-admin/?profile_id=123456). The "Open Nextdoor" button on each job will deep-link here. Leave blank to use the generic Nextdoor Business landing.' ],
            'dpjp_craigslist_post_url'   => [ 'label' => 'Craigslist Post URL',        'type' => 'url',      'desc' => 'Optional. If you always post to the same Craigslist city, paste its post URL (e.g., https://post.craigslist.org/). Leave blank to use the universal post-starter.' ],
        ];
        $fields = apply_filters( 'dpjp_settings_fields', $fields );
        ?>
        <div class="wrap">
            <h1>Job Poster Settings</h1>
            <?php if ( isset( $_GET['saved'] ) ) echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>'; ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'dpjp_save_settings', 'dpjp_nonce' ); ?>
                <input type="hidden" name="action" value="dpjp_save_settings">
                <h2>Facebook</h2>
                <table class="form-table">
                    <?php foreach ( array_slice( $fields, 0, 2, true ) as $key => $f ) self::settings_row( $key, $f ); ?>
                </table>
                <h2>Indeed</h2>
                <table class="form-table">
                    <?php foreach ( array_slice( $fields, 2, 4, true ) as $key => $f ) self::settings_row( $key, $f ); ?>
                </table>
                <h2>Nextdoor &amp; Craigslist</h2>
                <p class="description">Optional deep-link URLs so the "Open" buttons on each job drop you straight onto your page instead of a generic landing page.</p>
                <table class="form-table">
                    <?php foreach ( array_slice( $fields, 6, 2, true ) as $key => $f ) self::settings_row( $key, $f ); ?>
                </table>
                <h2>Elementor</h2>
                <p class="description">When Elementor is active, new job listings automatically get a clean banner + content layout applied. Disable by deactivating the Elementor plugin.</p>
                <table class="form-table">
                    <?php
                    $el_fields = array_slice( $fields, 8, null, true );
                    foreach ( $el_fields as $key => $f ) self::settings_row( $key, $f );
                    ?>
                </table>
                <?php submit_button( 'Save Settings' ); ?>
            </form>
            <hr>
            <h2>How to get your Facebook Page Access Token</h2>
            <ol>
                <li>Go to <strong>developers.facebook.com</strong> and create a free App (type: Business).</li>
                <li>Add the <strong>Pages API</strong> product to your app.</li>
                <li>Go to <strong>Tools → Graph API Explorer</strong>.</li>
                <li>Select your app, click <strong>Generate Access Token</strong>, choose your Page.</li>
                <li>Request the <code>pages_manage_posts</code> permission.</li>
                <li>Copy the token and paste it above.</li>
            </ol>
            <h2>Nextdoor &amp; Craigslist</h2>
            <p>Neither platform has a public API that allows automated posting. The plugin generates your post content and opens the site — you paste and submit. Takes about 60 seconds each.</p>
        </div>
        <?php
    }

    private static function settings_row( string $key, array $f ): void {
        $val = get_option( $key, '' );
        $type = esc_attr( $f['type'] );
        echo "<tr><th><label for='{$key}'>{$f['label']}</label></th><td>";
        echo "<input type='{$type}' id='{$key}' name='{$key}' value='" . esc_attr( $val ) . "' class='regular-text'>";
        echo "<p class='description'>{$f['desc']}</p></td></tr>";
    }

    public static function save_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No.' );
        check_admin_referer( 'dpjp_save_settings', 'dpjp_nonce' );
        $keys = [ 'dpjp_fb_page_id', 'dpjp_fb_page_token', 'dpjp_indeed_client_id', 'dpjp_indeed_client_secret', 'dpjp_indeed_employer_id', 'dpjp_indeed_company_name', 'dpjp_nextdoor_page_url', 'dpjp_craigslist_post_url' ];
        $keys = apply_filters( 'dpjp_settings_keys', $keys );
        foreach ( $keys as $key ) {
            update_option( $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) ) );
        }
        wp_safe_redirect( add_query_arg( [ 'post_type' => 'dpjp_job', 'page' => 'dpjp-settings', 'saved' => '1' ], admin_url( 'edit.php' ) ) );
        exit;
    }

    // ── List table columns ────────────────────────────────────────────────────

    public static function columns( array $cols ): array {
        $new = [];
        foreach ( $cols as $k => $v ) {
            $new[ $k ] = $v;
            if ( $k === 'title' ) {
                $new['dpjp_trade']    = 'Trade';
                $new['dpjp_location'] = 'Location';
                $new['dpjp_pay']      = 'Pay';
                $new['dpjp_posted']   = 'Posted To';
            }
        }
        return $new;
    }

    public static function column_content( string $col, int $id ): void {
        $meta = DPJP_Meta_Fields::get( $id );
        switch ( $col ) {
            case 'dpjp_trade':    echo esc_html( $meta['dpjp_trade']    ?: '—' ); break;
            case 'dpjp_location': echo esc_html( $meta['dpjp_location'] ?: '—' ); break;
            case 'dpjp_pay':      echo esc_html( $meta['dpjp_pay']      ?: '—' ); break;
            case 'dpjp_posted':
                $badges = [];
                if ( get_post_meta( $id, 'dpjp_fb_posted_at',     true ) ) $badges[] = '<span style="background:#1877f2;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;">FB</span>';
                if ( get_post_meta( $id, 'dpjp_indeed_posted_at', true ) ) $badges[] = '<span style="background:#2164f3;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;">IN</span>';
                echo $badges ? implode( ' ', $badges ) : '—';
                break;
        }
    }
}
