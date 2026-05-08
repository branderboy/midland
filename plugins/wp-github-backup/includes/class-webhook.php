<?php
/**
 * Webhook handler for GitHub push events.
 *
 * Registers a REST API endpoint that GitHub can call when pushes happen,
 * triggering an automatic deploy of content into WordPress.
 *
 * @package WPGitHubBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WGB_Webhook {

	/**
	 * Register the REST API route.
	 */
	public static function register_routes() {
		register_rest_route( 'gitdeploy/v1', '/webhook', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_webhook' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * Handle the incoming GitHub webhook.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response
	 */
	public static function handle_webhook( $request ) {
		// HMAC is mandatory. Without a configured secret there is no way to
		// tell a real GitHub push from a spoofed POST, so the endpoint must
		// reject every request until the admin sets one. This closes the
		// pre-3.2 behaviour where an empty secret silently made the webhook
		// unauthenticated.
		$secret = WGB_Settings::get( 'webhook_secret', '' );

		if ( empty( $secret ) ) {
			return new WP_REST_Response(
				array(
					'error' => __( 'Webhook secret is not configured. Set one in the plugin settings before enabling the GitHub webhook.', 'wp-github-backup' ),
				),
				403
			);
		}

		$signature = $request->get_header( 'X-Hub-Signature-256' );

		if ( empty( $signature ) ) {
			return new WP_REST_Response( array( 'error' => __( 'Missing signature.', 'wp-github-backup' ) ), 403 );
		}

		$payload  = $request->get_body();
		$expected = 'sha256=' . hash_hmac( 'sha256', $payload, $secret );

		if ( ! hash_equals( $expected, $signature ) ) {
			// Diagnostic: signature mismatches are almost always one of
			// (a) different secret on each side, (b) trailing whitespace
			// on the WP-side option, (c) a CDN/optimizer mutating the
			// request body before we see it. Log enough to tell which
			// without leaking the secret itself.
			$diag = array(
				'wgb_webhook_diag' => true,
				'secret_len'       => strlen( $secret ),
				'secret_trimmed'   => strlen( trim( $secret ) ) !== strlen( $secret ),
				'secret_sha8'      => substr( hash( 'sha256', $secret ), 0, 8 ),
				'payload_bytes'    => strlen( $payload ),
				'payload_sha8'     => substr( hash( 'sha256', $payload ), 0, 8 ),
				'expected_tail'    => substr( $expected, -12 ),
				'received_tail'    => substr( (string) $signature, -12 ),
				'delivery'         => $request->get_header( 'X-GitHub-Delivery' ),
				'event'            => $request->get_header( 'X-GitHub-Event' ),
			);
			error_log( 'WGB webhook signature mismatch: ' . wp_json_encode( $diag ) );

			return new WP_REST_Response(
				array(
					'error' => __( 'Invalid signature.', 'wp-github-backup' ),
					'diag'  => $diag,
				),
				403
			);
		}

		// Only process push events.
		$event = $request->get_header( 'X-GitHub-Event' );

		if ( 'ping' === $event ) {
			return new WP_REST_Response( array( 'status' => 'pong' ), 200 );
		}

		if ( 'push' !== $event ) {
			return new WP_REST_Response(
				array(
					'status' => 'ignored',
					'reason' => __( 'Not a push event.', 'wp-github-backup' ),
				),
				200
			);
		}

		// Check that the push is to the configured branch.
		$body   = $request->get_json_params();
		$ref    = isset( $body['ref'] ) ? sanitize_text_field( (string) $body['ref'] ) : '';
		$branch = WGB_Settings::get( 'deploy_branch', 'main' );

		if ( empty( $branch ) ) {
			$branch = 'main';
		}

		if ( 'refs/heads/' . $branch !== $ref ) {
			return new WP_REST_Response(
				array(
					'status' => 'ignored',
					/* translators: 1: ref actually pushed, 2: configured deploy branch */
					'reason' => sprintf( __( 'Push was to %1$s, not %2$s.', 'wp-github-backup' ), $ref, $branch ),
				),
				200
			);
		}

		// Run the deploy.
		$token = WGB_Settings::get_token();
		$owner = WGB_Settings::get( 'github_username' );
		$repo  = WGB_Settings::get( 'repo_name' );

		if ( empty( $token ) || empty( $owner ) || empty( $repo ) ) {
			return new WP_REST_Response(
				array(
					'error' => __( 'Plugin not configured. Set GitHub token, username, and repo in settings.', 'wp-github-backup' ),
				),
				500
			);
		}

		$github   = new WGB_GitHub_API( $token, $owner, $repo );
		$target   = WGB_Settings::get( 'deploy_target', 'content' );

		// Run the deploy asynchronously so we can ack the webhook
		// inside GitHub's 10-second delivery timeout. Synchronous deploy
		// took 30-120s on real sites and showed up in GitHub's UI as
		// "timed out" with the deploy still running on the WP side.
		// We schedule a one-off cron event with the deploy params and
		// kick the cron loop with a non-blocking loopback request so
		// it runs in the next ~1s instead of waiting on the next
		// visitor to trigger wp-cron.
		$args = array(
			'target' => $target,
			'branch' => $branch,
			'ref'    => $ref,
		);

		if ( ! wp_next_scheduled( 'wgb_run_async_deploy', array( $args ) ) ) {
			wp_schedule_single_event( time(), 'wgb_run_async_deploy', array( $args ) );
		}

		self::spawn_cron_now();

		return new WP_REST_Response(
			array(
				'status' => 'queued',
				'branch' => $branch,
				'target' => $target,
			),
			202
		);
	}

	/**
	 * Trigger wp-cron in the background without blocking the current
	 * request. We do not care about the response — the only goal is
	 * to wake cron so wgb_run_async_deploy runs within ~1s instead of
	 * waiting for the next page view.
	 */
	private static function spawn_cron_now() {
		$cron_url = site_url( 'wp-cron.php?doing_wp_cron=' . sprintf( '%.22F', microtime( true ) ) );

		wp_remote_post(
			$cron_url,
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'headers'   => array(
					'Cache-Control' => 'no-cache',
				),
			)
		);
	}

	/**
	 * Cron handler that actually runs the deploy. Fired by the
	 * wgb_run_async_deploy single event scheduled inside the webhook
	 * handler. Bails fast if config is missing so a misconfigured
	 * site does not retry the same broken job forever.
	 *
	 * @param array $args {target, branch, ref}.
	 */
	public static function run_async_deploy( $args ) {
		$target = isset( $args['target'] ) ? (string) $args['target'] : 'content';
		$branch = isset( $args['branch'] ) ? (string) $args['branch'] : 'main';

		$token = WGB_Settings::get_token();
		$owner = WGB_Settings::get( 'github_username' );
		$repo  = WGB_Settings::get( 'repo_name' );

		if ( empty( $token ) || empty( $owner ) || empty( $repo ) ) {
			error_log( 'WGB async deploy aborted: missing token/owner/repo.' );
			return;
		}

		$github   = new WGB_GitHub_API( $token, $owner, $repo );
		$deployer = new WGB_Deployer( $github, $branch );
		$result   = $deployer->run( $target );

		error_log( 'WGB async deploy complete: ' . wp_json_encode( $result ) );
	}
}
