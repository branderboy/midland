<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SFCO_Pro_Analytics {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ), 33 );
    }

    public function add_menu() {
        add_submenu_page(
            'sfco-forms',
            esc_html__( 'Analytics', 'smart-forms-pro' ),
            esc_html__( 'Analytics', 'smart-forms-pro' ),
            'manage_options',
            'sfco-analytics',
            array( $this, 'render_page' )
        );
    }

    private function get_stats( $days = 30 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sfco_leads';

        $start = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $start // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        $won = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE status = 'won' AND created_at >= %s", $start // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        $by_status = $wpdb->get_results( $wpdb->prepare(
            "SELECT status, COUNT(*) as cnt FROM {$table} WHERE created_at >= %s GROUP BY status ORDER BY cnt DESC", $start // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        $by_project = $wpdb->get_results( $wpdb->prepare(
            "SELECT project_type, COUNT(*) as cnt FROM {$table} WHERE created_at >= %s AND project_type != '' GROUP BY project_type ORDER BY cnt DESC LIMIT 10", $start // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        $by_day = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(created_at) as day, COUNT(*) as cnt FROM {$table} WHERE created_at >= %s GROUP BY DATE(created_at) ORDER BY day ASC", $start // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        $revenue_est = $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(estimated_cost_max) FROM {$table} WHERE status = 'won' AND created_at >= %s", $start // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        $pipeline_est = $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(estimated_cost_max) FROM {$table} WHERE status IN ('new','contacted','quoted') AND created_at >= %s", $start // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        return array(
            'total'       => $total,
            'won'         => $won,
            'conversion'  => $total > 0 ? round( ( $won / $total ) * 100, 1 ) : 0,
            'revenue'     => floatval( $revenue_est ),
            'pipeline'    => floatval( $pipeline_est ),
            'by_status'   => $by_status,
            'by_project'  => $by_project,
            'by_day'      => $by_day,
        );
    }

    public function render_page() {
        if ( ! SFCO_Pro_License::is_valid() ) {
            echo '<div class="wrap"><div class="notice notice-warning"><p>' . esc_html__( 'Please activate your PRO license.', 'smart-forms-pro' ) . '</p></div></div>';
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $period = isset( $_GET['period'] ) ? absint( $_GET['period'] ) : 30;
        $stats  = $this->get_stats( $period );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Analytics', 'smart-forms-pro' ); ?></h1>

            <div class="sfco-analytics-period">
                <?php foreach ( array( 7, 30, 90 ) as $p ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'period', $p ) ); ?>" class="button <?php echo $period === $p ? 'button-primary' : ''; ?>"><?php echo esc_html( $p ); ?> <?php esc_html_e( 'days', 'smart-forms-pro' ); ?></a>
                <?php endforeach; ?>
            </div>

            <!-- Summary Cards -->
            <div class="sfco-analytics-cards">
                <div class="sfco-stat-card">
                    <span class="sfco-stat-label"><?php esc_html_e( 'Total Leads', 'smart-forms-pro' ); ?></span>
                    <span class="sfco-stat-value"><?php echo esc_html( $stats['total'] ); ?></span>
                </div>
                <div class="sfco-stat-card">
                    <span class="sfco-stat-label"><?php esc_html_e( 'Won', 'smart-forms-pro' ); ?></span>
                    <span class="sfco-stat-value sfco-stat-green"><?php echo esc_html( $stats['won'] ); ?></span>
                </div>
                <div class="sfco-stat-card">
                    <span class="sfco-stat-label"><?php esc_html_e( 'Conversion Rate', 'smart-forms-pro' ); ?></span>
                    <span class="sfco-stat-value"><?php echo esc_html( $stats['conversion'] ); ?>%</span>
                </div>
                <div class="sfco-stat-card">
                    <span class="sfco-stat-label"><?php esc_html_e( 'Revenue (Won)', 'smart-forms-pro' ); ?></span>
                    <span class="sfco-stat-value sfco-stat-green">$<?php echo esc_html( number_format( $stats['revenue'] ) ); ?></span>
                </div>
                <div class="sfco-stat-card">
                    <span class="sfco-stat-label"><?php esc_html_e( 'Pipeline Value', 'smart-forms-pro' ); ?></span>
                    <span class="sfco-stat-value">$<?php echo esc_html( number_format( $stats['pipeline'] ) ); ?></span>
                </div>
            </div>

            <div class="sfco-analytics-grid">
                <!-- Leads by Status -->
                <div class="sfco-card">
                    <h2><?php esc_html_e( 'Leads by Status', 'smart-forms-pro' ); ?></h2>
                    <?php if ( empty( $stats['by_status'] ) ) : ?>
                        <p class="description"><?php esc_html_e( 'No data yet.', 'smart-forms-pro' ); ?></p>
                    <?php else : ?>
                        <table class="sfco-detail-table">
                            <?php foreach ( $stats['by_status'] as $row ) : ?>
                                <tr>
                                    <th><span class="sfco-status-badge sfco-status-<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( ucfirst( $row->status ) ); ?></span></th>
                                    <td>
                                        <?php echo esc_html( $row->cnt ); ?>
                                        <?php if ( $stats['total'] > 0 ) : ?>
                                            <span class="sfco-analytics-pct">(<?php echo esc_html( round( ( $row->cnt / $stats['total'] ) * 100, 1 ) ); ?>%)</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Leads by Project Type -->
                <div class="sfco-card">
                    <h2><?php esc_html_e( 'Top Project Types', 'smart-forms-pro' ); ?></h2>
                    <?php if ( empty( $stats['by_project'] ) ) : ?>
                        <p class="description"><?php esc_html_e( 'No data yet.', 'smart-forms-pro' ); ?></p>
                    <?php else : ?>
                        <table class="sfco-detail-table">
                            <?php foreach ( $stats['by_project'] as $row ) : ?>
                                <tr>
                                    <th><?php echo esc_html( $row->project_type ); ?></th>
                                    <td><?php echo esc_html( $row->cnt ); ?> <?php esc_html_e( 'leads', 'smart-forms-pro' ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Leads Over Time -->
                <div class="sfco-card sfco-card-wide">
                    <h2><?php esc_html_e( 'Leads Over Time', 'smart-forms-pro' ); ?></h2>
                    <?php if ( empty( $stats['by_day'] ) ) : ?>
                        <p class="description"><?php esc_html_e( 'No data yet.', 'smart-forms-pro' ); ?></p>
                    <?php else : ?>
                        <?php $max = max( array_column( (array) $stats['by_day'], 'cnt' ) ); ?>
                        <div class="sfco-bar-chart">
                            <?php foreach ( $stats['by_day'] as $row ) : ?>
                                <?php $height = $max > 0 ? round( ( $row->cnt / $max ) * 100 ) : 0; ?>
                                <div class="sfco-bar-col" title="<?php echo esc_attr( $row->day . ': ' . $row->cnt . ' leads' ); ?>">
                                    <div class="sfco-bar" style="height:<?php echo esc_attr( $height ); ?>%;"></div>
                                    <span class="sfco-bar-label"><?php echo esc_html( gmdate( 'M j', strtotime( $row->day ) ) ); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
}

new SFCO_Pro_Analytics();
