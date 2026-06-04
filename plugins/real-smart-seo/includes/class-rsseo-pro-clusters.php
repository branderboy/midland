<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Keyword clustering.
 *
 * Paste a flat keyword list; the module groups them into topic clusters by
 * shared significant tokens (Jaccard similarity), labels each cluster, and
 * suggests the best existing page to target for that cluster (or flags that a
 * new pillar page is needed). No external API — pure local grouping.
 */
class RSSEO_Pro_Clusters {

    const OPT_KEYWORDS = 'rsseo_cluster_keywords';
    const OPT_RESULTS  = 'rsseo_cluster_results';
    const THRESHOLD    = 0.34; // min Jaccard to join an existing cluster
    const MAX_KEYWORDS = 600;

    private static $stopwords = array(
        'the','a','an','and','or','of','to','in','for','on','at','by','with','near','me','my',
        'best','top','cheap','affordable','local','services','service','company','companies','near',
        'cost','price','prices','quote','quotes','vs','your','you','is','are','how','what','where',
    );

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ), 30 );
        add_action( 'admin_init', array( $this, 'handle_build' ) );
    }

    public function add_menu() {
        add_submenu_page(
            null,
            esc_html__( 'Keyword Clusters', 'real-smart-seo-pro' ),
            esc_html__( 'Keyword Clusters', 'real-smart-seo-pro' ),
            'manage_options',
            'rsseo-clusters',
            array( $this, 'render_page' )
        );
    }

    public function handle_build() {
        if ( ! isset( $_POST['rsseo_build_clusters'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_rsseo_cl_nonce'] ?? '' ) ), 'rsseo_clusters' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'real-smart-seo-pro' ) );
        }

        $raw      = sanitize_textarea_field( wp_unslash( $_POST['rsseo_keywords'] ?? '' ) );
        $keywords = array_slice( array_values( array_unique( array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) ) ), 0, self::MAX_KEYWORDS );

        update_option( self::OPT_KEYWORDS, implode( "\n", $keywords ), false );
        update_option( self::OPT_RESULTS, $this->cluster( $keywords ), false );

        wp_safe_redirect( admin_url( 'admin.php?page=rsseo-clusters&built=1' ) );
        exit;
    }

    /* --------------------------- algorithm --------------------------- */

    private function tokenize( $keyword ) {
        $parts = preg_split( '/[^a-z0-9]+/', strtolower( $keyword ), -1, PREG_SPLIT_NO_EMPTY );
        $toks  = array();
        foreach ( $parts as $p ) {
            if ( mb_strlen( $p ) < 2 || in_array( $p, self::$stopwords, true ) ) {
                continue;
            }
            $toks[ $p ] = true;
        }
        return array_keys( $toks );
    }

    private function jaccard( $a, $b ) {
        if ( empty( $a ) || empty( $b ) ) {
            return 0.0;
        }
        $inter = count( array_intersect( $a, $b ) );
        $union = count( array_unique( array_merge( $a, $b ) ) );
        return $union ? $inter / $union : 0.0;
    }

    /**
     * Group keywords into clusters and attach a label + suggested target page.
     *
     * @param string[] $keywords
     * @return array[] clusters
     */
    public function cluster( $keywords ) {
        $clusters = array(); // each: ['keywords'=>[], 'tokens'=>[], 'freq'=>[]]

        foreach ( $keywords as $kw ) {
            $toks = $this->tokenize( $kw );
            if ( empty( $toks ) ) {
                $toks = array( strtolower( $kw ) );
            }

            $best = -1;
            $best_score = 0.0;
            foreach ( $clusters as $i => $c ) {
                $score = $this->jaccard( $toks, $c['tokens'] );
                if ( $score > $best_score ) {
                    $best_score = $score;
                    $best = $i;
                }
            }

            if ( $best >= 0 && $best_score >= self::THRESHOLD ) {
                $clusters[ $best ]['keywords'][] = $kw;
                $clusters[ $best ]['tokens'] = array_values( array_unique( array_merge( $clusters[ $best ]['tokens'], $toks ) ) );
                foreach ( $toks as $t ) {
                    $clusters[ $best ]['freq'][ $t ] = ( $clusters[ $best ]['freq'][ $t ] ?? 0 ) + 1;
                }
            } else {
                $freq = array();
                foreach ( $toks as $t ) {
                    $freq[ $t ] = 1;
                }
                $clusters[] = array( 'keywords' => array( $kw ), 'tokens' => $toks, 'freq' => $freq );
            }
        }

        // Label + target suggestion.
        $out = array();
        foreach ( $clusters as $c ) {
            arsort( $c['freq'] );
            $label_token = key( $c['freq'] );
            // Prefer the shortest keyword that contains the dominant token as the human label.
            $label = $this->pick_label( $c['keywords'], $label_token );
            $target = $this->suggest_target( $c['tokens'], $label );
            sort( $c['keywords'] );
            $out[] = array(
                'label'    => $label,
                'count'    => count( $c['keywords'] ),
                'keywords' => $c['keywords'],
                'target'   => $target,
            );
        }

        usort( $out, function ( $a, $b ) {
            return $b['count'] - $a['count'];
        } );
        return $out;
    }

    private function pick_label( $keywords, $token ) {
        $candidates = array();
        foreach ( $keywords as $kw ) {
            if ( $token && false !== stripos( $kw, $token ) ) {
                $candidates[] = $kw;
            }
        }
        $pool = $candidates ?: $keywords;
        usort( $pool, function ( $a, $b ) {
            return mb_strlen( $a ) - mb_strlen( $b );
        } );
        return ucwords( $pool[0] );
    }

    /** Best existing page for a cluster, by title-token overlap. */
    private function suggest_target( $tokens, $label ) {
        $posts = get_posts( array(
            'post_type'   => array( 'post', 'page' ),
            'post_status' => 'publish',
            'numberposts' => 300,
        ) );
        $best = null;
        $best_score = 0.0;
        foreach ( $posts as $p ) {
            $score = $this->jaccard( $tokens, $this->tokenize( $p->post_title ) );
            if ( $score > $best_score ) {
                $best_score = $score;
                $best = $p;
            }
        }
        if ( $best && $best_score >= 0.25 ) {
            return array(
                'type'  => 'existing',
                'id'    => $best->ID,
                'title' => $best->post_title,
                'url'   => get_permalink( $best->ID ),
                'score' => round( $best_score, 2 ),
            );
        }
        return array( 'type' => 'new', 'title' => $label );
    }

    /* ----------------------------- view ----------------------------- */

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $built = isset( $_GET['built'] );
        // phpcs:enable
        $keywords = (string) get_option( self::OPT_KEYWORDS, '' );
        $results  = (array) get_option( self::OPT_RESULTS, array() );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Keyword Clusters', 'real-smart-seo-pro' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Paste your keyword list — one per line. The clusters below group related terms and point each group at the page that should own it (or flag a new pillar page).', 'real-smart-seo-pro' ); ?></p>

            <?php if ( $built ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php printf( esc_html__( 'Built %d clusters.', 'real-smart-seo-pro' ), count( $results ) ); ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'rsseo_clusters', '_rsseo_cl_nonce' ); ?>
                <textarea name="rsseo_keywords" rows="8" class="large-text" placeholder="carpet cleaning bethesda&#10;commercial carpet cleaning&#10;tile and grout cleaning&#10;hardwood floor refinishing cost"><?php echo esc_textarea( $keywords ); ?></textarea>
                <p><button type="submit" name="rsseo_build_clusters" value="1" class="button button-primary"><?php esc_html_e( 'Build Clusters', 'real-smart-seo-pro' ); ?></button></p>
            </form>

            <?php if ( $results ) : ?>
                <?php foreach ( $results as $cluster ) :
                    $target = $cluster['target'];
                ?>
                    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:14px 18px;margin:14px 0;">
                        <h2 style="margin:0 0 4px;"><?php echo esc_html( $cluster['label'] ); ?> <span style="color:#888;font-weight:400;">(<?php echo (int) $cluster['count']; ?>)</span></h2>
                        <p style="margin:0 0 8px;">
                            <strong><?php esc_html_e( 'Target:', 'real-smart-seo-pro' ); ?></strong>
                            <?php if ( 'existing' === $target['type'] ) : ?>
                                <a href="<?php echo esc_url( $target['url'] ); ?>" target="_blank"><?php echo esc_html( $target['title'] ); ?></a>
                                <span style="color:#888;">(<?php echo esc_html( $target['score'] ); ?> <?php esc_html_e( 'match', 'real-smart-seo-pro' ); ?>)</span>
                            <?php else : ?>
                                <span style="display:inline-block;padding:2px 8px;background:#fef3c7;color:#92400e;border-radius:3px;font-size:12px;"><?php printf( esc_html__( 'New pillar page needed: %s', 'real-smart-seo-pro' ), esc_html( $target['title'] ) ); ?></span>
                            <?php endif; ?>
                        </p>
                        <div>
                            <?php foreach ( $cluster['keywords'] as $kw ) : ?>
                                <code style="display:inline-block;background:#F3FCF4;border:1px solid #cdeccf;border-radius:3px;padding:1px 7px;margin:2px 4px 2px 0;"><?php echo esc_html( $kw ); ?></code>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}

RSSEO_Pro_Clusters::get_instance();
