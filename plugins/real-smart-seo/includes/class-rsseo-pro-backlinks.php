<?php
/**
 * Backlinks dashboard — pulls inbound link profile, lost/gained, and toxic
 * warnings from DataForSEO and renders an inline panel under the free
 * plugin's Insights tab. Hooks into the rsseo_render_backlinks_panel action
 * so it lives inside the existing tabbed UI rather than as a separate menu
 * item.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class RSSEO_Pro_Backlinks {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'rsseo_render_backlinks_panel',          array( $this, 'render_panel' ) );
        add_action( 'admin_post_rsseo_pro_refresh_backlinks',array( $this, 'handle_refresh' ) );
    }

    public function render_panel() {
        if ( ! class_exists( 'RSSEO_Pro_DataForSEO' ) ) {
            echo '<p class="rsseo-error">DataForSEO module not loaded.</p>';
            return;
        }
        if ( ! RSSEO_Pro_DataForSEO::is_configured() ) {
            ?>
            <div class="rsseo-empty-state">
                <p><strong>DataForSEO credentials required.</strong> Backlinks data comes from DataForSEO (pay-as-you-go, ~$0.02 per refresh). Add your login + password under Real Smart SEO → Settings → Pro.</p>
                <p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=real-smart-seo&tab=settings&sub=pro' ) ); ?>">Configure DataForSEO →</a></p>
            </div>
            <?php
            return;
        }

        $target  = preg_replace( '#^https?://#', '', rtrim( home_url(), '/' ) );
        $summary = RSSEO_Pro_DataForSEO::get_backlinks_summary( $target );

        if ( is_wp_error( $summary ) ) {
            echo '<p class="rsseo-error">' . esc_html( $summary->get_error_message() ) . '</p>';
            $this->render_refresh_button( $target );
            return;
        }

        $referring = RSSEO_Pro_DataForSEO::get_referring_domains( $target, 25 );
        if ( is_wp_error( $referring ) ) $referring = array();

        $crawled = ! empty( $summary['crawled_at'] ) ? wp_date( 'M j, Y g:i A', (int) $summary['crawled_at'] ) : '';
        ?>
        <div class="rsseo-backlinks">
            <div class="rsseo-backlinks__head">
                <h3 style="margin:0;">Backlinks — <code><?php echo esc_html( $target ); ?></code></h3>
                <?php if ( $crawled ) : ?>
                    <span class="rsseo-muted">Last refreshed <?php echo esc_html( $crawled ); ?></span>
                <?php endif; ?>
                <?php $this->render_refresh_button( $target ); ?>
            </div>

            <div class="rsseo-stat-grid">
                <?php $this->stat( 'Domain Rank',        $summary['rank'] ); ?>
                <?php $this->stat( 'Total Backlinks',    number_format( $summary['backlinks'] ) ); ?>
                <?php $this->stat( 'Referring Domains', number_format( $summary['referring_domains'] ) ); ?>
                <?php $this->stat( 'New (60d)',          number_format( $summary['new_backlinks_30d'] ),  'good' ); ?>
                <?php $this->stat( 'Lost (60d)',         number_format( $summary['lost_backlinks_30d'] ), $summary['lost_backlinks_30d'] > 0 ? 'warn' : '' ); ?>
                <?php $this->stat( 'Broken',             number_format( $summary['broken_backlinks'] ),   $summary['broken_backlinks'] > 0 ? 'warn' : '' ); ?>
            </div>

            <?php if ( ! empty( $referring ) ) : ?>
                <h4 style="margin-top:24px;">Top referring domains</h4>
                <table class="widefat striped" style="max-width:900px;">
                    <thead>
                        <tr><th>Domain</th><th>Rank</th><th>Backlinks</th><th>First seen</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $referring as $r ) :
                        $status = $r['lost'] ? '<span style="color:#b32d2e;">Lost</span>' :
                                  ( $r['broken'] ? '<span style="color:#b32d2e;">Broken</span>' : '<span style="color:#1e7e34;">Live</span>' );
                        ?>
                        <tr>
                            <td><a href="https://<?php echo esc_attr( $r['domain'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $r['domain'] ); ?></a></td>
                            <td><?php echo (int) $r['rank']; ?></td>
                            <td><?php echo (int) $r['backlinks']; ?></td>
                            <td><?php echo esc_html( $r['first_seen'] ?: '—' ); ?></td>
                            <td><?php echo $status; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ( (int) $summary['broken_backlinks'] > 0 ) : ?>
                <p class="rsseo-toxic-warning" style="margin-top:18px;background:#fff3cd;border-left:4px solid #f0b429;padding:10px 14px;">
                    ⚠️ <strong><?php echo (int) $summary['broken_backlinks']; ?> broken backlink<?php echo $summary['broken_backlinks'] === 1 ? '' : 's'; ?></strong>
                    point at 404s on your site. Fix those URLs (or 301-redirect them) to recover the link equity.
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_refresh_button( $target ) {
        $url = wp_nonce_url(
            add_query_arg( array( 'action' => 'rsseo_pro_refresh_backlinks', 'target' => rawurlencode( $target ) ), admin_url( 'admin-post.php' ) ),
            'rsseo_pro_refresh_backlinks'
        );
        echo '<a class="button" href="' . esc_url( $url ) . '">Refresh from DataForSEO</a>';
    }

    private function stat( $label, $value, $tone = '' ) {
        $cls = $tone === 'good' ? 'rsseo-stat--good' : ( $tone === 'warn' ? 'rsseo-stat--warn' : '' );
        printf(
            '<div class="rsseo-stat-card %s"><span class="rsseo-stat-card__num">%s</span><span class="rsseo-stat-card__label">%s</span></div>',
            esc_attr( $cls ),
            esc_html( (string) $value ),
            esc_html( $label )
        );
    }

    public function handle_refresh() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No.' );
        check_admin_referer( 'rsseo_pro_refresh_backlinks' );
        $target = isset( $_GET['target'] ) ? sanitize_text_field( wp_unslash( $_GET['target'] ) ) : '';
        if ( '' === $target ) {
            $target = preg_replace( '#^https?://#', '', rtrim( home_url(), '/' ) );
        }
        delete_transient( 'rsseo_pro_backlinks_summary_' . md5( $target ) );
        // Default referring-domains limit = 25 (matches render_panel).
        delete_transient( 'rsseo_pro_referring_' . md5( $target . '|25' ) );
        wp_safe_redirect( admin_url( 'admin.php?page=real-smart-seo&tab=insights' ) );
        exit;
    }
}

RSSEO_Pro_Backlinks::get_instance();
