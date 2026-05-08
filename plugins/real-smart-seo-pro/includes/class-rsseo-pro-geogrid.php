<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Geo-Grid Rank Tracker.
 * Local Falcon-style NxN keyword rank scan around a center lat/lng.
 * Runs weekly via cron + on-demand via admin button.
 * Settings: Real Smart SEO Pro > Geo-Grid
 */
class RSSEO_Pro_Geogrid {

    const KM_PER_LAT_DEGREE = 111.32;
    const CRON_HOOK         = 'rsseo_geogrid_weekly_scan';

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ), 34 );
        add_action( 'admin_init', array( $this, 'handle_save' ) );
        add_action( 'admin_init', array( $this, 'handle_run_scan' ) );

        // Weekly cron.
        add_action( self::CRON_HOOK, array( $this, 'cron_scan' ) );
        add_action( 'init', array( $this, 'maybe_schedule_cron' ) );
    }

    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE {$wpdb->prefix}rsseo_pro_geogrid_runs (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            keyword varchar(255) NOT NULL,
            target_domain varchar(255) NOT NULL,
            center_lat decimal(10,6) NOT NULL,
            center_lng decimal(10,6) NOT NULL,
            grid_size int NOT NULL DEFAULT 5,
            spacing_km decimal(6,2) NOT NULL DEFAULT 1.50,
            cells_total int NOT NULL DEFAULT 0,
            cells_done int NOT NULL DEFAULT 0,
            avg_rank decimal(6,2) DEFAULT NULL,
            in_top10 int NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY keyword (keyword)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}rsseo_pro_geogrid_cells (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            run_id bigint(20) NOT NULL,
            row_idx int NOT NULL,
            col_idx int NOT NULL,
            lat decimal(10,6) NOT NULL,
            lng decimal(10,6) NOT NULL,
            rank int DEFAULT NULL,
            target_url varchar(500) DEFAULT NULL,
            error_msg varchar(255) DEFAULT NULL,
            scanned_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY run_id (run_id)
        ) $charset;" );
    }

    public function maybe_schedule_cron() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::CRON_HOOK );
        }
    }

    public function add_menu() {
        add_submenu_page(
            'rsseo-pro',
            esc_html__( 'Geo-Grid Tracker', 'real-smart-seo-pro' ),
            esc_html__( 'Geo-Grid Tracker', 'real-smart-seo-pro' ),
            'manage_options',
            'rsseo-geogrid',
            array( $this, 'render_page' )
        );
    }

    public function handle_save() {
        if ( ! isset( $_POST['rsseo_save_geogrid'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $nonce = isset( $_POST['_rsseo_geogrid_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_rsseo_geogrid_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'rsseo_save_geogrid' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'real-smart-seo-pro' ) );
        }

        $settings = array(
            'keyword'       => sanitize_text_field( wp_unslash( $_POST['geogrid_keyword'] ?? '' ) ),
            'target_domain' => sanitize_text_field( wp_unslash( $_POST['geogrid_target_domain'] ?? '' ) ),
            'center_lat'    => (float) ( $_POST['geogrid_center_lat'] ?? 0 ),
            'center_lng'    => (float) ( $_POST['geogrid_center_lng'] ?? 0 ),
            'grid_size'     => max( 3, min( 9, (int) ( $_POST['geogrid_grid_size'] ?? 5 ) ) ),
            'spacing_km'    => max( 0.5, min( 50, (float) ( $_POST['geogrid_spacing_km'] ?? 1.5 ) ) ),
        );
        // Force odd grid sizes so there is always a true center.
        if ( 0 === $settings['grid_size'] % 2 ) {
            $settings['grid_size'] += 1;
        }

        update_option( 'rsseo_pro_geogrid_settings', $settings );
        wp_safe_redirect( admin_url( 'admin.php?page=rsseo-geogrid&saved=1' ) );
        exit;
    }

    public function handle_run_scan() {
        if ( ! isset( $_GET['rsseo_geogrid_run'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'rsseo_geogrid_run' ) ) {
            return;
        }
        $this->cron_scan();
        wp_safe_redirect( admin_url( 'admin.php?page=rsseo-geogrid&ran=1' ) );
        exit;
    }

    public function cron_scan() {
        $settings = get_option( 'rsseo_pro_geogrid_settings', array() );
        if ( empty( $settings['keyword'] ) || empty( $settings['target_domain'] ) ) {
            return;
        }
        if ( ! class_exists( 'RSSEO_Pro_DataForSEO' ) || ! RSSEO_Pro_DataForSEO::is_configured() ) {
            return;
        }

        $cells = $this->enumerate_cells( (float) $settings['center_lat'], (float) $settings['center_lng'], (int) $settings['grid_size'], (float) $settings['spacing_km'] );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'rsseo_pro_geogrid_runs', array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'keyword'       => $settings['keyword'],
            'target_domain' => $settings['target_domain'],
            'center_lat'    => $settings['center_lat'],
            'center_lng'    => $settings['center_lng'],
            'grid_size'     => $settings['grid_size'],
            'spacing_km'    => $settings['spacing_km'],
            'cells_total'   => count( $cells ),
        ) );
        $run_id = (int) $wpdb->insert_id;

        $ranks_collected = array();
        $top10           = 0;
        $done            = 0;

        foreach ( $cells as $cell ) {
            $serp = RSSEO_Pro_DataForSEO::get_serp_at_coordinate(
                $settings['keyword'],
                $cell['lat'],
                $cell['lng'],
                max( 1, (int) round( $settings['spacing_km'] / 2 ) )
            );

            $rank       = null;
            $target_url = null;
            $error_msg  = null;

            if ( is_wp_error( $serp ) ) {
                $error_msg = substr( $serp->get_error_message(), 0, 250 );
            } else {
                foreach ( $serp as $row ) {
                    if ( $this->domain_matches( $row['domain'] ?? '', $settings['target_domain'] ) ) {
                        $rank       = (int) ( $row['rank'] ?? 0 );
                        $target_url = $row['url'] ?? '';
                        break;
                    }
                }
                if ( null !== $rank ) {
                    $ranks_collected[] = $rank;
                    if ( $rank <= 10 ) {
                        $top10++;
                    }
                }
            }

            $wpdb->insert( $wpdb->prefix . 'rsseo_pro_geogrid_cells', array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                'run_id'     => $run_id,
                'row_idx'    => $cell['row'],
                'col_idx'    => $cell['col'],
                'lat'        => $cell['lat'],
                'lng'        => $cell['lng'],
                'rank'       => $rank,
                'target_url' => $target_url,
                'error_msg'  => $error_msg,
                'scanned_at' => current_time( 'mysql' ),
            ) );

            $done++;
            $wpdb->update( $wpdb->prefix . 'rsseo_pro_geogrid_runs', // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                array( 'cells_done' => $done ),
                array( 'id' => $run_id )
            );
        }

        $avg = ! empty( $ranks_collected ) ? round( array_sum( $ranks_collected ) / count( $ranks_collected ), 2 ) : null;
        $wpdb->update( $wpdb->prefix . 'rsseo_pro_geogrid_runs', array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'cells_done' => count( $cells ),
            'avg_rank'   => $avg,
            'in_top10'   => $top10,
        ), array( 'id' => $run_id ) );
    }

    private function enumerate_cells( $center_lat, $center_lng, $grid_size, $spacing_km ) {
        $half       = (int) floor( $grid_size / 2 );
        $lat_step   = $spacing_km / self::KM_PER_LAT_DEGREE;
        $cos_factor = max( 0.01, cos( deg2rad( $center_lat ) ) );
        $lng_step   = $spacing_km / ( self::KM_PER_LAT_DEGREE * $cos_factor );

        $cells = array();
        for ( $r = -$half; $r <= $half; $r++ ) {
            for ( $c = -$half; $c <= $half; $c++ ) {
                $cells[] = array(
                    'row' => $r + $half,
                    'col' => $c + $half,
                    'lat' => round( $center_lat + ( $r * $lat_step ), 6 ),
                    'lng' => round( $center_lng + ( $c * $lng_step ), 6 ),
                );
            }
        }
        return $cells;
    }

    private function domain_matches( $found, $target ) {
        $found  = strtolower( preg_replace( '#^https?://#', '', (string) $found ) );
        $target = strtolower( preg_replace( '#^https?://#', '', (string) $target ) );
        $found  = preg_replace( '#^www\.#', '', $found );
        $target = preg_replace( '#^www\.#', '', $target );
        return $found === $target || ( false !== strpos( $found, '.' . $target ) );
    }

    private function get_runs( $limit = 10 ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT * FROM {$wpdb->prefix}rsseo_pro_geogrid_runs ORDER BY id DESC LIMIT %d",
            $limit
        ) );
    }

    private function get_cells( $run_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT * FROM {$wpdb->prefix}rsseo_pro_geogrid_cells WHERE run_id = %d ORDER BY row_idx, col_idx",
            $run_id
        ) );
    }

    public function render_page() {
        $settings = wp_parse_args( get_option( 'rsseo_pro_geogrid_settings', array() ), array(
            'keyword'       => '',
            'target_domain' => wp_parse_url( home_url(), PHP_URL_HOST ),
            'center_lat'    => 38.9072,
            'center_lng'    => -77.0369,
            'grid_size'     => 5,
            'spacing_km'    => 1.5,
        ) );
        $runs = $this->get_runs( 10 );
        $latest = ! empty( $runs ) ? $runs[0] : null;
        $cells  = $latest ? $this->get_cells( $latest->id ) : array();

        $run_url = wp_nonce_url( admin_url( 'admin.php?page=rsseo-geogrid&rsseo_geogrid_run=1' ), 'rsseo_geogrid_run' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Geo-Grid Rank Tracker', 'real-smart-seo-pro' ); ?></h1>
            <p class="description"><?php esc_html_e( 'NxN keyword rank scan around a center point. Weekly cron + manual run.', 'real-smart-seo-pro' ); ?></p>

            <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Geo-Grid settings saved.', 'real-smart-seo-pro' ); ?></p></div>
            <?php endif; ?>
            <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
            <?php if ( isset( $_GET['ran'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Scan complete.', 'real-smart-seo-pro' ); ?></p></div>
            <?php endif; ?>

            <?php if ( ! class_exists( 'RSSEO_Pro_DataForSEO' ) || ! RSSEO_Pro_DataForSEO::is_configured() ) : ?>
                <div class="notice notice-warning"><p><?php esc_html_e( 'DataForSEO credentials not configured. Add them under Real Smart SEO Pro > Settings.', 'real-smart-seo-pro' ); ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'rsseo_save_geogrid', '_rsseo_geogrid_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="geogrid_keyword"><?php esc_html_e( 'Keyword', 'real-smart-seo-pro' ); ?></label></th>
                        <td><input type="text" id="geogrid_keyword" name="geogrid_keyword" class="regular-text" value="<?php echo esc_attr( $settings['keyword'] ); ?>" placeholder="commercial floor cleaning"></td>
                    </tr>
                    <tr>
                        <th><label for="geogrid_target_domain"><?php esc_html_e( 'Target Domain', 'real-smart-seo-pro' ); ?></label></th>
                        <td><input type="text" id="geogrid_target_domain" name="geogrid_target_domain" class="regular-text" value="<?php echo esc_attr( $settings['target_domain'] ); ?>" placeholder="example.com"></td>
                    </tr>
                    <tr>
                        <th><label for="geogrid_center_lat"><?php esc_html_e( 'Center Latitude', 'real-smart-seo-pro' ); ?></label></th>
                        <td><input type="number" step="0.000001" id="geogrid_center_lat" name="geogrid_center_lat" class="regular-text" value="<?php echo esc_attr( $settings['center_lat'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="geogrid_center_lng"><?php esc_html_e( 'Center Longitude', 'real-smart-seo-pro' ); ?></label></th>
                        <td><input type="number" step="0.000001" id="geogrid_center_lng" name="geogrid_center_lng" class="regular-text" value="<?php echo esc_attr( $settings['center_lng'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="geogrid_grid_size"><?php esc_html_e( 'Grid Size (N×N)', 'real-smart-seo-pro' ); ?></label></th>
                        <td>
                            <select id="geogrid_grid_size" name="geogrid_grid_size">
                                <?php foreach ( array( 3, 5, 7, 9 ) as $n ) : ?>
                                    <option value="<?php echo esc_attr( $n ); ?>" <?php selected( $settings['grid_size'], $n ); ?>><?php echo esc_html( $n . ' x ' . $n . ' (' . ( $n * $n ) . ' cells)' ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'Each cell is one DataForSEO SERP call. Mind your quota.', 'real-smart-seo-pro' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="geogrid_spacing_km"><?php esc_html_e( 'Spacing (km)', 'real-smart-seo-pro' ); ?></label></th>
                        <td><input type="number" step="0.5" min="0.5" max="50" id="geogrid_spacing_km" name="geogrid_spacing_km" value="<?php echo esc_attr( $settings['spacing_km'] ); ?>" style="width:100px;"></td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" name="rsseo_save_geogrid" value="1" class="button button-primary"><?php esc_html_e( 'Save Settings', 'real-smart-seo-pro' ); ?></button>
                    <a href="<?php echo esc_url( $run_url ); ?>" class="button button-secondary" style="margin-left:8px;"><?php esc_html_e( 'Run Scan Now', 'real-smart-seo-pro' ); ?></a>
                </p>
            </form>

            <?php if ( $latest ) : ?>
                <hr>
                <h2><?php esc_html_e( 'Latest Run', 'real-smart-seo-pro' ); ?></h2>
                <p>
                    <strong><?php echo esc_html( $latest->keyword ); ?></strong>
                    — <?php esc_html_e( 'avg rank:', 'real-smart-seo-pro' ); ?>
                    <strong><?php echo $latest->avg_rank ? esc_html( $latest->avg_rank ) : '—'; ?></strong>
                    | <?php esc_html_e( 'in top 10:', 'real-smart-seo-pro' ); ?>
                    <strong><?php echo esc_html( (int) $latest->in_top10 . ' / ' . (int) $latest->cells_done ); ?></strong>
                    | <?php echo esc_html( $latest->created_at ); ?>
                </p>
                <?php $this->render_heatmap( $cells, (int) $latest->grid_size ); ?>
            <?php endif; ?>

            <?php if ( count( $runs ) > 1 ) : ?>
                <h2><?php esc_html_e( 'History', 'real-smart-seo-pro' ); ?></h2>
                <table class="widefat striped">
                    <thead><tr>
                        <th><?php esc_html_e( 'Date', 'real-smart-seo-pro' ); ?></th>
                        <th><?php esc_html_e( 'Keyword', 'real-smart-seo-pro' ); ?></th>
                        <th><?php esc_html_e( 'Grid', 'real-smart-seo-pro' ); ?></th>
                        <th><?php esc_html_e( 'Avg Rank', 'real-smart-seo-pro' ); ?></th>
                        <th><?php esc_html_e( 'In Top 10', 'real-smart-seo-pro' ); ?></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ( $runs as $r ) : ?>
                            <tr>
                                <td><?php echo esc_html( $r->created_at ); ?></td>
                                <td><?php echo esc_html( $r->keyword ); ?></td>
                                <td><?php echo esc_html( $r->grid_size . 'x' . $r->grid_size ); ?></td>
                                <td><?php echo $r->avg_rank ? esc_html( $r->avg_rank ) : '—'; ?></td>
                                <td><?php echo esc_html( (int) $r->in_top10 . ' / ' . (int) $r->cells_done ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_heatmap( $cells, $grid_size ) {
        if ( empty( $cells ) ) {
            return;
        }
        $matrix = array();
        foreach ( $cells as $c ) {
            $matrix[ (int) $c->row_idx ][ (int) $c->col_idx ] = $c;
        }
        echo '<div style="display:inline-block;border:1px solid #ddd;padding:8px;background:#fafafa;">';
        for ( $r = 0; $r < $grid_size; $r++ ) {
            echo '<div style="display:flex;">';
            for ( $c = 0; $c < $grid_size; $c++ ) {
                $cell = $matrix[ $r ][ $c ] ?? null;
                $rank = $cell && null !== $cell->rank ? (int) $cell->rank : null;
                $bg   = '#999';
                if ( null === $rank ) {
                    $bg = '#bbb';
                } elseif ( $rank <= 3 ) {
                    $bg = '#0a8754';
                } elseif ( $rank <= 10 ) {
                    $bg = '#46b450';
                } elseif ( $rank <= 20 ) {
                    $bg = '#dba617';
                } else {
                    $bg = '#d63638';
                }
                $label = null === $rank ? '—' : (string) $rank;
                $title = $cell ? sprintf( '%.4f, %.4f', (float) $cell->lat, (float) $cell->lng ) : '';
                echo '<div title="' . esc_attr( $title ) . '" style="width:48px;height:48px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;background:' . esc_attr( $bg ) . ';margin:2px;border-radius:4px;font-size:14px;">' . esc_html( $label ) . '</div>';
            }
            echo '</div>';
        }
        echo '</div>';
        echo '<p class="description">' . esc_html__( 'Green = top 10. Yellow = 11-20. Red = 21+. Gray = not found in top 100.', 'real-smart-seo-pro' ) . '</p>';
    }
}

RSSEO_Pro_Geogrid::get_instance();
