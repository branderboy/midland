<?php
/**
 * Indexer: orchestrates scan → chunk → store → embed, wires the WordPress
 * content lifecycle (save/delete), runs the weekly cron reindex, and drives the
 * resumable full-site scan used by the admin progress bar.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSB_Indexer {

	const STATE_QUEUE  = 'scan_queue';   // remaining post ids (admin progress bar)
	const STATE_TOTAL  = 'scan_total';
	const STATE_DONE   = 'scan_done';
	const STATE_LAST   = 'last_full_reindex';

	// Cron reindex runs on its own queue so it never collides with a manual
	// admin scan, and is time-boxed + resumable so it can't time out the host.
	const STATE_CRON_QUEUE = 'cron_queue';
	const STATE_CRON_PHASE = 'cron_phase';
	const CRON_TIME_BUDGET = 20; // seconds of work per cron tick

	/** @var GSB_Indexer|null */
	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_hooks() {
		// On publish/update, refresh that post's index asynchronously so the
		// editor save stays fast.
		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 3 );
		add_action( 'before_delete_post', array( $this, 'on_delete_post' ) );
		add_action( 'wp_trash_post', array( $this, 'on_delete_post' ) );

		// Async single-post index + weekly full reindex (+ its resumable continuation).
		add_action( GSB_CRON_POST, array( $this, 'index_post' ) );
		add_action( GSB_CRON_REINDEX, array( $this, 'run_weekly_reindex' ) );
		add_action( GSB_CRON_CONTINUE, array( $this, 'process_reindex_queue' ) );
	}

	/* --------------------------------------------------------------- lifecycle */

	public function on_save_post( $post_id, $post, $update ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'publish' !== get_post_status( $post_id ) ) {
			return;
		}
		if ( ! in_array( get_post_type( $post_id ), GSB_Scanner::scannable_post_types(), true ) ) {
			return;
		}
		// Defer the heavy work (scan + embeddings) to a near-immediate cron tick.
		if ( ! wp_next_scheduled( GSB_CRON_POST, array( (int) $post_id ) ) ) {
			wp_schedule_single_event( time() + 10, GSB_CRON_POST, array( (int) $post_id ) );
		}
	}

	public function on_delete_post( $post_id ) {
		$ids = GSB_Database::delete_post_chunks( $post_id );
		GSB_Database::delete_score( $post_id );
		$store = new GSB_Vector_Store();
		$store->delete_post( $post_id );
		if ( ! empty( $ids ) ) {
			$store->delete_ids( $ids );
		}
		GSB_Logger::info( 'index', sprintf( 'Removed index for post #%d.', $post_id ) );
	}

	/* ----------------------------------------------------- single-post index */

	/**
	 * Scan, chunk, store and embed one post, then score it. Used by the async
	 * hook, the per-post "reindex" button, and the full scan.
	 *
	 * @return array stats
	 */
	public function index_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return array( 'chunks' => 0, 'embedded' => 0, 'skipped' => true );
		}

		$data   = GSB_Scanner::analyze( $post );
		$chunks = GSB_Scanner::build_chunks( $data );

		$keep = array();
		foreach ( $chunks as $i => $chunk ) {
			$text = $chunk['text'];
			$keep[] = GSB_Database::upsert_chunk( array(
				'post_id'        => $data['post_id'],
				'url'            => $data['url'],
				'content_type'   => $data['content_type'],
				'section_type'   => $chunk['section_type'],
				'chunk_index'    => $i,
				'chunk_text'     => $text,
				'content_hash'   => sha1( $text ),
				'token_estimate' => (int) ceil( mb_strlen( $text ) / 4 ),
			) );
		}

		// Drop chunks that no longer exist after the edit, both locally + Neon.
		$stale = GSB_Database::prune_post_chunks( $post_id, $keep );
		if ( ! empty( $stale ) ) {
			( new GSB_Vector_Store() )->delete_ids( $stale );
		}

		// Embed any chunks for this post that need it.
		$embedded = $this->embed_post_chunks( $post_id );

		// Score the page from the fresh analysis.
		GSB_Scorer::score_post( $post, $data );

		GSB_Logger::info( 'index', sprintf( 'Indexed "%s" — %d chunks, %d embedded.', get_the_title( $post ), count( $keep ), $embedded ) );

		return array( 'chunks' => count( $keep ), 'embedded' => $embedded, 'skipped' => false );
	}

	/**
	 * Embed the unembedded chunks belonging to a single post.
	 */
	private function embed_post_chunks( $post_id ) {
		if ( ! GSB_Settings::has_openai() ) {
			return 0;
		}
		global $wpdb;
		$table = GSB_Database::table( 'chunks' );
		$rows  = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE post_id = %d AND embedded = 0",
			$post_id
		) );
		return $this->embed_rows( $rows );
	}

	/* ------------------------------------------------------------ embed batch */

	/**
	 * Embed up to $batch unembedded chunks site-wide. Returns the number
	 * embedded this call. Used by the scan progress loop and cron.
	 */
	public function embed_pending( $batch = null ) {
		if ( ! GSB_Settings::has_openai() ) {
			return 0;
		}
		$batch = $batch ? (int) $batch : (int) GSB_Settings::get( 'embed_batch', 64 );
		$ids   = GSB_Database::get_unembedded_ids( $batch );
		if ( empty( $ids ) ) {
			return 0;
		}
		global $wpdb;
		$table = GSB_Database::table( 'chunks' );
		$in    = implode( ',', array_map( 'intval', $ids ) );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $this->embed_rows( $rows );
	}

	/**
	 * Embed a set of chunk rows in one OpenAI call and persist locally + Neon.
	 */
	private function embed_rows( $rows ) {
		if ( empty( $rows ) ) {
			return 0;
		}
		$openai = new GSB_OpenAI();
		$texts  = array();
		foreach ( $rows as $r ) {
			$texts[] = $r->chunk_text;
		}
		$vectors = $openai->embed( $texts );
		if ( is_wp_error( $vectors ) ) {
			GSB_Logger::error( 'embed', 'Embedding failed: ' . $vectors->get_error_message() );
			return 0;
		}

		$store = new GSB_Vector_Store();
		$count = 0;
		foreach ( $rows as $i => $r ) {
			if ( ! isset( $vectors[ $i ] ) ) {
				continue;
			}
			$vec = $vectors[ $i ];
			$ref = $store->upsert( $r, $vec );
			$ref = is_wp_error( $ref ) ? null : $ref;
			GSB_Database::set_chunk_embedding( $r->id, $vec, $ref );
			$count++;
		}
		return $count;
	}

	/* ------------------------------------------------------- full-site scan */

	/**
	 * Reset and prime the resumable scan. Returns total post count.
	 */
	public function start_full_scan() {
		$ids = GSB_Scanner::all_post_ids();
		GSB_Database::set_state( self::STATE_QUEUE, $ids );
		GSB_Database::set_state( self::STATE_TOTAL, count( $ids ) );
		GSB_Database::set_state( self::STATE_DONE, 0 );
		GSB_Logger::info( 'scan', sprintf( 'Started full scan of %d items.', count( $ids ) ) );
		return count( $ids );
	}

	/**
	 * Process the next $per posts of the scan (chunk + store + score). Embedding
	 * runs as a separate step so progress stays responsive. Returns progress.
	 */
	public function scan_step( $per = 3 ) {
		$queue = (array) GSB_Database::get_state( self::STATE_QUEUE, array() );
		$total = (int) GSB_Database::get_state( self::STATE_TOTAL, 0 );
		$done  = (int) GSB_Database::get_state( self::STATE_DONE, 0 );

		$slice = array_splice( $queue, 0, max( 1, (int) $per ) );
		foreach ( $slice as $pid ) {
			$post = get_post( $pid );
			if ( ! $post || 'publish' !== $post->post_status ) {
				$done++;
				continue;
			}
			$data   = GSB_Scanner::analyze( $post );
			$chunks = GSB_Scanner::build_chunks( $data );
			$keep   = array();
			foreach ( $chunks as $i => $chunk ) {
				$text   = $chunk['text'];
				$keep[] = GSB_Database::upsert_chunk( array(
					'post_id'        => $data['post_id'],
					'url'            => $data['url'],
					'content_type'   => $data['content_type'],
					'section_type'   => $chunk['section_type'],
					'chunk_index'    => $i,
					'chunk_text'     => $text,
					'content_hash'   => sha1( $text ),
					'token_estimate' => (int) ceil( mb_strlen( $text ) / 4 ),
				) );
			}
			$stale = GSB_Database::prune_post_chunks( $pid, $keep );
			if ( ! empty( $stale ) ) {
				( new GSB_Vector_Store() )->delete_ids( $stale );
			}
			GSB_Scorer::score_post( $post, $data );
			$done++;
		}

		GSB_Database::set_state( self::STATE_QUEUE, array_values( $queue ) );
		GSB_Database::set_state( self::STATE_DONE, $done );

		$complete = empty( $queue );
		if ( $complete ) {
			GSB_Database::set_state( self::STATE_LAST, current_time( 'mysql' ) );
		}

		return array(
			'phase'    => 'scan',
			'total'    => $total,
			'done'     => min( $done, $total ),
			'complete' => $complete,
		);
	}

	/**
	 * Progress snapshot for the dashboard / poller.
	 */
	public function progress() {
		$stats = GSB_Database::chunk_stats();
		return array(
			'scan_total'   => (int) GSB_Database::get_state( self::STATE_TOTAL, 0 ),
			'scan_done'    => (int) GSB_Database::get_state( self::STATE_DONE, 0 ),
			'chunks'       => $stats['chunks'],
			'embedded'     => $stats['embedded'],
			'unembedded'   => max( 0, $stats['chunks'] - $stats['embedded'] ),
			'posts'        => $stats['posts'],
			'last_reindex' => GSB_Database::get_state( self::STATE_LAST, '' ),
		);
	}

	/* ----------------------------------------------------------------- cron */

	/**
	 * Weekly entry point. Kicks off (or resets) the resumable reindex queue,
	 * then processes the first time-boxed slice.
	 */
	public function run_weekly_reindex() {
		if ( ! (int) GSB_Settings::get( 'weekly_reindex', 1 ) ) {
			return;
		}
		// Start fresh: prime the cron queue and mark the scan phase.
		GSB_Database::set_state( self::STATE_CRON_QUEUE, GSB_Scanner::all_post_ids() );
		GSB_Database::set_state( self::STATE_CRON_PHASE, 'scan' );
		$this->process_reindex_queue();
	}

	/**
	 * Process one time-boxed slice of the cron reindex, then reschedule itself
	 * until the queue drains. This keeps each request short so large sites or
	 * limited shared hosting never hit max_execution_time / memory limits.
	 */
	public function process_reindex_queue() {
		$phase = (string) GSB_Database::get_state( self::STATE_CRON_PHASE, '' );
		if ( '' === $phase ) {
			return; // nothing in progress
		}

		$start = microtime( true );

		if ( 'scan' === $phase ) {
			$queue = (array) GSB_Database::get_state( self::STATE_CRON_QUEUE, array() );
			// index_post() embeds that post's chunks inline, so the scan phase
			// also produces embeddings — no separate embed phase needed.
			while ( ! empty( $queue ) && ( microtime( true ) - $start ) < self::CRON_TIME_BUDGET ) {
				$pid = array_shift( $queue );
				try {
					$this->index_post( $pid );
				} catch ( \Throwable $e ) {
					GSB_Logger::error( 'cron', sprintf( 'Reindex of #%d failed: %s', $pid, $e->getMessage() ) );
				}
			}
			GSB_Database::set_state( self::STATE_CRON_QUEUE, array_values( $queue ) );

			if ( ! empty( $queue ) ) {
				$this->reschedule_continue();
				return;
			}
			// Scan complete → move to recommendations.
			$phase = 'recs';
			GSB_Database::set_state( self::STATE_CRON_PHASE, 'recs' );
		}

		if ( 'recs' === $phase ) {
			// Mop up any chunks still awaiting embedding within the time budget.
			while ( ( microtime( true ) - $start ) < self::CRON_TIME_BUDGET && $this->embed_pending() > 0 ) {
				// keep embedding
			}
			$stats = GSB_Database::chunk_stats();
			if ( $stats['chunks'] > $stats['embedded'] && GSB_Settings::has_openai() ) {
				$this->reschedule_continue(); // more embeddings to do
				return;
			}
			// Rebuild the business understanding (entities, graph, visibility, fixes).
			GSB_Knowledge_Graph::rebuild_all();
			GSB_Database::set_state( self::STATE_LAST, current_time( 'mysql' ) );
			GSB_Database::set_state( self::STATE_CRON_PHASE, '' ); // done
			// Email the scheduled AI Visibility digest / drop alert if enabled.
			GSB_Monitor::after_reindex();
			GSB_Logger::info( 'cron', 'Weekly reindex complete.' );
		}
	}

	private function reschedule_continue() {
		if ( ! wp_next_scheduled( GSB_CRON_CONTINUE ) ) {
			wp_schedule_single_event( time() + 60, GSB_CRON_CONTINUE );
		}
	}
}
