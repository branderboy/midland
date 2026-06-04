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

		$ajax = array(
			'gsb_start_scan'   => 'ajax_start_scan',
			'gsb_scan_step'    => 'ajax_scan_step',
			'gsb_embed_step'   => 'ajax_embed_step',
			'gsb_finalize'     => 'ajax_finalize',
			'gsb_progress'     => 'ajax_progress',
			'gsb_reindex_post' => 'ajax_reindex_post',
			'gsb_rebuild_recs' => 'ajax_rebuild_recs',
			'gsb_rec_status'   => 'ajax_rec_status',
			'gsb_apply_fix'    => 'ajax_apply_fix',
			'gsb_narrative'    => 'ajax_narrative',
			'gsb_probe'        => 'ajax_probe',
			'gsb_run_competitors' => 'ajax_run_competitors',
			'gsb_send_digest'  => 'ajax_send_digest',
			'gsb_regen_key'    => 'ajax_regen_key',
			'gsb_test_openai'  => 'ajax_test_openai',
			'gsb_test_neon'    => 'ajax_test_neon',
			'gsb_chat'         => 'ajax_chat',
		);
		foreach ( $ajax as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( $this, $method ) );
		}
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
		// Labelled as a product loop — Understand → Scan → Scorecard → Fix →
		// Ask → Setup — instead of developer nouns. Slugs are unchanged so links,
		// bookmarks and AJAX keep working.
		$pages = array(
			'geo-site-brain'      => array( __( 'Dashboard', 'geo-site-brain' ), 'view_dashboard' ),
			'gsb-knowledge-graph' => array( __( 'Knowledge Graph', 'geo-site-brain' ), 'view_knowledge_graph' ),
			'gsb-scan'            => array( __( 'Scan Website', 'geo-site-brain' ), 'view_scan' ),
			'gsb-visibility'      => array( __( 'AI Visibility Gaps', 'geo-site-brain' ), 'view_visibility' ),
			'gsb-recommendations' => array( __( 'Fix Queue', 'geo-site-brain' ), 'view_recommendations' ),
			'gsb-chat'            => array( __( 'Ask My Website', 'geo-site-brain' ), 'view_chat' ),
			'gsb-competitors'     => array( __( 'Competitors', 'geo-site-brain' ), 'view_competitors' ),
			'gsb-reports'         => array( __( 'Reports', 'geo-site-brain' ), 'view_reports' ),
			'gsb-scores'          => array( __( 'Page Scorecard', 'geo-site-brain' ), 'view_scores' ),
			'gsb-settings'        => array( __( 'Settings', 'geo-site-brain' ), 'view_settings' ),
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
				'scanning'      => __( 'Reading your website…', 'geo-site-brain' ),
				'embedding'     => __( 'Building knowledge…', 'geo-site-brain' ),
				'understanding' => __( 'Mapping your business…', 'geo-site-brain' ),
				'done'          => __( 'Done.', 'geo-site-brain' ),
				'thinking'  => __( 'Thinking…', 'geo-site-brain' ),
				'error'     => __( 'Something went wrong.', 'geo-site-brain' ),
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
		GSB_Knowledge_Graph::rebuild_all();
		wp_send_json_success( array( 'count' => count( GSB_Database::get_recommendations( 'open' ) ) ) );
	}

	/**
	 * Post-scan "understanding" pass: build entities, graph, visibility + fixes.
	 */
	public function ajax_finalize() {
		$this->guard();
		GSB_Knowledge_Graph::rebuild_all();
		wp_send_json_success( array(
			'entities' => array_sum( GSB_Database::entity_counts() ),
			'fixes'    => count( GSB_Database::get_recommendations( 'open' ) ),
		) );
	}

	public function ajax_apply_fix() {
		$this->guard();
		$id  = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$res = GSB_Fixes::apply( $id );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
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

	public function ajax_rec_status() {
		$this->guard();
		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'No id.', 'geo-site-brain' ) ) );
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
