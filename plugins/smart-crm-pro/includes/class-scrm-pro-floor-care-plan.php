<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Floor Care Plan generator. Commercial-only.
 *
 * When a COMMERCIAL job completes, render a personalized maintenance plan from
 * the booking metadata (floor type, square footage, frequency), publish it as
 * a private (noindex) CPT page, and stash the URL on the lead so the AC bridge
 * forwards it to ActiveCampaign as the floor_care_plan_url field. AC's flow
 * can then link directly to the plan in the post-job email.
 *
 * Two templates ship: a standard commercial one and an "emergency" variant
 * used when the lead is both commercial and emergency — the latter leans on
 * the prevention pitch (the call-out cost more than a plan visit would have).
 *
 * Settings: Smart CRM Pro > Floor Care Plan
 */
class SCRM_Pro_Floor_Care_Plan {

    const CPT                   = 'mfc_floor_care_plan';
    const META_LEAD_ID          = '_lead_id';
    const META_PLAN_TIER        = '_plan_tier';
    const META_PRICE            = '_monthly_price';
    const OPT_TIERS             = 'scrm_pro_fcp_tiers';
    const OPT_TEMPLATE          = 'scrm_pro_fcp_template';
    const OPT_TEMPLATE_EMERG    = 'scrm_pro_fcp_template_emergency';
    const OPT_ENABLED           = 'scrm_pro_fcp_enabled';
    const OPT_LAST_RUN          = 'scrm_pro_fcp_last_run';

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init',                      array( $this, 'register_cpt' ) );

        // Run BEFORE the AC bridge (default priority 10) so $lead->floor_care_plan_url
        // is set when AC reads its fieldValues.
        add_action( 'sfco_lead_completed',       array( $this, 'maybe_generate_plan' ), 5 );
        add_action( 'scrm_pro_job_completed',    array( $this, 'maybe_generate_plan' ), 5 );
        add_action( 'sfco_lead_status_changed',  array( $this, 'on_status_changed' ), 5, 3 );

        add_action( 'admin_menu',                array( $this, 'add_menu' ), 23 );
        add_action( 'admin_init',                array( $this, 'handle_save' ) );

