<?php
/**
 * Shortcodes for displaying job listings on any page.
 *
 * Usage:
 *   [dpjp_jobs]                    — Grid of all active job listings
 *   [dpjp_jobs layout="list"]      — Simple list layout
 *   [dpjp_jobs count="4"]          — Limit to 4 jobs
 *   [dpjp_jobs trade="Drywall"]    — Filter by trade
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class DPJP_Shortcode {

    public static function register(): void {
        add_shortcode( 'dpjp_jobs', [ __CLASS__, 'render_jobs' ] );
        add_shortcode( 'dpjp_job',  [ __CLASS__, 'render_single' ] );
        add_action( 'admin_menu',   [ __CLASS__, 'menu' ] );
    }

    public static function menu(): void {
        add_submenu_page(
            'edit.php?post_type=dpjp_job',
            'Shortcodes',
            'Shortcodes',
            'manage_options',
            'dpjp-shortcodes',
            [ __CLASS__, 'render_help' ]
        );
    }

    public static function render_help(): void {
        ?>
        <div class="wrap">
            <h1>Job Listing Shortcodes</h1>
            <p>Copy these shortcodes and paste them into any page, post, or Elementor <strong>Shortcode widget</strong> to display your jobs.</p>

            <style>
                .dpjp-sc-row { background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:20px;margin-bottom:15px; }
                .dpjp-sc-code { background:#f6f7f7;border:1px solid #dcdcde;padding:10px 14px;font-family:Consolas,Monaco,monospace;font-size:14px;border-radius:3px;display:inline-block;margin:5px 0; }
                .dpjp-sc-copy { background:#2271b1;color:#fff;border:none;padding:6px 14px;border-radius:3px;cursor:pointer;font-size:12px;margin-left:8px;vertical-align:middle; }
                .dpjp-sc-copy:hover { background:#135e96; }
                .dpjp-sc-desc { color:#666;font-size:13px;margin-top:8px; }
            </style>

            <h2>Main Shortcode: All Active Jobs</h2>
            <div class="dpjp-sc-row">
                <div class="dpjp-sc-code" id="sc1">[dpjp_jobs]</div>
                <button class="dpjp-sc-copy" data-target="sc1">Copy</button>
                <p class="dpjp-sc-desc">Shows a 2-column grid of all active job listings with pay and Apply buttons.</p>
            </div>

            <h2>Layout Options</h2>
            <div class="dpjp-sc-row">
                <div class="dpjp-sc-code" id="sc2">[dpjp_jobs layout="list"]</div>
                <button class="dpjp-sc-copy" data-target="sc2">Copy</button>
                <p class="dpjp-sc-desc">Simple vertical list layout (good for sidebars or narrow columns).</p>
            </div>
            <div class="dpjp-sc-row">
                <div class="dpjp-sc-code" id="sc3">[dpjp_jobs columns="3"]</div>
                <button class="dpjp-sc-copy" data-target="sc3">Copy</button>
                <p class="dpjp-sc-desc">3-column grid. Use <code>columns="1"</code>, <code>"2"</code>, <code>"3"</code>, or <code>"4"</code>.</p>
            </div>

            <h2>Filters</h2>
            <div class="dpjp-sc-row">
                <div class="dpjp-sc-code" id="sc4">[dpjp_jobs count="4"]</div>
                <button class="dpjp-sc-copy" data-target="sc4">Copy</button>
                <p class="dpjp-sc-desc">Limit to the 4 most recent jobs.</p>
            </div>
            <div class="dpjp-sc-row">
                <div class="dpjp-sc-code" id="sc5">[dpjp_jobs trade="Drywall"]</div>
                <button class="dpjp-sc-copy" data-target="sc5">Copy</button>
                <p class="dpjp-sc-desc">Show only jobs with a specific trade. Values: Drywall, Framing, Carpentry, Painting, HVAC, Plumbing, etc.</p>
            </div>
            <div class="dpjp-sc-row">
                <div class="dpjp-sc-code" id="sc6">[dpjp_jobs type="full-time"]</div>
                <button class="dpjp-sc-copy" data-target="sc6">Copy</button>
                <p class="dpjp-sc-desc">Filter by employment type: <code>full-time</code>, <code>part-time</code>, <code>contract</code>, <code>seasonal</code>.</p>
            </div>

            <h2>Single Job Display</h2>
            <div class="dpjp-sc-row">
                <div class="dpjp-sc-code" id="sc7">[dpjp_job id="123"]</div>
                <button class="dpjp-sc-copy" data-target="sc7">Copy</button>
                <p class="dpjp-sc-desc">Display one specific job. Replace <code>123</code> with the job's post ID (shown in the URL when editing a job).</p>
            </div>

            <h2>How to Use in Elementor</h2>
            <ol>
                <li>Edit any page with Elementor</li>
                <li>Drag a <strong>Shortcode widget</strong> (in the Basic section) onto your page</li>
                <li>Paste one of the shortcodes above</li>
                <li>Click Update</li>
            </ol>

            <h2>How to Use in WordPress Editor</h2>
            <ol>
                <li>Edit any page or post</li>
                <li>Add a <strong>Shortcode block</strong> (type <code>/shortcode</code>)</li>
                <li>Paste the shortcode</li>
                <li>Click Update</li>
            </ol>

            <h2>Live Preview</h2>
            <p>Here's what <code>[dpjp_jobs]</code> will display on your site:</p>
            <div style="border:2px dashed #ccc;padding:20px;background:#fafafa;">
                <?php echo do_shortcode( '[dpjp_jobs]' ); ?>
            </div>

            <script>
            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('dpjp-sc-copy')) {
                    var el = document.getElementById(e.target.dataset.target);
                    if (el) {
                        navigator.clipboard.writeText(el.textContent).then(function() {
                            var orig = e.target.textContent;
                            e.target.textContent = 'Copied!';
                            setTimeout(function() { e.target.textContent = orig; }, 1500);
                        });
                    }
                }
            });
            </script>
        </div>
        <?php
    }

    public static function render_jobs( $atts ): string {
        $atts = shortcode_atts( [
            'layout'  => 'grid',   // grid | list | cards
            'count'   => -1,       // number of jobs, -1 for all
            'trade'   => '',       // filter by trade
            'type'    => '',       // filter by employment type
            'columns' => 2,        // grid columns
            'apply'   => 'page',   // page | popup — where the Apply button goes
        ], $atts, 'dpjp_jobs' );

        $args = [
            'post_type'      => 'dpjp_job',
            'post_status'    => 'publish',
            'posts_per_page' => (int) $atts['count'],
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        $meta_query = [];
        if ( ! empty( $atts['trade'] ) ) {
            $meta_query[] = [ 'key' => 'dpjp_trade', 'value' => $atts['trade'], 'compare' => 'LIKE' ];
        }
        if ( ! empty( $atts['type'] ) ) {
            $meta_query[] = [ 'key' => 'dpjp_employment_type', 'value' => $atts['type'] ];
        }
        if ( $meta_query ) $args['meta_query'] = $meta_query;

        $jobs = get_posts( $args );
        if ( ! $jobs ) return '<p>No open positions at this time. Check back soon!</p>';

        $apply_mode = $atts['apply'] === 'popup' ? 'popup' : 'page';
        ob_start();
        if ( $atts['layout'] === 'list' ) {
            self::render_list( $jobs, $apply_mode );
        } else {
            self::render_grid( $jobs, (int) $atts['columns'], $apply_mode );
        }
        if ( $apply_mode === 'popup' ) self::render_modal();
        return ob_get_clean();
    }

    public static function render_single( $atts ): string {
        $atts = shortcode_atts( [ 'id' => 0, 'slug' => '' ], $atts, 'dpjp_job' );

        if ( $atts['id'] ) {
            $post = get_post( (int) $atts['id'] );
        } elseif ( $atts['slug'] ) {
            $post = get_page_by_path( $atts['slug'], OBJECT, 'dpjp_job' );
        } else {
            return '';
        }
        if ( ! $post || $post->post_type !== 'dpjp_job' ) return '';

        $meta = DPJP_Meta_Fields::get( $post->ID );
        ob_start();
        ?>
        <div class="dpjp-job-single" style="border:1px solid #e0e0e0;border-radius:8px;padding:25px;margin:20px 0;background:#fff;">
            <h3 style="margin:0 0 10px;"><?php echo esc_html( $post->post_title ); ?></h3>
            <p style="color:#2a7f2a;font-size:20px;font-weight:bold;margin:0 0 15px;"><?php echo esc_html( $meta['dpjp_pay'] ?? '' ); ?></p>
            <div><?php echo apply_filters( 'the_content', $post->post_content ); ?></div>
            <a href="<?php echo esc_url( get_permalink( $post ) ); ?>" style="display:inline-block;background:#0073aa;color:#fff;padding:12px 24px;border-radius:4px;text-decoration:none;font-weight:bold;margin-top:15px;">View &amp; Apply &rarr;</a>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function apply_button( $job, string $mode ): string {
        $title = esc_attr( get_the_title( $job ) );
        if ( $mode === 'popup' && class_exists( 'DPJP_Application' ) ) {
            return sprintf(
                '<a href="#" class="dpjp-apply-trigger" data-job-id="%d" data-job-title="%s" style="display:inline-block;background:#0073aa;color:#fff;padding:10px 20px;border-radius:4px;text-decoration:none;font-weight:bold;text-align:center;">Apply Now</a>',
                $job->ID, $title
            );
        }
        $url = class_exists( 'DPJP_Application' ) ? DPJP_Application::apply_url( $job->ID ) : get_permalink( $job );
        return sprintf(
            '<a href="%s" style="display:inline-block;background:#0073aa;color:#fff;padding:10px 20px;border-radius:4px;text-decoration:none;font-weight:bold;text-align:center;">Apply Now</a>',
            esc_url( $url )
        );
    }

    private static function render_grid( array $jobs, int $columns, string $apply_mode = 'page' ): void {
        $columns = max( 1, min( 4, $columns ) );
        ?>
        <div class="dpjp-jobs-grid" style="display:grid;grid-template-columns:repeat(<?php echo $columns; ?>,1fr);gap:20px;margin:20px 0;">
            <?php foreach ( $jobs as $job ) :
                $meta = DPJP_Meta_Fields::get( $job->ID );
                $type_label = self::employment_label( $meta['dpjp_employment_type'] ?? 'full-time' );
                ?>
                <div class="dpjp-job-card" style="border:2px solid #0073aa;border-radius:8px;padding:25px;background:#fff;display:flex;flex-direction:column;">
                    <div style="font-size:12px;color:#666;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;"><?php echo esc_html( $type_label ); ?> &bull; <?php echo esc_html( $meta['dpjp_location'] ?? '' ); ?></div>
                    <h3 style="margin:0 0 10px;color:#0073aa;"><?php echo esc_html( $job->post_title ); ?></h3>
                    <p style="color:#2a7f2a;font-size:20px;font-weight:bold;margin:0 0 10px;"><?php echo esc_html( $meta['dpjp_pay'] ?? '' ); ?></p>
                    <p style="flex-grow:1;margin:0 0 15px;font-size:14px;color:#444;"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $job->post_content ), 20 ) ); ?></p>
                    <?php echo self::apply_button( $job, $apply_mode ); ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private static function render_list( array $jobs, string $apply_mode = 'page' ): void {
        ?>
        <ul class="dpjp-jobs-list" style="list-style:none;padding:0;margin:20px 0;">
            <?php foreach ( $jobs as $job ) :
                $meta = DPJP_Meta_Fields::get( $job->ID );
                ?>
                <li style="border-bottom:1px solid #e0e0e0;padding:20px 0;display:flex;justify-content:space-between;align-items:center;gap:20px;flex-wrap:wrap;">
                    <div style="flex:1;min-width:250px;">
                        <h3 style="margin:0 0 5px;"><?php echo esc_html( $job->post_title ); ?></h3>
                        <p style="margin:0;color:#666;font-size:14px;"><?php echo esc_html( self::employment_label( $meta['dpjp_employment_type'] ?? 'full-time' ) ); ?> &bull; <?php echo esc_html( $meta['dpjp_location'] ?? '' ); ?></p>
                    </div>
                    <div style="color:#2a7f2a;font-size:18px;font-weight:bold;"><?php echo esc_html( $meta['dpjp_pay'] ?? '' ); ?></div>
                    <?php echo self::apply_button( $job, $apply_mode ); ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
    }

    private static function render_modal(): void {
        if ( ! class_exists( 'DPJP_Application' ) ) return;
        ?>
        <div id="dpjp-modal-overlay" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.7);z-index:99998;align-items:flex-start;justify-content:center;overflow-y:auto;padding:40px 20px;">
            <div id="dpjp-modal" style="background:#fff;border-radius:8px;max-width:700px;width:100%;position:relative;">
                <button id="dpjp-modal-close" style="position:absolute;top:10px;right:15px;background:none;border:none;font-size:28px;cursor:pointer;color:#666;z-index:1;">&times;</button>
                <?php echo DPJP_Application::render_form( [] ); ?>
            </div>
        </div>
        <style>
            #dpjp-modal-overlay.open { display:flex; }
            body.dpjp-modal-open { overflow:hidden; }
        </style>
        <script>
        (function() {
            var overlay = document.getElementById('dpjp-modal-overlay');
            var closeBtn = document.getElementById('dpjp-modal-close');
            document.addEventListener('click', function(e) {
                var trigger = e.target.closest('.dpjp-apply-trigger');
                if (trigger) {
                    e.preventDefault();
                    var select = overlay.querySelector('select[name="job_id"]');
                    if (select) select.value = trigger.dataset.jobId;
                    overlay.classList.add('open');
                    document.body.classList.add('dpjp-modal-open');
                }
                if (e.target === overlay || e.target === closeBtn) {
                    overlay.classList.remove('open');
                    document.body.classList.remove('dpjp-modal-open');
                }
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    overlay.classList.remove('open');
                    document.body.classList.remove('dpjp-modal-open');
                }
            });
        })();
        </script>
        <?php
    }

    private static function employment_label( string $type ): string {
        $map = [ 'full-time' => 'Full Time', 'part-time' => 'Part Time', 'contract' => 'Contract', 'seasonal' => 'Seasonal' ];
        return $map[ $type ] ?? ucfirst( $type );
    }
}
