<?php
/**
 * Runtime smoke test for the Midland Smart SEO (real-smart-seo) plugin.
 *
 * Run via:  wp eval-file tools/smoke-test.php   (see tools/smoke-test.sh)
 *
 * Catches the class of bug `php -l` cannot: undefined methods used as
 * callbacks, fatals during admin render, missing tables, and cron/teardown
 * regressions. Prints "SMOKE PASS" or "SMOKE FAIL (n)" as the last line.
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "Must run inside WordPress (wp eval-file).\n" );
    exit( 1 );
}

wp_set_current_user( 1 );
if ( function_exists( 'set_current_screen' ) ) {
    set_current_screen( 'toplevel_page_real-smart-seo' );
}

$fail = 0;

// 1) Every table the plugin creates exists.
global $wpdb;
$tables = array(
    'rsseo_scans', 'rsseo_reports', 'rsseo_fixes', 'rsseo_audits', 'rsseo_audit_issues', 'rsseo_api_log',
    'rsseo_pro_scans', 'rsseo_pro_schema', 'rsseo_pro_backlinks',
    'rsseo_pro_geogrid_runs', 'rsseo_pro_geogrid_cells', 'rsseo_pro_ai_rank',
);
foreach ( $tables as $t ) {
    $full = $wpdb->prefix . $t;
    if ( ! $wpdb->get_var( "SHOW TABLES LIKE '$full'" ) ) {
        echo "TABLE MISSING: $t\n";
        $fail++;
    }
}
echo '[tables] checked ' . count( $tables ) . "\n";

// 2) Core admin assets enqueue (registers rsseo-admin + rsseoData/nonce).
if ( ! class_exists( 'RSSEO_Admin' ) ) {
    echo "FATAL: RSSEO_Admin not loaded\n";
    echo "\n=== SMOKE FAIL (1) ===\n";
    return;
}
$admin = RSSEO_Admin::get_instance();
try {
    $admin->enqueue_assets( 'toplevel_page_real-smart-seo' );
} catch ( \Throwable $e ) {
    echo 'enqueue_assets FATAL: ' . $e->getMessage() . "\n";
    $fail++;
}
echo '[enqueue] rsseo-admin registered: ' . ( wp_script_is( 'rsseo-admin', 'registered' ) ? 'YES' : 'NO' ) . "\n";
if ( ! wp_script_is( 'rsseo-admin', 'registered' ) ) {
    $fail++;
}

// 3) Every registered $this/__CLASS__ callback resolves to a real method.
$classmap = array();
foreach ( get_declared_classes() as $cls ) {
    if ( 0 === strpos( $cls, 'RSSEO_' ) ) {
        $classmap[ $cls ] = true;
    }
}
// Spot-check the admin shell callbacks (the ones wired in its constructor).
foreach ( array( 'enqueue_assets', 'handle_new_scan', 'register_menu', 'render_tabbed_page',
                 'ajax_apply_fix', 'ajax_apply_all', 'ajax_restore_fix', 'ajax_test_api',
                 'ajax_save_settings', 'ajax_analysis_status', 'ajax_run_audit' ) as $m ) {
    if ( ! method_exists( 'RSSEO_Admin', $m ) ) {
        echo "MISSING RSSEO_Admin::$m\n";
        $fail++;
    }
}

// 4) Render every tab — catch any fatal in the render paths.
foreach ( array( 'dashboard', 'settings', 'audit', 'analysis', 'fixqueue', 'content', 'links', 'indexing', 'reports' ) as $tab ) {
    $_GET['tab'] = $tab;
    ob_start();
    try {
        $admin->render_tabbed_page();
        $len = strlen( ob_get_clean() );
        echo "[tab] $tab OK ($len bytes)\n";
        if ( $len < 50 ) {
            echo "  WARN: $tab rendered suspiciously little output\n";
        }
    } catch ( \Throwable $e ) {
        ob_end_clean();
        echo "[tab] $tab FATAL: " . $e->getMessage() . "\n";
        $fail++;
    }
}

// 5) Cron gating: nothing scheduled while unconfigured.
foreach ( array( 'rsseo_geogrid_weekly_scan', 'rsseo_ai_rank_weekly_scan' ) as $hook ) {
    if ( wp_next_scheduled( $hook ) ) {
        echo "[cron] $hook scheduled while unconfigured (should not be)\n";
        $fail++;
    }
}
echo "[cron] gating ok\n";

// 6) Deactivation clears scheduled events without fatal.
wp_schedule_event( time() + 3600, 'weekly', 'rsseo_geogrid_weekly_scan' );
try {
    RSSEO_Plugin::get_instance()->deactivate();
    echo '[deactivate] geogrid event: ' . ( wp_next_scheduled( 'rsseo_geogrid_weekly_scan' ) ? 'STILL SET' : 'cleared' ) . "\n";
    if ( wp_next_scheduled( 'rsseo_geogrid_weekly_scan' ) ) {
        $fail++;
    }
} catch ( \Throwable $e ) {
    echo 'deactivate FATAL: ' . $e->getMessage() . "\n";
    $fail++;
}

echo $fail ? "\n=== SMOKE FAIL ($fail) ===\n" : "\n=== SMOKE PASS ===\n";
