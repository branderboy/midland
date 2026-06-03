<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Internal-link suggestion engine.
 *
 * Scans published posts/pages and finds places where one page mentions another
 * page's title in plain text without linking to it — a missed internal link.
 * The operator reviews each opportunity and applies it with one click; the link
 * is inserted into the first unlinked occurrence and the edit creates a normal
 * WordPress revision, so it can be rolled back from the post editor.
 */
class RSSEO_Pro_Internal_Links {

    const OPT_RESULTS = 'rsseo_il_suggestions';
    const MAX_TOTAL   = 200; // cap suggestions per scan
    const MAX_PER_SRC = 6;   // cap suggestions per source page
    const MIN_LEN     = 6;   // ignore very short titles (noise)

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ), 29 );
        add_action( 'admin_init', array( $this, 'handle_scan' ) );
        add_action( 'wp_ajax_rsseo_il_apply', array( $this, 'ajax_apply' ) );
        add_action( 'wp_ajax_rsseo_il_dismiss', array( $this, 'ajax_dismiss' ) );
    }

    public function add_menu() {
        add_submenu_page(
            'real-smart-seo',
            esc_html__( 'Internal Links', 'real-smart-seo-pro' ),
            esc_html__( 'Internal Links', 'real-smart-seo-pro' ),
            'manage_options',
            'rsseo-internal-links',
            array( $this, 'render_page' )
        );
    }

    /* ----------------------------- scan ----------------------------- */

    public function handle_scan() {
        if ( ! isset( $_POST['rsseo_il_scan'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_rsseo_il_nonce'] ?? '' ) ), 'rsseo_il_scan' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'real-smart-seo-pro' ) );
        }
        $types = array_map( 'sanitize_key', (array) ( $_POST['rsseo_il_types'] ?? array( 'post', 'page' ) ) );
        $results = $this->scan( $types );
        update_option( self::OPT_RESULTS, $results, false );
        wp_safe_redirect( admin_url( 'admin.php?page=rsseo-internal-links&scanned=' . count( $results ) ) );
        exit;
    }

    /**
     * Build the list of link opportunities.
     *
     * @param string[] $types Post types to include.
     * @return array[] suggestion rows
     */
    public function scan( $types = array( 'post', 'page' ) ) {
        $types = array_values( array_filter( $types ) ) ?: array( 'post', 'page' );

        $posts = get_posts( array(
            'post_type'   => $types,
            'post_status' => 'publish',
            'numberposts' => 500,
            'orderby'     => 'modified',
            'order'       => 'DESC',
        ) );

        // Build the target index: title (phrase) -> [id, url]. Skip titles that
        // are too short or are common single words.
        $targets = array();
        foreach ( $posts as $p ) {
            $title = trim( wp_strip_all_tags( $p->post_title ) );
            if ( mb_strlen( $title ) < self::MIN_LEN || str_word_count( $title ) < 2 ) {
                continue;
            }
            $targets[ $p->ID ] = array(
                'id'    => $p->ID,
                'title' => $title,
                'url'   => get_permalink( $p->ID ),
            );
        }

        $dismissed = (array) get_option( 'rsseo_il_dismissed', array() );
        $results   = array();

        foreach ( $posts as $src ) {
            $content = (string) $src->post_content;
            if ( '' === trim( $content ) ) {
                continue;
            }
            $per_src = 0;
            foreach ( $targets as $tid => $t ) {
                if ( $tid === $src->ID ) {
                    continue; // never link a page to itself
                }
                $key = $src->ID . ':' . $tid;
                if ( isset( $dismissed[ $key ] ) ) {
                    continue;
                }
                if ( ! $this->mentions_unlinked( $content, $t['title'], $t['url'] ) ) {
                    continue;
                }
                $results[] = array(
                    'key'          => $key,
                    'source_id'    => $src->ID,
                    'source_title' => wp_strip_all_tags( $src->post_title ),
                    'source_edit'  => get_edit_post_link( $src->ID, '' ),
                    'phrase'       => $t['title'],
                    'target_id'    => $tid,
                    'target_url'   => $t['url'],
                );
                if ( ++$per_src >= self::MAX_PER_SRC ) {
                    break;
                }
                if ( count( $results ) >= self::MAX_TOTAL ) {
                    break 2;
                }
            }
        }
        return $results;
    }

    /**
     * True when $phrase appears in $content as plain text and is not already
     * linked (anywhere) to $url.
     */
    private function mentions_unlinked( $content, $phrase, $url ) {
        if ( false === stripos( $content, $phrase ) ) {
            return false;
        }
        // Already linked to this exact target? Then it's not an opportunity.
        if ( '' !== $url && false !== stripos( $content, 'href="' . $url . '"' ) ) {
            return false;
        }
        // Is there at least one occurrence that is NOT inside a tag or an anchor?
        return (bool) preg_match( $this->phrase_pattern( $phrase ), $content );
    }

    /** Regex that matches the phrase only in text (not inside a tag or <a>). */
    private function phrase_pattern( $phrase ) {
        return '/(?<![\w>])(' . preg_quote( $phrase, '/' ) . ')(?![^<]*>)(?![^<]*<\/a>)/i';
    }

    /**
     * Insert a link around the first unlinked occurrence of $phrase. Returns the
     * new content, or null when nothing safe to change.
     */
    public function insert_link( $content, $phrase, $url ) {
        $new = preg_replace(
            $this->phrase_pattern( $phrase ),
            '<a href="' . esc_url( $url ) . '">$1</a>',
            $content,
            1,
            $count
        );
        if ( null === $new || $count < 1 || $new === $content ) {
            return null;
        }
        return $new;
    }

    /* ----------------------------- apply ----------------------------- */

    public function ajax_apply() {
        check_ajax_referer( 'rsseo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'real-smart-seo-pro' ) );
        }
        $source = isset( $_POST['source_id'] ) ? (int) $_POST['source_id'] : 0;
        $target = isset( $_POST['target_id'] ) ? (int) $_POST['target_id'] : 0;
        $phrase = isset( $_POST['phrase'] ) ? sanitize_text_field( wp_unslash( $_POST['phrase'] ) ) : '';

        $src = $source ? get_post( $source ) : null;
        $url = $target ? get_permalink( $target ) : '';
        if ( ! $src || '' === $phrase || '' === $url ) {
            wp_send_json_error( __( 'Invalid suggestion.', 'real-smart-seo-pro' ) );
        }

        $new_content = $this->insert_link( (string) $src->post_content, $phrase, $url );
        if ( null === $new_content ) {
            wp_send_json_error( __( 'The phrase is no longer present unlinked — nothing changed.', 'real-smart-seo-pro' ) );
        }

        // wp_update_post stores a revision, so this is reversible from the editor.
        $result = wp_update_post( array( 'ID' => $source, 'post_content' => $new_content ), true );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $this->forget_suggestion( $source . ':' . $target );
        wp_send_json_success( array( 'message' => __( 'Internal link added.', 'real-smart-seo-pro' ) ) );
    }

    public function ajax_dismiss() {
        check_ajax_referer( 'rsseo_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'real-smart-seo-pro' ) );
        }
        $key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
        if ( '' === $key ) {
            wp_send_json_error( __( 'Invalid key.', 'real-smart-seo-pro' ) );
        }
        $dismissed         = (array) get_option( 'rsseo_il_dismissed', array() );
        $dismissed[ $key ] = time();
        update_option( 'rsseo_il_dismissed', $dismissed, false );
        $this->forget_suggestion( $key );
        wp_send_json_success();
    }

    /** Drop a suggestion from the cached results after apply/dismiss. */
    private function forget_suggestion( $key ) {
        $results = (array) get_option( self::OPT_RESULTS, array() );
        foreach ( $results as $i => $row ) {
            if ( ( $row['key'] ?? '' ) === $key ) {
                unset( $results[ $i ] );
            }
        }
        update_option( self::OPT_RESULTS, array_values( $results ), false );
    }

    /* ----------------------------- view ----------------------------- */

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $scanned = isset( $_GET['scanned'] ) ? absint( $_GET['scanned'] ) : -1;
        // phpcs:enable
        $results = (array) get_option( self::OPT_RESULTS, array() );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Internal Link Opportunities', 'real-smart-seo-pro' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Finds pages that mention another page by name without linking to it. Review each one and add the link in a click — the change is a normal revision you can undo from the post editor.', 'real-smart-seo-pro' ); ?></p>

            <?php if ( $scanned >= 0 ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php printf( esc_html__( 'Scan complete — %d opportunities found.', 'real-smart-seo-pro' ), (int) $scanned ); ?></p></div>
            <?php endif; ?>

            <form method="post" style="margin:14px 0;">
                <?php wp_nonce_field( 'rsseo_il_scan', '_rsseo_il_nonce' ); ?>
                <label style="margin-right:12px;"><input type="checkbox" name="rsseo_il_types[]" value="page" checked> <?php esc_html_e( 'Pages', 'real-smart-seo-pro' ); ?></label>
                <label style="margin-right:12px;"><input type="checkbox" name="rsseo_il_types[]" value="post" checked> <?php esc_html_e( 'Posts', 'real-smart-seo-pro' ); ?></label>
                <button type="submit" name="rsseo_il_scan" value="1" class="button button-primary"><?php esc_html_e( 'Scan for opportunities', 'real-smart-seo-pro' ); ?></button>
            </form>

            <?php if ( empty( $results ) ) : ?>
                <p><em><?php esc_html_e( 'No opportunities cached. Run a scan to find internal-link opportunities.', 'real-smart-seo-pro' ); ?></em></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr>
                        <th><?php esc_html_e( 'On this page', 'real-smart-seo-pro' ); ?></th>
                        <th><?php esc_html_e( 'Link the phrase', 'real-smart-seo-pro' ); ?></th>
                        <th><?php esc_html_e( 'To this page', 'real-smart-seo-pro' ); ?></th>
                        <th></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $results as $row ) : ?>
                        <tr id="rsseo-il-<?php echo esc_attr( $row['key'] ); ?>">
                            <td><a href="<?php echo esc_url( $row['source_edit'] ); ?>"><?php echo esc_html( $row['source_title'] ); ?></a></td>
                            <td><code><?php echo esc_html( $row['phrase'] ); ?></code></td>
                            <td><a href="<?php echo esc_url( $row['target_url'] ); ?>" target="_blank"><?php echo esc_html( $row['target_url'] ); ?></a></td>
                            <td>
                                <button class="button button-small rsseo-il-apply"
                                    data-key="<?php echo esc_attr( $row['key'] ); ?>"
                                    data-source="<?php echo esc_attr( $row['source_id'] ); ?>"
                                    data-target="<?php echo esc_attr( $row['target_id'] ); ?>"
                                    data-phrase="<?php echo esc_attr( $row['phrase'] ); ?>"><?php esc_html_e( 'Add link', 'real-smart-seo-pro' ); ?></button>
                                <button class="button button-small rsseo-il-dismiss" data-key="<?php echo esc_attr( $row['key'] ); ?>"><?php esc_html_e( 'Dismiss', 'real-smart-seo-pro' ); ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <script>
                (function($){
                    var d = window.rsseoData || window.rsseoProData || {};
                    var ajaxUrl = d.ajax_url || ajaxurl, nonce = d.nonce || '';
                    $(document).on('click', '.rsseo-il-apply', function(){
                        var b=$(this), r=$('#rsseo-il-'+$.escapeSelector(b.data('key')));
                        b.prop('disabled',true).text('<?php echo esc_js( __( 'Adding…', 'real-smart-seo-pro' ) ); ?>');
                        $.post(ajaxUrl,{action:'rsseo_il_apply',nonce:nonce,source_id:b.data('source'),target_id:b.data('target'),phrase:b.data('phrase')},function(res){
                            if(res.success){ r.css('background','#f3fcf4').fadeOut(400,function(){r.remove();}); }
                            else { alert(res.data||'Error'); b.prop('disabled',false).text('<?php echo esc_js( __( 'Add link', 'real-smart-seo-pro' ) ); ?>'); }
                        });
                    });
                    $(document).on('click', '.rsseo-il-dismiss', function(){
                        var b=$(this), r=$('#rsseo-il-'+$.escapeSelector(b.data('key')));
                        $.post(ajaxUrl,{action:'rsseo_il_dismiss',nonce:nonce,key:b.data('key')},function(){ r.fadeOut(300,function(){r.remove();}); });
                    });
                })(jQuery);
                </script>
            <?php endif; ?>
        </div>
        <?php
    }
}

RSSEO_Pro_Internal_Links::get_instance();
