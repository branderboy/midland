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

	const STATE_QUEUE  = 'scan_queue';   // remaining post ids
	const STATE_TOTAL  = 'scan_total';
	const STATE_DONE   = 'scan_done';
	const STATE_LAST   = 'last_full_reindex';

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

		// Async single-post index + weekly full reindex.
		add_action( GSB_CRON_POST, array( $this, 'index_post' ) );
		add_action( GSB_CRON_REINDEX, array( $this, 'run_weekly_reindex' ) );
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

	public function run_weekly_reindex() {
		if ( ! (int) GSB_Settings::get( 'weekly_reindex', 1 ) ) {
			return;
		}
		// Walk every post inline (cron has no UI to drive batches). Each call is
		// guarded so one bad post can't abort the run.
		$ids = GSB_Scanner::all_post_ids();
		foreach ( $ids as $pid ) {
			try {
				$this->index_post( $pid );
			} catch ( \Throwable $e ) {
				GSB_Logger::error( 'cron', sprintf( 'Reindex of #%d failed: %s', $pid, $e->getMessage() ) );
			}
		}
		// Make sure any remaining chunks get embedded.
		$guard = 0;
		while ( $this->embed_pending() > 0 && $guard < 200 ) {
			$guard++;
		}
		GSB_Recommendations::rebuild();
		GSB_Database::set_state( self::STATE_LAST, current_time( 'mysql' ) );
		GSB_Logger::info( 'cron', 'Weekly reindex complete.' );
	}
}
