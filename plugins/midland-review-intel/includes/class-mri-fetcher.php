<?php
/**
 * DataForSEO Google Reviews fetcher (task_post + cron-polled task_get).
 *
 * Reuses the credentials stored by Midland Local SEO (MLS_DataForSEO) — the
 * client never configures a second key. Google Reviews lives in DataForSEO's
 * Business Data API, which is a separate product from SERP; if the plan lacks
 * it we surface a clear notice instead of failing silently.
 *
 * @package Midland_Review_Intel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Posts review-collection tasks and polls for results via WP-Cron.
 */
class MRI_Fetcher {

	const BASE_URL    = 'https://api.dataforseo.com/v3/business_data/google/reviews/';
	const OPT_PENDING = 'mri_pending_tasks';
	const OPT_ERRORS  = 'mri_last_errors';
	const OPT_DEPTH   = 'mri_depth';

	/**
	 * Singleton instance.
	 *
	 * @var MRI_Fetcher|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton.
	 *
	 * @return MRI_Fetcher
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Bind the polling cron hook.
	 */
	private function __construct() {
		add_action( 'mri_poll_tasks', array( $this, 'poll_tasks' ) );
	}

	/**
	 * Basic-auth header built from the Midland Local SEO credentials.
	 *
	 * @return string|WP_Error
	 */
	private static function auth() {
		if ( ! class_exists( 'MLS_DataForSEO' ) || ! MLS_DataForSEO::is_configured() ) {
			return new WP_Error( 'no_credentials', __( 'Configure DataForSEO in Midland Local SEO first.', 'midland-review-intel' ) );
		}
		return 'Basic ' . base64_encode( MLS_DataForSEO::get_login() . ':' . MLS_DataForSEO::get_password() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * API request helper.
	 *
	 * @param string     $method  GET|POST.
	 * @param string     $path    Path relative to BASE_URL.
	 * @param array|null $payload POST payload.
	 * @return array|WP_Error Decoded body.
	 */
	private static function request( $method, $path, $payload = null ) {
		$auth = self::auth();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => $auth,
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $payload ) {
			$args['body'] = wp_json_encode( $payload );
		}

		$response = wp_remote_request( self::BASE_URL . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 || ! is_array( $data ) ) {
			return new WP_Error( 'dfs_error', $data['status_message'] ?? ( 'DataForSEO HTTP ' . $code ) );
		}
		return $data;
	}

	/**
	 * Whether an error message means the plan lacks the Business Data API.
	 *
	 * @param string $message Error message.
	 * @return bool
	 */
	public static function is_plan_error( $message ) {
		return false !== stripos( $message, 'not authorized' )
			|| false !== stripos( $message, 'access denied' )
			|| false !== stripos( $message, '40301' );
	}

	/**
	 * Post one review-collection task per competitor.
	 *
	 * @return array|WP_Error { queued: int, errors: string[] }.
	 */
	public static function fetch_all() {
		$competitors = MRI_DB::get_competitors();
		$depth       = max( 10, min( 700, (int) get_option( self::OPT_DEPTH, 100 ) ) );

		$payload = array();
		foreach ( $competitors as $c ) {
			$payload[] = array(
				'keyword'       => $c['query'],
				'location_name' => 'United States',
				'language_name' => 'English',
				'depth'         => $depth,
				'sort_by'       => 'newest',
			);
		}

		$result = self::request( 'POST', 'task_post', $payload );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$pending = get_option( self::OPT_PENDING, array() );
		if ( ! is_array( $pending ) ) {
			$pending = array();
		}
		$errors = array();
		$queued = 0;

		$tasks = isset( $result['tasks'] ) && is_array( $result['tasks'] ) ? $result['tasks'] : array();
		foreach ( $tasks as $i => $task ) {
			$company = $competitors[ $i ] ?? null;
			$code    = (int) ( $task['status_code'] ?? 0 );
			if ( $company && in_array( $code, array( 20000, 20100 ), true ) && ! empty( $task['id'] ) ) {
				$pending[ $task['id'] ] = $company;
				$queued++;
			} else {
				$errors[] = ( $company['name'] ?? "task {$i}" ) . ': ' . ( $task['status_message'] ?? 'unknown error' );
			}
		}

		update_option( self::OPT_PENDING, $pending, false );
		update_option( self::OPT_ERRORS, $errors, false );

		if ( $queued > 0 && ! wp_next_scheduled( 'mri_poll_tasks' ) ) {
			wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, 'mri_poll_tasks' );
		}

		return array(
			'queued' => $queued,
			'errors' => $errors,
		);
	}

	/**
	 * Cron callback: poll task_get for each pending task; reschedule while any
	 * remain. Finished tasks are parsed into the reviews table.
	 */
	public function poll_tasks() {
		$pending = get_option( self::OPT_PENDING, array() );
		if ( ! is_array( $pending ) || empty( $pending ) ) {
			return;
		}
		$errors = get_option( self::OPT_ERRORS, array() );
		if ( ! is_array( $errors ) ) {
			$errors = array();
		}

		foreach ( $pending as $task_id => $company ) {
			$result = self::request( 'GET', 'task_get/' . rawurlencode( $task_id ) );
			if ( is_wp_error( $result ) ) {
				continue; // Transient transport error — retry next poll.
			}

			$task = $result['tasks'][0] ?? array();
			$code = (int) ( $task['status_code'] ?? 0 );
			$msg  = (string) ( $task['status_message'] ?? '' );

			// 40601 "Task Handed" / 40602 "Task In Queue" — still collecting.
			if ( in_array( $code, array( 40601, 40602 ), true ) || false !== stripos( $msg, 'queue' ) || false !== stripos( $msg, 'handed' ) ) {
				continue;
			}

			if ( 20000 !== $code ) {
				$errors[] = $company['name'] . ': ' . $msg;
				unset( $pending[ $task_id ] );
				continue;
			}

			$inserted = 0;
			$results  = isset( $task['result'] ) && is_array( $task['result'] ) ? $task['result'] : array();
			foreach ( $results as $block ) {
				$items = isset( $block['items'] ) && is_array( $block['items'] ) ? $block['items'] : array();
				foreach ( $items as $item ) {
					$rating = $item['rating'] ?? null;
					if ( is_array( $rating ) ) {
						$rating = $rating['value'] ?? null;
					}
					$inserted += (int) MRI_DB::insert_review(
						array(
							'company'        => $company['name'],
							'segment'        => $company['segment'],
							'rating'         => (int) $rating,
							'review_date'    => substr( (string) ( $item['timestamp'] ?? '' ), 0, 10 ),
							'review_text'    => (string) ( $item['review_text'] ?? ( $item['original_review_text'] ?? '' ) ),
							'owner_response' => (string) ( $item['owner_answer'] ?? '' ),
						)
					);
				}
			}
			unset( $pending[ $task_id ] );
			$errors[] = sprintf( '%s: collected (%d new reviews with text)', $company['name'], $inserted );
		}

		update_option( self::OPT_PENDING, $pending, false );
		update_option( self::OPT_ERRORS, $errors, false );

		if ( ! empty( $pending ) ) {
			wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, 'mri_poll_tasks' );
		}
	}

	/**
	 * Pending task count (for the admin status line).
	 *
	 * @return int
	 */
	public static function pending_count() {
		$pending = get_option( self::OPT_PENDING, array() );
		return is_array( $pending ) ? count( $pending ) : 0;
	}
}
