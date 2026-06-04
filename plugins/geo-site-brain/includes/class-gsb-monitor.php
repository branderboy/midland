<?php
/**
 * Scheduled monitoring + email alerts. After each weekly reindex the AI
 * Visibility score is snapshotted (by the visibility engine); this class emails
 * a plain-language digest and raises an alert when the score drops, so owners
 * and agencies are told when their AI visibility changes without logging in.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GSB_Monitor {

	const DROP_ALERT = 5; // points

	/**
	 * Called at the end of the weekly reindex. Sends the digest if enabled.
	 */
	public static function after_reindex() {
		if ( ! (int) GSB_Settings::get( 'enable_digest', 0 ) ) {
			return;
		}
		self::send_digest( false );
	}

	/**
	 * Build + send the digest. $force ignores the enable flag (manual test).
	 *
	 * @return true|WP_Error
	 */
	public static function send_digest( $force = false ) {
		if ( ! $force && ! (int) GSB_Settings::get( 'enable_digest', 0 ) ) {
			return new WP_Error( 'gsb_digest_off', __( 'Email digest is turned off.', 'geo-site-brain' ) );
		}

		$to = trim( (string) GSB_Settings::get( 'digest_email' ) );
		if ( '' === $to || ! is_email( $to ) ) {
			$to = get_option( 'admin_email' );
		}

		$overall = GSB_Visibility::overall_score();
		if ( null === $overall ) {
			return new WP_Error( 'gsb_no_data', __( 'No visibility data yet — scan first.', 'geo-site-brain' ) );
		}

		list( $delta, $previous ) = self::delta();
		$business = trim( (string) GSB_Settings::get( 'business_name' ) ) ?: get_bloginfo( 'name' );
		$dropped  = ( null !== $previous && $delta <= -self::DROP_ALERT );

		$subject = $dropped
			? sprintf( __( '⚠ AI Visibility dropped to %1$d for %2$s', 'geo-site-brain' ), $overall, $business )
			: sprintf( __( 'AI Visibility digest: %1$d for %2$s', 'geo-site-brain' ), $overall, $business );

		$body = self::render( $business, $overall, $delta, $previous, $dropped );

		add_filter( 'wp_mail_content_type', array( __CLASS__, 'html_content_type' ) );
		$sent = wp_mail( $to, $subject, $body );
		remove_filter( 'wp_mail_content_type', array( __CLASS__, 'html_content_type' ) );

		if ( ! $sent ) {
			return new WP_Error( 'gsb_mail', __( 'WordPress could not send the email. Check your site mailer.', 'geo-site-brain' ) );
		}
		GSB_Logger::info( 'monitor', 'AI Visibility digest sent to ' . $to . '.' );
		return true;
	}

	public static function html_content_type() {
		return 'text/html';
	}

	/** Change vs the previous recorded history point. */
	private static function delta() {
		$history = (array) GSB_Database::get_state( 'visibility_history', array() );
		if ( count( $history ) < 2 ) {
			return array( 0, null );
		}
		$last = (int) $history[ count( $history ) - 1 ]['score'];
		$prev = (int) $history[ count( $history ) - 2 ]['score'];
		return array( $last - $prev, $prev );
	}

	private static function render( $business, $overall, $delta, $previous, $dropped ) {
		$arrow = $delta > 0 ? '▲ +' . $delta : ( $delta < 0 ? '▼ ' . $delta : '— 0' );
		$color = $delta > 0 ? '#00794b' : ( $delta < 0 ? '#b32d2e' : '#50575e' );
		$engines = GSB_Database::get_visibility();
		$fixes   = array_slice( GSB_Database::get_recommendations( 'open' ), 0, 3 );
		$dash    = admin_url( 'admin.php?page=geo-site-brain' );

		$html  = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;color:#1d2327">';
		$html .= '<h2 style="margin:0 0 4px">' . esc_html( $business ) . '</h2>';
		$html .= '<p style="color:#50575e;margin:0 0 16px">' . esc_html__( 'AI Visibility digest', 'geo-site-brain' ) . '</p>';

		$html .= '<div style="border:1px solid #dcdcde;border-radius:8px;padding:18px;text-align:center;margin-bottom:16px">';
		$html .= '<div style="font-size:44px;font-weight:800">' . (int) $overall . '</div>';
		$html .= '<div style="color:' . $color . ';font-weight:700">' . esc_html( $arrow ) . '</div>';
		$html .= '<div style="color:#50575e;font-size:13px">' . esc_html__( 'Overall AI Visibility', 'geo-site-brain' ) . '</div>';
		$html .= '</div>';

		if ( $dropped ) {
			$html .= '<p style="background:#fcf0f1;border-left:4px solid #b32d2e;padding:10px 12px">'
				. esc_html__( 'Heads up: your AI visibility dropped this period. Check the Fix Queue.', 'geo-site-brain' ) . '</p>';
		}

		if ( $engines ) {
			$html .= '<table style="width:100%;border-collapse:collapse;margin-bottom:16px">';
			foreach ( $engines as $e ) {
				$html .= '<tr><td style="padding:6px 0;border-bottom:1px solid #f0f0f1">' . esc_html( GSB_Visibility::engine_label( $e->engine ) )
					. '</td><td style="padding:6px 0;border-bottom:1px solid #f0f0f1;text-align:right;font-weight:700">' . (int) $e->visibility_score . '</td></tr>';
			}
			$html .= '</table>';
		}

		if ( $fixes ) {
			$html .= '<h3>' . esc_html__( 'Top fixes', 'geo-site-brain' ) . '</h3><ol>';
			foreach ( $fixes as $f ) {
				$html .= '<li>' . esc_html( $f->title ) . '</li>';
			}
			$html .= '</ol>';
		}

		$html .= '<p><a href="' . esc_url( $dash ) . '" style="display:inline-block;background:#2271b1;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none">'
			. esc_html__( 'Open the dashboard', 'geo-site-brain' ) . '</a></p>';
		$html .= '</div>';
		return $html;
	}
}
