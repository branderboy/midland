<?php
/**
 * Dashboard — the "Local SEO" landing page.
 *
 * Summarizes citation score, sameAs status, last geo-grid run, GMB optimizer
 * score, backlink progress, and a competitor snapshot. Also hosts the DataForSEO
 * credentials settings section (blank secret field + "•••• saved" placeholder)
 * and a nonce/cap-gated "Test connection" action.
 *
 * @package Midland_Local_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboard module.
 */
class MLS_Dashboard {

	/**
	 * Singleton instance.
	 *
	 * @var MLS_Dashboard|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return MLS_Dashboard
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
		add_action( 'admin_init', array( $this, 'handle_save_credentials' ) );
		add_action( 'admin_init', array( $this, 'handle_test_connection' ) );
	}

	/**
	 * Save DataForSEO credentials. The password field is rendered blank; only
	 * update it when a non-empty value is posted (leave blank to keep existing).
	 */
	public function handle_save_credentials() {
		if ( ! isset( $_POST['mls_save_credentials'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$nonce = isset( $_POST['_mls_creds_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_mls_creds_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mls_save_credentials' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'midland-local-seo' ) );
		}

		$login = isset( $_POST['mls_dfs_login'] ) ? sanitize_text_field( wp_unslash( $_POST['mls_dfs_login'] ) ) : '';
		// The secret is intentionally NOT run through sanitize_text_field — API
		// passwords can contain characters it would strip. It is unslashed,
		// trimmed, and stored encrypted (never echoed back to the page).
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$password = isset( $_POST['mls_dfs_password'] ) ? trim( (string) wp_unslash( $_POST['mls_dfs_password'] ) ) : '';

		update_option( 'mls_dfs_login', $login );
		// Only overwrite the stored secret when a new one was actually entered.
		if ( '' !== $password ) {
			MLS_DataForSEO::save_credentials( $login, $password );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . MLS_Plugin::MENU_SLUG . '&creds=1' ) );
		exit;
	}

	/**
	 * Test-connection action.
	 */
	public function handle_test_connection() {
		if ( ! isset( $_GET['mls_test_connection'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'mls_test_connection' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'midland-local-seo' ) );
		}
		$result = MLS_DataForSEO::test_connection();
		$arg    = is_wp_error( $result ) ? 'testfail' : 'testok';
		if ( is_wp_error( $result ) ) {
			set_transient( 'mls_test_error', $result->get_error_message(), 60 );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . MLS_Plugin::MENU_SLUG . '&' . $arg . '=1' ) );
		exit;
	}

