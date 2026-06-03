<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Content brief builder.
 *
 * Given a target keyword (+ optional context), asks the configured AI model to
 * produce a writer-ready SEO brief: intent, title/meta options, word-count
 * target, H2/H3 outline, entities to cover, People-Also-Ask questions, internal
 * link anchors, and a CTA. Reuses the free plugin's RSSEO_Claude_API client.
 */
class RSSEO_Pro_Content_Brief {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ), 31 );
        add_action( 'wp_ajax_rsseo_brief_generate', array( $this, 'ajax_generate' ) );
    }

    public function add_menu() {
        add_submenu_page(
            'real-smart-seo',
            esc_html__( 'Content Brief', 'real-smart-seo-pro' ),
            esc_html__( 'Content Brief', 'real-smart-seo-pro' ),
            'manage_options',
            'rsseo-content-brief',
            array( $this, 'render_page' )
        );
    }

    public function ajax_generate() {
        check_ajax_referer( 'rsseo_brief', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'real-smart-seo-pro' ) );
        }
        if ( ! class_exists( 'RSSEO_Claude_API' ) ) {
            wp_send_json_error( __( 'The Real Smart SEO base plugin (AI client) is not active.', 'real-smart-seo-pro' ) );
        }

        $keyword = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
        $notes   = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
        if ( '' === $keyword ) {
            wp_send_json_error( __( 'Enter a target keyword.', 'real-smart-seo-pro' ) );
        }

        $result = RSSEO_Claude_API::ask( $this->build_prompt( $keyword, $notes ) );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $text = is_array( $result ) ? ( $result['text'] ?? '' ) : (string) $result;
        if ( '' === trim( $text ) ) {
            wp_send_json_error( __( 'The model returned an empty brief. Try again.', 'real-smart-seo-pro' ) );
        }

        wp_send_json_success( array(
            'markdown' => $text,
            'html'     => $this->md_to_html( $text ),
            'cost'     => is_array( $result ) ? ( $result['cost'] ?? 0 ) : 0,
        ) );
    }

    private function build_prompt( $keyword, $notes ) {
        $identity = get_option( 'rsseo_sameas_identity', array() );
        $business = ! empty( $identity['business_name'] ) ? $identity['business_name'] : get_bloginfo( 'name' );

        $prompt  = "You are an expert SEO content strategist. Produce a concise, writer-ready content brief in Markdown for the target keyword below. Be specific and practical — no fluff.\n\n";
        $prompt .= 'Target keyword: "' . $keyword . "\"\n";
        $prompt .= 'Business: ' . $business . "\n";
        if ( '' !== $notes ) {
            $prompt .= 'Context: ' . $notes . "\n";
        }
        $prompt .= "\nUse exactly these sections as Markdown H2 headings:\n";
        $prompt .= "## Search Intent\n## SEO Title Options (3, each ≤ 60 characters)\n## Meta Description (≤ 155 characters)\n## Target Word Count\n## Outline (the H2 and H3 headings the page should use)\n## Entities & Topics to Cover\n## Questions to Answer (People Also Ask style)\n## Suggested Internal Link Anchors\n## Primary CTA\n";
        $prompt .= "\nKeep each section tight and ready to hand to a writer.";
        return $prompt;
    }

    /** Minimal, safe Markdown → HTML for headings, lists, bold, and paragraphs. */
    private function md_to_html( $md ) {
        $lines = preg_split( '/\r\n|\r|\n/', (string) $md );
        $html  = '';
        $in_ul = false;

        $inline = function ( $text ) {
            $text = esc_html( $text );
            $text = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
            $text = preg_replace( '/(?<!\*)\*(?!\*)(.+?)\*(?!\*)/', '<em>$1</em>', $text );
            return $text;
        };

        foreach ( $lines as $line ) {
            $line = rtrim( $line );

            if ( preg_match( '/^\s*[-*]\s+(.+)/', $line, $m ) ) {
                if ( ! $in_ul ) {
                    $html .= '<ul>';
                    $in_ul = true;
                }
                $html .= '<li>' . $inline( $m[1] ) . '</li>';
                continue;
            }
            if ( $in_ul ) {
                $html .= '</ul>';
                $in_ul = false;
            }

            if ( preg_match( '/^###\s+(.+)/', $line, $m ) ) {
                $html .= '<h4>' . $inline( $m[1] ) . '</h4>';
            } elseif ( preg_match( '/^##\s+(.+)/', $line, $m ) ) {
                $html .= '<h3 style="margin:18px 0 6px;color:#0F1411;">' . $inline( $m[1] ) . '</h3>';
            } elseif ( preg_match( '/^#\s+(.+)/', $line, $m ) ) {
                $html .= '<h2>' . $inline( $m[1] ) . '</h2>';
            } elseif ( '' === trim( $line ) ) {
                // skip blank
                continue;
            } else {
                $html .= '<p style="margin:4px 0;">' . $inline( $line ) . '</p>';
            }
        }
        if ( $in_ul ) {
            $html .= '</ul>';
        }
        return $html;
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $nonce = wp_create_nonce( 'rsseo_brief' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Content Brief Builder', 'real-smart-seo-pro' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Generate a writer-ready SEO brief for any target keyword — intent, title/meta options, outline, entities, PAA questions, internal links, and a CTA.', 'real-smart-seo-pro' ); ?></p>

            <table class="form-table">
                <tr>
                    <th><label for="rsseo-brief-keyword"><?php esc_html_e( 'Target keyword', 'real-smart-seo-pro' ); ?></label></th>
                    <td><input type="text" id="rsseo-brief-keyword" class="regular-text" placeholder="commercial carpet cleaning bethesda"></td>
                </tr>
                <tr>
                    <th><label for="rsseo-brief-notes"><?php esc_html_e( 'Context (optional)', 'real-smart-seo-pro' ); ?></label></th>
                    <td><textarea id="rsseo-brief-notes" rows="3" class="large-text" placeholder="Audience, location, angle, anything the writer should know."></textarea></td>
                </tr>
            </table>
            <p>
                <button id="rsseo-brief-go" class="button button-primary" data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php esc_html_e( 'Generate Brief', 'real-smart-seo-pro' ); ?></button>
                <span id="rsseo-brief-status" style="margin-left:10px;color:#666;"></span>
            </p>

            <div id="rsseo-brief-out" style="display:none;margin-top:16px;">
                <div style="display:flex;gap:16px;align-items:flex-start;">
                    <div style="flex:1;background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:18px 22px;" id="rsseo-brief-rendered"></div>
                    <div style="flex:1;">
                        <p style="margin:0 0 6px;color:#666;font-size:12px;"><?php esc_html_e( 'Markdown (copy/paste)', 'real-smart-seo-pro' ); ?></p>
                        <textarea id="rsseo-brief-md" rows="22" class="large-text code" readonly></textarea>
                    </div>
                </div>
            </div>

            <script>
            (function($){
                $('#rsseo-brief-go').on('click', function(){
                    var b = $(this), kw = $('#rsseo-brief-keyword').val();
                    if (!kw) { alert('<?php echo esc_js( __( 'Enter a target keyword.', 'real-smart-seo-pro' ) ); ?>'); return; }
                    b.prop('disabled', true);
                    $('#rsseo-brief-status').text('<?php echo esc_js( __( 'Generating… this can take 20–40 seconds.', 'real-smart-seo-pro' ) ); ?>');
                    $.post(ajaxurl, {
                        action: 'rsseo_brief_generate',
                        nonce:  b.data('nonce'),
                        keyword: kw,
                        notes:  $('#rsseo-brief-notes').val()
                    }, function(res){
                        b.prop('disabled', false);
                        if (res.success) {
                            $('#rsseo-brief-rendered').html(res.data.html);
                            $('#rsseo-brief-md').val(res.data.markdown);
                            $('#rsseo-brief-out').show();
                            var c = res.data.cost ? (' · $' + Number(res.data.cost).toFixed(4)) : '';
                            $('#rsseo-brief-status').text('<?php echo esc_js( __( 'Done.', 'real-smart-seo-pro' ) ); ?>' + c);
                        } else {
                            $('#rsseo-brief-status').text('');
                            alert(res.data || 'Error');
                        }
                    }).fail(function(){
                        b.prop('disabled', false);
                        $('#rsseo-brief-status').text('');
                        alert('<?php echo esc_js( __( 'Request failed. Try again.', 'real-smart-seo-pro' ) ); ?>');
                    });
                });
            })(jQuery);
            </script>
        </div>
        <?php
    }
}

RSSEO_Pro_Content_Brief::get_instance();
