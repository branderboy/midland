<?php
/**
 * Admin interface for WP GitHub Backup.
 *
 * @package WPGitHubBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WGB_Admin {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'admin_post_wgb_save_settings', array( $this, 'handle_post_save' ) );
		add_action( 'wp_ajax_wgb_run_backup', array( $this, 'ajax_run_backup' ) );
		add_action( 'wp_ajax_wgb_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_wgb_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_wgb_get_backups', array( $this, 'ajax_get_backups' ) );
		add_action( 'wp_ajax_wgb_get_content_items', array( $this, 'ajax_get_content_items' ) );
		add_action( 'wp_ajax_wgb_get_content_item', array( $this, 'ajax_get_content_item' ) );
		add_action( 'wp_ajax_wgb_save_content_item', array( $this, 'ajax_save_content_item' ) );
		add_action( 'wp_ajax_wgb_deploy_preview', array( $this, 'ajax_deploy_preview' ) );
		add_action( 'wp_ajax_wgb_run_deploy', array( $this, 'ajax_run_deploy' ) );
		add_action( 'wp_ajax_wgb_deploy_history', array( $this, 'ajax_deploy_history' ) );
		add_action( 'wp_ajax_wgb_save_deploy_settings', array( $this, 'ajax_save_deploy_settings' ) );
		add_action( 'wp_ajax_wgb_ai_analyze_content', array( $this, 'ajax_ai_analyze_content' ) );
		add_action( 'wp_ajax_wgb_ai_generate_seo', array( $this, 'ajax_ai_generate_seo' ) );
		add_action( 'wp_ajax_wgb_ai_save_key', array( $this, 'ajax_ai_save_key' ) );
		add_action( 'wp_ajax_wgb_update_plugin', array( $this, 'ajax_update_plugin' ) );
		add_action( 'wp_ajax_wgb_rollback_deploy', array( $this, 'ajax_rollback_deploy' ) );
		add_action( 'wp_ajax_wgb_run_deploy_incremental', array( $this, 'ajax_run_deploy_incremental' ) );
		add_action( 'wp_ajax_wgb_clean_redirects', array( $this, 'ajax_clean_redirects' ) );
	}

	/**
	 * AJAX handler: Bulk-replace redirected URLs across post content and
	 * postmeta so internal links point at the canonical target in one click.
	 *
	 * Accepts $_POST['pairs'] — newline-separated "old_url|new_url" lines.
	 * For each pair, runs UPDATE on wp_posts.post_content and
	 * wp_postmeta.meta_value. Reports per-pair counts.
	 */
	public function ajax_clean_redirects() {
		check_ajax_referer( 'wgb_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'wp-github-backup' ) );
		}

		$raw = isset( $_POST['pairs'] ) ? wp_unslash( $_POST['pairs'] ) : '';
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			wp_send_json_error( __( 'Paste at least one "old_url|new_url" pair.', 'wp-github-backup' ) );
		}

		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		$pairs = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			$parts = explode( '|', $line, 2 );
			if ( 2 !== count( $parts ) ) {
				continue;
			}
			$old = trim( $parts[0] );
			$new = trim( $parts[1] );
			if ( '' === $old || '' === $new || $old === $new ) {
				continue;
			}
			// Basic sanity — both should look like URLs or at least paths.
			if ( false === strpos( $old, '/' ) ) {
				continue;
			}
			$pairs[] = array( 'old' => $old, 'new' => $new );
		}

		if ( empty( $pairs ) ) {
			wp_send_json_error( __( 'No valid pairs parsed. Use format: old_url|new_url (one per line).', 'wp-github-backup' ) );
		}

		global $wpdb;
		$results = array();
		$total   = 0;

		foreach ( $pairs as $pair ) {
			$posts_updated = $wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->posts}
				 SET post_content = REPLACE(post_content, %s, %s)
				 WHERE post_content LIKE %s",
				$pair['old'],
				$pair['new'],
				'%' . $wpdb->esc_like( $pair['old'] ) . '%'
			) );

			$meta_updated = $wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->postmeta}
				 SET meta_value = REPLACE(meta_value, %s, %s)
				 WHERE meta_value LIKE %s",
				$pair['old'],
				$pair['new'],
				'%' . $wpdb->esc_like( $pair['old'] ) . '%'
			) );

			$posts_updated = (int) $posts_updated;
			$meta_updated  = (int) $meta_updated;
			$total        += $posts_updated + $meta_updated;

			$results[] = array(
				'old'           => $pair['old'],
				'new'           => $pair['new'],
				'posts_updated' => $posts_updated,
				'meta_updated'  => $meta_updated,
			);
		}

		// Flush caches so the changes appear immediately on the front end.
		wp_cache_flush();

		wp_send_json_success( array(
			'total_rows_changed' => $total,
			'pairs'              => $results,
		) );
	}

	/**
	 * AJAX handler: Run an incremental deploy — import only files changed
	 * since the last successful deploy on the current branch.
	 */
	public function ajax_run_deploy_incremental() {
		check_ajax_referer( 'wgb_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'wp-github-backup' ) );
		}

		$github = $this->get_deploy_github();
		if ( is_wp_error( $github ) ) {
			wp_send_json_error( $github->get_error_message() );
		}

		$branch   = $this->resolve_deploy_branch( $github );
		$target   = WGB_Settings::get( 'deploy_target', 'content' );
		$deployer = new WGB_Deployer( $github, $branch );
		$result   = $deployer->run_incremental( $target );

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler: Rollback the last deploy.
	 *
	 * For every post/page modified during the last successful deploy, finds
	 * the most recent revision created BEFORE the deploy and restores it.
	 * This reverses destructive overwrites when a deploy replaces newer
	 * WordPress edits with older content from the repo.
	 */
	public function ajax_rollback_deploy() {
		check_ajax_referer( 'wgb_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'wp-github-backup' ) );
		}

		global $wpdb;

		$log_table = $wpdb->prefix . 'github_deploy_log';
		$last_row  = $wpdb->get_row( "SELECT deploy_date FROM {$log_table} WHERE status IN ('success','partial') ORDER BY id DESC LIMIT 1" );

		if ( ! $last_row ) {
			wp_send_json_error( __( 'No deploy history found — nothing to roll back.', 'wp-github-backup' ) );
		}

		$deploy_gmt = get_gmt_from_date( $last_row->deploy_date );
		$deploy_ts  = strtotime( $deploy_gmt );

		if ( ! $deploy_ts ) {
			wp_send_json_error( __( 'Could not parse the last deploy timestamp.', 'wp-github-backup' ) );
		}

		// Window: 30s before start, 10m after (covers deploy duration).
		$window_start_gmt = gmdate( 'Y-m-d H:i:s', $deploy_ts - 30 );
		$window_end_gmt   = gmdate( 'Y-m-d H:i:s', $deploy_ts + 600 );

		$touched = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type IN ('post','page')
			 AND post_status NOT IN ('trash','auto-draft')
			 AND post_modified_gmt BETWEEN %s AND %s",
			$window_start_gmt,
			$window_end_gmt
		) );

		$restored = 0;
		$skipped  = 0;
		$errors   = array();

		foreach ( $touched as $row ) {
			$post_id   = (int) $row->ID;
			$revisions = wp_get_post_revisions( $post_id, array( 'numberposts' => 20, 'orderby' => 'date', 'order' => 'DESC' ) );

			$target = null;
			foreach ( $revisions as $rev ) {
				// Pick the newest revision that predates the deploy window.
				if ( strtotime( $rev->post_modified_gmt ) < ( $deploy_ts - 30 ) ) {
					$target = $rev;
					break;
				}
			}

			if ( ! $target ) {
				$skipped++;
				continue;
			}

			$result = wp_restore_post_revision( $target->ID );
			if ( $result && ! is_wp_error( $result ) ) {
				$restored++;
				// Invalidate deploy hash so the next deploy treats this post as changed again.
				delete_post_meta( $post_id, '_wgb_last_deploy_sha' );
			} else {
				$errors[] = 'Post ' . $post_id . ': ' . ( is_wp_error( $result ) ? $result->get_error_message() : 'unknown error' );
			}
		}

		wp_send_json_success( array(
			'restored'    => $restored,
			'skipped'     => $skipped,
			'total'       => count( $touched ),
			'deploy_date' => $last_row->deploy_date,
			'errors'      => $errors,
		) );
	}

	/**
	 * Add the admin menu page.
	 */
	public function add_menu_page() {
		add_management_page(
			'Midland GitHub Vault',
			'Midland GitHub Vault',
			'manage_options',
			'wp-github-backup',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'tools_page_wp-github-backup' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wgb-admin',
			WGB_PLUGIN_URL . 'admin/css/admin.css',
			array(),
			WGB_VERSION
		);

		wp_enqueue_script(
			'wgb-admin',
			WGB_PLUGIN_URL . 'admin/js/admin.js',
			// wp-i18n provides window.wp.i18n.__() so the JS can
			// translate strings with the plugin text domain.
			array( 'jquery', 'wp-i18n' ),
			WGB_VERSION,
			true
		);

		// Tell WordPress where this script's .json translation files live
		// so translations pushed to translate.wordpress.org (or bundled
		// via Loco/WPML/etc.) are applied automatically.
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations(
				'wgb-admin',
				'wp-github-backup',
				plugin_dir_path( WGB_PLUGIN_FILE ) . 'languages'
			);
		}

		wp_localize_script(
			'wgb-admin',
			'wgbAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wgb_nonce' ),
				// Pre-translated strings for dynamic messages the JS
				// composes at runtime (e.g. HTML table cells rendered
				// from AJAX payloads). Translating on the PHP side means
				// WordPress caches the translation in the locale MO
				// file instead of shipping every variant in JS.
				'i18n'    => array(
					'loading'          => esc_html__( 'Loading…', 'wp-github-backup' ),
					'noItems'          => esc_html__( 'No items found.', 'wp-github-backup' ),
					'noTitle'          => esc_html__( '(no title)', 'wp-github-backup' ),
					'edit'             => esc_html__( 'Edit', 'wp-github-backup' ),
					'itemsLabel'       => esc_html__( 'items', 'wp-github-backup' ),
					'error'            => esc_html__( 'Error:', 'wp-github-backup' ),
					'saveFailed'       => esc_html__( 'Save failed.', 'wp-github-backup' ),
					'connectionFailed' => esc_html__( 'Connection test failed.', 'wp-github-backup' ),
					'requestFailed'    => esc_html__( 'Request failed:', 'wp-github-backup' ),
				),
			)
		);
	}

	/**
	 * Display admin notices.
	 */
	public function admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Only show notices on the plugin page.
		$screen = get_current_screen();
		if ( ! $screen || 'tools_page_wp-github-backup' !== $screen->id ) {
			return;
		}

		// Suppress "not configured" warning right after saving.
		if ( isset( $_GET['saved'] ) ) {
			return;
		}

		$token    = WGB_Settings::get_token();
		$username = WGB_Settings::get( 'github_username' );
		$repo     = WGB_Settings::get( 'repo_name' );

		if ( empty( $token ) || empty( $username ) || empty( $repo ) ) {
			echo '<div class="notice notice-warning"><p>';
			echo '<strong>Midland GitHub Vault:</strong> ';
			echo 'Please configure your GitHub settings under ';
			echo '<a href="' . esc_url( admin_url( 'tools.php?page=wp-github-backup&tab=settings' ) ) . '">Tools &rarr; Midland GitHub Vault</a>.';
			echo '</p></div>';
		}

		// Check last backup status.
		$last = WGB_Backup_Runner::get_last_backup();
		if ( $last && 'failed' === $last->status ) {
			echo '<div class="notice notice-error"><p>';
			echo '<strong>Midland GitHub Vault:</strong> ';
			echo 'The last backup failed. ';
			echo '<a href="' . esc_url( admin_url( 'tools.php?page=wp-github-backup&tab=history' ) ) . '">View details</a>.';
			echo '</p></div>';
		}
	}

	/**
	 * Render the admin page.
	 */
	/**
	 * Handle settings form POST via admin-post.php.
	 */
	public function handle_post_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'wp-github-backup' ) );
		}

		check_admin_referer( 'wgb_save_settings_action', 'wgb_settings_nonce' );
		$this->process_settings_save();
		wp_safe_redirect( admin_url( 'tools.php?page=wp-github-backup&tab=settings&saved=1' ) );
		exit;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		$settings   = WGB_Settings::get_all();
		$last       = WGB_Backup_Runner::get_last_backup();
		$next_cron  = wp_next_scheduled( 'wp_github_backup_cron' );
		?>
		<div class="wrap wgb-wrap">
			<h1>Midland GitHub Vault</h1>

			<nav class="nav-tab-wrapper wgb-tabs">
				<a href="?page=wp-github-backup&tab=dashboard" class="nav-tab <?php echo 'dashboard' === $active_tab ? 'nav-tab-active' : ''; ?>">Dashboard</a>
				<a href="?page=wp-github-backup&tab=deploy" class="nav-tab <?php echo 'deploy' === $active_tab ? 'nav-tab-active' : ''; ?>">Deploy</a>
				<a href="?page=wp-github-backup&tab=history" class="nav-tab <?php echo 'history' === $active_tab ? 'nav-tab-active' : ''; ?>">Backup History</a>
				<a href="?page=wp-github-backup&tab=restore" class="nav-tab <?php echo 'restore' === $active_tab ? 'nav-tab-active' : ''; ?>">Restore</a>
				<a href="?page=wp-github-backup&tab=editor" class="nav-tab <?php echo 'editor' === $active_tab ? 'nav-tab-active' : ''; ?>">Content Editor</a>
				<a href="?page=wp-github-backup&tab=ai" class="nav-tab <?php echo 'ai' === $active_tab ? 'nav-tab-active' : ''; ?>">AI Assistant</a>
				<a href="?page=wp-github-backup&tab=settings" class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>">Settings</a>
			</nav>

			<div class="wgb-tab-content">
				<?php
				switch ( $active_tab ) {
					case 'deploy':
						$this->render_deploy_tab();
						break;
					case 'history':
						$this->render_history_tab();
						break;
					case 'restore':
						$this->render_restore_tab();
						break;
					case 'editor':
						$this->render_editor_tab();
						break;
					case 'ai':
						$this->render_ai_tab();
						break;
					case 'settings':
						$this->render_settings_tab( $settings );
						break;
					default:
						$this->render_dashboard_tab( $last, $next_cron );
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Dashboard tab.
	 *
	 * @param object|null $last      Last backup entry.
	 * @param int|false   $next_cron Next cron timestamp.
	 */
	private function render_dashboard_tab( $last, $next_cron ) {
		$total_backups = WGB_Backup_Runner::get_total_count();
		?>
		<div class="wgb-dashboard">
			<div class="wgb-cards">
				<div class="wgb-card">
					<h3>Last Backup</h3>
					<?php if ( $last ) : ?>
						<p class="wgb-status wgb-status-<?php echo esc_attr( $last->status ); ?>">
							<?php echo esc_html( ucfirst( $last->status ) ); ?>
						</p>
						<p><?php echo esc_html( $last->backup_date ); ?></p>
						<p><?php echo esc_html( size_format( $last->total_size ) ); ?> &middot; <?php echo esc_html( $last->files_pushed ); ?> files &middot; <?php echo esc_html( $last->duration ); ?>s</p>
					<?php else : ?>
						<p>No backups yet.</p>
					<?php endif; ?>
				</div>

				<div class="wgb-card">
					<h3>Next Scheduled</h3>
					<?php if ( $next_cron ) : ?>
						<p><?php echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next_cron ), 'Y-m-d H:i:s' ) ); ?></p>
					<?php else : ?>
						<p>No scheduled backup (manual only).</p>
					<?php endif; ?>
				</div>

				<div class="wgb-card">
					<h3>Quick Stats</h3>
					<p>Total Backups: <?php echo esc_html( $total_backups ); ?></p>
				</div>
			</div>

			<div class="wgb-actions">
				<button type="button" id="wgb-backup-now" class="button button-primary button-hero">
					Backup Now
				</button>
				<div id="wgb-backup-status" class="wgb-backup-progress" style="display:none;">
					<span class="spinner is-active"></span>
					<span class="wgb-progress-text">Running backup...</span>
				</div>
				<div id="wgb-backup-result" style="display:none;"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the History tab.
	 */
	private function render_history_tab() {
		$history = WGB_Backup_Runner::get_history( 50 );
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th>Date</th>
					<th>Status</th>
					<th>Files</th>
					<th>Size</th>
					<th>Duration</th>
					<th>Errors</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $history ) ) : ?>
					<tr><td colspan="6">No backup history found.</td></tr>
				<?php else : ?>
					<?php foreach ( $history as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( $entry->backup_date ); ?></td>
							<td>
								<span class="wgb-status wgb-status-<?php echo esc_attr( $entry->status ); ?>">
									<?php echo esc_html( ucfirst( $entry->status ) ); ?>
								</span>
							</td>
							<td><?php echo esc_html( $entry->files_pushed ); ?></td>
							<td><?php echo esc_html( size_format( $entry->total_size ) ); ?></td>
							<td><?php echo esc_html( $entry->duration ); ?>s</td>
							<td>
								<?php
								if ( ! empty( $entry->errors ) ) {
									$errors = json_decode( $entry->errors, true );
									if ( is_array( $errors ) ) {
										echo esc_html( implode( '; ', $errors ) );
									}
								} else {
									echo '—';
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the Restore tab.
	 */
	private function render_restore_tab() {
		?>
		<div class="wgb-restore">
			<h2>Available Backups</h2>
			<div id="wgb-restore-list">
				<p><button type="button" id="wgb-load-backups" class="button">Load Backups from GitHub</button></p>
				<div id="wgb-backups-table" style="display:none;"></div>
				<div id="wgb-backups-loading" style="display:none;">
					<span class="spinner is-active"></span> Loading backups...
				</div>
			</div>

			<hr />

			<?php echo wp_kses_post( WGB_Restore::get_restore_instructions() ); ?>
		</div>
		<?php
	}

	/**
	 * Render the Settings tab.
	 *
	 * @param array $settings Current settings.
	 */
	private function render_settings_tab( $settings ) {
		$token_set = ! empty( WGB_Settings::get_token() );

		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p><strong>Settings saved successfully!</strong></p></div>';
		}
		?>
		<form id="wgb-settings-form" class="wgb-settings" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wgb_save_settings_action', 'wgb_settings_nonce' ); ?>
			<input type="hidden" name="action" value="wgb_save_settings" />
			<table class="form-table">
				<tr>
					<th><label for="wgb-token">GitHub Personal Access Token</label></th>
					<td>
						<input type="password" id="wgb-token" name="github_token" class="regular-text"
							placeholder="<?php echo $token_set ? '••••••••••••••••' : 'ghp_xxxxxxxxxxxxxxxxxxxx'; ?>"
							autocomplete="off" />
						<p class="description">(e.g., ghp_abc123...) — Classic token from GitHub &gt; Settings &gt; Developer settings &gt; Personal access tokens (classic). Needs <strong>repo</strong> scope. Leave blank to keep current token. <?php echo $token_set ? '<span class="wgb-token-set" style="color:#46b450;font-weight:bold;">Token is saved.</span>' : '<span style="color:#dc3232;font-weight:bold;">No token set!</span>'; ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="wgb-username">GitHub Username</label></th>
					<td>
						<input type="text" id="wgb-username" name="github_username" class="regular-text" value="<?php echo esc_attr( $settings['github_username'] ); ?>" placeholder="your-username" />
						<p class="description">Your GitHub account username, not your email.</p>
					</td>
				</tr>
				<tr>
					<th><label for="wgb-repo">Repository Name</label></th>
					<td>
						<input type="text" id="wgb-repo" name="repo_name" class="regular-text" value="<?php echo esc_attr( $settings['repo_name'] ); ?>" placeholder="my-repo" />
						<p class="description">Just the repo name, not the full URL.</p>
					</td>
				</tr>
				<tr>
					<th><label for="wgb-schedule">Backup Schedule</label></th>
					<td>
						<select id="wgb-schedule" name="schedule">
							<option value="manual" <?php selected( $settings['schedule'], 'manual' ); ?>>Manual Only</option>
							<option value="daily" <?php selected( $settings['schedule'], 'daily' ); ?>>Daily</option>
							<option value="weekly" <?php selected( $settings['schedule'], 'weekly' ); ?>>Weekly</option>
						</select>
						<p class="description">(Manual = you click "Backup Now", Daily/Weekly = runs automatically)</p>
					</td>
				</tr>
				<tr>
					<th>Include in Backup</th>
					<td>
						<fieldset>
							<label><input type="checkbox" name="include_db" value="1" <?php checked( $settings['include_db'], '1' ); ?> /> Database</label><br />
							<label><input type="checkbox" name="include_themes" value="1" <?php checked( $settings['include_themes'], '1' ); ?> /> Themes</label><br />
							<label><input type="checkbox" name="include_plugins" value="1" <?php checked( $settings['include_plugins'], '1' ); ?> /> Plugins</label><br />
							<label><input type="checkbox" name="include_uploads" value="1" <?php checked( $settings['include_uploads'], '1' ); ?> /> Uploads</label><br />
						<label><input type="checkbox" name="include_posts" value="1" <?php checked( $settings['include_posts'], '1' ); ?> /> Posts</label><br />
						<label><input type="checkbox" name="include_pages" value="1" <?php checked( $settings['include_pages'], '1' ); ?> /> Pages</label>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th><label for="wgb-exclude">Exclude Folders</label></th>
					<td>
						<input type="text" id="wgb-exclude" name="exclude_folders" class="regular-text" value="<?php echo esc_attr( $settings['exclude_folders'] ); ?>" placeholder="cache,node_modules,upgrade" />
						<p class="description">(e.g., cache,node_modules,upgrade) — Comma-separated folder names to skip during backup.</p>
					</td>
				</tr>
				<tr>
					<th><label for="wgb-retention">Retention Period (days)</label></th>
					<td>
						<input type="number" id="wgb-retention" name="retention_days" class="small-text" min="1" max="365" value="<?php echo esc_attr( $settings['retention_days'] ); ?>" placeholder="30" />
						<p class="description">(e.g., 30) — Number of days to keep old backups on GitHub before auto-deleting them.</p>
					</td>
				</tr>
				<tr>
					<th><label for="wgb-email">Notification Email</label></th>
					<td>
						<input type="email" id="wgb-email" name="notification_email" class="regular-text" value="<?php echo esc_attr( $settings['notification_email'] ); ?>" placeholder="you@example.com" />
						<p class="description">(e.g., you@example.com) — Gets notified when backups succeed or fail. Optional.</p>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary">Save Settings</button>
				<button type="button" id="wgb-test-connection" class="button">Test Connection</button>
				<span id="wgb-settings-status"></span>
			</p>
		</form>
		<?php
	}

	/**
	 * AJAX handler: Run backup now.
	 */
	public function ajax_run_backup() {
		check_ajax_referer( 'wgb_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'wp-github-backup' ) );
		}

		$runner = new WGB_Backup_Runner();
		$result = $runner->run();

		wp_send_json_success( $result );
	}

	/**
	 * Process settings save from POST data.
	 * Shared by both the AJAX handler and the traditional POST handler.
	 */
	private function process_settings_save() {
		// Save token if provided.
		if ( ! empty( $_POST['github_token'] ) ) {
			WGB_Settings::save_token( sanitize_text_field( wp_unslash( $_POST['github_token'] ) ) );
		}

		$fields = array(
			'github_username',
			'repo_name',
			'schedule',
			'exclude_folders',
			'retention_days',
			'notification_email',
		);

		foreach ( $fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				WGB_Settings::save( $field, wp_unslash( $_POST[ $field ] ) );
			}
		}

		// Checkbox fields — save as '1' or '0'.
		// AJAX sends explicit '0'/'1'; native form only sends checked boxes.
		$checkboxes = array( 'include_db', 'include_themes', 'include_plugins', 'include_uploads', 'include_posts', 'include_pages' );
		foreach ( $checkboxes as $cb ) {
			$val = isset( $_POST[ $cb ] ) ? sanitize_text_field( wp_unslash( $_POST[ $cb ] ) ) : '0';
			WGB_Settings::save( $cb, '1' === $val ? '1' : '0' );
		}

		// Reschedule cron.
		$timestamp = wp_next_scheduled( 'wp_github_backup_cron' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wp_github_backup_cron' );
		}

		$schedule = WGB_Settings::get( 'schedule', 'manual' );
		if ( 'manual' !== $schedule ) {
			wp_schedule_event( time(), $schedule, 'wp_github_backup_cron' );
		}
	}

	/**
	 * AJAX handler: Save settings.
	 */
	public function ajax_save_settings() {
		check_ajax_referer( 'wgb_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'wp-github-backup' ) );
		}

		$this->process_settings_save();

		wp_send_json_success( __( 'Settings saved.', 'wp-github-backup' ) );
	}

	/**
	 * AJAX handler: Test GitHub connection.
	 */
	public function ajax_test_connection() {
		check_ajax_referer( 'wgb_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'wp-github-backup' ) );
		}

		$token    = WGB_Settings::get_token();
		$username = WGB_Settings::get( 'github_username' );
		$repo     = WGB_Settings::get( 'repo_name' );

		if ( empty( $token ) || empty( $username ) || empty( $repo ) ) {
			wp_send_json_error( __( 'Please save your GitHub settings first.', 'wp-github-backup' ) );
		}

		$github = new WGB_GitHub_API( $token, $username, $repo );
		$result = $github->test_connection();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( __( 'Connection successful! Repository is accessible.', 'wp-github-backup' ) );
	}

	/**
	 * AJAX handler: Get available backups from GitHub.
	 */
	public function ajax_get_backups() {
		check_ajax_referer( 'wgb_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'wp-github-backup' ) );
		}

		$backups = WGB_Restore::get_available_backups();
		wp_send_json_success( $backups );
	}

	/**
	 * Render the Deploy tab.
	 */
	private function render_deploy_tab() {
		$repo_owner      = WGB_Settings::get( 'github_username' );
		$repo_name       = WGB_Settings::get( 'repo_name' );
		$deploy_settings = array(
			'deploy_branch'    => WGB_Settings::get( 'deploy_branch', '' ),
			'deploy_repo_path' => WGB_Settings::get( 'deploy_repo_path', '' ),
			'deploy_target'    => WGB_Settings::get( 'deploy_target', 'content' ),
			'webhook_secret'   => WGB_Settings::get( 'webhook_secret', '' ),
		);
		$last_deploy = WGB_Deployer::get_last_deploy();
		?>
		<div class="wgb-deploy">
			<div class="wgb-cards">
				<div class="wgb-card">
					<h3>Last Deploy</h3>
					<?php if ( $last_deploy ) : ?>
						<p class="wgb-status wgb-status-<?php echo esc_attr( $last_deploy->status ); ?>">
							<?php echo esc_html( ucfirst( $last_deploy->status ) ); ?>
						</p>
						<p><?php echo esc_html( $last_deploy->deploy_date ); ?></p>
						<p><?php echo esc_html( $last_deploy->files_deployed ); ?> files &middot; <?php echo esc_html( $last_deploy->duration ); ?>s &middot; <?php echo esc_html( $last_deploy->target ); ?></p>
					<?php else : ?>
						<p>No deploys yet.</p>
					<?php endif; ?>
				</div>

				<div class="wgb-card">
					<h3>Deploy Source</h3>
					<p><strong><?php echo esc_html( $repo_owner . '/' . $repo_name ); ?></strong></p>
					<p>Branch: <code><?php echo ! empty( $deploy_settings['deploy_branch'] ) ? esc_html( $deploy_settings['deploy_branch'] ) : 'auto-detect'; ?></code></p>
					<?php if ( ! empty( $deploy_settings['deploy_repo_path'] ) ) : ?>
						<p>Path: <code><?php echo esc_html( $deploy_settings['deploy_repo_path'] ); ?></code></p>
					<?php endif; ?>
					<p class="description">Uses the repo configured in Settings tab.</p>
				</div>
			</div>

			<!-- Deploy Settings -->
			<h3>Deploy Settings</h3>
			<form id="wgb-deploy-settings-form" class="wgb-settings">
				<table class="form-table">
					<tr>
						<th><label for="wgb-deploy-branch">Branch</label></th>
						<td>
							<input type="text" id="wgb-deploy-branch" name="deploy_branch" class="regular-text" value="<?php echo esc_attr( $deploy_settings['deploy_branch'] ); ?>" placeholder="main" />
							<p class="description">(e.g., main) — Leave blank to auto-detect. Or type the branch name to pull content from.</p>
						</td>
					</tr>
					<tr>
						<th><label for="wgb-deploy-path">Repo Subdirectory</label></th>
						<td>
							<input type="text" id="wgb-deploy-path" name="deploy_repo_path" class="regular-text" value="<?php echo esc_attr( $deploy_settings['deploy_repo_path'] ); ?>" placeholder="wp-content" />
							<p class="description">(e.g., wp-content) — Optional. Only if your backup files are in a subfolder. Leave blank if at repo root.</p>
						</td>
					</tr>
					<tr>
						<th><label for="wgb-deploy-target">Deploy Target</label></th>
						<td>
							<select id="wgb-deploy-target" name="deploy_target">
								<option value="content" <?php selected( $deploy_settings['deploy_target'], 'content' ); ?>>Posts &amp; Pages</option>
								<option value="posts" <?php selected( $deploy_settings['deploy_target'], 'posts' ); ?>>Posts Only</option>
								<option value="pages" <?php selected( $deploy_settings['deploy_target'], 'pages' ); ?>>Pages Only</option>
							</select>
							<p class="description">(Posts &amp; Pages = import both, Posts Only/Pages Only = just one type) — Matches by slug, updates existing or creates new.</p>
						</td>
					</tr>
					<tr>
						<th><label for="wgb-webhook-secret">GitHub Webhook Secret</label></th>
						<td>
							<input type="text" id="wgb-webhook-secret" name="webhook_secret" class="regular-text" value="<?php echo esc_attr( $deploy_settings['webhook_secret'] ); ?>" placeholder="generate a strong random string" autocomplete="off" />
							<button type="button" class="button" id="wgb-generate-webhook-secret">Generate</button>
							<p class="description">Required to authenticate auto-deploys triggered by GitHub pushes. Paste the same value into your repo's webhook <strong>Secret</strong> field at <code>github.com/&lt;owner&gt;/&lt;repo&gt;/settings/hooks</code>. Webhook URL: <code><?php echo esc_html( rest_url( 'gitdeploy/v1/webhook' ) ); ?></code></p>
						</td>
					</tr>
				</table>
				<script>
				(function(){
					var btn = document.getElementById('wgb-generate-webhook-secret');
					if (!btn) return;
					btn.addEventListener('click', function(){
						var input = document.getElementById('wgb-webhook-secret');
						var bytes = new Uint8Array(32);
						(window.crypto || window.msCrypto).getRandomValues(bytes);
						input.value = Array.from(bytes).map(function(b){ return ('0'+b.toString(16)).slice(-2); }).join('');
					});
				})();
				</script>
				<p class="submit">
					<button type="submit" class="button button-primary">Save Deploy Settings</button>
					<span id="wgb-deploy-settings-status"></span>
				</p>
			</form>

			<hr />

			<!-- Deploy Actions -->
			<h3>Run Deploy</h3>
			<p class="description">Import posts and pages from your GitHub backup into this WordPress site. Existing content with matching slugs will be <strong>updated</strong>; new content will be created.</p>

			<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:12px 16px;margin:12px 0;max-width:900px;">
				<strong style="display:block;margin-bottom:4px;">What this button deploys:</strong>
				<ul style="margin:6px 0 6px 20px;list-style:disc;">
					<li><code>pages/*.html</code> → WordPress pages (<code>jobs-*.html</code> → <code>dpjp_job</code> custom post type at <code>/jobs/{slug}/</code>)</li>
					<li><code>posts/*.html</code> → WordPress blog posts</li>
					<li>Companion <code>content/elementor/{slug}.json</code> → imported into <code>_elementor_data</code> for that page</li>
				</ul>
				<strong style="display:block;margin:10px 0 4px;">What this button does NOT deploy:</strong>
				<ul style="margin:6px 0 6px 20px;list-style:disc;color:#6f6f6f;">
					<li><code>public/.htaccess</code> — server config; upload manually to site root via FTP/cPanel</li>
					<li><code>*.zip</code> (plugin bundles) — install via Plugins → Add New → Upload</li>
					<li><code>*.md</code> reports — documentation only</li>
					<li>Elementor global headers / footers / library templates</li>
				</ul>
				<p style="margin:6px 0 0;">On every successful deploy we also <strong>auto-purge</strong> WP Rocket / LiteSpeed / W3TC / WP Super Cache / Cache Enabler / Autoptimize / Hummingbird / SiteGround / Kinsta / GoDaddy WPaaS / Cloudflare (plugin) if installed, then <strong>live-verify</strong> the first imported page actually renders the new content.</p>
			</div>

			<div class="wgb-actions">
				<button type="button" id="wgb-deploy-preview" class="button">Preview Deploy</button>
				<button type="button" id="wgb-deploy-now" class="button button-primary button-hero">Deploy Now</button>
				<button type="button" id="wgb-deploy-incremental" class="button button-primary">Deploy Latest Changes Only</button>
			</div>
			<p class="description"><strong>Deploy Latest Changes Only</strong> imports just the posts/pages changed since your last successful deploy on this branch — skips untouched files so you don't re-import 93 items every time. Requires at least one full Deploy Now first to set the baseline.</p>

			<div id="wgb-deploy-preview-result" style="display:none;"></div>

			<div id="wgb-deploy-status" class="wgb-backup-progress" style="display:none;">
				<span class="spinner is-active"></span>
				<span class="wgb-progress-text">Deploying...</span>
			</div>
			<div id="wgb-deploy-result" style="display:none;"></div>

			<hr />

			<!-- Clean Redirected Links -->
			<h3>Clean Redirected Links</h3>
			<p class="description">Paste redirect pairs below (one per line, format <code>old_url|new_url</code>). Runs an SQL <code>REPLACE</code> across <code>wp_posts.post_content</code> and <code>wp_postmeta.meta_value</code> so every internal link jumps straight to the canonical target — no more "Page with redirect" crawl hits in Google Search Console.</p>

			<textarea id="wgb-clean-redirects-pairs" class="large-text code" rows="6" placeholder="https://example.com/old-slug/|https://example.com/new-slug/
https://example.com/another-old/|https://example.com/another-new/"></textarea>

			<div class="wgb-actions" style="margin-top:10px;">
				<button type="button" id="wgb-clean-redirects" class="button button-secondary">Replace URLs Now</button>
			</div>

			<div id="wgb-clean-redirects-status" class="wgb-backup-progress" style="display:none;">
				<span class="spinner is-active"></span>
				<span class="wgb-progress-text">Running replacements...</span>
			</div>
			<div id="wgb-clean-redirects-result" style="display:none;"></div>

			<hr />

			<!-- Rollback Last Deploy -->
			<h3>Rollback Last Deploy</h3>
			<p class="description">Revert every post and page that the last deploy touched to its pre-deploy WordPress revision. Use this if a deploy overwrote edits you made in the WP admin.</p>

			<div class="wgb-actions">
				<button type="button" id="wgb-rollback-deploy" class="button button-secondary">Rollback Last Deploy</button>
			</div>

			<div id="wgb-rollback-status" class="wgb-backup-progress" style="display:none;">
				<span class="spinner is-active"></span>
				<span class="wgb-progress-text">Restoring revisions...</span>
			</div>
			<div id="wgb-rollback-result" style="display:none;"></div>

			<hr />

			<!-- Plugin Self-Update -->
			<h3>Update Plugin Files</h3>
			<p class="description">Pull the latest plugin PHP/JS/CSS files from your GitHub repo and overwrite the installed plugin files. This updates <strong>wp-github-backup</strong> and <strong>wp-claude-manager</strong>.</p>

			<div class="wgb-actions">
				<button type="button" id="wgb-update-plugin" class="button button-secondary">Update Plugin from GitHub</button>
			</div>

			<div id="wgb-update-plugin-status" class="wgb-backup-progress" style="display:none;">
				<span class="spinner is-active"></span>
				<span class="wgb-progress-text">Updating plugin files...</span>
			</div>
			<div id="wgb-update-plugin-result" style="display:none;"></div>

			<hr />

			<!-- Deploy History -->
			<h3>Deploy History</h3>
			<div id="wgb-deploy-history">
				<button type="button" id="wgb-load-deploy-history" class="button">Load History</button>
				<div id="wgb-deploy-history-table" style="display:none;"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler: Preview deploy.
	 */
	public function ajax_deploy_preview() {
		check_ajax_referer( 'wgb_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'wp-github-backup' ) );
		}

		$github = $this->get_deploy_github();
		if ( is_wp_error( $github ) ) {
			wp_send_json_error( $github->get_error_message() );
		}

		$branch = $this->resolve_deploy_branch( $github );
		$target = WGB_Settings::get( 'deploy_target', 'content' );

		$deployer = new WGB_Deployer( $github, $branch );
		$preview  = $deployer->preview( $target );

		if ( is_wp_error( $preview ) ) {
			wp_send_json_error( $preview->get_error_message() );
		}

		wp_send_json_success( $preview );
	}

	/**
	 * AJAX handler: Run deploy.
	 */
	public function ajax_run_deploy() {
		check_ajax_referer( 'wgb_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'wp-github-backup' ) );
		}

		$github = $this->get_deploy_github();
		if ( is_wp_error( $github ) ) {
			wp_send_json_error( $github->get_error_message() );
		}

		$branch = $this->resolve_deploy_branch( $github );
		$target = WGB_Settings::get( 'deploy_target', 'content' );

		$deployer = new WGB_Deployer( $github, $branch );
		$result   = $deployer->run( $target );

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler: Get deploy history.
	 */
	public function ajax_deploy_history() {
		check_ajax_referer( 'wgb_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'wp-github-backup' ) );
		}

		$history = WGB_Deployer::get_history( 20 );
		wp_send_json_success( $history );
	}

	/**
	 * AJAX handler: Save deploy settings.
	 */
	public function ajax_save_deploy_settings() {
		check_ajax_referer( 'wgb_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'wp-github-backup' ) );
		}

		$fields = array(
			'deploy_branch',
			'deploy_repo_path',
			'deploy_target',
			'webhook_secret',
		);

		foreach ( $fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				WGB_Settings::save( $field, wp_unslash( $_POST[ $field ] ) );
			}
		}

		wp_send_json_success( __( 'Deploy settings saved.', 'wp-github-backup' ) );
	}

	/**
	 * Get a GitHub API instance for deploy operations.
	 *
	 * @return WGB_GitHub_API|WP_Error
	 */
	private function get_deploy_github() {
		$token = WGB_Settings::get_token();
		$owner = WGB_Settings::get( 'github_username' );
		$repo  = WGB_Settings::get( 'repo_name' );

		if ( empty( $token ) || empty( $owner ) || empty( $repo ) ) {
			return new WP_Error( 'missing_config', __( 'Please configure your GitHub token, username, and repo name in the Settings tab.', 'wp-github-backup' ) );
		}

		return new WGB_GitHub_API( $token, $owner, $repo );
	}

	/**
	 * Resolve the deploy branch — use saved setting, or auto-detect from the repo.
	 *
	 * @param WGB_GitHub_API $github GitHub API instance.
	 * @return string Branch name.
	 */
	private function resolve_deploy_branch( $github ) {
		$branch = WGB_Settings::get( 'deploy_branch', '' );

		if ( ! empty( $branch ) ) {
			return $branch;
		}

		// Auto-detect the default branch from the repo.
		$default = $github->get_default_branch();

		return is_wp_error( $default ) ? 'main' : $default;
	}

	/**
	 * AJAX handler: Update plugin files from GitHub.
	 *
	 * Downloads all PHP/JS/CSS files from the plugin directories in the repo
	 * and overwrites the local plugin files on the WordPress installation.
	 */
	public function ajax_update_plugin() {
		check_ajax_referer( 'wgb_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'wp-github-backup' ) );
		}

		// Self-updater is disabled by default because writing executable
		// code downloaded from a third-party source at runtime is against
		// WordPress.org plugin guidelines. Site owners who want to use it
		// in a private/self-hosted context can opt in by defining
		//     define( 'WGB_ALLOW_SELF_UPDATE', true );
		// in wp-config.php. Standard WP plugin updates are the supported
		// distribution channel for everyone else.
		if ( ! ( defined( 'WGB_ALLOW_SELF_UPDATE' ) && WGB_ALLOW_SELF_UPDATE ) ) {
			wp_send_json_error(
				__( 'The plugin self-updater is disabled. Update through the standard WordPress Plugins screen, or define WGB_ALLOW_SELF_UPDATE in wp-config.php to re-enable.', 'wp-github-backup' )
			);
		}

		$github = $this->get_deploy_github();
		if ( is_wp_error( $github ) ) {
			wp_send_json_error( $github->get_error_message() );
		}

		$branch = $this->resolve_deploy_branch( $github );

		// Plugin directories in the repo to sync.
		$plugin_dirs = array(
			'wp-github-backup'  => WP_PLUGIN_DIR . '/wp-github-backup',
			'wp-claude-manager' => WP_PLUGIN_DIR . '/wp-claude-manager',
			'job-poster-wp'     => WP_PLUGIN_DIR . '/job-poster-wp',
		);

		$updated = 0;
		$errors  = array();

		foreach ( $plugin_dirs as $repo_dir => $local_dir ) {
			$result = $this->sync_plugin_directory( $github, $branch, $repo_dir, $local_dir, '' );

			if ( is_wp_error( $result ) ) {
				$errors[] = $repo_dir . ': ' . $result->get_error_message();
				continue;
			}

			$updated += $result['updated'];
			if ( ! empty( $result['errors'] ) ) {
				$errors = array_merge( $errors, $result['errors'] );
			}
		}

		$status = empty( $errors ) ? 'success' : ( $updated > 0 ? 'partial' : 'failed' );

		wp_send_json_success( array(
			'status'  => $status,
			'updated' => $updated,
			'errors'  => $errors,
		) );
	}

	/**
	 * Recursively sync a plugin directory from GitHub to the local filesystem.
	 *
	 * @param WGB_GitHub_API $github    GitHub API instance.
	 * @param string         $branch    Branch to pull from.
	 * @param string         $repo_dir  Directory path in the repo.
	 * @param string         $local_dir Local filesystem directory.
	 * @param string         $subpath   Current subdirectory path.
	 * @return array|WP_Error Result with 'updated' count and 'errors' array.
	 */
	private function sync_plugin_directory( $github, $branch, $repo_dir, $local_dir, $subpath ) {
		$repo_path = $repo_dir . ( $subpath ? '/' . $subpath : '' );
		$files     = $github->list_files( $repo_path );

		if ( is_wp_error( $files ) ) {
			return $files;
		}

		if ( empty( $files ) ) {
			return array( 'updated' => 0, 'errors' => array() );
		}

		$updated = 0;
		$errors  = array();

		// Allowed file extensions for plugin updates.
		$allowed_ext = array( 'php', 'js', 'css', 'json', 'txt', 'md' );

		// Resolve the local destination root once so we can sandbox every write.
		// realpath() returns false for paths that don't exist yet — fall back to
		// the raw $local_dir, which is built from WP_PLUGIN_DIR + the configured
		// repo dir name and is admin-controlled, not webhook-controlled.
		$local_dir_real = realpath( $local_dir );
		if ( false === $local_dir_real ) {
			$local_dir_real = rtrim( $local_dir, '/\\' );
		}

		foreach ( $files as $entry ) {
			$name = isset( $entry['name'] ) ? (string) $entry['name'] : '';
			$type = isset( $entry['type'] ) ? (string) $entry['type'] : '';

			// Reject any GitHub entry whose name could escape the sync root.
			// Tree entries from a hostile repo can be named "../wp-config.php"
			// even though git itself usually rejects such paths — never trust
			// the API response.
			if ( '' === $name
				|| false !== strpos( $name, '/' )
				|| false !== strpos( $name, '\\' )
				|| false !== strpos( $name, "\0" )
				|| '..' === $name
				|| '.' === $name ) {
				$errors[] = 'Refused suspicious entry: ' . $repo_path . '/' . $name;
				continue;
			}

			if ( 'dir' === $type ) {
				// Recurse into subdirectory.
				$sub = $subpath ? $subpath . '/' . $name : $name;
				$sub_result = $this->sync_plugin_directory( $github, $branch, $repo_dir, $local_dir, $sub );

				if ( is_wp_error( $sub_result ) ) {
					$errors[] = $repo_path . '/' . $name . ': ' . $sub_result->get_error_message();
				} else {
					$updated += $sub_result['updated'];
					$errors   = array_merge( $errors, $sub_result['errors'] );
				}
				continue;
			}

			// Check file extension.
			$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, $allowed_ext, true ) ) {
				continue;
			}

			// Download file content.
			$file_repo_path = $repo_path . '/' . $name;
			$content = $github->download_file( $file_repo_path, $branch );

			if ( is_wp_error( $content ) ) {
				$errors[] = 'Failed to download ' . $file_repo_path . ': ' . $content->get_error_message();
				continue;
			}

			// Build the destination, then verify it stays inside $local_dir_real.
			$local_path   = $local_dir . ( $subpath ? '/' . $subpath : '' ) . '/' . $name;
			$local_subdir = dirname( $local_path );

			if ( ! is_dir( $local_subdir ) ) {
				wp_mkdir_p( $local_subdir );
			}

			$resolved_subdir = realpath( $local_subdir );
			if ( false === $resolved_subdir || 0 !== strpos( $resolved_subdir, $local_dir_real ) ) {
				$errors[] = 'Refused write outside sync root: ' . $file_repo_path;
				continue;
			}

			$written = file_put_contents( $local_path, $content );

			if ( false === $written ) {
				$errors[] = 'Failed to write ' . $local_path;
			} else {
				$updated++;
			}
		}

		return array( 'updated' => $updated, 'errors' => $errors );
	}

	/**
	 * Render the Content Editor tab.
	 */
	private function render_editor_tab() {
		$all_categories = get_categories( array( 'hide_empty' => false ) );
		?>
		<div class="wgb-editor">
			<!-- List view -->
			<div id="wgb-editor-list">
				<div class="wgb-editor-toolbar">
					<select id="wgb-editor-type">
						<option value="post">Posts</option>
						<option value="page">Pages</option>
					</select>
					<input type="text" id="wgb-editor-search" placeholder="Search..." class="regular-text" />
					<button type="button" id="wgb-editor-search-btn" class="button">Search</button>
				</div>
				<table class="wp-list-table widefat fixed striped" id="wgb-editor-table">
					<thead>
						<tr>
							<th>Title</th>
							<th>Status</th>
							<th>URL / Slug</th>
							<th>Date</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody id="wgb-editor-tbody">
						<tr><td colspan="5">Select a content type and click Search.</td></tr>
					</tbody>
				</table>
				<div id="wgb-editor-pagination" class="tablenav bottom"></div>
			</div>

			<!-- Edit view (hidden by default) -->
			<div id="wgb-editor-form" style="display:none;">
				<p><a href="#" id="wgb-editor-back">&larr; Back to list</a></p>
				<h2 id="wgb-editor-form-title">Edit</h2>
				<input type="hidden" id="wgb-edit-id" />

				<table class="form-table">
					<tr>
						<th><label for="wgb-edit-title">Title</label></th>
						<td><input type="text" id="wgb-edit-title" class="large-text" /></td>
					</tr>
					<tr>
						<th><label for="wgb-edit-slug">URL Slug</label></th>
						<td>
							<input type="text" id="wgb-edit-slug" class="regular-text" />
							<p class="description" id="wgb-edit-permalink"></p>
						</td>
					</tr>
					<tr>
						<th><label for="wgb-edit-status">Status</label></th>
						<td>
							<select id="wgb-edit-status">
								<option value="publish">Published</option>
								<option value="draft">Draft</option>
								<option value="pending">Pending Review</option>
								<option value="private">Private</option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="wgb-edit-content">Content</label></th>
						<td><textarea id="wgb-edit-content" class="large-text" rows="15"></textarea></td>
					</tr>
					<tr>
						<th><label for="wgb-edit-excerpt">Excerpt</label></th>
						<td><textarea id="wgb-edit-excerpt" class="large-text" rows="3"></textarea></td>
					</tr>
					<tr id="wgb-edit-cats-row">
						<th>Categories</th>
						<td>
							<fieldset id="wgb-edit-categories">
								<?php foreach ( $all_categories as $cat ) : ?>
									<label><input type="checkbox" name="wgb_cat[]" value="<?php echo esc_attr( $cat->term_id ); ?>" /> <?php echo esc_html( $cat->name ); ?></label><br />
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
					<tr id="wgb-edit-tags-row">
						<th><label for="wgb-edit-tags">Tags</label></th>
						<td>
							<input type="text" id="wgb-edit-tags" class="regular-text" />
							<p class="description">Comma-separated tags</p>
						</td>
					</tr>
				</table>

				<h3>Custom Meta Fields</h3>
				<table class="wp-list-table widefat fixed" id="wgb-meta-table">
					<thead>
						<tr>
							<th>Key</th>
							<th>Value</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody id="wgb-meta-tbody"></tbody>
				</table>
				<p><button type="button" id="wgb-meta-add" class="button">+ Add Meta Field</button></p>

				<h3>Structured Data (JSON-LD)</h3>
				<p class="description">Add Schema.org structured data for this content. Must be valid JSON.</p>
				<textarea id="wgb-edit-schema" class="large-text code" rows="10" placeholder='{"@context":"https://schema.org","@type":"Article","headline":"..."}'></textarea>

				<p class="submit">
					<button type="button" id="wgb-editor-save" class="button button-primary">Save Changes</button>
					<span id="wgb-editor-status"></span>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler: Get list of posts/pages.
	 */
	public function ajax_get_content_items() {
		check_ajax_referer( 'wgb_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'wp-github-backup' ) );
		}

		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( $_POST['post_type'] ) : 'post';
		$paged     = isset( $_POST['paged'] ) ? absint( $_POST['paged'] ) : 1;
		$search    = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

		$result = WGB_Content_Editor::get_items( $post_type, $paged, 20, $search );
		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler: Get a single post/page for editing.
	 */
	public function ajax_get_content_item() {
		check_ajax_referer( 'wgb_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'wp-github-backup' ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( __( 'No post ID provided.', 'wp-github-backup' ) );
		}

		$item = WGB_Content_Editor::get_item( $post_id );
		if ( is_wp_error( $item ) ) {
			wp_send_json_error( $item->get_error_message() );
		}

		wp_send_json_success( $item );
	}

	/**
	 * AJAX handler: Save a post/page.
	 */
	public function ajax_save_content_item() {
		check_ajax_referer( 'wgb_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'wp-github-backup' ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( __( 'No post ID provided.', 'wp-github-backup' ) );
		}

		$data = array();

		$fields = array( 'title', 'content', 'excerpt', 'status', 'slug', 'tags', 'schema_json_ld' );
		foreach ( $fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$data[ $field ] = wp_unslash( $_POST[ $field ] );
			}
		}

		if ( isset( $_POST['featured_image_id'] ) ) {
			$data['featured_image_id'] = absint( $_POST['featured_image_id'] );
		}

		if ( isset( $_POST['category_ids'] ) ) {
			$data['category_ids'] = array_map( 'absint', (array) $_POST['category_ids'] );
		}

		// Meta fields — sent as JSON string.
		if ( isset( $_POST['meta'] ) ) {
			$meta = json_decode( wp_unslash( $_POST['meta'] ), true );
			if ( is_array( $meta ) ) {
				$data['meta'] = $meta;
			}
		}

		$result = WGB_Content_Editor::update_item( $post_id, $data );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	// ─── AI Assistant Tab ───────────────────────────────────

	private function render_ai_tab() {
		$has_key = WGB_Claude_API::has_api_key();
		$posts   = get_posts( array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );
		?>
		<div class="wgb-ai-tab">
			<h2>AI Assistant</h2>
			<p>Use Claude AI to analyze content before deploying, generate SEO metadata, and create smart commit messages.</p>

			<?php if ( ! $has_key ) : ?>
				<div class="notice notice-warning inline" style="margin:12px 0;">
					<p><strong>API Key Required:</strong> Enter your Anthropic API key below to enable AI features.</p>
				</div>
			<?php endif; ?>

			<h3>API Key</h3>
			<table class="form-table">
				<tr>
					<th><label for="wgb-ai-api-key">Anthropic API Key</label></th>
					<td>
						<input type="password" id="wgb-ai-api-key" class="regular-text" placeholder="<?php echo $has_key ? '••••••••••••••' : 'sk-ant-...'; ?>" autocomplete="off" />
						<button type="button" id="wgb-ai-save-key-btn" class="button">Save Key</button>
						<span id="wgb-ai-key-status" style="margin-left:8px;"></span>
						<?php if ( $has_key ) : ?>
							<p class="description" style="color:#46b450;font-weight:600;">Key is configured. Leave blank to keep current key.</p>
						<?php else : ?>
							<p class="description">Get your key from <a href="https://console.anthropic.com/settings/keys" target="_blank">console.anthropic.com</a></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<hr />

			<h3>Pre-Deploy Content Analysis</h3>
			<p>Analyze a page for SEO quality, readability, and deployment readiness before pushing live.</p>
			<table class="form-table">
				<tr>
					<th><label for="wgb-ai-post-select">Select Page/Post</label></th>
					<td>
						<select id="wgb-ai-post-select">
							<option value="">— Select a page/post —</option>
							<?php foreach ( $posts as $p ) : ?>
								<option value="<?php echo esc_attr( $p->ID ); ?>"><?php echo esc_html( $p->post_title ); ?> (<?php echo esc_html( $p->post_type ); ?>)</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>
			<p>
				<button type="button" id="wgb-ai-analyze-btn" class="button button-primary" <?php echo $has_key ? '' : 'disabled'; ?>>Analyze Content</button>
				<button type="button" id="wgb-ai-seo-btn" class="button" <?php echo $has_key ? '' : 'disabled'; ?>>Generate SEO Meta</button>
				<span id="wgb-ai-status" style="margin-left:10px;"></span>
			</p>
			<div id="wgb-ai-results"></div>
		</div>
		<?php
	}

	// ─── AI AJAX Handlers ───────────────────────────────────

	public function ajax_ai_save_key() {
		check_ajax_referer( 'wgb_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'wp-github-backup' ) );
		}

		$key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		if ( empty( $key ) ) {
			wp_send_json_error( __( 'API key is required.', 'wp-github-backup' ) );
		}

		// Third-party data-transfer consent. WordPress.org requires an
		// explicit opt-in for any call that leaves the site. Saving a key
		// is not, by itself, consent to make calls — the admin must tick
		// the consent checkbox in the AI settings form.
		$consent = isset( $_POST['ai_consent'] ) && '1' === (string) $_POST['ai_consent'];

		WGB_Claude_API::save_api_key( $key );
		WGB_Claude_API::set_consent( $consent );

		wp_send_json_success(
			$consent
				? __( 'API key saved and consent granted.', 'wp-github-backup' )
				: __( 'API key saved. Tick the consent checkbox before using AI features.', 'wp-github-backup' )
		);
	}

	public function ajax_ai_analyze_content() {
		check_ajax_referer( 'wgb_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'wp-github-backup' ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( __( 'No post selected.', 'wp-github-backup' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( __( 'Post not found.', 'wp-github-backup' ) );
		}

		$result = WGB_Claude_API::analyze_deploy_content( $post->post_title, $post->post_content );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	public function ajax_ai_generate_seo() {
		check_ajax_referer( 'wgb_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'wp-github-backup' ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( __( 'No post selected.', 'wp-github-backup' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( __( 'Post not found.', 'wp-github-backup' ) );
		}

		$result = WGB_Claude_API::generate_deploy_seo( $post->post_title, $post->post_content );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}
}