	/**
	 * Render the dashboard.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'midland-local-seo' ) );
		}

		$citation    = class_exists( 'MLS_Citations' ) ? MLS_Citations::score() : array( 'score' => 0 );
		$identity    = class_exists( 'MLS_SameAs' ) ? MLS_SameAs::get_identity() : array();
		$sameas_live = ! empty( $identity['business_name'] );
		$geo_run     = class_exists( 'MLS_Geogrid' ) ? MLS_Geogrid::latest_run() : null;
		$backlinks   = class_exists( 'MLS_Backlinks' ) ? MLS_Backlinks::score( MLS_Backlinks::parse_targets() ) : array(
			'score' => 0,
			'live'  => 0,
			'total' => 0,
		);
		$configured  = MLS_DataForSEO::is_configured();
		$has_secret  = '' !== MLS_DataForSEO::get_password();
		$test_url    = wp_nonce_url( admin_url( 'admin.php?page=' . MLS_Plugin::MENU_SLUG . '&mls_test_connection=1' ), 'mls_test_connection' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Local SEO Dashboard', 'midland-local-seo' ); ?></h1>

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php if ( isset( $_GET['creds'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'DataForSEO credentials saved.', 'midland-local-seo' ); ?></p></div>
			<?php endif; ?>
			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php if ( isset( $_GET['testok'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'DataForSEO connection successful.', 'midland-local-seo' ); ?></p></div>
			<?php endif; ?>
			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php if ( isset( $_GET['testfail'] ) ) : ?>
				<?php
				$err = get_transient( 'mls_test_error' );
				delete_transient( 'mls_test_error' );
				?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html__( 'DataForSEO connection failed: ', 'midland-local-seo' ) . esc_html( $err ? $err : '' ); ?></p></div>
			<?php endif; ?>

			<div class="mls-cards" style="display:flex;flex-wrap:wrap;gap:16px;margin:20px 0;">
				<?php
				$this->card( __( 'Citation Score', 'midland-local-seo' ), $citation['score'] . '%', admin_url( 'admin.php?page=mls-citations' ), __( 'Citation Audit →', 'midland-local-seo' ) );
				$this->card(
					__( 'sameAs Schema', 'midland-local-seo' ),
					$sameas_live ? __( 'Live', 'midland-local-seo' ) : __( 'Not set', 'midland-local-seo' ),
					admin_url( 'admin.php?page=mls-sameas' ),
					__( 'Identity →', 'midland-local-seo' )
				);
				$this->card(
					__( 'Last Geo-Grid', 'midland-local-seo' ),
					$geo_run ? ( ( null !== $geo_run->avg_rank ? $geo_run->avg_rank : '—' ) . ' ' . __( 'avg', 'midland-local-seo' ) ) : __( 'No runs', 'midland-local-seo' ),
					admin_url( 'admin.php?page=mls-geogrid' ),
					__( 'Geo-Grid →', 'midland-local-seo' )
				);
				$this->card(
					__( 'Backlink Progress', 'midland-local-seo' ),
					$backlinks['score'] . '%',
					admin_url( 'admin.php?page=mls-backlinks' ),
					__( 'Backlinks →', 'midland-local-seo' )
				);
				$this->card(
					__( 'GMB Optimizer', 'midland-local-seo' ),
					$configured ? __( 'Run scorecard', 'midland-local-seo' ) : __( 'Needs DataForSEO', 'midland-local-seo' ),
					admin_url( 'admin.php?page=mls-gmb-optimizer' ),
					__( 'Optimizer →', 'midland-local-seo' )
				);
				$this->card(
					__( 'Competitors', 'midland-local-seo' ),
					$configured ? __( 'View rivals', 'midland-local-seo' ) : __( 'Needs DataForSEO', 'midland-local-seo' ),
					admin_url( 'admin.php?page=mls-gmb-competitors' ),
					__( 'Audit →', 'midland-local-seo' )
				);
				?>
			</div>

			<hr>
			<h2><?php esc_html_e( 'DataForSEO Credentials', 'midland-local-seo' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Bring your own DataForSEO account (pay-as-you-go). Powers geo-grid, GMB optimizer/mirror, competitor audit, and backlinks. The password is encrypted at rest.', 'midland-local-seo' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'mls_save_credentials', '_mls_creds_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="mls_dfs_login"><?php esc_html_e( 'API Login', 'midland-local-seo' ); ?></label></th>
						<td><input type="text" id="mls_dfs_login" name="mls_dfs_login" class="regular-text" value="<?php echo esc_attr( MLS_DataForSEO::get_login() ); ?>" autocomplete="off"></td>
					</tr>
					<tr>
						<th><label for="mls_dfs_password"><?php esc_html_e( 'API Password', 'midland-local-seo' ); ?></label></th>
						<td>
							<input type="password" id="mls_dfs_password" name="mls_dfs_password" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo $has_secret ? esc_attr__( '•••• saved — leave blank to keep', 'midland-local-seo' ) : ''; ?>">
						</td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" name="mls_save_credentials" value="1" class="button button-primary"><?php esc_html_e( 'Save Credentials', 'midland-local-seo' ); ?></button>
					<?php if ( $configured ) : ?>
						<a href="<?php echo esc_url( $test_url ); ?>" class="button button-secondary" style="margin-left:8px;"><?php esc_html_e( 'Test Connection', 'midland-local-seo' ); ?></a>
					<?php endif; ?>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Render one summary card.
	 *
	 * @param string $title    Card title.
	 * @param string $value    Big value.
	 * @param string $link     Link URL.
	 * @param string $cta      Link label.
	 */
	private function card( $title, $value, $link, $cta ) {
		echo '<div style="flex:1;min-width:200px;border:1px solid #dcdcde;border-radius:8px;padding:16px;background:#fff;">';
		echo '<div style="font-size:13px;color:#646970;">' . esc_html( $title ) . '</div>';
		echo '<div style="font-size:24px;font-weight:700;margin:6px 0;">' . esc_html( $value ) . '</div>';
		echo '<a href="' . esc_url( $link ) . '">' . esc_html( $cta ) . '</a>';
		echo '</div>';
	}
}