        // Plans are personalized — never let search engines index them.
        add_filter( 'wp_robots',                 array( $this, 'noindex_plan' ) );
    }

    public function register_cpt() {
        register_post_type( self::CPT, array(
            'labels' => array(
                'name'          => __( 'Floor Care Plans', 'smart-crm-pro' ),
                'singular_name' => __( 'Floor Care Plan', 'smart-crm-pro' ),
            ),
            'public'              => true,
            'publicly_queryable'  => true,
            'show_ui'             => true,
            'show_in_menu'        => false,
            'show_in_rest'        => false,
            'has_archive'         => false,
            'rewrite'             => array( 'slug' => 'floor-care-plan', 'with_front' => false ),
            'supports'            => array( 'title', 'editor' ),
            'capability_type'     => 'post',
            'menu_position'       => 60,
        ) );
    }

    public function noindex_plan( $robots ) {
        if ( is_singular( self::CPT ) ) {
            $robots['noindex']  = true;
            $robots['nofollow'] = true;
        }
        return $robots;
    }

    public function on_status_changed( $lead, $old_status, $new_status ) {
        if ( 'completed' !== strtolower( (string) $new_status ) ) {
            return;
        }
        $this->maybe_generate_plan( $lead );
    }

    public function maybe_generate_plan( $lead ) {
        if ( ! get_option( self::OPT_ENABLED, 1 ) ) {
            return;
        }

        // Floor Care Plan is COMMERCIAL only — residential jobs get the review
        // request flow instead. Emergency commercial gets the plan with extra
        // weight (the AC bridge applies an additional emergency tag).
        $segment      = 'residential';
        $is_emergency = false;
        if ( class_exists( 'SCRM_Pro_ActiveCampaign' ) ) {
            $ac           = SCRM_Pro_ActiveCampaign::get_instance();
            $segment      = $ac->lead_segment( $lead );
            $is_emergency = $ac->is_emergency( $lead );
        } else {
            // Standalone fallback when the AC bridge isn't loaded.
            $project = strtolower( (string) $this->get_field( $lead, array( 'project_type', 'service_type', 'segment', 'category' ) ) );
            if ( false !== strpos( $project, 'commercial' ) || false !== strpos( $project, 'business' ) || false !== strpos( $project, 'office' ) ) {
                $segment = 'commercial';
            }
        }

        if ( 'commercial' !== $segment ) {
            return;
        }

        $email = sanitize_email( $this->get_field( $lead, array( 'customer_email', 'email' ) ) );
        if ( ! is_email( $email ) ) {
            return;
        }
        $name = (string) $this->get_field( $lead, array( 'customer_name', 'name' ) );
        $sqft = (int) $this->get_field( $lead, array( 'square_footage', 'sqft', 'square_feet' ) );

        $tier    = $this->pick_tier( $sqft );
        $content = $this->render_plan( $lead, $tier, $is_emergency );

        $post_id = wp_insert_post( array(
            'post_type'    => self::CPT,
            'post_status'  => 'publish',
            'post_title'   => sprintf( __( 'Floor Care Plan for %s', 'smart-crm-pro' ), $name ?: $email ),
            'post_content' => $content,
        ), true );

        if ( is_wp_error( $post_id ) || ! $post_id ) {
            return;
        }

        $lead_id = (int) $this->get_field( $lead, array( 'id' ) );
        if ( $lead_id ) {
            update_post_meta( $post_id, self::META_LEAD_ID, $lead_id );
        }
        update_post_meta( $post_id, self::META_PLAN_TIER, $tier['name'] );
        update_post_meta( $post_id, self::META_PRICE, (int) $tier['monthly_price'] );
        update_post_meta( $post_id, '_segment', $segment );
        update_post_meta( $post_id, '_is_emergency', $is_emergency ? 1 : 0 );

        $url = get_permalink( $post_id );

        // Decorate the lead so the AC bridge picks up the URL as a fieldValue.
        // Objects in PHP are passed by handle through do_action, so this mutation
        // is visible to later listeners on the same hook tick.
        if ( is_object( $lead ) ) {
            $lead->floor_care_plan_url = $url;
        }

        // Persist on wp_sfco_leads if the column is there — survives the request
        // and lets the operator pull the URL later from the leads table.
        if ( $lead_id ) {
            global $wpdb;
            $table = $wpdb->prefix . 'sfco_leads';
            $has_column = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                "SHOW COLUMNS FROM {$table} LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                'floor_care_plan_url'
            ) );
            if ( $has_column ) {
                $wpdb->update( $table, array( 'floor_care_plan_url' => $url ), array( 'id' => $lead_id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            }
        }

        update_option( self::OPT_LAST_RUN, array(
            'at'      => time(),
            'post_id' => (int) $post_id,
            'tier'    => $tier['name'],
            'sqft'    => $sqft,
            'email'   => $email,
            'url'     => $url,
        ) );

        do_action( 'scrm_pro_floor_care_plan_generated', $post_id, $lead, $tier );
    }

    private function pick_tier( $sqft ) {
        $tiers = (array) get_option( self::OPT_TIERS, $this->default_tiers() );
        if ( empty( $tiers ) ) {
            $tiers = $this->default_tiers();
        }
        foreach ( $tiers as $tier ) {
            $min = (int) ( $tier['min_sqft'] ?? 0 );
            $max = (int) ( $tier['max_sqft'] ?? PHP_INT_MAX );
            if ( $sqft >= $min && $sqft <= $max ) {
                return $tier;
            }
        }
        return $tiers[0];
    }

    private function default_tiers() {
        // Commercial cadence tiers by site size. No price — quotes are handled
        // off-page. monthly_price is left in the schema as 0 in case the
        // operator wants to bring it back in a custom template later.
        return array(
            array( 'name' => 'Site Care',   'min_sqft' => 0,     'max_sqft' => 4999,  'monthly_price' => 0 ),
            array( 'name' => 'Site Care+',  'min_sqft' => 5000,  'max_sqft' => 14999, 'monthly_price' => 0 ),
            array( 'name' => 'Facility',    'min_sqft' => 15000, 'max_sqft' => 39999, 'monthly_price' => 0 ),
            array( 'name' => 'Enterprise',  'min_sqft' => 40000, 'max_sqft' => 999999,'monthly_price' => 0 ),
        );
    }

    private function default_template() {
        // Standard commercial post-job plan. Calmer pitch — "we kept things
        // running, here's how to keep them that way". No price on the page;
        // pricing is sent separately by the account manager.
        return "<div style=\"max-width:680px;margin:0 auto;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1f2937;line-height:1.55;\">\n"
             . "<p style=\"color:#6b7280;font-size:13px;margin:0 0 6px;\">Floor Care Plan prepared for</p>\n"
             . "<h1 style=\"margin:0 0 4px;font-size:28px;\">{customer_name}</h1>\n"
             . "<p style=\"color:#6b7280;margin:0 0 28px;\">{business_name} &middot; site footprint ~{sqft} sq ft &middot; {floor_type}</p>\n\n"
             . "<p>Thanks for trusting us with your recent job. Now that the floors are back in shape, here's the plan we'd recommend so they stay that way and so the next surprise doesn't catch you off-guard.</p>\n\n"
             . "<div style=\"border:1px solid #e5e7eb;border-radius:10px;padding:22px;margin:24px 0;background:#f9fafb;\">\n"
             . "<p style=\"text-transform:uppercase;letter-spacing:0.08em;color:#6b7280;font-size:12px;margin:0 0 6px;\">Recommended plan</p>\n"
             . "<h2 style=\"margin:0 0 6px;font-size:24px;\">{plan_tier}</h2>\n"
             . "<p style=\"color:#6b7280;font-size:14px;margin:0;\">Visits: every {frequency} &middot; Month-to-month, cancel any time.</p>\n"
             . "</div>\n\n"
             . "<h3 style=\"margin:0 0 10px;font-size:18px;\">What you get</h3>\n"
             . "<ul style=\"margin:0 0 24px;padding-left:20px;\">\n"
             . "<li style=\"margin-bottom:6px;\">Scheduled deep-clean visits sized to your traffic and floor type</li>\n"
             . "<li style=\"margin-bottom:6px;\">Annual sealant / protective coating refresh included</li>\n"
             . "<li style=\"margin-bottom:6px;\">Priority response &mdash; under 4 hours for plan members</li>\n"
             . "<li style=\"margin-bottom:6px;\">Discount on any work outside the plan (spills, post-event, build-outs)</li>\n"
             . "<li style=\"margin-bottom:6px;\">A dedicated point of contact and a tracked maintenance log per site</li>\n"
             . "</ul>\n\n"
             . "<h3 style=\"margin:0 0 10px;font-size:18px;\">Why this tier</h3>\n"
             . "<p style=\"margin:0 0 24px;\">At ~{sqft} sq ft of {floor_type}, this is the cadence that keeps the surface from degrading between visits without overspending on cleaning you don't need.</p>\n\n"
             . "<p><strong>Ready to start?</strong> Reply to the email this came in, or call us &mdash; we'll send a quote tailored to your site and have your first scheduled visit on the calendar inside a business day.</p>\n\n"
             . "<p style=\"color:#9ca3af;font-size:12px;margin-top:32px;border-top:1px solid #e5e7eb;padding-top:14px;\">Plan terms reviewed annually. {business_name}.</p>\n"
             . "</div>";
    }

    private function default_template_emergency() {
        // Commercial-emergency variant. The customer just had a costly call-out;
        // the pitch is "you don't want this to happen again — here's the
        // protection plan". Stronger urgency, leads with the prevention angle.
        // No price on the page; quote is sent separately.
        return "<div style=\"max-width:680px;margin:0 auto;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1f2937;line-height:1.55;\">\n"
             . "<p style=\"color:#b91c1c;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;margin:0 0 6px;\">Post-emergency plan</p>\n"
             . "<h1 style=\"margin:0 0 4px;font-size:28px;\">{customer_name}, let's not do that again.</h1>\n"
             . "<p style=\"color:#6b7280;margin:0 0 28px;\">{business_name} &middot; site footprint ~{sqft} sq ft &middot; {floor_type}</p>\n\n"
             . "<p>We got the floor back in shape, but the call-out cost more than it would have if we'd been on a regular schedule. Most emergencies we respond to are predictable &mdash; missed sealant cycles, drainage build-up, traffic-pattern wear that goes unnoticed until it fails.</p>\n\n"
             . "<p>Here's the plan we'd put your site on so the next one doesn't happen.</p>\n\n"
             . "<div style=\"border:2px solid #b91c1c;border-radius:10px;padding:22px;margin:24px 0;background:#fef2f2;\">\n"
             . "<p style=\"text-transform:uppercase;letter-spacing:0.08em;color:#b91c1c;font-size:12px;margin:0 0 6px;\">Recommended &middot; Priority enrollment</p>\n"
             . "<h2 style=\"margin:0 0 6px;font-size:24px;\">{plan_tier}</h2>\n"
             . "<p style=\"color:#6b7280;font-size:14px;margin:0;\">Visits: every {frequency} &middot; Same-day emergency window included &middot; Month-to-month.</p>\n"
             . "</div>\n\n"
             . "<h3 style=\"margin:0 0 10px;font-size:18px;\">What changes when you're on the plan</h3>\n"
             . "<ul style=\"margin:0 0 24px;padding-left:20px;\">\n"
             . "<li style=\"margin-bottom:6px;\"><strong>2-hour emergency response window</strong> &mdash; not the standard 4 hours</li>\n"
             . "<li style=\"margin-bottom:6px;\">Quarterly inspection log so we catch failures before they become call-outs</li>\n"
             . "<li style=\"margin-bottom:6px;\">Sealant + protective coating refresh on the schedule it actually needs</li>\n"
             . "<li style=\"margin-bottom:6px;\">Better discount on any out-of-plan work than non-plan customers get</li>\n"
             . "<li style=\"margin-bottom:6px;\">Direct line to the technician who knows your site</li>\n"
             . "</ul>\n\n"
             . "<h3 style=\"margin:0 0 10px;font-size:18px;\">The case for it</h3>\n"
             . "<p style=\"margin:0 0 24px;\">A single emergency call-out on a site your size typically runs several times what a routine plan visit would. One avoided emergency a year usually pays for the plan multiple times over &mdash; and we'll show you the math against your actual call-out invoice when we send the quote.</p>\n\n"
             . "<p><strong>Lock in priority enrollment this week.</strong> Reply to the email this came in or call us &mdash; we'll send your tailored quote and have your first scheduled inspection on the calendar inside a business day. Your priority response window starts the moment you sign up.</p>\n\n"
             . "<p style=\"color:#9ca3af;font-size:12px;margin-top:32px;border-top:1px solid #e5e7eb;padding-top:14px;\">Plan terms reviewed annually. {business_name}.</p>\n"
             . "</div>";
    }

    private function render_plan( $lead, $tier, $is_emergency = false ) {
        $option_key = $is_emergency ? self::OPT_TEMPLATE_EMERG : self::OPT_TEMPLATE;
        $default    = $is_emergency ? $this->default_template_emergency() : $this->default_template();
        $tpl        = (string) get_option( $option_key, $default );
        if ( '' === trim( $tpl ) ) {
            $tpl = $default;
        }

        $vars = array(
            '{customer_name}'  => $this->get_field( $lead, array( 'customer_name', 'name' ) ) ?: 'there',
            '{floor_type}'     => $this->get_field( $lead, array( 'floor_type', 'flooring' ) ) ?: 'floor',
            '{sqft}'           => number_format_i18n( (int) $this->get_field( $lead, array( 'square_footage', 'sqft' ) ) ),
            '{frequency}'      => $this->get_field( $lead, array( 'frequency', 'cleaning_frequency' ) ) ?: 'month',
            '{plan_tier}'      => (string) $tier['name'],
            '{monthly_price}'  => '$' . number_format_i18n( (int) $tier['monthly_price'] ),
            '{business_name}'  => get_bloginfo( 'name' ),
        );

        // strtr is faster than str_replace and avoids ordering issues if a value
        // accidentally contains another placeholder.
        return strtr( $tpl, $vars );
    }

    private function get_field( $source, array $keys ) {
        foreach ( $keys as $key ) {
            if ( is_array( $source ) && isset( $source[ $key ] ) && '' !== $source[ $key ] ) {
                return (string) $source[ $key ];
            }
            if ( is_object( $source ) && isset( $source->$key ) && '' !== $source->$key ) {
                return (string) $source->$key;
            }
        }
        return '';
    }

    public function add_menu() {
        add_submenu_page(
            'smart-crm-pro',
            esc_html__( 'Floor Care Plan', 'smart-crm-pro' ),
            esc_html__( 'Floor Care Plan', 'smart-crm-pro' ),
            'manage_options',
            'scrm-pro-floor-care-plan',
            array( $this, 'render_page' )
        );
    }

    public function handle_save() {
        if ( ! isset( $_POST['scrm_save_fcp'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $nonce = isset( $_POST['_scrm_fcp_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_scrm_fcp_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'scrm_save_fcp' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'smart-crm-pro' ) );
        }

        update_option( self::OPT_ENABLED, isset( $_POST['fcp_enabled'] ) ? 1 : 0 );

        // Tiers — repeatable rows of (name, min_sqft, max_sqft, monthly_price).
        $names  = isset( $_POST['tier_name'] )  ? (array) wp_unslash( $_POST['tier_name'] )  : array();
        $mins   = isset( $_POST['tier_min'] )   ? (array) wp_unslash( $_POST['tier_min'] )   : array();
        $maxs   = isset( $_POST['tier_max'] )   ? (array) wp_unslash( $_POST['tier_max'] )   : array();
        $prices = isset( $_POST['tier_price'] ) ? (array) wp_unslash( $_POST['tier_price'] ) : array();

        $tiers = array();
        $count = max( count( $names ), count( $mins ), count( $maxs ), count( $prices ) );
        for ( $i = 0; $i < $count; $i++ ) {
            $name = isset( $names[ $i ] ) ? sanitize_text_field( $names[ $i ] ) : '';
            if ( '' === $name ) {
                continue;
            }
            $tiers[] = array(
                'name'          => $name,
                'min_sqft'      => isset( $mins[ $i ] )   ? max( 0, (int) $mins[ $i ] )   : 0,
                'max_sqft'      => isset( $maxs[ $i ] )   ? max( 0, (int) $maxs[ $i ] )   : 99999,
                'monthly_price' => isset( $prices[ $i ] ) ? max( 0, (int) $prices[ $i ] ) : 0,
            );
        }
        if ( empty( $tiers ) ) {
            $tiers = $this->default_tiers();
        }
        update_option( self::OPT_TIERS, $tiers );

        if ( isset( $_POST['fcp_template'] ) ) {
            $template = wp_kses_post( wp_unslash( $_POST['fcp_template'] ) );
            update_option( self::OPT_TEMPLATE, $template );
        }
        if ( isset( $_POST['fcp_template_emergency'] ) ) {
            $template_e = wp_kses_post( wp_unslash( $_POST['fcp_template_emergency'] ) );
            update_option( self::OPT_TEMPLATE_EMERG, $template_e );
        }

        // Reset-to-default knobs — useful while iterating on copy.
        if ( ! empty( $_POST['fcp_reset_template'] ) ) {
            update_option( self::OPT_TEMPLATE, $this->default_template() );
        }
        if ( ! empty( $_POST['fcp_reset_template_emergency'] ) ) {
            update_option( self::OPT_TEMPLATE_EMERG, $this->default_template_emergency() );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=scrm-pro-floor-care-plan&saved=1' ) );
        exit;
    }

    public function render_page() {
        $enabled    = (int) get_option( self::OPT_ENABLED, 1 );
        $tiers      = (array) get_option( self::OPT_TIERS, $this->default_tiers() );
        $template   = (string) get_option( self::OPT_TEMPLATE, $this->default_template() );
        $template_e = (string) get_option( self::OPT_TEMPLATE_EMERG, $this->default_template_emergency() );
        $last       = get_option( self::OPT_LAST_RUN, array() );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $saved    = isset( $_GET['saved'] );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Floor Care Plan Generator', 'smart-crm-pro' ); ?></h1>
            <p class="description"><?php esc_html_e( 'When a COMMERCIAL job completes (any urgency), generates a personalized care plan, publishes it as a private CPT page, and forwards the URL to ActiveCampaign as the floor_care_plan_url field. AC flows link to it in their post-job email. Commercial-emergency jobs get the same plan plus the midland-floor-care-plan-offer-emergency tag so AC can run a more urgent version of the offer.', 'smart-crm-pro' ); ?></p>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Floor Care Plan settings saved.', 'smart-crm-pro' ); ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'scrm_save_fcp', '_scrm_fcp_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Enable', 'smart-crm-pro' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="fcp_enabled" value="1" <?php checked( $enabled ); ?>>
                                <?php esc_html_e( 'Generate a plan on every commercial job completion. Commercial-emergency completions get the emergency template.', 'smart-crm-pro' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Plan Tiers', 'smart-crm-pro' ); ?></h2>
                <table class="widefat striped" style="max-width:780px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Name', 'smart-crm-pro' ); ?></th>
                            <th><?php esc_html_e( 'Min sqft', 'smart-crm-pro' ); ?></th>
                            <th><?php esc_html_e( 'Max sqft', 'smart-crm-pro' ); ?></th>
                            <th><?php esc_html_e( 'Monthly $', 'smart-crm-pro' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Always render at least one extra blank row so adding a tier is just typing into the next row.
                        $rows = max( count( $tiers ) + 1, 4 );
                        for ( $i = 0; $i < $rows; $i++ ) :
                            $tier = $tiers[ $i ] ?? array( 'name' => '', 'min_sqft' => '', 'max_sqft' => '', 'monthly_price' => '' );
                        ?>
                            <tr>
                                <td><input type="text" name="tier_name[]" value="<?php echo esc_attr( $tier['name'] ); ?>" class="regular-text" placeholder="Site Care / Site Care+ / Facility / Enterprise"></td>
                                <td><input type="number" name="tier_min[]" value="<?php echo esc_attr( $tier['min_sqft'] ); ?>" min="0" style="width:90px;"></td>
                                <td><input type="number" name="tier_max[]" value="<?php echo esc_attr( $tier['max_sqft'] ); ?>" min="0" style="width:90px;"></td>
                                <td><input type="number" name="tier_price[]" value="<?php echo esc_attr( $tier['monthly_price'] ); ?>" min="0" style="width:90px;"></td>
                            </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
                <p class="description"><?php esc_html_e( 'Tiers are matched by sqft range, top-down. Leave a row\'s name blank to drop it.', 'smart-crm-pro' ); ?></p>

                <h2><?php esc_html_e( 'Plan Template — Standard (commercial completion)', 'smart-crm-pro' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'Available placeholders:', 'smart-crm-pro' ); ?>
                    <code>{customer_name}</code>
                    <code>{floor_type}</code>
                    <code>{sqft}</code>
                    <code>{frequency}</code>
                    <code>{plan_tier}</code>
                    <code>{monthly_price}</code>
                    <code>{business_name}</code>
                </p>
                <textarea name="fcp_template" rows="14" class="large-text code"><?php echo esc_textarea( $template ); ?></textarea>
                <p>
                    <label>
                        <input type="checkbox" name="fcp_reset_template" value="1">
                        <?php esc_html_e( 'Reset standard template to default on save', 'smart-crm-pro' ); ?>
                    </label>
                </p>

                <h2><?php esc_html_e( 'Plan Template — Emergency (commercial-emergency completion)', 'smart-crm-pro' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Used when the lead is both commercial AND emergency. Same placeholders as above.', 'smart-crm-pro' ); ?></p>
                <textarea name="fcp_template_emergency" rows="14" class="large-text code"><?php echo esc_textarea( $template_e ); ?></textarea>
                <p>
                    <label>
                        <input type="checkbox" name="fcp_reset_template_emergency" value="1">
                        <?php esc_html_e( 'Reset emergency template to default on save', 'smart-crm-pro' ); ?>
                    </label>
                </p>

                <p class="submit">
                    <button type="submit" name="scrm_save_fcp" value="1" class="button button-primary"><?php esc_html_e( 'Save', 'smart-crm-pro' ); ?></button>
                </p>
            </form>

            <?php if ( ! empty( $last ) ) : ?>
                <hr>
                <h2><?php esc_html_e( 'Last Generated Plan', 'smart-crm-pro' ); ?></h2>
                <p>
                    <strong><?php echo esc_html( $last['email'] ?? '' ); ?></strong>
                    — <?php echo esc_html( $last['tier'] ?? '' ); ?>
                    (<?php echo esc_html( (int) ( $last['sqft'] ?? 0 ) ); ?> sqft)
                    — <?php echo esc_html( ! empty( $last['at'] ) ? wp_date( 'Y-m-d H:i', (int) $last['at'] ) : '' ); ?>
                    <?php if ( ! empty( $last['url'] ) ) : ?>
                        — <a href="<?php echo esc_url( $last['url'] ); ?>" target="_blank"><?php esc_html_e( 'Open plan', 'smart-crm-pro' ); ?></a>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }
}

SCRM_Pro_Floor_Care_Plan::get_instance();
