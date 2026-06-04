<?php
/**
 * Database layer: table schema, install/upgrade, and CRUD helpers for the
 * local MySQL metadata store. Vectors also live in Neon (see GSB_Vector_Store)
 * but the chunk table keeps a JSON copy for the local fallback and as a cache.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSB_Database {

	const DB_VERSION_OPTION = 'gsb_db_version';

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'gsb_' . $name;
	}

	/**
	 * Create / migrate all tables. Safe to call repeatedly (dbDelta).
	 */
	public static function install() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$chunks = self::table( 'chunks' );
		dbDelta( "CREATE TABLE {$chunks} (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			post_id bigint(20) NOT NULL DEFAULT 0,
			url text DEFAULT NULL,
			content_type varchar(50) DEFAULT NULL,
			section_type varchar(50) DEFAULT NULL,
			chunk_index int DEFAULT 0,
			chunk_text longtext DEFAULT NULL,
			content_hash char(40) DEFAULT NULL,
			token_estimate int DEFAULT 0,
			embedding longtext DEFAULT NULL,
			vector_ref varchar(191) DEFAULT NULL,
			embedded tinyint(1) DEFAULT 0,
			indexed_at datetime DEFAULT NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			KEY post_id (post_id),
			KEY content_hash (content_hash),
			KEY type_section (content_type, section_type),
			KEY embedded (embedded)
		) {$charset};" );

		$scores = self::table( 'scores' );
		dbDelta( "CREATE TABLE {$scores} (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			post_id bigint(20) NOT NULL DEFAULT 0,
			url text DEFAULT NULL,
			score int DEFAULT 0,
			subscores longtext DEFAULT NULL,
			details longtext DEFAULT NULL,
			scored_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY post_id (post_id),
			KEY score (score)
		) {$charset};" );

		$recs = self::table( 'recommendations' );
		dbDelta( "CREATE TABLE {$recs} (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			post_id bigint(20) NOT NULL DEFAULT 0,
			rec_type varchar(50) DEFAULT NULL,
			priority varchar(10) DEFAULT 'medium',
			title varchar(255) DEFAULT NULL,
			detail longtext DEFAULT NULL,
			status varchar(20) DEFAULT 'open',
			source varchar(20) DEFAULT 'heuristic',
			created_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			KEY post_id (post_id),
			KEY rec_type (rec_type),
			KEY status (status)
		) {$charset};" );

		$logs = self::table( 'logs' );
		dbDelta( "CREATE TABLE {$logs} (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			level varchar(10) DEFAULT 'info',
			context varchar(50) DEFAULT NULL,
			message text DEFAULT NULL,
			created_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			KEY level (level),
			KEY created_at (created_at)
		) {$charset};" );

		$settings = self::table( 'settings' );
		dbDelta( "CREATE TABLE {$settings} (
			setting_key varchar(191) NOT NULL,
			setting_value longtext DEFAULT NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY (setting_key)
		) {$charset};" );

		update_option( self::DB_VERSION_OPTION, GSB_VERSION );
	}

	/**
	 * Cheap version compare on admin load; re-runs install() when behind so the
	 * schema is correct even after a file-only update.
	 */
	public static function maybe_upgrade() {
		if ( version_compare( (string) get_option( self::DB_VERSION_OPTION, '0' ), GSB_VERSION, '>=' ) ) {
			return;
		}
		self::install();
	}

	/* ----------------------------------------------------------------- chunks */

	/**
	 * Insert or update a chunk row keyed by (post_id, section_type, chunk_index).
	 * Returns the chunk id.
	 */
	public static function upsert_chunk( array $data ) {
		global $wpdb;
		$table = self::table( 'chunks' );
		$now   = current_time( 'mysql' );

		$existing = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT id, content_hash FROM {$table} WHERE post_id = %d AND section_type = %s AND chunk_index = %d",
			$data['post_id'],
			$data['section_type'],
			$data['chunk_index']
		) );

		$row = array(
			'post_id'        => (int) $data['post_id'],
			'url'            => $data['url'],
			'content_type'   => $data['content_type'],
			'section_type'   => $data['section_type'],
			'chunk_index'    => (int) $data['chunk_index'],
			'chunk_text'     => $data['chunk_text'],
			'content_hash'   => $data['content_hash'],
			'token_estimate' => (int) $data['token_estimate'],
			'updated_at'     => $now,
		);

		if ( $existing ) {
			// Text changed → invalidate the embedding so it is re-generated.
			if ( $existing->content_hash !== $data['content_hash'] ) {
				$row['embedded']  = 0;
				$row['embedding'] = null;
			}
			$wpdb->update( $table, $row, array( 'id' => $existing->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return (int) $existing->id;
		}
		$row['indexed_at'] = $now;
		$wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	/**
	 * Mark a chunk as embedded and store the local vector copy + Neon ref.
	 */
	public static function set_chunk_embedding( $chunk_id, array $vector, $vector_ref = null ) {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table( 'chunks' ),
			array(
				'embedding'  => wp_json_encode( $vector ),
				'vector_ref' => $vector_ref,
				'embedded'   => 1,
				'indexed_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $chunk_id )
		);
	}

	public static function get_chunk( $chunk_id ) {
		global $wpdb;
		$table = self::table( 'chunks' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $chunk_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function get_chunks_for_post( $post_id ) {
		global $wpdb;
		$table = self::table( 'chunks' );
		return $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE post_id = %d ORDER BY chunk_index ASC",
			$post_id
		) );
	}

	/**
	 * Chunk ids still needing an embedding (changed text or never embedded).
	 */
	public static function get_unembedded_ids( $limit = 64 ) {
		global $wpdb;
		$table = self::table( 'chunks' );
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT id FROM {$table} WHERE embedded = 0 ORDER BY id ASC LIMIT %d",
			$limit
		) ) );
	}

	/**
	 * Remove chunks for a post that are no longer present after a rescan.
	 * $keep_ids are the chunk ids we just upserted.
	 */
	public static function prune_post_chunks( $post_id, array $keep_ids ) {
		global $wpdb;
		$table = self::table( 'chunks' );
		if ( empty( $keep_ids ) ) {
			$stale = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE post_id = %d", $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$placeholders = implode( ',', array_fill( 0, count( $keep_ids ), '%d' ) );
			$params       = array_merge( array( $post_id ), array_map( 'intval', $keep_ids ) );
			$stale        = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$table} WHERE post_id = %d AND id NOT IN ({$placeholders})",
				$params
			) );
		}
		if ( ! empty( $stale ) ) {
			$ids = implode( ',', array_map( 'intval', $stale ) );
			$wpdb->query( "DELETE FROM {$table} WHERE id IN ({$ids})" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $stale ? array_map( 'intval', $stale ) : array();
	}

	public static function delete_post_chunks( $post_id ) {
		global $wpdb;
		$table = self::table( 'chunks' );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE post_id = %d", $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->delete( $table, array( 'post_id' => (int) $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $ids ? array_map( 'intval', $ids ) : array();
	}

	public static function chunk_stats() {
		global $wpdb;
		$table = self::table( 'chunks' );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$emb   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE embedded = 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$posts = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$table} WHERE post_id > 0" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array( 'chunks' => $total, 'embedded' => $emb, 'posts' => $posts );
	}

	/* ---------------------------------------------------------------- scores */

	public static function save_score( $post_id, $url, $score, array $subscores, array $details ) {
		global $wpdb;
		$table = self::table( 'scores' );
		$now   = current_time( 'mysql' );

		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE post_id = %d", $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row      = array(
			'post_id'   => (int) $post_id,
			'url'       => $url,
			'score'     => (int) $score,
			'subscores' => wp_json_encode( $subscores ),
			'details'   => wp_json_encode( $details ),
			'scored_at' => $now,
		);
		if ( $existing ) {
			$wpdb->update( $table, $row, array( 'id' => $existing ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	public static function get_scores( $orderby = 'score', $order = 'ASC', $limit = 200 ) {
		global $wpdb;
		$table   = self::table( 'scores' );
		$orderby = in_array( $orderby, array( 'score', 'scored_at', 'post_id' ), true ) ? $orderby : 'score';
		$order   = ( 'DESC' === strtoupper( $order ) ) ? 'DESC' : 'ASC';
		return $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} ORDER BY {$orderby} {$order} LIMIT %d",
			$limit
		) );
	}

	public static function site_score() {
		global $wpdb;
		$table = self::table( 'scores' );
		$avg   = $wpdb->get_var( "SELECT AVG(score) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return null === $avg ? null : (int) round( $avg );
	}

	public static function delete_score( $post_id ) {
		global $wpdb;
		$wpdb->delete( self::table( 'scores' ), array( 'post_id' => (int) $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/* ------------------------------------------------------- recommendations */

	public static function clear_recommendations( $source = null ) {
		global $wpdb;
		$table = self::table( 'recommendations' );
		if ( $source ) {
			$wpdb->delete( $table, array( 'source' => $source, 'status' => 'open' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$wpdb->query( "DELETE FROM {$table} WHERE status = 'open'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	public static function add_recommendation( array $data ) {
		global $wpdb;
		$wpdb->insert( self::table( 'recommendations' ), array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'post_id'    => (int) ( $data['post_id'] ?? 0 ),
			'rec_type'   => $data['rec_type'] ?? 'general',
			'priority'   => $data['priority'] ?? 'medium',
			'title'      => $data['title'] ?? '',
			'detail'     => $data['detail'] ?? '',
			'status'     => 'open',
			'source'     => $data['source'] ?? 'heuristic',
			'created_at' => current_time( 'mysql' ),
		) );
		return (int) $wpdb->insert_id;
	}

	public static function get_recommendations( $status = 'open' ) {
		global $wpdb;
		$table = self::table( 'recommendations' );
		return $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE status = %s ORDER BY FIELD(priority,'high','medium','low'), id DESC",
			$status
		) );
	}

	public static function update_recommendation_status( $id, $status ) {
		global $wpdb;
		$status = in_array( $status, array( 'open', 'done', 'dismissed' ), true ) ? $status : 'open';
		$wpdb->update( self::table( 'recommendations' ), array( 'status' => $status ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/* ------------------------------------------------------------- settings KV */

	public static function get_state( $key, $default = null ) {
		global $wpdb;
		$table = self::table( 'settings' );
		$val   = $wpdb->get_var( $wpdb->prepare( "SELECT setting_value FROM {$table} WHERE setting_key = %s", $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( null === $val ) {
			return $default;
		}
		$decoded = json_decode( $val, true );
		return null === $decoded ? $val : $decoded;
	}

	public static function set_state( $key, $value ) {
		global $wpdb;
		$table = self::table( 'settings' );
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"INSERT INTO {$table} (setting_key, setting_value, updated_at) VALUES (%s, %s, %s)
			 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)",
			$key,
			wp_json_encode( $value ),
			current_time( 'mysql' )
		) );
	}
}
