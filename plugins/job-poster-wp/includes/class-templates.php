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
    }

    public static function menu(): void {
        add_submenu_page(
            'edit.php?post_type=dpjp_job',
            __( 'Job Templates', 'job-manager-pro' ),
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
        $cta           = 'Call or text us today — we respond fast.';
        $location      = 'Washington DC Metro Area';

        return [
            'residential-carpet-tech' => [
                'title' => 'Residential Carpet Cleaning Technician',
                'trade' => 'Residential Carpet Cleaning',
                'location' => $location,
                'pay' => '$20–$28/hr + tips',
                'employment_type' => 'full-time',
                'description' => "We're hiring a Residential Carpet Cleaning Technician to run in-home carpet jobs across DC, MD, and VA. Steady book of repeat homeowners and word-of-mouth referrals.\n\nWhat you'll do:\n- Operate truck-mounted hot water extraction units\n- Pre-treat, agitate, and rinse carpets\n- Spot treatment and stain removal (pet, red wine, grease)\n- Walk homeowners through what was cleaned and after-care\n- Maintain equipment and report issues\n\nWe pay weekly, provide the truck, equipment, and chemicals, and you get tips on top.",
                'requirements' => "Valid driver's license + clean driving record\n1+ year carpet cleaning experience preferred (we'll train the right person)\nClean appearance, customer-facing demeanor\nAble to lift 50 lbs\nWeekend availability a plus",
            ],

            'commercial-carpet-tech' => [
                'title' => 'Commercial Carpet Care Technician',
                'trade' => 'Commercial Carpet Care',
                'location' => $location,
                'pay' => '$22–$30/hr',
                'employment_type' => 'full-time',
                'description' => "Commercial Carpet Care Tech wanted for our office, medical, and retail accounts in DC/MD/VA. Mostly after-hours, mostly recurring contracts. Predictable schedule, consistent paychecks, no homeowner drama.\n\nWhat you'll do:\n- Hot water extraction + low-moisture encapsulation on commercial carpet\n- Spot + stain treatment on heavy traffic lanes\n- Carpet bonneting and interim maintenance\n- Operate truck-mount and portable extractors\n- Document before/after for property managers\n\nWe service offices, medical buildings, schools, and retail — recurring contracts mean recurring pay.",
                'requirements' => "1+ year commercial carpet experience preferred\nFamiliar with extraction + encapsulation methods\nReliable transportation + clean driving record\nAvailable evenings/weekends\nMust pass background check (medical + government accounts require it)",
            ],

            'floor-stripping-tech' => [
                'title' => 'Floor Stripping & Wax Technician',
                'trade' => 'Floor Stripping & Waxing',
                'location' => $location,
                'pay' => '$22–$30/hr',
                'employment_type' => 'full-time',
                'description' => "Looking for an experienced Floor Stripping & Wax Tech for commercial accounts (offices, schools, healthcare, retail). Mostly after-hours work, paid weekly.\n\nWhat you'll do:\n- Strip and re-coat VCT, vinyl, and rubber floors\n- Apply 4–6 coats of finish; buff between coats\n- Operate auto-scrubbers, swing buffers, and burnishers\n- Handle furniture moves and floor protection\n- Document jobs with before/after photos\n\nCommercial accounts means consistent hours and no homeowner drama.",
                'requirements' => "2+ years floor stripping experience\nFamiliar with auto-scrubbers and burnishers\nReliable transportation\nAvailable evenings/weekends\nMust pass background check (some accounts require it)",
            ],

            'tile-grout-tech' => [
                'title' => 'Tile & Grout Cleaning Specialist',
                'trade' => 'Tile & Grout Cleaning',
                'location' => $location,
                'pay' => '$22–$30/hr',
                'employment_type' => 'full-time',
                'description' => "Hiring a Tile & Grout Cleaning Specialist for commercial restroom + lobby restoration and residential bathroom + kitchen jobs.\n\nWhat you'll do:\n- High-pressure tile and grout cleaning with truck-mount units\n- Grout color sealing and repair\n- Stone polishing (limited)\n- Residential bathroom and kitchen jobs\n- Commercial restroom and lobby restoration\n\nGreat fit if you've done carpet cleaning and want to expand into harder surfaces — same trucks, same crews, more billable hours.",
                'requirements' => "1+ year tile/grout cleaning experience\nFamiliarity with high-pressure rotary tools\nClean appearance, professional demeanor\nValid driver's license",
            ],

            'commercial-floor-crew' => [
                'title' => 'Commercial Floor Care Crew Member (Nights/Weekends)',
                'trade' => 'Commercial Floor Care',
                'location' => $location,
                'pay' => '$18–$22/hr',
                'employment_type' => 'part-time',
                'description' => "We're growing our commercial floor care crew. Night and weekend shifts servicing offices, medical facilities, and retail spaces in DC/MD/VA.\n\nWhat you'll do:\n- Operate floor scrubbers, burnishers, and carpet extractors\n- Strip + wax hard floors, extract commercial carpet\n- Empty trash, vacuum, dust, high-touch sanitize\n- Lock up and arm alarms at end of shift\n\nReliable hours, weekly pay, and overtime when available. No experience needed — we train.",
                'requirements' => "Reliable transportation\nAble to pass a background check\nAvailable evenings + weekends\nNo experience necessary — we train\nMust be 18+",
            ],

            'concrete-polishing-tech' => [
                'title' => 'Concrete Polishing & Coating Technician',
                'trade' => 'Concrete Polishing',
                'location' => $location,
                'pay' => '$25–$38/hr',
                'employment_type' => 'full-time',
                'description' => "Concrete Polishing Tech wanted for commercial and industrial projects — warehouses, retail buildouts, and showrooms.\n\nWhat you'll do:\n- Multi-step diamond grinding and polishing\n- Densifier and stain guard application\n- Epoxy coating prep and install\n- Operate planetary grinders, edgers, and dust extraction\n\nLearn a high-skill trade. We invest in training.",
                'requirements' => "Experience with concrete grinding or polishing\nFamiliar with diamond tooling\nOSHA 10 a plus\nValid driver's license\nWilling to travel within the DMV",
            ],

            'water-damage-restoration' => [
                'title' => 'Water Damage Restoration Technician (IICRC)',
                'trade' => 'Water Damage Restoration',
                'location' => $location,
                'pay' => '$22–$32/hr + on-call premium',
                'employment_type' => 'full-time',
                'description' => "24/7 emergency response — Water Damage Restoration Technician needed. IICRC certification preferred (or we'll get you certified).\n\nWhat you'll do:\n- Extract water from carpet, hardwood, and concrete\n- Set up air movers and dehumidifiers\n- Moisture mapping with thermal imaging\n- Document daily readings for insurance\n- Communicate with adjusters and homeowners under stress\n\nOn-call rotation pays a premium. Tons of overtime available.",
                'requirements' => "IICRC WRT certification (or willing to obtain — we pay)\nClean driving record\nMust pass a drug screen\nAbility to work nights and weekends on rotation\nGood under pressure",
            ],

            'sales-estimator' => [
                'title' => 'Floor Care Sales / Estimator',
                'trade' => 'Sales / Estimating',
                'location' => $location,
                'pay' => 'Base $50K + commission (OTE $85K–$120K)',
                'employment_type' => 'full-time',
                'description' => "Hiring a Floor Care Sales / Estimator. Walk commercial and high-end residential prospects, measure square footage, write proposals, close the deal.\n\nWhat you'll do:\n- Run scheduled estimate appointments\n- Quote carpet, hardwood, tile, and floor-care contracts\n- Build relationships with property managers and facility ops\n- Hand off won jobs to operations\n- Hit monthly revenue targets\n\nWarm leads provided. You're not cold-calling — you're closing.",
                'requirements' => "2+ years field sales (B2B or B2C)\nComfortable with on-site measuring + writing quotes\nCRM experience (we use ServiceM8)\nClean driving record\nProfessional appearance",
            ],

            'office-dispatcher' => [
                'title' => 'Customer Service / Dispatcher',
                'trade' => 'Office / Dispatch',
                'location' => $location,
                'pay' => '$20–$26/hr',
                'employment_type' => 'full-time',
                'description' => "Customer Service / Dispatcher role — answer inbound calls, schedule jobs, route crews. The voice of the company.\n\nWhat you'll do:\n- Answer inbound phone and chat leads\n- Schedule and dispatch crews via ServiceM8\n- Confirm appointments and route changes\n- Handle reschedule and cancellation requests\n- Pre-quote simple residential jobs over the phone\n\nIn-office role. M-F, daytime hours.",
                'requirements' => "1+ year customer service or dispatch experience\nComfortable with scheduling software\nClear phone voice, calm under pressure\nBasic computer literacy\nLocal to DC/MD/VA",
            ],
        ];
    }

    public static function render(): void {
        $templates = self::templates();
        $action    = admin_url( 'admin-post.php' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Floor-Care Job Templates', 'job-manager-pro' ); ?></h1>
            <p><?php esc_html_e( 'Click "Use this template" to create a draft job post pre-filled with title, description, pay, and requirements. Edit before publishing.', 'job-manager-pro' ); ?></p>

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
        update_post_meta( $post_id, 'dpjp_call_to_action',  'Call or text us today — we respond fast.' );
        update_post_meta( $post_id, 'dpjp_valid_through',   gmdate( 'Y-m-d', strtotime( '+30 days' ) ) );

        wp_safe_redirect( add_query_arg(
            [ 'post_type' => 'dpjp_job', 'page' => 'dpjp-templates', 'template_created' => $post_id ],
            admin_url( 'edit.php' )
        ) );
        exit;
    }
}
