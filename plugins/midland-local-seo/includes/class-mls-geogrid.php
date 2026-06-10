<?php
/**
 * Geo-Grid Rank Tracker (Local Falcon style).
 *
 * NxN keyword rank scan around a center lat/lng. Runs weekly via cron + on
 * demand via an admin button. Each grid cell is one DataForSEO SERP call,
 * processed asynchronously (one cell per cron tick) so we never block on N×N
 * synchronous API calls.
 *
 * @package Midland_Local_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Geo-grid module.
 */
class MLS_Geogrid {

	const KM_PER_LAT_DEGREE = 111.32;
	const CRON_HOOK         = 'mls_geogrid_weekly_scan';
	const TICK_HOOK         = 'mls_geogrid_process_cell';
	const TICK_DELAY        = 5; // Seconds between cell scans; lets WP-Cron breathe.

	/**
	 * Singleton instance.
	 *
	 * @var MLS_Geogrid|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return MLS_Geogrid
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Bind hooks.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 16 );
		add_action( 'admin_init', array( $this, 'handle_save' ) );
		add_action( 'admin_init', array( $this, 'handle_run_scan' ) );

		add_action( self::CRON_HOOK, array( $this, 'cron_scan' ) );
		add_action( self::TICK_HOOK, array( $this, 'process_next_cell' ), 10, 1 );
		add_action( 'admin_init', array( $this, 'maybe_schedule_cron' ) );
	}

	/**
	 * Create the runs + cells tables. Callable from activation and upgrade.
	 */
	public static function create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}mls_geogrid_runs (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				keyword varchar(255) NOT NULL,
				target_domain varchar(255) NOT NULL,
				center_lat decimal(10,6) NOT NULL,
				center_lng decimal(10,6) NOT NULL,
				grid_size int NOT NULL DEFAULT 5,
				spacing_km decimal(6,2) NOT NULL DEFAULT 1.50,
				cells_total int NOT NULL DEFAULT 0,
				cells_done int NOT NULL DEFAULT 0,
				avg_rank decimal(6,2) DEFAULT NULL,
				in_top10 int NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY keyword (keyword)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}mls_geogrid_cells (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				run_id bigint(20) NOT NULL,
				row_idx int NOT NULL,
				col_idx int NOT NULL,
				lat decimal(10,6) NOT NULL,
				lng decimal(10,6) NOT NULL,
				rank int DEFAULT NULL,
				target_url varchar(500) DEFAULT NULL,
				error_msg varchar(255) DEFAULT NULL,
				scanned_at datetime DEFAULT NULL,
				PRIMARY KEY (id),
				KEY run_id (run_id)
			) $charset;"
		);
	}

	/**
	 * Schedule the weekly scan only when fully configured; clear stale events.
	 */
	public function maybe_schedule_cron() {
		$settings   = get_option( 'mls_geogrid_settings', array() );
		$configured = class_exists( 'MLS_DataForSEO' )
			&& MLS_DataForSEO::is_configured()
			&& ! empty( $settings['keyword'] )
			&& ! empty( $settings['target_domain'] );

		if ( ! $configured ) {
			if ( wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_unschedule_hook( self::CRON_HOOK );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::CRON_HOOK );
		}
	}

	/**
	 * Register the submenu.
	 */
	public function add_menu() {
		add_submenu_page(
			MLS_Plugin::MENU_SLUG,
			__( 'Geo-Grid (Local Falcon)', 'midland-local-seo' ),
			__( 'Geo-Grid', 'midland-local-seo' ),
			'manage_options',
			'mls-geogrid',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Persist geo-grid settings.
	 */
	public function handle_save() {
		if ( ! isset( $_POST['mls_save_geogrid'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$nonce = isset( $_POST['_mls_geogrid_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_mls_geogrid_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mls_save_geogrid' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'midland-local-seo' ) );
		}

		$settings = array(
			'keyword'       => sanitize_text_field( wp_unslash( isset( $_POST['geogrid_keyword'] ) ? $_POST['geogrid_keyword'] : '' ) ),
			'target_domain' => sanitize_text_field( wp_unslash( isset( $_POST['geogrid_target_domain'] ) ? $_POST['geogrid_target_domain'] : '' ) ),
			// Numeric inputs: the (float)/(int) cast fully sanitizes — any
			// non-numeric/slashed content collapses to a number.
			'center_lat'    => (float) ( isset( $_POST['geogrid_center_lat'] ) ? wp_unslash( $_POST['geogrid_center_lat'] ) : 0 ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'center_lng'    => (float) ( isset( $_POST['geogrid_center_lng'] ) ? wp_unslash( $_POST['geogrid_center_lng'] ) : 0 ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'grid_size'     => max( 3, min( 9, (int) ( isset( $_POST['geogrid_grid_size'] ) ? wp_unslash( $_POST['geogrid_grid_size'] ) : 5 ) ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'spacing_km'    => max( 0.5, min( 50, (float) ( isset( $_POST['geogrid_spacing_km'] ) ? wp_unslash( $_POST['geogrid_spacing_km'] ) : 1.5 ) ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'measure'       => ( isset( $_POST['geogrid_measure'] ) && 'organic' === $_POST['geogrid_measure'] ) ? 'organic' : 'map_pack',
		);
		if ( 0 === $settings['grid_size'] % 2 ) {
			$settings['grid_size'] += 1;
		}

		update_option( 'mls_geogrid_settings', $settings );
		wp_safe_redirect( admin_url( 'admin.php?page=mls-geogrid&saved=1' ) );
		exit;
	}

	/**
	 * Manual "Run Scan Now" handler.
	 */
	public function handle_run_scan() {
		if ( ! isset( $_GET['mls_geogrid_run'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mls_geogrid_run' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'midland-local-seo' ) );
		}
		$this->cron_scan();
		wp_safe_redirect( admin_url( 'admin.php?page=mls-geogrid&ran=1' ) );
		exit;
	}

	/**
	 * Entry point: build a run row, queue every cell as pending, schedule the
	 * first tick. Each tick processes one cell.
	 */
	public function cron_scan() {
		$settings = get_option( 'mls_geogrid_settings', array() );
		if ( empty( $settings['keyword'] ) || empty( $settings['target_domain'] ) ) {
			return;
		}
		if ( ! class_exists( 'MLS_DataForSEO' ) || ! MLS_DataForSEO::is_configured() ) {
			return;
		}

		// Guard against overlapping runs: if any cell is still pending (scanned_at
		// IS NULL), a scan is already in progress — do not stack another one.
		global $wpdb;
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mls_geogrid_cells WHERE scanned_at IS NULL" ) > 0 ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}

		$cells = $this->enumerate_cells(
			(float) $settings['center_lat'],
			(float) $settings['center_lng'],
			(int) $settings['grid_size'],
			(float) $settings['spacing_km']
		);

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mls_geogrid_runs',
			array(
				'keyword'       => $settings['keyword'],
				'target_domain' => $settings['target_domain'],
				'center_lat'    => $settings['center_lat'],
				'center_lng'    => $settings['center_lng'],
				'grid_size'     => $settings['grid_size'],
				'spacing_km'    => $settings['spacing_km'],
				'cells_total'   => count( $cells ),
			)
		);
		$run_id = (int) $wpdb->insert_id;
		if ( ! $run_id ) {
			return;
		}

		foreach ( $cells as $cell ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prefix . 'mls_geogrid_cells',
				array(
					'run_id'  => $run_id,
					'row_idx' => $cell['row'],
					'col_idx' => $cell['col'],
					'lat'     => $cell['lat'],
					'lng'     => $cell['lng'],
				)
			);
		}

		if ( ! wp_next_scheduled( self::TICK_HOOK, array( $run_id ) ) ) {
			wp_schedule_single_event( time(), self::TICK_HOOK, array( $run_id ) );
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
		}
	}

	/**
	 * Process one pending cell, then reschedule itself until done. Idempotent.
	 *
	 * @param int $run_id Run identifier.
	 */
	public function process_next_cell( $run_id ) {
		global $wpdb;
		$run_id = (int) $run_id;
		if ( ! $run_id ) {
			return;
		}

		$run = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mls_geogrid_runs WHERE id = %d", $run_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $run ) {
			return;
		}
		if ( ! class_exists( 'MLS_DataForSEO' ) || ! MLS_DataForSEO::is_configured() ) {
			return;
		}

		$cell = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}mls_geogrid_cells WHERE run_id = %d AND scanned_at IS NULL ORDER BY id ASC LIMIT 1",
				$run_id
			)
		);

		if ( ! $cell ) {
			$this->finalize_run( $run_id );
			return;
		}

		// Measurement source is a setting: map_pack (Local Falcon style, the
		// default) or organic Google search results at each coordinate.
		$settings = get_option( 'mls_geogrid_settings', array() );
		$measure  = isset( $settings['measure'] ) && 'organic' === $settings['measure'] ? 'organic' : 'map_pack';

		if ( 'organic' === $measure ) {
			$serp = MLS_DataForSEO::get_serp_at_coordinate(
				$run->keyword,
				(float) $cell->lat,
				(float) $cell->lng
			);
		} else {
			$serp = MLS_DataForSEO::get_local_pack_at_coordinate(
				$run->keyword,
				(float) $cell->lat,
				(float) $cell->lng
			);
			// Plans without the Maps API: fall back to organic rather than
			// returning an empty grid.
			if ( is_wp_error( $serp ) ) {
				$msg     = $serp->get_error_message();
				$is_auth = ( false !== stripos( $msg, 'not authorized' ) || false !== stripos( $msg, 'access denied' ) || false !== stripos( $msg, '40301' ) );
				if ( $is_auth ) {
					$serp = MLS_DataForSEO::get_serp_at_coordinate(
						$run->keyword,
						(float) $cell->lat,
						(float) $cell->lng
					);
					if ( ! is_wp_error( $serp ) ) {
						update_option( 'mls_geogrid_mode', 'organic', false );
					}
				}
			}
		}

		// Map listings reliably carry the GBP business name but not always the
		// website domain, so match on either signal.
		$identity    = get_option( 'mls_identity', array() );
		$target_name = isset( $identity['business_name'] ) ? (string) $identity['business_name'] : '';

		$rank       = null;
		$target_url = null;
		$error_msg  = null;

		if ( is_wp_error( $serp ) ) {
			$error_msg = substr( $serp->get_error_message(), 0, 250 );
		} else {
			foreach ( $serp as $row ) {
				$row_domain = isset( $row['domain'] ) ? $row['domain'] : '';
				$row_title  = isset( $row['title'] ) ? $row['title'] : '';
				if ( $this->domain_matches( $row_domain, $run->target_domain )
					|| ( '' !== $target_name && $this->name_matches( $row_title, $target_name ) ) ) {
					$rank       = (int) ( isset( $row['rank'] ) ? $row['rank'] : 0 );
					$target_url = isset( $row['url'] ) ? $row['url'] : '';
					break;
				}
			}
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mls_geogrid_cells',
			array(
				'rank'       => $rank,
				'target_url' => $target_url,
				'error_msg'  => $error_msg,
				'scanned_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $cell->id )
		);

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}mls_geogrid_runs SET cells_done = cells_done + 1 WHERE id = %d",
				$run_id
			)
		);

		wp_schedule_single_event( time() + self::TICK_DELAY, self::TICK_HOOK, array( $run_id ) );
	}

	/**
	 * Compute the run summary (avg rank, in-top-10) once all cells are scanned.
	 *
	 * @param int $run_id Run identifier.
	 */
	private function finalize_run( $run_id ) {
		global $wpdb;
		$stats = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT AVG(rank) AS avg_rank, SUM(CASE WHEN rank IS NOT NULL AND rank <= 10 THEN 1 ELSE 0 END) AS in_top10 FROM {$wpdb->prefix}mls_geogrid_cells WHERE run_id = %d AND scanned_at IS NOT NULL",
				$run_id
			)
		);

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mls_geogrid_runs',
			array(
				'avg_rank' => $stats && null !== $stats->avg_rank ? round( (float) $stats->avg_rank, 2 ) : null,
				'in_top10' => $stats ? (int) $stats->in_top10 : 0,
			),
			array( 'id' => (int) $run_id )
		);
	}

	/**
	 * Enumerate grid cells around a center point.
	 *
	 * @param float $center_lat Center latitude.
	 * @param float $center_lng Center longitude.
	 * @param int   $grid_size  N (odd).
	 * @param float $spacing_km Spacing in km.
	 * @return array
	 */
	private function enumerate_cells( $center_lat, $center_lng, $grid_size, $spacing_km ) {
		$half       = (int) floor( $grid_size / 2 );
		$lat_step   = $spacing_km / self::KM_PER_LAT_DEGREE;
		$cos_factor = max( 0.01, cos( deg2rad( $center_lat ) ) );
		$lng_step   = $spacing_km / ( self::KM_PER_LAT_DEGREE * $cos_factor );

		$cells = array();
		for ( $r = -$half; $r <= $half; $r++ ) {
			for ( $c = -$half; $c <= $half; $c++ ) {
				$cells[] = array(
					'row' => $r + $half,
					'col' => $c + $half,
					'lat' => round( $center_lat + ( $r * $lat_step ), 6 ),
					'lng' => round( $center_lng + ( $c * $lng_step ), 6 ),
				);
			}
		}
		return $cells;
	}

	/**
	 * Compare a found domain to the target domain (ignoring scheme/www).
	 *
	 * @param string $found  Found domain.
	 * @param string $target Target domain.
	 * @return bool
	 */
	private function domain_matches( $found, $target ) {
		$found  = strtolower( preg_replace( '#^https?://#', '', (string) $found ) );
		$target = strtolower( preg_replace( '#^https?://#', '', (string) $target ) );
		$found  = preg_replace( '#^www\.#', '', $found );
		$target = preg_replace( '#^www\.#', '', $target );
		// Strip any path/query and trailing slash so a user entering
		// "https://midlandfloors.com/" still matches "midlandfloors.com".
		$found  = preg_replace( '#[/?].*$#', '', $found );
		$target = preg_replace( '#[/?].*$#', '', $target );
		if ( '' === $found || '' === $target ) {
			return false;
		}
		return $found === $target || ( false !== strpos( $found, '.' . $target ) );
	}

	/**
	 * Compare a found map-listing title to the target business name, ignoring
	 * case, punctuation and spacing.
	 *
	 * @param string $found  Listing title from the map pack.
	 * @param string $target Configured business name.
	 * @return bool
	 */
	private function name_matches( $found, $target ) {
		$found  = preg_replace( '/[^a-z0-9]+/', '', strtolower( (string) $found ) );
		$target = preg_replace( '/[^a-z0-9]+/', '', strtolower( (string) $target ) );
		if ( '' === $found || '' === $target ) {
			return false;
		}
		return $found === $target || false !== strpos( $found, $target ) || false !== strpos( $target, $found );
	}

	/**
	 * Fetch recent runs.
	 *
	 * @param int $limit Max runs.
	 * @return array
	 */
	private function get_runs( $limit = 10 ) {
		global $wpdb;
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}mls_geogrid_runs ORDER BY id DESC LIMIT %d",
				$limit
			)
		);
	}

	/**
	 * Fetch cells for a run.
	 *
	 * @param int $run_id Run identifier.
	 * @return array
	 */
	private function get_cells( $run_id ) {
		global $wpdb;
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}mls_geogrid_cells WHERE run_id = %d ORDER BY row_idx, col_idx",
				$run_id
			)
		);
	}

	/**
	 * Public accessor for the dashboard: the most recent run row or null.
	 *
	 * @return object|null
	 */
	public static function latest_run() {
		global $wpdb;
		return $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}mls_geogrid_runs ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Render the admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'midland-local-seo' ) );
		}
		// Default the center to the business location from the Profile, and the
		// keyword to the top real search term, so the grid is ready to run.
		$identity = class_exists( 'MLS_SameAs' ) ? MLS_SameAs::get_identity() : array();
		$def_lat  = ( isset( $identity['center_lat'] ) && '' !== $identity['center_lat'] ) ? (float) $identity['center_lat'] : 38.9847;
		$def_lng  = ( isset( $identity['center_lng'] ) && '' !== $identity['center_lng'] ) ? (float) $identity['center_lng'] : -77.0947;
		$settings = wp_parse_args(
			get_option( 'mls_geogrid_settings', array() ),
			array(
				'keyword'       => 'commercial carpet cleaning',
				'target_domain' => wp_parse_url( home_url(), PHP_URL_HOST ),
				'center_lat'    => $def_lat,
				'center_lng'    => $def_lng,
				'grid_size'     => 5,
				'spacing_km'    => 1.5,
			)
		);
		$runs     = $this->get_runs( 10 );
		$latest   = ! empty( $runs ) ? $runs[0] : null;
		$cells    = $latest ? $this->get_cells( $latest->id ) : array();

		$run_url = wp_nonce_url( admin_url( 'admin.php?page=mls-geogrid&mls_geogrid_run=1' ), 'mls_geogrid_run' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Geo-Grid Rank Tracker', 'midland-local-seo' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Map-pack / organic rank measured at an NxN grid of points around your service area. Weekly cron + manual run.', 'midland-local-seo' ); ?></p>

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Geo-Grid settings saved.', 'midland-local-seo' ); ?></p></div>
			<?php endif; ?>
			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php if ( isset( $_GET['ran'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Scan started — cells are processed in the background. Reload in a minute to see results.', 'midland-local-seo' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! class_exists( 'MLS_DataForSEO' ) || ! MLS_DataForSEO::is_configured() ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'DataForSEO credentials not configured. Add them on the Local SEO dashboard.', 'midland-local-seo' ); ?></p></div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'mls_save_geogrid', '_mls_geogrid_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="geogrid_keyword"><?php esc_html_e( 'Keyword', 'midland-local-seo' ); ?></label></th>
						<td><input type="text" id="geogrid_keyword" name="geogrid_keyword" class="regular-text" value="<?php echo esc_attr( $settings['keyword'] ); ?>" placeholder="commercial floor cleaning"></td>
					</tr>
					<tr>
						<th><label for="geogrid_target_domain"><?php esc_html_e( 'Target Domain', 'midland-local-seo' ); ?></label></th>
						<td><input type="text" id="geogrid_target_domain" name="geogrid_target_domain" class="regular-text" value="<?php echo esc_attr( $settings['target_domain'] ); ?>" placeholder="midlandfloors.com"></td>
					</tr>
					<tr>
						<th><label for="geogrid_center_lat"><?php esc_html_e( 'Center Latitude', 'midland-local-seo' ); ?></label></th>
						<td><input type="number" step="0.000001" id="geogrid_center_lat" name="geogrid_center_lat" class="regular-text" value="<?php echo esc_attr( $settings['center_lat'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="geogrid_center_lng"><?php esc_html_e( 'Center Longitude', 'midland-local-seo' ); ?></label></th>
						<td><input type="number" step="0.000001" id="geogrid_center_lng" name="geogrid_center_lng" class="regular-text" value="<?php echo esc_attr( $settings['center_lng'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="geogrid_grid_size"><?php esc_html_e( 'Grid Size (N×N)', 'midland-local-seo' ); ?></label></th>
						<td>
							<select id="geogrid_grid_size" name="geogrid_grid_size">
								<?php foreach ( array( 3, 5, 7, 9 ) as $n ) : ?>
									<option value="<?php echo esc_attr( $n ); ?>" <?php selected( $settings['grid_size'], $n ); ?>><?php echo esc_html( $n . ' x ' . $n . ' (' . ( $n * $n ) . ' cells)' ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Each cell is one DataForSEO SERP call. Mind your quota.', 'midland-local-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="geogrid_spacing_km"><?php esc_html_e( 'Spacing (km)', 'midland-local-seo' ); ?></label></th>
						<td><input type="number" step="0.5" min="0.5" max="50" id="geogrid_spacing_km" name="geogrid_spacing_km" value="<?php echo esc_attr( $settings['spacing_km'] ); ?>" style="width:100px;"></td>
					</tr>
					<tr>
						<th><label for="geogrid_measure"><?php esc_html_e( 'Measure', 'midland-local-seo' ); ?></label></th>
						<td>
							<select id="geogrid_measure" name="geogrid_measure">
								<option value="map_pack" <?php selected( isset( $settings['measure'] ) ? $settings['measure'] : 'map_pack', 'map_pack' ); ?>><?php esc_html_e( 'Map pack rank (Google Maps, Local Falcon style)', 'midland-local-seo' ); ?></option>
								<option value="organic" <?php selected( isset( $settings['measure'] ) ? $settings['measure'] : 'map_pack', 'organic' ); ?>><?php esc_html_e( 'Organic rank (Google Search results)', 'midland-local-seo' ); ?></option>
							</select>
						</td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" name="mls_save_geogrid" value="1" class="button button-primary"><?php esc_html_e( 'Save Settings', 'midland-local-seo' ); ?></button>
					<a href="<?php echo esc_url( $run_url ); ?>" class="button button-secondary" style="margin-left:8px;"><?php esc_html_e( 'Run Scan Now', 'midland-local-seo' ); ?></a>
				</p>
			</form>

			<?php if ( $latest ) : ?>
				<hr>
				<h2><?php esc_html_e( 'Latest Run', 'midland-local-seo' ); ?></h2>
				<p>
					<strong><?php echo esc_html( $latest->keyword ); ?></strong>
					&mdash; <?php esc_html_e( 'avg rank:', 'midland-local-seo' ); ?>
					<strong><?php echo $latest->avg_rank ? esc_html( $latest->avg_rank ) : '&mdash;'; ?></strong>
					| <?php esc_html_e( 'in top 10:', 'midland-local-seo' ); ?>
					<strong><?php echo esc_html( (int) $latest->in_top10 . ' / ' . (int) $latest->cells_done ); ?></strong>
					| <?php echo esc_html( $latest->created_at ); ?>
				</p>
				<?php if ( 'organic' === get_option( 'mls_geogrid_mode' ) ) : ?>
					<div class="notice notice-info inline"><p><?php esc_html_e( 'Measuring ORGANIC rank at each point: your DataForSEO plan does not include the Maps API, so map-pack rank is unavailable. Enable Maps in your DataForSEO dashboard for true local-pack tracking.', 'midland-local-seo' ); ?></p></div>
				<?php endif; ?>
				<?php
				$mls_cell_error = '';
				foreach ( $cells as $mls_cell ) {
					if ( ! empty( $mls_cell->error_msg ) ) {
						$mls_cell_error = (string) $mls_cell->error_msg;
						break;
					}
				}
				if ( '' !== $mls_cell_error ) :
					?>
					<div class="notice notice-error inline"><p><strong><?php esc_html_e( 'Scan error:', 'midland-local-seo' ); ?></strong> <?php echo esc_html( $mls_cell_error ); ?></p></div>
				<?php endif; ?>
				<?php $this->render_heatmap( $cells, (int) $latest->grid_size ); ?>
			<?php endif; ?>

			<?php if ( count( $runs ) > 1 ) : ?>
				<h2><?php esc_html_e( 'History', 'midland-local-seo' ); ?></h2>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Date', 'midland-local-seo' ); ?></th>
						<th><?php esc_html_e( 'Keyword', 'midland-local-seo' ); ?></th>
						<th><?php esc_html_e( 'Grid', 'midland-local-seo' ); ?></th>
						<th><?php esc_html_e( 'Avg Rank', 'midland-local-seo' ); ?></th>
						<th><?php esc_html_e( 'In Top 10', 'midland-local-seo' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $runs as $r ) : ?>
							<tr>
								<td><?php echo esc_html( $r->created_at ); ?></td>
								<td><?php echo esc_html( $r->keyword ); ?></td>
								<td><?php echo esc_html( $r->grid_size . 'x' . $r->grid_size ); ?></td>
								<td><?php echo $r->avg_rank ? esc_html( $r->avg_rank ) : '&mdash;'; ?></td>
								<td><?php echo esc_html( (int) $r->in_top10 . ' / ' . (int) $r->cells_done ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the heat-map matrix.
	 *
	 * @param array $cells     Cell rows.
	 * @param int   $grid_size N.
	 */
	private function render_heatmap( $cells, $grid_size ) {
		if ( empty( $cells ) ) {
			return;
		}
		$matrix = array();
		foreach ( $cells as $c ) {
			$matrix[ (int) $c->row_idx ][ (int) $c->col_idx ] = $c;
		}
		echo '<div style="display:inline-block;border:1px solid #ddd;padding:8px;background:#fafafa;">';
		for ( $r = 0; $r < $grid_size; $r++ ) {
			echo '<div style="display:flex;">';
			for ( $c = 0; $c < $grid_size; $c++ ) {
				$cell = isset( $matrix[ $r ][ $c ] ) ? $matrix[ $r ][ $c ] : null;
				$rank = $cell && null !== $cell->rank ? (int) $cell->rank : null;
				if ( null === $rank ) {
					$bg = '#bbb';
				} elseif ( $rank <= 3 ) {
					$bg = '#0a8754';
				} elseif ( $rank <= 10 ) {
					$bg = '#46b450';
				} elseif ( $rank <= 20 ) {
					$bg = '#dba617';
				} else {
					$bg = '#d63638';
				}
				$label = null === $rank ? '—' : (string) $rank;
				$title = $cell ? sprintf( '%.4f, %.4f', (float) $cell->lat, (float) $cell->lng ) : '';
				echo '<div title="' . esc_attr( $title ) . '" style="width:48px;height:48px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;background:' . esc_attr( $bg ) . ';margin:2px;border-radius:4px;font-size:14px;">' . esc_html( $label ) . '</div>';
			}
			echo '</div>';
		}
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'Green = top 10. Yellow = 11-20. Red = 21+. Gray = not found in top 100.', 'midland-local-seo' ) . '</p>';
	}
}

MLS_Geogrid::get_instance();
