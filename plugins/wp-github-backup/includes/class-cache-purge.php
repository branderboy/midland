<?php
/**
 * Cache purge helpers for WP GitHub Backup.
 *
 * Detects common WordPress page-cache and CDN plugins installed on the
 * site and calls each of their purge APIs after a successful deploy, so
 * content changes land on the frontend without the admin having to open
 * yet another plugin and click yet another button.
 *
 * Each integration is a defensive no-op when the target plugin isn't
 * installed — nothing fatal, nothing logged. Returns the list of cache
 * layers that actually fired so the admin UI can surface them.
 *
 * @package WPGitHubBackup
 * @since   3.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WGB_Cache_Purge {

	/**
	 * Fire every detected cache layer's purge hook/function.
	 *
	 * @return string[] Labels of the layers that actually fired.
	 */
	public static function purge_all() {
		$fired = array();

		// Each entry is [ label, detector (callable returning bool), purger (callable) ].
		$integrations = array(
			array(
				'WP Rocket',
				static function () {
					return function_exists( 'rocket_clean_domain' );
				},
				static function () {
					rocket_clean_domain();
				},
			),
			array(
				'LiteSpeed Cache',
				static function () {
					return defined( 'LSCWP_V' ) || class_exists( '\\LiteSpeed\\Purge' );
				},
				static function () {
					do_action( 'litespeed_purge_all' );
				},
			),
			array(
				'W3 Total Cache',
				static function () {
					return function_exists( 'w3tc_flush_all' );
				},
				static function () {
					w3tc_flush_all();
				},
			),
			array(
				'WP Super Cache',
				static function () {
					return function_exists( 'wp_cache_clear_cache' );
				},
				static function () {
					wp_cache_clear_cache();
				},
			),
			array(
				'Cache Enabler',
				static function () {
					return class_exists( 'Cache_Enabler' ) && method_exists( 'Cache_Enabler', 'clear_total_cache' );
				},
				static function () {
					\Cache_Enabler::clear_total_cache();
				},
			),
			array(
				'Autoptimize',
				static function () {
					return class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' );
				},
				static function () {
					\autoptimizeCache::clearall();
				},
			),
			array(
				'Hummingbird',
				static function () {
					return has_action( 'wphb_clear_page_cache' );
				},
				static function () {
					do_action( 'wphb_clear_page_cache' );
				},
			),
			array(
				'SiteGround Optimizer',
				static function () {
					return function_exists( 'sg_cachepress_purge_cache' );
				},
				static function () {
					sg_cachepress_purge_cache();
				},
			),
			array(
				'Kinsta Cache',
				static function () {
					return class_exists( '\\Kinsta\\Cache' );
				},
				static function () {
					do_action( 'kinsta_cache_purge_all' );
				},
			),
			array(
				'GoDaddy WPaaS',
				static function () {
					return class_exists( '\\WPaaS\\Plugin' );
				},
				static function () {
					do_action( 'wpaas_clear_cache' );
				},
			),
			array(
				'Cloudflare (plugin)',
				static function () {
					return class_exists( 'CF\\WordPress\\Hooks' ) && defined( 'CLOUDFLARE_PLUGIN_DIR' );
				},
				static function () {
					do_action( 'cloudflare_purge_everything' );
				},
			),
		);

		foreach ( $integrations as $integration ) {
			list( $label, $detector, $purger ) = $integration;

			if ( ! call_user_func( $detector ) ) {
				continue;
			}

			try {
				call_user_func( $purger );
				$fired[] = $label;
			} catch ( \Throwable $e ) {
				// Swallow — a broken cache plugin must not fail the
				// deploy response.
			}
		}

		// WordPress object cache — always safe to flush.
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
			$fired[] = 'WP object cache';
		}

		/**
		 * Fires after the built-in cache purge. Custom integrations may
		 * hook here to extend the plugin.
		 *
		 * @since 3.2.0
		 *
		 * @param string[] $fired Layers that already fired.
		 */
		do_action( 'wgb_after_deploy_purge', $fired );

		return $fired;
	}
}
