<?php
/**
 * Admin layer: menu, settings registration (with secret-preserving sanitizers),
 * asset enqueue, and all AJAX endpoints. Every endpoint checks a nonce and the
 * manage_options capability; secrets are never sent back to the browser.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSB_Admin {

	const CAP   = 'manage_options';
	const NONCE = 'gsb_admin';
	const GROUP = 'gsb_settings_group';

	/** @var GSB_Admin|null */
	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( 'GSB_Logger', 'render_notice' ) );

		// Fix 1: HTML checkboxes are not submitted when unchecked, so the
		// sanitize_callback never fires and the stored value stays 1 forever.
		// Hook pre_update_option so unchecking actually saves 0 by detecting
		// whether the field is present in the form POST for our settings group.
		foreach ( array( 'gsb_weekly_reindex', 'gsb_neon_enabled', 'gsb_enable_digest' ) as $opt ) {
			add_filter(
				'pre_update_option_' . $opt,
				static function ( $new_value, $old_value ) use ( $opt ) {
					// phpcs:ignore WordPress.Security.NonceVerification
					if ( isset( $_POST['option_page'] ) && GSB_Admin::GROUP === sanitize_key( wp_unslash( $_POST['option_page'] ) ) ) {
						// phpcs:ignore WordPress.Security.NonceVerification
						return isset( $_POST[ $opt ] ) ? 1 : 0;
					}
					return $new_value;
				},
				10,
				2
			);
		}

		// Sync cron whenever options.php saves weekly_reindex (Fix 12).
		add_action( 'update_option_gsb_weekly_reindex', array( $this, 'sync_reindex_cron' ), 10, 2 );

		// Sync the independent weekly digest cron when enable_digest is saved, so
		// the digest schedule follows the setting even with reindex turned off.
		add_action( 'update_option_gsb_enable_digest', array( $this, 'sync_digest_cron' ), 10, 2 );

		// Fix 3: gsb_save_settings removed — settings are saved via the standard
		// options.php form which calls the register_setting sanitize callbacks.
		// A parallel AJAX save path created dead/unsafe duplicate logic and its
		// keep_secret() calls broke in AJAX context because current_filter()
		// returns 'wp_ajax_gsb_save_settings', not 'sanitize_option_gsb_*'.
		$ajax = array(
			'gsb_start_scan'      => 'ajax_start_scan',
			'gsb_scan_step'       => 'ajax_scan_step',
			'gsb_embed_step'      => 'ajax_embed_step',
			'gsb_finalize'        => 'ajax_finalize',
			'gsb_progress'        => 'ajax_progress',
			'gsb_reindex_post'    => 'ajax_reindex_post',
			'gsb_rebuild_recs'    => 'ajax_rebuild_recs',
			'gsb_rec_status'      => 'ajax_rec_status',
			'gsb_apply_fix'       => 'ajax_apply_fix',
			'gsb_narrative'       => 'ajax_narrative',
			'gsb_probe'           => 'ajax_probe',
			'gsb_run_competitors' => 'ajax_run_competitors',
			'gsb_send_digest'     => 'ajax_send_digest',
			'gsb_regen_key'       => 'ajax_regen_key',
			'gsb_test_openai'     => 'ajax_test_openai',
			'gsb_test_neon'       => 'ajax_test_neon',
			'gsb_chat'            => 'ajax_chat',
		);
		foreach ( $ajax as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( $this, $method ) );
		}
	}

	/**
	 * Called whenever gsb_weekly_reindex is saved via options.php.
	 * $old_value is the previous value; $new_value is the just-saved value.
	 */
	public function sync_reindex_cron( $old_value, $new_value ) {
		if ( (int) $new_value ) {
			if ( ! wp_next_scheduled( GSB_CRON_REINDEX ) ) {
				wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', GSB_CRON_REINDEX );
			}
		} else {
			wp_clear_scheduled_hook( GSB_CRON_REINDEX );
		}
	}

	/**
	 * Called whenever gsb_enable_digest is saved via options.php. Schedules or
	 * clears the standalone weekly digest cron to match the new value.
	 */
	public function sync_digest_cron( $old_value, $new_value ) {
		GSB_Monitor::sync_digest_cron( (int) $new_value );
	}

	/* ----------------------------------------------------------------- menu */

	public function menu() {
		add_menu_page(
			__( 'Site Brain', 'geo-site-brain' ),
			__( 'Site Brain', 'geo-site-brain' ),
			self::CAP,
			'geo-site-brain',
			array( $this, 'view_dashboard' ),
			'dashicons-superhero',
			58
		);
		// Order: Dashboard → Settings → Scan → Page Scorecard → Knowledge Graph
		//        → AI Visibility Gaps → Fix Queue → Site Chat → Competitors
		//        → Reports
		// Settings moves to position 2 because every other screen depends on
		// API keys, post types, services, locations, and competitors being set.
		// Page Scorecard moves immediately after Scan because scoring is the
		// direct output of a scan run. All slugs unchanged.
		$pages = array(
			'geo-site-brain'      => array( __( 'Dashboard', 'geo-site-brain' ), 'view_dashboard' ),
			'gsb-settings'        => array( __( 'Settings', 'geo-site-brain' ), 'view_settings' ),
			'gsb-scan'            => array( __( 'Scan Website', 'geo-site-brain' ), 'view_scan' ),
			'gsb-scores'          => array( __( 'Page Scorecard', 'geo-site-brain' ), 'view_scores' ),
			'gsb-knowledge-graph' => array( __( 'Knowledge Graph', 'geo-site-brain' ), 'view_knowledge_graph' ),
			'gsb-visibility'      => array( __( 'AI Visibility Gaps', 'geo-site-brain' ), 'view_visibility' ),
			'gsb-recommendations' => array( __( 'Fix Queue', 'geo-site-brain' ), 'view_recommendations' ),
			'gsb-chat'            => array( __( 'Site Chat', 'geo-site-brain' ), 'view_chat' ),
			'gsb-competitors'     => array( __( 'Competitors', 'geo-site-brain' ), 'view_competitors' ),
			'gsb-reports'         => array( __( 'Reports', 'geo-site-brain' ), 'view_reports' ),
		);
		foreach ( $pages as $slug => $cfg ) {
			add_submenu_page( 'geo-site-brain', $cfg[0], $cfg[0], self::CAP, $slug, array( $this, $cfg[1] ) );
		}
	}

	/* ------------------------------------------------------------- settings */

	public function register_settings() {
		$o = GSB_Settings::OPTION_PREFIX;

		// Secrets: preserve the stored value when the field is submitted empty so
		// saving the form doesn't wipe a configured key.
		register_setting( self::GROUP, $o . 'openai_api_key', array( 'type' => 'string', 'sanitize_callback' => array( $this, 'keep_secret' ) ) );
		register_setting( self::GROUP, $o . 'anthropic_api_key', array( 'type' => 'string', 'sanitize_callback' => array( $this, 'keep_secret' ) ) );
		register_setting( self::GROUP, $o . 'gemini_api_key', array( 'type' => 'string', 'sanitize_callback' => array( $this, 'keep_secret' ) ) );
		register_setting( self::GROUP, $o . 'perplexity_api_key', array( 'type' => 'string', 'sanitize_callback' => array( $this, 'keep_secret' ) ) );
		register_setting( self::GROUP, $o . 'neon_dsn', array( 'type' => 'string', 'sanitize_callback' => array( $this, 'keep_secret' ) ) );

		register_setting( self::GROUP, $o . 'chat_model', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( self::GROUP, $o . 'anthropic_model', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( self::GROUP, $o . 'gemini_model', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( self::GROUP, $o . 'perplexity_model', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( self::GROUP, $o . 'neon_enabled', array( 'type' => 'integer', 'sanitize_callback' => 'absint' ) );
		register_setting( self::GROUP, $o . 'post_types', array( 'type' => 'array', 'sanitize_callback' => array( $this, 'sanitize_post_types' ) ) );
		register_setting( self::GROUP, $o . 'chunk_max_chars', array( 'type' => 'integer', 'sanitize_callback' => 'absint' ) );
		register_setting( self::GROUP, $o . 'embed_batch', array( 'type' => 'integer', 'sanitize_callback' => 'absint' ) );
		register_setting( self::GROUP, $o . 'retrieval_k', array( 'type' => 'integer', 'sanitize_callback' => 'absint' ) );
		register_setting( self::GROUP, $o . 'weekly_reindex', array( 'type' => 'integer', 'sanitize_callback' => 'absint' ) );
		register_setting( self::GROUP, $o . 'business_name', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( self::GROUP, $o . 'business_locations', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ) );
		register_setting( self::GROUP, $o . 'core_services', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ) );

		// Phase 3 — competitors, monitoring, white-label.
		register_setting( self::GROUP, $o . 'competitor_urls', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ) );
		register_setting( self::GROUP, $o . 'enable_digest', array( 'type' => 'integer', 'sanitize_callback' => 'absint' ) );
		register_setting( self::GROUP, $o . 'digest_email', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_email' ) );
		register_setting( self::GROUP, $o . 'agency_name', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( self::GROUP, $o . 'agency_logo', array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ) );
		register_setting( self::GROUP, $o . 'report_contact', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ) );

		// REST API + webhooks (the key + secret are managed via buttons, not the form).
		register_setting( self::GROUP, $o . 'webhooks_enabled', array( 'type' => 'integer', 'sanitize_callback' => 'absint' ) );
		register_setting( self::GROUP, $o . 'webhook_urls', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ) );
	}

	/**
	 * Keep the existing secret if the submitted value is blank or the mask.
	 */
	public function keep_secret( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || preg_match( '/^[•\*]+$/u', $value ) ) {
			// Determine which option is being saved via the current filter.
			$current = current_filter(); // sanitize_option_gsb_openai_api_key
			$option  = preg_replace( '/^sanitize_option_/', '', $current );
			return (string) get_option( $option, '' );
		}
		return sanitize_text_field( $value );
	}

	public function sanitize_post_types( $value ) {
		if ( ! is_array( $value ) ) {
			$value = array();
		}
		$value = array_map( 'sanitize_key', $value );
		return $value ? array_values( array_unique( $value ) ) : array( 'page', 'post' );
	}

	/* -------------------------------------------------------------- assets */

	public function assets( $hook ) {
		if ( false === strpos( $hook, 'geo-site-brain' ) && false === strpos( $hook, 'gsb-' ) ) {
			return;
		}
		wp_enqueue_style( 'gsb-admin', GSB_PLUGIN_URL . 'assets/css/admin.css', array(), GSB_VERSION );
		wp_enqueue_script( 'gsb-admin', GSB_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), GSB_VERSION, true );
		// Knowledge-graph map renderer (dependency-free; no-ops without #gsb-graph).
		wp_enqueue_script( 'gsb-graph', GSB_PLUGIN_URL . 'assets/js/graph.js', array(), GSB_VERSION, true );
		wp_localize_script( 'gsb-admin', 'GSB', array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'strings' => array(
				// Scan phase labels shown in the progress bar.
				'phase_scanning'    => __( 'Scanning content…', 'geo-site-brain' ),
				'phase_chunks'      => __( 'Creating chunks…', 'geo-site-brain' ),
				'phase_embedding'   => __( 'Generating embeddings…', 'geo-site-brain' ),
				'phase_graph'       => __( 'Building knowledge graph…', 'geo-site-brain' ),
				'phase_visibility'  => __( 'Generating visibility data…', 'geo-site-brain' ),
				'phase_fixes'       => __( 'Generating fix queue…', 'geo-site-brain' ),
				// Legacy keys kept so any cached JS still works.
				'scanning'          => __( 'Scanning content…', 'geo-site-brain' ),
				'embedding'         => __( 'Generating embeddings…', 'geo-site-brain' ),
				'understanding'     => __( 'Building knowledge graph…', 'geo-site-brain' ),
				'done'              => __( 'Done.', 'geo-site-brain' ),
				'thinking'          => __( 'Thinking…', 'geo-site-brain' ),
				'error'             => __( 'Something went wrong.', 'geo-site-brain' ),
				'no_openai'         => __( 'Content scanned and scored. Add an OpenAI key in Settings to generate embeddings for semantic search and the chat agent.', 'geo-site-brain' ),
				// Fix Queue UI (Fix 9 — failed section created dynamically).
				'fixes_failed'      => __( 'Fix failed', 'geo-site-brain' ),
				'retry'             => __( 'Retry Fix', 'geo-site-brain' ),
				'dismiss'           => __( 'Dismiss', 'geo-site-brain' ),
			),
		) );
	}

	/* --------------------------------------------------------------- guards */

	private function guard() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'geo-site-brain' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	/* ----------------------------------------------------------- AJAX: scan */

	public function ajax_start_scan() {
		$this->guard();
		$total = GSB_Indexer::get_instance()->start_full_scan();
		wp_send_json_success( array( 'total' => $total ) );
	}

	public function ajax_scan_step() {
		$this->guard();
		$per  = isset( $_POST['per'] ) ? absint( $_POST['per'] ) : 3;
		$prog = GSB_Indexer::get_instance()->scan_step( $per );
		// Recommendations are rebuilt once after embeddings finish (see the JS
		// scan loop), so duplicate/overlap detection runs against real vectors.
		// Rebuilding here too would be wasted work and produce stale overlaps.
		wp_send_json_success( $prog );
	}

	public function ajax_embed_step() {
		$this->guard();
		$embedded = GSB_Indexer::get_instance()->embed_pending();
		$prog     = GSB_Indexer::get_instance()->progress();
		$prog['embedded_now'] = $embedded;
		$prog['has_openai']   = GSB_Settings::has_openai();
		wp_send_json_success( $prog );
	}

	public function ajax_progress() {
		$this->guard();
		wp_send_json_success( GSB_Indexer::get_instance()->progress() );
	}

	public function ajax_reindex_post() {
		$this->guard();
		$pid = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $pid ) {
			wp_send_json_error( array( 'message' => __( 'No post id.', 'geo-site-brain' ) ) );
		}
		$res = GSB_Indexer::get_instance()->index_post( $pid );
		wp_send_json_success( $res );
	}

	public function ajax_rebuild_recs() {
		$this->guard();
		try {
			GSB_Knowledge_Graph::rebuild_all();
		} catch ( \Throwable $e ) {
			GSB_Logger::error( 'graph', 'Rebuild recommendations failed: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => __( 'Rebuild failed. Check the activity log for details.', 'geo-site-brain' ) ) );
		}
		wp_send_json_success( array( 'count' => count( GSB_Database::get_recommendations( 'open' ) ) ) );
	}

	/**
	 * Post-scan "understanding" pass: build entities, graph, visibility + fixes.
	 * Fix 10: wraps rebuild_all() in a try/catch so internal failures are
	 * surfaced to the UI instead of always returning success.
	 */
	public function ajax_finalize() {
		$this->guard();
		try {
			GSB_Knowledge_Graph::rebuild_all();
		} catch ( \Throwable $e ) {
			GSB_Logger::error( 'graph', 'Knowledge graph rebuild failed: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => __( 'Knowledge graph rebuild failed. Check the activity log for details.', 'geo-site-brain' ) ) );
		}
		wp_send_json_success( array(
			'entities' => array_sum( GSB_Database::entity_counts() ),
			'fixes'    => count( GSB_Database::get_recommendations( 'open' ) ),
		) );
	}

	/**
	 * Apply a fix. On success marks the recommendation as 'applied' and returns
	 * result data. On failure logs the error and marks as 'failed' when the fix
	 * was attempted (not when pre-conditions are missing).
	 */
	public function ajax_apply_fix() {
		$this->guard();
		$id  = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$res = GSB_Fixes::apply( $id );
		if ( is_wp_error( $res ) ) {
			$msg = $res->get_error_message();
			GSB_Logger::error( 'fixes', sprintf( 'Fix #%d failed: %s', $id, $msg ) );
			// Mark as failed only when the fix was attempted but hit a runtime
			// error (not missing pre-conditions like no OpenAI key or no post).
			$runtime_codes = array( 'gsb_no_fix' );
			if ( ! in_array( $res->get_error_code(), $runtime_codes, true ) ) {
				GSB_Database::update_recommendation_status( $id, 'failed' );
			}
			wp_send_json_error( array( 'message' => $msg ) );
		}
		wp_send_json_success( $res );
	}

	/**
	 * Run a live probe against a real model (or all engines that have keys).
	 */
	public function ajax_probe() {
		$this->guard();
		$engine = isset( $_POST['engine'] ) ? sanitize_key( wp_unslash( $_POST['engine'] ) ) : '';
		if ( 'all' === $engine ) {
			$n = GSB_Visibility::probe_all();
			if ( 0 === $n ) {
				wp_send_json_error( array( 'message' => __( 'No engine keys configured. Add at least one in Settings.', 'geo-site-brain' ) ) );
			}
			wp_send_json_success( array( 'probed' => $n ) );
		}
		$res = GSB_Visibility::probe( $engine );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( $res );
	}

	public function ajax_run_competitors() {
		$this->guard();
		if ( empty( GSB_Settings::competitor_urls() ) ) {
			wp_send_json_error( array( 'message' => __( 'Add competitor URLs in Settings first.', 'geo-site-brain' ) ) );
		}
		$n = GSB_Competitors::run();
		wp_send_json_success( array( 'analysed' => $n ) );
	}

	public function ajax_send_digest() {
		$this->guard();
		$res = GSB_Monitor::send_digest( true );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => __( 'Digest email sent.', 'geo-site-brain' ) ) );
	}

	public function ajax_regen_key() {
		$this->guard();
		$which = isset( $_POST['which'] ) ? sanitize_key( wp_unslash( $_POST['which'] ) ) : '';
		if ( 'webhook' === $which ) {
			wp_send_json_success( array( 'value' => GSB_Settings::regenerate_webhook_secret() ) );
		}
		wp_send_json_success( array( 'value' => GSB_Settings::regenerate_api_key() ) );
	}

	public function ajax_narrative() {
		$this->guard();
		$engine = isset( $_POST['engine'] ) ? sanitize_key( wp_unslash( $_POST['engine'] ) ) : '';
		if ( ! in_array( $engine, GSB_Visibility::ENGINES, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown engine.', 'geo-site-brain' ) ) );
		}
		$res = GSB_Visibility::narrative( $engine );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array( 'narrative' => $res ) );
	}

	/**
	 * Update a recommendation status.
	 * Fix 8: returns an error on invalid status instead of silently defaulting
	 * to 'open', which previously turned stray POST values into unexpected resets.
	 */
	public function ajax_rec_status() {
		$this->guard();
		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'No id.', 'geo-site-brain' ) ) );
		}
		$valid = array( 'open', 'in_progress', 'applied', 'manual', 'dismissed', 'failed', 'done' );
		if ( ! in_array( $status, $valid, true ) ) {
			wp_send_json_error( array( 'message' => sprintf(
				/* translators: %s: the invalid status string */
				__( 'Invalid status "%s".', 'geo-site-brain' ),
				$status
			) ) );
		}
		GSB_Database::update_recommendation_status( $id, $status );
		wp_send_json_success();
	}

	/* --------------------------------------------------------- AJAX: tests */

	public function ajax_test_openai() {
		$this->guard();
		$res = ( new GSB_OpenAI() )->test();
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => __( 'OpenAI connected — embeddings are working.', 'geo-site-brain' ) ) );
	}

	public function ajax_test_neon() {
		$this->guard();
		$res = ( new GSB_Vector_Store() )->test();
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => __( 'Neon connected — pgvector table is ready.', 'geo-site-brain' ) ) );
	}

	/* ---------------------------------------------------------- AJAX: chat */

	public function ajax_chat() {
		$this->guard();
		$q = isset( $_POST['question'] ) ? sanitize_textarea_field( wp_unslash( $_POST['question'] ) ) : '';
		$res = ( new GSB_Agent() )->ask( $q );
		wp_send_json_success( $res );
	}

	/* --------------------------------------------------------------- views */

	public function view_dashboard() { $this->render( 'dashboard' ); }
	public function view_knowledge_graph() { $this->render( 'knowledge-graph' ); }
	public function view_scan() { $this->render( 'scan' ); }
	public function view_visibility() { $this->render( 'visibility' ); }
	public function view_scores() { $this->render( 'scores' ); }
	public function view_recommendations() { $this->render( 'recommendations' ); }
	public function view_chat() { $this->render( 'chat' ); }
	public function view_competitors() { $this->render( 'competitors' ); }
	public function view_reports() { $this->render( 'reports' ); }
	public function view_settings() { $this->render( 'settings' ); }

	private function render( $view ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'geo-site-brain' ) );
		}
		$file = GSB_PLUGIN_DIR . 'includes/views/' . $view . '.php';
		if ( file_exists( $file ) ) {
			include $file;
		}
	}
}

