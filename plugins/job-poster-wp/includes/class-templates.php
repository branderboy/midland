<?php
/**
 * Floor-care job templates — one-click drafts for the most common roles a
 * commercial/residential floor-care company hires for. Operator picks a card,
 * gets a draft job post with all meta fields and a clean description already
 * populated, edits the location/pay if needed, hits Publish.
 *
 * Templates are intentionally Midland-Floors-flavored (DC/MD/VA, commercial
 * floor focus) but the operator can tweak before publishing.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class DPJP_Templates {

    public static function register(): void {
        add_action( 'admin_menu',                       [ __CLASS__, 'menu' ] );
        add_action( 'admin_post_dpjp_use_template',     [ __CLASS__, 'handle_create' ] );
        add_action( 'admin_post_dpjp_seed_all',         [ __CLASS__, 'handle_seed_all' ] );
        add_action( 'admin_post_dpjp_publish_all',      [ __CLASS__, 'handle_publish_all' ] );
    }

    public static function menu(): void {
        add_submenu_page(
            'edit.php?post_type=dpjp_job',
            __( 'Midland Jobs', 'job-manager-pro' ),
            __( 'Templates', 'job-manager-pro' ),
            'edit_posts',
            'dpjp-templates',
            [ __CLASS__, 'render' ]
        );
    }

    /**
     * The template library. Each entry is what gets pre-filled when the
     * operator clicks "Use this template" — they can still edit before
     * publishing.
     */
    public static function templates(): array {
        $valid_through = gmdate( 'Y-m-d', strtotime( '+30 days' ) );
        $cta           = 'Call or text us today. We respond fast.';
        $location      = 'Washington DC Metro Area';

        return [
            'commercial-carpet-tech' => [
                'title' => 'Commercial Carpet Care Technician',
                'trade' => 'Commercial Carpet Care',
                'location' => $location,
                'pay' => '$22 to $30/hr',
                'employment_type' => 'full-time',
                'description' => "Commercial Carpet Care Tech wanted for our office, medical, and retail accounts in DC, Maryland, and Northern Virginia. Mostly after hours, mostly recurring contracts. Predictable schedule, consistent paychecks, no homeowner drama.\n\nWhat you'll do:\n• Hot water extraction and low moisture encapsulation on commercial carpet.\n• Spot and stain treatment on heavy traffic lanes.\n• Carpet bonneting and interim maintenance.\n• Operate truck mount and portable extractors.\n• Document before and after photos for property managers.\n\nWe service offices, medical buildings, schools, and retail. Recurring contracts mean recurring pay.",
                'requirements' => "One or more years of commercial carpet experience preferred.\nFamiliar with extraction and encapsulation methods.\nReliable transportation and a clean driving record.\nAvailable evenings and weekends.\nMust pass a background check (medical and government accounts require it).",
            ],

            'floor-stripping-tech' => [
                'title' => 'Floor Stripping and Wax Technician',
                'trade' => 'Floor Stripping and Waxing',
                'location' => $location,
                'pay' => '$22 to $30/hr',
                'employment_type' => 'full-time',
                'description' => "Looking for an experienced Floor Stripping and Wax Tech for commercial accounts (offices, schools, healthcare, retail). Mostly after hours work, paid weekly.\n\nWhat you'll do:\n• Strip and recoat VCT, vinyl, and rubber floors.\n• Apply four to six coats of finish and buff between coats.\n• Operate auto scrubbers, swing buffers, and burnishers.\n• Handle furniture moves and floor protection.\n• Document jobs with before and after photos.\n\nCommercial accounts mean consistent hours and no homeowner drama.",
                'requirements' => "Two or more years of floor stripping experience.\nFamiliar with auto scrubbers and burnishers.\nReliable transportation.\nAvailable evenings and weekends.\nMust pass a background check (some accounts require it).",
            ],

            'tile-grout-tech' => [
                'title' => 'Tile and Grout Cleaning Specialist',
                'trade' => 'Tile and Grout Cleaning',
                'location' => $location,
                'pay' => '$22 to $30/hr',
                'employment_type' => 'full-time',
                'description' => "Hiring a Tile and Grout Cleaning Specialist for commercial restroom and lobby restoration plus residential bathroom and kitchen jobs.\n\nWhat you'll do:\n• High pressure tile and grout cleaning with truck mount units.\n• Grout color sealing and repair.\n• Stone polishing (limited).\n• Residential bathroom and kitchen jobs.\n• Commercial restroom and lobby restoration.\n\nGreat fit if you've done carpet cleaning and want to expand into harder surfaces. Same trucks, same crews, more billable hours.",
                'requirements' => "One or more years of tile and grout cleaning experience.\nFamiliarity with high pressure rotary tools.\nClean appearance and professional demeanor.\nValid driver's license.",
            ],

            'commercial-floor-crew' => [
                'title' => 'Commercial Floor Care Crew Member (Nights and Weekends)',
                'trade' => 'Commercial Floor Care',
                'location' => $location,
                'pay' => '$18 to $22/hr',
                'employment_type' => 'part-time',
                'description' => "We're growing our commercial floor care crew. Night and weekend shifts servicing offices, medical facilities, and retail spaces in DC, Maryland, and Northern Virginia.\n\nWhat you'll do:\n• Operate floor scrubbers, burnishers, and carpet extractors.\n• Strip and wax hard floors, extract commercial carpet.\n• Empty trash, vacuum, dust, and high touch sanitize.\n• Lock up and arm alarms at end of shift.\n\nReliable hours, weekly pay, and overtime when available. No experience needed. We train.",
                'requirements' => "Reliable transportation.\nAble to pass a background check.\nAvailable evenings and weekends.\nNo experience necessary. We train.\nMust be 18 or older.",
            ],

            'concrete-polishing-tech' => [
                'title' => 'Concrete Polishing and Coating Technician',
                'trade' => 'Concrete Polishing',
                'location' => $location,
                'pay' => '$25 to $38/hr',
                'employment_type' => 'full-time',
                'description' => "Concrete Polishing Tech wanted for commercial and industrial projects in warehouses, retail buildouts, and showrooms.\n\nWhat you'll do:\n• Multi step diamond grinding and polishing.\n• Densifier and stain guard application.\n• Epoxy coating prep and install.\n• Operate planetary grinders, edgers, and dust extraction.\n\nLearn a high skill trade. We invest in training.",
                'requirements' => "Experience with concrete grinding or polishing.\nFamiliar with diamond tooling.\nOSHA 10 is a plus.\nValid driver's license.\nWilling to travel within the DMV.",
            ],

            'water-damage-restoration' => [
                'title' => 'Water Damage Restoration Technician (IICRC)',
                'trade' => 'Water Damage Restoration',
                'location' => $location,
                'pay' => '$22 to $32/hr plus on call premium',
                'employment_type' => 'full-time',
                'description' => "24/7 emergency response. Water Damage Restoration Technician needed. IICRC certification preferred, or we'll get you certified.\n\nWhat you'll do:\n• Extract water from carpet, hardwood, and concrete.\n• Set up air movers and dehumidifiers.\n• Moisture mapping with thermal imaging.\n• Document daily readings for insurance.\n• Communicate with adjusters and homeowners under stress.\n\nOn call rotation pays a premium. Tons of overtime available.",
                'requirements' => "IICRC WRT certification (or willing to obtain, we pay).\nClean driving record.\nMust pass a drug screen.\nAbility to work nights and weekends on rotation.\nGood under pressure.",
            ],

            'sales-estimator' => [
                'title' => 'Floor Care Sales and Estimator',
                'trade' => 'Sales and Estimating',
                'location' => $location,
                'pay' => 'Base $50K plus commission (OTE $85K to $120K)',
                'employment_type' => 'full-time',
                'description' => "Hiring a Floor Care Sales and Estimator. Walk commercial and high end residential prospects, measure square footage, write proposals, close the deal.\n\nWhat you'll do:\n• Run scheduled estimate appointments.\n• Quote carpet, hardwood, tile, and floor care contracts.\n• Build relationships with property managers and facility ops.\n• Hand off won jobs to operations.\n• Hit monthly revenue targets.\n\nWarm leads provided. You're not cold calling, you're closing.",
                'requirements' => "Two or more years of field sales (B2B or B2C).\nComfortable with on site measuring and writing quotes.\nCRM experience (we use ServiceM8).\nClean driving record.\nProfessional appearance.",
            ],

            'office-dispatcher' => [
                'title' => 'Customer Service and Dispatcher',
                'trade' => 'Office and Dispatch',
                'location' => $location,
                'pay' => '$20 to $26/hr',
                'employment_type' => 'full-time',
                'description' => "Customer Service and Dispatcher role. Answer inbound calls, schedule jobs, route crews. The voice of the company.\n\nWhat you'll do:\n• Answer inbound phone and chat leads.\n• Schedule and dispatch crews via ServiceM8.\n• Confirm appointments and route changes.\n• Handle reschedule and cancellation requests.\n• Pre quote simple residential jobs over the phone.\n\nIn office role. Monday through Friday, daytime hours.",
                'requirements' => "One or more years of customer service or dispatch experience.\nComfortable with scheduling software.\nClear phone voice, calm under pressure.\nBasic computer literacy.\nLocal to DC, Maryland, or Northern Virginia.",
            ],
        ];
    }

    public static function render(): void {
        $templates = self::templates();
        $action    = admin_url( 'admin-post.php' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Midland Jobs', 'job-manager-pro' ); ?></h1>
            <p><?php esc_html_e( 'Click "Use this template" to create a draft job post pre-filled with title, description, pay, and requirements. Edit before publishing.', 'job-manager-pro' ); ?></p>

            <?php
            // One-click flows. Two flavors:
            //   - "publish all" → publishes every template + creates a Careers
            //     page with the [dpjp_jobs] shortcode so the operator can ship
            //     the entire careers section in a single click.
            //   - "seed all" → creates drafts only (safer for review-first
            //     workflows). Kept for back-compat.
            $publish_url = wp_nonce_url(
                add_query_arg( 'action', 'dpjp_publish_all', admin_url( 'admin-post.php' ) ),
                'dpjp_publish_all'
            );
            $seed_url = wp_nonce_url(
                add_query_arg( 'action', 'dpjp_seed_all', admin_url( 'admin-post.php' ) ),
                'dpjp_seed_all'
            );
            ?>
            <div style="background:#f0f6fc;border:1px solid #c3d4e7;border-left:4px solid #2271b1;padding:16px 18px;margin:14px 0;border-radius:4px;">
                <h2 style="margin:0 0 8px;">🚀 <?php esc_html_e( 'One-click careers section', 'job-manager-pro' ); ?></h2>
                <p style="margin:0 0 12px;color:#1d2327;">
                    <?php esc_html_e( 'Publishes every Midland floor-care job template, creates a "Careers" page with the job list shortcode embedded, and links every individual job page. Idempotent — re-clicking only adds what\'s new.', 'job-manager-pro' ); ?>
                </p>
                <p style="margin:0;">
                    <a href="<?php echo esc_url( $publish_url ); ?>" class="button button-primary button-large">
                        <?php esc_html_e( '✨ Publish all jobs + create Careers page', 'job-manager-pro' ); ?>
                    </a>
                    <a href="<?php echo esc_url( $seed_url ); ?>" class="button" style="margin-left:8px;">
                        <?php esc_html_e( 'Or: add as drafts only (review before publish)', 'job-manager-pro' ); ?>
                    </a>
                </p>
            </div>

            <?php if ( isset( $_GET['seeded'] ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php
                        /* translators: 1: created count, 2: skipped count */
                        printf(
                            esc_html__( '✓ Seeded %1$d new draft jobs. Skipped %2$d that already existed.', 'job-manager-pro' ),
                            (int) $_GET['seeded'],
                            (int) ( $_GET['skipped'] ?? 0 )
                        );
                        ?>
                        <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=dpjp_job&post_status=draft' ) ); ?>">
                            <?php esc_html_e( 'Review drafts →', 'job-manager-pro' ); ?>
                        </a>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ( isset( $_GET['published_all'] ) ) :
                $careers_id  = isset( $_GET['careers_id'] ) ? (int) $_GET['careers_id'] : 0;
                $careers_url = $careers_id ? get_permalink( $careers_id ) : '';
                $apply_id    = isset( $_GET['apply_id'] ) ? (int) $_GET['apply_id'] : 0;
                $apply_url   = $apply_id ? get_permalink( $apply_id ) : '';
                ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php
                        /* translators: 1: jobs published, 2: jobs already live */
                        printf(
                            esc_html__( '✓ %1$d jobs published, %2$d already live. Careers and Apply pages are ready.', 'job-manager-pro' ),
                            (int) $_GET['published_all'],
                            (int) ( $_GET['already'] ?? 0 )
                        );
                        ?>
                        <?php if ( $careers_url ) : ?>
                            <a href="<?php echo esc_url( $careers_url ); ?>" target="_blank" rel="noopener">
                                <?php esc_html_e( 'View Careers page →', 'job-manager-pro' ); ?>
                            </a>
                        <?php endif; ?>
                        <?php if ( $apply_url ) : ?>
                            &nbsp;
                            <a href="<?php echo esc_url( $apply_url ); ?>" target="_blank" rel="noopener">
                                <?php esc_html_e( 'View Apply page →', 'job-manager-pro' ); ?>
                            </a>
                        <?php endif; ?>
                        &nbsp;
                        <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=dpjp_job&post_status=publish' ) ); ?>">
                            <?php esc_html_e( 'View all published jobs →', 'job-manager-pro' ); ?>
                        </a>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ( isset( $_GET['template_created'] ) ) :
                $post_id = (int) $_GET['template_created'];
                $edit    = get_edit_post_link( $post_id );
                ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php esc_html_e( '✓ Draft created.', 'job-manager-pro' ); ?>
                        <?php if ( $edit ) : ?>
                            <a href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Open it →', 'job-manager-pro' ); ?></a>
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>

            <div class="dpjp-templates-grid">
                <?php foreach ( $templates as $slug => $t ) : ?>
                    <div class="dpjp-template-card">
                        <h3 style="margin-top:0;"><?php echo esc_html( $t['title'] ); ?></h3>
                        <p class="dpjp-template-meta">
                            <strong><?php echo esc_html( $t['trade'] ); ?></strong> ·
                            <?php echo esc_html( $t['pay'] ); ?> ·
                            <?php echo esc_html( str_replace( '-', ' ', $t['employment_type'] ) ); ?>
                        </p>
                        <p class="dpjp-template-desc"><?php echo esc_html( mb_strimwidth( $t['description'], 0, 220, '…' ) ); ?></p>
                        <form method="post" action="<?php echo esc_url( $action ); ?>" style="display:inline;">
                            <?php wp_nonce_field( 'dpjp_use_template' ); ?>
                            <input type="hidden" name="action" value="dpjp_use_template">
                            <input type="hidden" name="template" value="<?php echo esc_attr( $slug ); ?>">
                            <button type="submit" class="button button-primary"><?php esc_html_e( 'Use this template', 'job-manager-pro' ); ?></button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <style>
        .dpjp-templates-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 16px; margin-top: 20px;
        }
        .dpjp-template-card {
            background: #fff; border: 1px solid #c3c4c7; border-radius: 6px;
            padding: 18px 20px; display: flex; flex-direction: column;
        }
        .dpjp-template-meta { color: #475569; font-size: 13px; margin: 4px 0 10px; }
        .dpjp-template-desc { color: #1d2327; font-size: 13px; flex: 1; line-height: 1.5; }
        </style>
        <?php
    }

    /**
     * Loop every template into a draft post. Idempotent — skips any title
     * that already exists so the operator can re-click safely after editing
     * a couple of drafts to add the rest.
     */
    public static function handle_seed_all(): void {
        if ( ! current_user_can( 'edit_posts' ) ) wp_die( 'No.' );
        check_admin_referer( 'dpjp_seed_all' );

        $tpls    = self::templates();
        $created = 0;
        $skipped = 0;

        foreach ( $tpls as $slug => $t ) {
            // Skip if a job with this title already exists (any status).
            $existing = get_page_by_title( $t['title'], OBJECT, 'dpjp_job' );
            if ( $existing instanceof WP_Post ) {
                $skipped++;
                continue;
            }

            $post_id = wp_insert_post( [
                'post_type'    => 'dpjp_job',
                'post_status'  => 'draft',
                'post_title'   => $t['title'],
                'post_content' => $t['description'],
            ], true );

            if ( is_wp_error( $post_id ) || ! $post_id ) {
                continue;
            }

            update_post_meta( $post_id, 'dpjp_trade',           $t['trade'] );
            update_post_meta( $post_id, 'dpjp_location',        $t['location'] );
            update_post_meta( $post_id, 'dpjp_pay',             $t['pay'] );
            update_post_meta( $post_id, 'dpjp_employment_type', $t['employment_type'] );
            update_post_meta( $post_id, 'dpjp_requirements',    $t['requirements'] );
            update_post_meta( $post_id, 'dpjp_call_to_action',  'Call or text us today. We respond fast.' );
            update_post_meta( $post_id, 'dpjp_valid_through',   gmdate( 'Y-m-d', strtotime( '+30 days' ) ) );

            $created++;
        }

        wp_safe_redirect( add_query_arg(
            [
                'post_type' => 'dpjp_job',
                'page'      => 'dpjp-templates',
                'seeded'    => $created,
                'skipped'   => $skipped,
            ],
            admin_url( 'edit.php' )
        ) );
        exit;
    }

    /**
     * One-click "publish everything + build the Careers page" flow.
     *
     * Steps:
     *   1. Loop every template. If a job with the template's title already
     *      exists in any status, transition it to publish (so a re-click of
     *      the button promotes existing drafts). If it doesn't exist,
     *      create + publish it with the full template payload.
     *   2. Find or create a top-level page titled "Careers" whose content
     *      is the [dpjp_jobs] shortcode. The shortcode auto-renders all
     *      published jobs as a 2-column grid with Apply buttons, and each
     *      job card already links to the canonical /jobs/{slug}/ permalink.
     *   3. Redirect back to the templates screen with success counts so
     *      the operator gets a single "view careers page / view jobs"
     *      confirmation instead of hunting through Pages and Job Listings.
     *
     * Idempotent: safe to re-click after editing a template — only adds
     * what's new and promotes whatever was sitting in drafts.
     */
    public static function handle_publish_all(): void {
        if ( ! current_user_can( 'publish_posts' ) ) {
            wp_die( 'No.' );
        }
        check_admin_referer( 'dpjp_publish_all' );

        $tpls      = self::templates();
        $published = 0;
        $already   = 0;

        foreach ( $tpls as $slug => $t ) {
            // Every published job carries the application form inline at the
            // bottom of its post_content (no the_content filter dependency,
            // so it survives themes that bypass filters for CPTs). The whole
            // body is wrapped in a brand themed hero + body + phone CTA
            // block above the form so the page matches the look of the
            // About / homepage hero.
            $body = self::build_branded_job_body( $t );

            $existing = get_page_by_title( $t['title'], OBJECT, 'dpjp_job' );

            if ( $existing instanceof WP_Post ) {
                // Force the branded body on every re-click so a previously
                // published job from a pre-1.6.3 release picks up the new
                // theme on the next publish-all. Operator edits to the
                // template's description string still flow through; manual
                // edits inside the WP post editor will be overwritten by
                // design (this button is the source of truth).
                $update = [
                    'ID'           => $existing->ID,
                    'post_status'  => 'publish',
                    'post_content' => $body,
                ];
                $was_publish      = 'publish' === $existing->post_status;
                $was_same_content = trim( (string) $existing->post_content ) === trim( $body );
                if ( $was_publish && $was_same_content ) {
                    $already++;
                } else {
                    wp_update_post( $update );
                    $published++;
                }
                $post_id = $existing->ID;
            } else {
                $post_id = wp_insert_post( [
                    'post_type'    => 'dpjp_job',
                    'post_status'  => 'publish',
                    'post_title'   => $t['title'],
                    'post_content' => $body,
                ], true );

                if ( is_wp_error( $post_id ) || ! $post_id ) {
                    continue;
                }
                $published++;
            }

            update_post_meta( $post_id, 'dpjp_trade',           $t['trade'] );
            update_post_meta( $post_id, 'dpjp_location',        $t['location'] );
            update_post_meta( $post_id, 'dpjp_pay',             $t['pay'] );
            update_post_meta( $post_id, 'dpjp_employment_type', $t['employment_type'] );
            update_post_meta( $post_id, 'dpjp_requirements',    $t['requirements'] );
            update_post_meta( $post_id, 'dpjp_call_to_action',  'Call or text us today. We respond fast.' );
            update_post_meta( $post_id, 'dpjp_valid_through',   gmdate( 'Y-m-d', strtotime( '+30 days' ) ) );

            // Persist the matching Elementor tree so the page renders
            // through Elementor (theme fonts, CSS vars, container styles)
            // instead of through the_content() raw HTML fallback.
            self::save_elementor( $post_id, self::build_elementor_job( $t ), 'wp-post' );
        }

        $apply_id   = self::ensure_apply_page();
        $careers_id = self::ensure_careers_page();

        wp_safe_redirect( add_query_arg(
            [
                'post_type'     => 'dpjp_job',
                'page'          => 'dpjp-templates',
                'published_all' => $published,
                'already'       => $already,
                'careers_id'    => $careers_id,
                'apply_id'      => $apply_id,
            ],
            admin_url( 'edit.php' )
        ) );
        exit;
    }

    /**
     * Find an existing "Careers" page or create one. Always ensures the
     * page content contains the [dpjp_jobs] shortcode so the operator's
     * Careers page is never silently empty even if it was created without
     * the shortcode in a prior version.
     *
     * @return int Page ID.
     */
    private static function ensure_careers_page(): int {
        // Careers page is a directory only. It lists every open role via
        // [dpjp_jobs]; each card's Apply Now button routes the visitor to
        // that role's dedicated page where the [sfco_apply] form lives.
        // No application form on the directory itself — applicants must
        // pick a role first.
        //
        // Hero block matches the brand look from the About page (light
        // mint background, dark green title and copy, centered).
        $intro = '<div style="background:#F3FCF4;margin:0 0 32px;">'
               . '<div style="max-width:920px;margin:0 auto;padding:3em 1.5em 2em;text-align:center;">'
               . '<div style="color:#2F8137;font-size:13px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;margin:0 0 10px;">Now Hiring</div>'
               . '<h1 style="color:#0F1411;font-size:44px;font-weight:800;line-height:1.05;margin:0 0 18px;">Join the Midland Floors Team</h1>'
               . '<p style="color:#4B5563;font-size:17px;line-height:1.6;margin:0;"><strong>Commercial floor care jobs across DC, Maryland, and Northern Virginia.</strong> Predictable schedules, weekly pay, mostly recurring contracts. Browse open positions below, then click any role for full details and to apply.</p>'
               . '</div>'
               . '</div>' . "\n\n"
               . '[dpjp_jobs]';

        $existing = get_page_by_title( 'Careers', OBJECT, 'page' );

        if ( $existing instanceof WP_Post ) {
            // The publish-all button is the source of truth for the Careers
            // page — always rewrite content so brand updates (hero colors,
            // copy, shortcode order) propagate on every click. Operators
            // who want a custom Careers page should build it elsewhere and
            // rename this one out of the way.
            wp_update_post( [
                'ID'           => $existing->ID,
                'post_status'  => 'publish',
                'post_content' => $intro,
            ] );
            self::save_elementor( (int) $existing->ID, self::build_elementor_careers(), 'wp-page' );
            return (int) $existing->ID;
        }

        $page_id = wp_insert_post( [
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => 'Careers',
            'post_name'    => 'careers',
            'post_content' => $intro,
        ], true );

        if ( ! is_wp_error( $page_id ) && $page_id ) {
            self::save_elementor( (int) $page_id, self::build_elementor_careers(), 'wp-page' );
        }

        return is_wp_error( $page_id ) ? 0 : (int) $page_id;
    }

    /**
     * Find or create the single /apply/ page that hosts the Midland Smart
     * Forms job application shortcode. One form, one URL, every Apply Now
     * button on every job page routes through here with ?job=<slug> so the
     * position field auto fills.
     *
     * @return int Page ID.
     */
    /**
     * Return a fresh Elementor element ID (8 hex chars, matching what
     * Elementor itself emits). Each element in an Elementor tree needs a
     * unique ID per page.
     */
    private static function eid(): string {
        return substr( bin2hex( random_bytes( 4 ) ), 0, 8 );
    }

    /**
     * Build the Elementor element tree for a job page, matching the
     * About Midland hero template the operator exported on 2026-05-18.
     * Structure: mint hero (#F3FCF4) → eyebrow h6 → h1 title → 17/1.6
     * subtitle. Then a content section with the description + a CTA strip
     * + the [sfco_apply] shortcode widget.
     *
     * @param array $t Template entry.
     * @return array Elementor data tree (encode as JSON before storing).
     */
    private static function build_elementor_job( array $t ): array {
        $type_label = ( 'part-time' === ( $t['employment_type'] ?? '' ) ) ? 'Part Time'
            : ( ( 'contract' === ( $t['employment_type'] ?? '' ) ) ? 'Contract'
            : ( ( 'seasonal' === ( $t['employment_type'] ?? '' ) ) ? 'Seasonal' : 'Full Time' ) );

        $eyebrow  = strtoupper( (string) ( $t['trade'] ?? 'Now Hiring' ) );
        $title    = (string) ( $t['title'] ?? '' );
        $subtitle = sprintf(
            '<p>%s &middot; %s &middot; %s</p>',
            esc_html( (string) ( $t['location'] ?? '' ) ),
            esc_html( (string) ( $t['pay'] ?? '' ) ),
            esc_html( $type_label )
        );

        $description_html = wpautop( esc_html( (string) ( $t['description'] ?? '' ) ) )
            . '<h2><strong>What we need from you</strong></h2>'
            . wpautop( esc_html( (string) ( $t['requirements'] ?? '' ) ) );

        return array(
            self::el_hero( $eyebrow, $title, $subtitle ),
            self::el_body( $description_html ),
            self::el_cta_phone( 'Ready to apply?', 'Call or text us today', 'We respond fast, usually within one business day.', '(240) 532-9097', 'tel:2405329097' ),
            self::el_shortcode( '[sfco_apply]' ),
        );
    }

    /**
     * Careers page tree: hero + [dpjp_jobs] shortcode widget.
     */
    private static function build_elementor_careers(): array {
        $subtitle = '<p><strong>Commercial floor care jobs across DC, Maryland, and Northern Virginia.</strong> Predictable schedules, weekly pay, mostly recurring contracts. Browse open positions below, then click any role for full details and to apply.</p>';
        return array(
            self::el_hero( 'NOW HIRING', 'Join the Midland Floors Team', $subtitle ),
            self::el_shortcode( '[dpjp_jobs]' ),
        );
    }

    /**
     * Apply page tree: hero + [sfco_apply] shortcode widget.
     */
    private static function build_elementor_apply(): array {
        $subtitle = '<p>Fill out the form below. We respond fast, usually within one business day.</p>';
        return array(
            self::el_hero( 'APPLY', 'Apply to Join Midland Floors', $subtitle ),
            self::el_shortcode( '[sfco_apply]' ),
        );
    }

    /**
     * Hero section element matching the operator's About Midland export:
     * outer container bg #F3FCF4, inner container 920px boxed, 3em/1.5em/2em
     * padding, three widgets (h6 eyebrow #2F8137, h1 title #0F1411 at 44/800
     * with 1.05 line-height, text-editor body #4B5563 at 17/1.6).
     */
    private static function el_hero( string $eyebrow, string $title, string $subtitle_html ): array {
        return array(
            'id'        => self::eid(),
            'elType'    => 'container',
            'isInner'   => false,
            'settings'  => array(
                'flex_direction'        => 'column',
                'content_width'         => 'full',
                'flex_gap'              => array( 'column' => '20', 'row' => '20', 'isLinked' => true, 'unit' => 'px', 'size' => 20 ),
                'padding'               => array( 'unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => true ),
                'background_background' => 'classic',
                'background_color'      => '#F3FCF4',
                '_title'                => 'Hero',
            ),
            'elements'  => array(
                array(
                    'id'       => self::eid(),
                    'elType'   => 'container',
                    'isInner'  => true,
                    'settings' => array(
                        'flex_direction'    => 'column',
                        'flex_gap'          => array( 'column' => '20', 'row' => '20', 'isLinked' => true, 'unit' => 'px', 'size' => 20 ),
                        'boxed_width'       => array( 'unit' => 'px', 'size' => 920, 'sizes' => array() ),
                        'padding'           => array( 'unit' => 'em', 'top' => '3', 'right' => '1.5', 'bottom' => '2', 'left' => '1.5', 'isLinked' => false ),
                        'flex_align_items'  => 'center',
                    ),
                    'elements' => array(
                        array(
                            'id'         => self::eid(),
                            'elType'     => 'widget',
                            'isInner'    => false,
                            'widgetType' => 'heading',
                            'elements'   => array(),
                            'settings'   => array(
                                'title'                  => $eyebrow,
                                'header_size'            => 'h6',
                                'align'                  => 'center',
                                'title_color'            => '#2F8137',
                                'typography_typography'  => 'custom',
                                'typography_font_size'   => array( 'unit' => 'px', 'size' => 13, 'sizes' => array() ),
                                'typography_font_weight' => '800',
                                '_margin'                => array( 'unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '10', 'left' => '0', 'isLinked' => false ),
                            ),
                        ),
                        array(
                            'id'         => self::eid(),
                            'elType'     => 'widget',
                            'isInner'    => false,
                            'widgetType' => 'heading',
                            'elements'   => array(),
                            'settings'   => array(
                                'title'                   => $title,
                                'header_size'             => 'h1',
                                'align'                   => 'center',
                                'title_color'             => '#0F1411',
                                'typography_typography'   => 'custom',
                                'typography_font_size'    => array( 'unit' => 'px', 'size' => 44, 'sizes' => array() ),
                                'typography_font_weight'  => '800',
                                'typography_line_height'  => array( 'unit' => 'em', 'size' => 1.05, 'sizes' => array() ),
                            ),
                        ),
                        array(
                            'id'         => self::eid(),
                            'elType'     => 'widget',
                            'isInner'    => false,
                            'widgetType' => 'text-editor',
                            'elements'   => array(),
                            'settings'   => array(
                                'editor'                 => $subtitle_html,
                                'align'                  => 'center',
                                'text_color'             => '#4B5563',
                                'typography_typography'  => 'custom',
                                'typography_font_size'   => array( 'unit' => 'px', 'size' => 17, 'sizes' => array() ),
                                'typography_line_height' => array( 'unit' => 'em', 'size' => 1.6, 'sizes' => array() ),
                            ),
                        ),
                    ),
                ),
            ),
        );
    }

    /**
     * Body section: a flex-column container with a spacer + text-editor
     * holding the page's long-form HTML content.
     */
    private static function el_body( string $html ): array {
        return array(
            'id'       => self::eid(),
            'elType'   => 'container',
            'isInner'  => false,
            'settings' => array(
                'flex_direction'       => 'column',
                'flex_justify_content' => 'center',
            ),
            'elements' => array(
                array(
                    'id'         => self::eid(),
                    'elType'     => 'widget',
                    'isInner'    => false,
                    'widgetType' => 'spacer',
                    'elements'   => array(),
                    'settings'   => array(),
                ),
                array(
                    'id'         => self::eid(),
                    'elType'     => 'widget',
                    'isInner'    => false,
                    'widgetType' => 'text-editor',
                    'elements'   => array(),
                    'settings'   => array(
                        'editor' => $html,
                    ),
                ),
            ),
        );
    }

    /**
     * Dark CTA strip with the phone number. Mirrors the About page's
     * "Ready for floors that sell for you?" block in tone.
     */
    private static function el_cta_phone( string $eyebrow, string $headline, string $sub, string $phone_label, string $tel_url ): array {
        $editor = '<p style="text-align:center;color:#cfe4d6;font-size:16px;margin:0;">' . esc_html( $sub ) . '</p>'
                . '<p style="text-align:center;margin:14px 0 0;"><a href="' . esc_url( $tel_url ) . '" style="color:#fff;font-size:26px;font-weight:800;text-decoration:none;border-bottom:2px solid #7CCE8E;padding-bottom:2px;">' . esc_html( $phone_label ) . '</a></p>';

        return array(
            'id'       => self::eid(),
            'elType'   => 'container',
            'isInner'  => false,
            'settings' => array(
                'flex_direction'        => 'column',
                'content_width'         => 'full',
                'background_background' => 'classic',
                'background_color'      => '#0E2F14',
                'padding'               => array( 'unit' => 'em', 'top' => '2.5', 'right' => '1.5', 'bottom' => '2.5', 'left' => '1.5', 'isLinked' => false ),
                '_title'                => 'CTA - Phone',
            ),
            'elements' => array(
                array(
                    'id'       => self::eid(),
                    'elType'   => 'container',
                    'isInner'  => true,
                    'settings' => array(
                        'flex_direction'    => 'column',
                        'boxed_width'       => array( 'unit' => 'px', 'size' => 920, 'sizes' => array() ),
                        'flex_align_items'  => 'center',
                    ),
                    'elements' => array(
                        array(
                            'id'         => self::eid(),
                            'elType'     => 'widget',
                            'isInner'    => false,
                            'widgetType' => 'heading',
                            'elements'   => array(),
                            'settings'   => array(
                                'title'                  => $eyebrow,
                                'header_size'            => 'h6',
                                'align'                  => 'center',
                                'title_color'            => '#7CCE8E',
                                'typography_typography'  => 'custom',
                                'typography_font_size'   => array( 'unit' => 'px', 'size' => 13, 'sizes' => array() ),
                                'typography_font_weight' => '800',
                                '_margin'                => array( 'unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '8', 'left' => '0', 'isLinked' => false ),
                            ),
                        ),
                        array(
                            'id'         => self::eid(),
                            'elType'     => 'widget',
                            'isInner'    => false,
                            'widgetType' => 'heading',
                            'elements'   => array(),
                            'settings'   => array(
                                'title'                  => $headline,
                                'header_size'            => 'h2',
                                'align'                  => 'center',
                                'title_color'            => '#FFFFFF',
                                'typography_typography'  => 'custom',
                                'typography_font_size'   => array( 'unit' => 'px', 'size' => 30, 'sizes' => array() ),
                                'typography_font_weight' => '800',
                            ),
                        ),
                        array(
                            'id'         => self::eid(),
                            'elType'     => 'widget',
                            'isInner'    => false,
                            'widgetType' => 'text-editor',
                            'elements'   => array(),
                            'settings'   => array(
                                'editor' => $editor,
                            ),
                        ),
                    ),
                ),
            ),
        );
    }

    /**
     * Generic shortcode widget. Used to drop [sfco_apply] and [dpjp_jobs]
     * inside the Elementor tree so the page renders entirely through
     * Elementor's widget system instead of mixing post_content fragments.
     */
    private static function el_shortcode( string $shortcode ): array {
        return array(
            'id'       => self::eid(),
            'elType'   => 'container',
            'isInner'  => false,
            'settings' => array(
                'flex_direction' => 'column',
                'boxed_width'    => array( 'unit' => 'px', 'size' => 920, 'sizes' => array() ),
                'padding'        => array( 'unit' => 'em', 'top' => '1', 'right' => '1.5', 'bottom' => '3', 'left' => '1.5', 'isLinked' => false ),
            ),
            'elements' => array(
                array(
                    'id'         => self::eid(),
                    'elType'     => 'widget',
                    'isInner'    => false,
                    'widgetType' => 'shortcode',
                    'elements'   => array(),
                    'settings'   => array(
                        'shortcode' => $shortcode,
                    ),
                ),
            ),
        );
    }

    /**
     * Persist an Elementor element tree to a post so the page renders
     * through Elementor (picking up the theme's font, container CSS vars,
     * widget styles) instead of through the_content().
     *
     * @param int    $post_id       Post or page ID.
     * @param array  $tree          Result of one of the build_elementor_* methods.
     * @param string $template_type 'wp-page' for pages, 'wp-post' for posts.
     */
    private static function save_elementor( int $post_id, array $tree, string $template_type = 'wp-page' ): void {
        $json = wp_json_encode( $tree );
        if ( false === $json ) {
            return;
        }
        // _elementor_data must be stored as slashed JSON; update_post_meta
        // unslashes on the way in so we double up here.
        update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );
        update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
        update_post_meta( $post_id, '_elementor_template_type', $template_type );
        update_post_meta( $post_id, '_elementor_version', defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.21.0' );
        update_post_meta( $post_id, '_elementor_pro_version', defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : '3.21.0' );
        // Clear Elementor's compiled CSS cache for this post so the next
        // page load regenerates with our new tree instead of serving the
        // pre-edit cached CSS.
        delete_post_meta( $post_id, '_elementor_css' );
    }

    /**
     * Build the full post_content for a single job. Matches the brand hero
     * spec from the About page (light mint #F3FCF4 background, small green
     * eyebrow #2F8137, big black title #0F1411 at 44px/800, gray body
     * #4B5563 at 17px/1.6, centered in a 920px column), then the regular
     * description body, then the application form shortcode.
     *
     * Used as a fallback for non-Elementor renders. When Elementor is
     * active, the matching Elementor tree above takes over.
     *
     * @param array $t Template entry from self::templates().
     * @return string Full HTML post_content.
     */
    private static function build_branded_job_body( array $t ): string {
        $type_label = ( 'part-time' === ( $t['employment_type'] ?? '' ) ) ? 'Part Time'
            : ( ( 'contract' === ( $t['employment_type'] ?? '' ) ) ? 'Contract'
            : ( ( 'seasonal' === ( $t['employment_type'] ?? '' ) ) ? 'Seasonal' : 'Full Time' ) );

        $eyebrow  = strtoupper( (string) ( $t['trade'] ?? 'Now Hiring' ) );
        $title    = (string) ( $t['title'] ?? '' );
        $subtitle = sprintf(
            '%s &middot; %s &middot; %s',
            esc_html( (string) ( $t['location'] ?? '' ) ),
            esc_html( (string) ( $t['pay'] ?? '' ) ),
            esc_html( $type_label )
        );

        $hero = '<div style="background:#F3FCF4;margin:0 0 32px;">'
              . '<div style="max-width:920px;margin:0 auto;padding:3em 1.5em 2em;text-align:center;">'
              . '<div style="color:#2F8137;font-size:13px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;margin:0 0 10px;">' . esc_html( $eyebrow ) . '</div>'
              . '<h1 style="color:#0F1411;font-size:44px;font-weight:800;line-height:1.05;margin:0 0 18px;">' . esc_html( $title ) . '</h1>'
              . '<p style="color:#4B5563;font-size:17px;line-height:1.6;margin:0;">' . $subtitle . '</p>'
              . '</div>'
              . '</div>';

        $description = '<div style="max-width:920px;margin:0 auto;padding:0 1.5em;font-size:16px;line-height:1.65;color:#1d2933;">'
                     . wpautop( esc_html( (string) ( $t['description'] ?? '' ) ) )
                     . '<h3 style="color:#0F1411;margin:28px 0 12px;font-size:22px;">What we need from you</h3>'
                     . wpautop( esc_html( (string) ( $t['requirements'] ?? '' ) ) )
                     . '</div>';

        $cta = '<div style="background:#0E2F14;color:#fff;margin:32px 0;padding:36px 1.5em;text-align:center;">'
             . '<div style="color:#7CCE8E;font-size:13px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;margin:0 0 8px;">Ready to apply?</div>'
             . '<h2 style="color:#fff;font-size:30px;font-weight:800;margin:0 0 8px;">Call or text us today</h2>'
             . '<p style="color:#cfe4d6;font-size:16px;margin:0 0 14px;">We respond fast, usually within one business day.</p>'
             . '<a href="tel:2405329097" style="color:#fff;font-size:26px;font-weight:800;text-decoration:none;border-bottom:2px solid #7CCE8E;padding-bottom:2px;">(240) 532-9097</a>'
             . '</div>';

        $form = '<div style="max-width:920px;margin:0 auto;padding:0 1.5em;">[sfco_apply]</div>';

        return $hero . "\n\n" . $description . "\n\n" . $cta . "\n\n" . $form;
    }

    private static function ensure_apply_page(): int {
        $intro = "<h2>Apply to Join Midland Floors</h2>\n"
               . "<p>Fill out the form below. We respond fast, usually within one business day.</p>\n"
               . '[sfco_apply]';

        $existing = get_page_by_title( 'Apply', OBJECT, 'page' );

        if ( $existing instanceof WP_Post ) {
            wp_update_post( [
                'ID'           => $existing->ID,
                'post_status'  => 'publish',
                'post_content' => $intro,
            ] );
            self::save_elementor( (int) $existing->ID, self::build_elementor_apply(), 'wp-page' );
            return (int) $existing->ID;
        }

        $page_id = wp_insert_post( [
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => 'Apply',
            'post_name'    => 'apply',
            'post_content' => $intro,
        ], true );

        if ( ! is_wp_error( $page_id ) && $page_id ) {
            self::save_elementor( (int) $page_id, self::build_elementor_apply(), 'wp-page' );
        }

        return is_wp_error( $page_id ) ? 0 : (int) $page_id;
    }

    public static function handle_create(): void {
        if ( ! current_user_can( 'edit_posts' ) ) wp_die( 'No.' );
        check_admin_referer( 'dpjp_use_template' );

        $slug = isset( $_POST['template'] ) ? sanitize_key( wp_unslash( $_POST['template'] ) ) : '';
        $tpls = self::templates();
        if ( ! isset( $tpls[ $slug ] ) ) wp_die( 'Unknown template.' );

        $t = $tpls[ $slug ];

        $post_id = wp_insert_post( [
            'post_type'    => 'dpjp_job',
            'post_status'  => 'draft',
            'post_title'   => $t['title'],
            'post_content' => $t['description'],
        ], true );

        if ( is_wp_error( $post_id ) ) {
            wp_die( esc_html( $post_id->get_error_message() ) );
        }

        update_post_meta( $post_id, 'dpjp_trade',           $t['trade'] );
        update_post_meta( $post_id, 'dpjp_location',        $t['location'] );
        update_post_meta( $post_id, 'dpjp_pay',             $t['pay'] );
        update_post_meta( $post_id, 'dpjp_employment_type', $t['employment_type'] );
        update_post_meta( $post_id, 'dpjp_requirements',    $t['requirements'] );
        update_post_meta( $post_id, 'dpjp_call_to_action',  'Call or text us today. We respond fast.' );
        update_post_meta( $post_id, 'dpjp_valid_through',   gmdate( 'Y-m-d', strtotime( '+30 days' ) ) );

        wp_safe_redirect( add_query_arg(
            [ 'post_type' => 'dpjp_job', 'page' => 'dpjp-templates', 'template_created' => $post_id ],
            admin_url( 'edit.php' )
        ) );
        exit;
    }
}
