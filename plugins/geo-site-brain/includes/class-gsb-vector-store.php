<?php
/**
 * Vector storage. Primary backend is Neon (serverless Postgres + pgvector),
 * reached from PHP over a TLS Postgres connection using the PDO pgsql driver.
 *
 * If Neon is disabled, not configured, the pgsql PDO driver is missing, or the
 * connection fails, the store transparently falls back to the local MySQL
 * `gsb_chunks.embedding` JSON column and computes cosine similarity in PHP.
 * Both paths expose the same upsert()/search()/delete() API, and every chunk
 * embedding is mirrored locally so search keeps working if Neon goes away.
 *
 * The Neon connection string is read from options and is NEVER returned to the
 * browser.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSB_Vector_Store {

	const NEON_TABLE = 'gsb_chunks';

	// Above this many local embeddings, nudge the admin toward Neon.
	const LOCAL_SEARCH_WARN = 5000;

	/** @var PDO|null */
	private $pdo = null;
	private $schema_ready = false;
	private $last_backend = 'local';

	/* -------------------------------------------------------- capability check */

	public static function pgsql_available() {
		return class_exists( 'PDO' ) && in_array( 'pgsql', PDO::getAvailableDrivers(), true );
	}

	public function use_neon() {
		return GSB_Settings::neon_active() && self::pgsql_available();
	}

	public function last_backend() {
		return $this->last_backend;
	}

	private function site_key() {
		return wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'site';
	}

	/* --------------------------------------------------------------- Neon conn */

	/**
	 * Parse a libpq/URI connection string into a PDO DSN + user/pass. Accepts
	 * the `postgresql://user:pass@host:port/db?sslmode=require` form Neon hands
	 * out. Returns array|WP_Error.
	 */
	private function parse_dsn( $conn ) {
		$conn = trim( (string) $conn );
		if ( '' === $conn ) {
			return new WP_Error( 'gsb_neon_dsn', __( 'Neon connection string is empty.', 'geo-site-brain' ) );
		}
		$parts = wp_parse_url( $conn );
		if ( empty( $parts['host'] ) ) {
			return new WP_Error( 'gsb_neon_dsn', __( 'Could not parse the Neon connection string.', 'geo-site-brain' ) );
		}
		$host = $parts['host'];
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 5432;
		$db   = isset( $parts['path'] ) ? ltrim( $parts['path'], '/' ) : '';
		$user = isset( $parts['user'] ) ? rawurldecode( $parts['user'] ) : '';
		$pass = isset( $parts['pass'] ) ? rawurldecode( $parts['pass'] ) : '';

		$sslmode = 'require';
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $q );
			if ( ! empty( $q['sslmode'] ) ) {
				$sslmode = preg_replace( '/[^a-z\-]/', '', strtolower( $q['sslmode'] ) );
			}
		}

		$dsn = sprintf( 'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s', $host, $port, $db, $sslmode );
		return array( 'dsn' => $dsn, 'user' => $user, 'pass' => $pass );
	}

	/**
	 * Lazily connect to Neon. Returns PDO or WP_Error. Cached per request.
	 */
	private function connect() {
		if ( $this->pdo instanceof PDO ) {
			return $this->pdo;
		}
		if ( ! self::pgsql_available() ) {
			return new WP_Error( 'gsb_no_pgsql', __( 'The PDO pgsql driver is not installed on this server.', 'geo-site-brain' ) );
		}
		$parsed = $this->parse_dsn( GSB_Settings::get( 'neon_dsn' ) );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		try {
			$pdo = new PDO( $parsed['dsn'], $parsed['user'], $parsed['pass'], array(
				PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_TIMEOUT            => 15,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			) );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'gsb_neon_connect', $e->getMessage() );
		}
		$this->pdo = $pdo;
		return $pdo;
	}

	/**
	 * Ensure the pgvector extension, table and index exist. Idempotent.
	 *
	 * @return true|WP_Error
	 */
	private function ensure_schema( PDO $pdo ) {
		if ( $this->schema_ready ) {
			return true;
		}
		try {
			$pdo->exec( 'CREATE EXTENSION IF NOT EXISTS vector' );
			$pdo->exec( 'CREATE TABLE IF NOT EXISTS ' . self::NEON_TABLE . ' (
				id bigint PRIMARY KEY,
				site text NOT NULL,
				post_id bigint,
				url text,
				content_type text,
				section_type text,
				chunk_text text,
				embedding vector(' . (int) GSB_EMBED_DIM . '),
				indexed_at timestamptz DEFAULT now()
			)' );
			// HNSW index for cosine distance; ignore if the pgvector build is too
			// old to support HNSW (falls back to a sequential scan, still correct).
			try {
				$pdo->exec( 'CREATE INDEX IF NOT EXISTS gsb_chunks_embedding_idx ON ' . self::NEON_TABLE . ' USING hnsw (embedding vector_cosine_ops)' );
			} catch ( \Throwable $e ) {
				// non-fatal
				unset( $e );
			}
		} catch ( \Throwable $e ) {
			return new WP_Error( 'gsb_neon_schema', $e->getMessage() );
		}
		$this->schema_ready = true;
		return true;
	}

	/** Format a float[] as a pgvector literal: [0.1,0.2,...] */
	private function to_pgvector( array $vec ) {
		return '[' . implode( ',', array_map( static function ( $f ) {
			return rtrim( rtrim( sprintf( '%.7f', (float) $f ), '0' ), '.' );
		}, $vec ) ) . ']';
	}

	/* ----------------------------------------------------------------- upsert */

	/**
	 * Store a chunk's embedding. Always writes the local JSON copy (via the
	 * caller's DB layer) AND pushes to Neon when active. Returns the vector_ref
	 * used in Neon (the chunk id) or null when only local.
	 *
	 * @param object $chunk  row from gsb_chunks
	 * @param array  $vector float[]
	 * @return string|null|WP_Error
	 */
	public function upsert( $chunk, array $vector ) {
		if ( ! $this->use_neon() ) {
			$this->last_backend = 'local';
			return null;
		}
		$pdo = $this->connect();
		if ( is_wp_error( $pdo ) ) {
			GSB_Logger::warning( 'neon', 'Neon connect failed, using local store: ' . $pdo->get_error_message() );
			$this->last_backend = 'local';
			return null;
		}
		$schema = $this->ensure_schema( $pdo );
		if ( is_wp_error( $schema ) ) {
			GSB_Logger::warning( 'neon', 'Neon schema error, using local store: ' . $schema->get_error_message() );
			$this->last_backend = 'local';
			return null;
		}
		try {
			$stmt = $pdo->prepare( 'INSERT INTO ' . self::NEON_TABLE . '
				(id, site, post_id, url, content_type, section_type, chunk_text, embedding)
				VALUES (:id, :site, :post_id, :url, :ctype, :stype, :text, :emb)
				ON CONFLICT (id) DO UPDATE SET
					site = EXCLUDED.site, post_id = EXCLUDED.post_id, url = EXCLUDED.url,
					content_type = EXCLUDED.content_type, section_type = EXCLUDED.section_type,
					chunk_text = EXCLUDED.chunk_text, embedding = EXCLUDED.embedding,
					indexed_at = now()' );
			$stmt->execute( array(
				':id'     => (int) $chunk->id,
				':site'   => $this->site_key(),
				':post_id'=> (int) $chunk->post_id,
				':url'    => (string) $chunk->url,
				':ctype'  => (string) $chunk->content_type,
				':stype'  => (string) $chunk->section_type,
				':text'   => (string) $chunk->chunk_text,
				':emb'    => $this->to_pgvector( $vector ),
			) );
			$this->last_backend = 'neon';
			return (string) $chunk->id;
		} catch ( \Throwable $e ) {
			GSB_Logger::warning( 'neon', 'Neon upsert failed, kept local copy: ' . $e->getMessage() );
			$this->last_backend = 'local';
			return null;
		}
	}

	/* ----------------------------------------------------------------- search */

	/**
	 * Top-k nearest chunks to a query vector. Returns rows with id, post_id,
	 * url, section_type, chunk_text, score (0..1 cosine similarity).
	 *
	 * @param array $query_vec float[]
	 * @return array
	 */
	public function search( array $query_vec, $k = 8 ) {
		$k = max( 1, min( 50, (int) $k ) );

		if ( $this->use_neon() ) {
			$rows = $this->search_neon( $query_vec, $k );
			if ( is_array( $rows ) ) {
				$this->last_backend = 'neon';
				return $rows;
			}
			// fall through to local on error
		}
		$this->last_backend = 'local';
		return $this->search_local( $query_vec, $k );
	}

	private function search_neon( array $query_vec, $k ) {
		$pdo = $this->connect();
		if ( is_wp_error( $pdo ) ) {
			return null;
		}
		$schema = $this->ensure_schema( $pdo );
		if ( is_wp_error( $schema ) ) {
			return null;
		}
		try {
			$stmt = $pdo->prepare( 'SELECT id, post_id, url, content_type, section_type, chunk_text,
				1 - (embedding <=> CAST(:q AS vector)) AS score
				FROM ' . self::NEON_TABLE . '
				WHERE site = :site
				ORDER BY embedding <=> CAST(:q AS vector)
				LIMIT ' . (int) $k );
			$stmt->execute( array(
				':q'    => $this->to_pgvector( $query_vec ),
				':site' => $this->site_key(),
			) );
			$out = array();
			foreach ( $stmt->fetchAll() as $r ) {
				$out[] = array(
					'id'           => (int) $r['id'],
					'post_id'      => (int) $r['post_id'],
					'url'          => (string) $r['url'],
					'content_type' => (string) $r['content_type'],
					'section_type' => (string) $r['section_type'],
					'chunk_text'   => (string) $r['chunk_text'],
					'score'        => (float) $r['score'],
				);
			}
			return $out;
		} catch ( \Throwable $e ) {
			GSB_Logger::warning( 'neon', 'Neon search failed, using local: ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * Local fallback: cosine similarity in PHP over the JSON embeddings stored
	 * in gsb_chunks. Fine for small/medium sites; Neon is recommended at scale.
	 */
	private function search_local( array $query_vec, $k ) {
		global $wpdb;
		$table = GSB_Database::table( 'chunks' );

		// The local backend ranks in PHP, so it must read every embedded row.
		// That's fine for small/medium sites but gets heavy on large ones — warn
		// (throttled) and point to Neon rather than silently straining the host.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE embedded = 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $count > self::LOCAL_SEARCH_WARN && ! get_transient( 'gsb_local_scale_warned' ) ) {
			set_transient( 'gsb_local_scale_warned', 1, DAY_IN_SECONDS );
			GSB_Logger::warning( 'search', sprintf(
				'Local vector search is ranking %d embeddings in PHP. For better performance at this scale, enable Neon (pgvector) in Settings.',
				$count
			) );
		}

		$rows  = $wpdb->get_results( "SELECT id, post_id, url, content_type, section_type, chunk_text, embedding FROM {$table} WHERE embedded = 1 AND embedding IS NOT NULL" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$qnorm = $this->norm( $query_vec );
		if ( $qnorm <= 0 ) {
			return array();
		}

		$scored = array();
		foreach ( $rows as $r ) {
			$vec = json_decode( (string) $r->embedding, true );
			if ( ! is_array( $vec ) || empty( $vec ) ) {
				continue;
			}
			$score = $this->cosine( $query_vec, $vec, $qnorm );
			$scored[] = array(
				'id'           => (int) $r->id,
				'post_id'      => (int) $r->post_id,
				'url'          => (string) $r->url,
				'content_type' => (string) $r->content_type,
				'section_type' => (string) $r->section_type,
				'chunk_text'   => (string) $r->chunk_text,
				'score'        => $score,
			);
		}
		usort( $scored, static function ( $a, $b ) {
			return $b['score'] <=> $a['score'];
		} );
		return array_slice( $scored, 0, $k );
	}

	private function norm( array $v ) {
		$s = 0.0;
		foreach ( $v as $x ) {
			$s += $x * $x;
		}
		return sqrt( $s );
	}

	private function cosine( array $a, array $b, $anorm = null ) {
		$dot = 0.0;
		$bn  = 0.0;
		$n   = min( count( $a ), count( $b ) );
		for ( $i = 0; $i < $n; $i++ ) {
			$dot += $a[ $i ] * $b[ $i ];
			$bn  += $b[ $i ] * $b[ $i ];
		}
		$an = null === $anorm ? $this->norm( $a ) : $anorm;
		$bn = sqrt( $bn );
		if ( $an <= 0 || $bn <= 0 ) {
			return 0.0;
		}
		return $dot / ( $an * $bn );
	}

	/* ----------------------------------------------------------------- delete */

	public function delete_ids( array $ids ) {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) || ! $this->use_neon() ) {
			return;
		}
		$pdo = $this->connect();
		if ( is_wp_error( $pdo ) ) {
			return;
		}
		try {
			$in = implode( ',', array_fill( 0, count( $ids ), '?' ) );
			$stmt = $pdo->prepare( 'DELETE FROM ' . self::NEON_TABLE . " WHERE id IN ($in)" );
			$stmt->execute( $ids );
		} catch ( \Throwable $e ) {
			GSB_Logger::warning( 'neon', 'Neon delete failed: ' . $e->getMessage() );
		}
	}

	public function delete_post( $post_id ) {
		if ( ! $this->use_neon() ) {
			return;
		}
		$pdo = $this->connect();
		if ( is_wp_error( $pdo ) ) {
			return;
		}
		try {
			$stmt = $pdo->prepare( 'DELETE FROM ' . self::NEON_TABLE . ' WHERE post_id = ? AND site = ?' );
			$stmt->execute( array( (int) $post_id, $this->site_key() ) );
		} catch ( \Throwable $e ) {
			GSB_Logger::warning( 'neon', 'Neon delete_post failed: ' . $e->getMessage() );
		}
	}

	/* ------------------------------------------------------------------- test */

	/**
	 * Connect + ensure schema, used by the "Test connection" button.
	 *
	 * @return true|WP_Error
	 */
	public function test() {
		if ( ! self::pgsql_available() ) {
			return new WP_Error( 'gsb_no_pgsql', __( 'The PDO pgsql driver is not installed, so Neon cannot be used. Embeddings will be stored locally.', 'geo-site-brain' ) );
		}
		$pdo = $this->connect();
		if ( is_wp_error( $pdo ) ) {
			return $pdo;
		}
		return $this->ensure_schema( $pdo );
	}
}
