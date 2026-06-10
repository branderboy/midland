<?php
/**
 * Reviews storage + competitor list.
 *
 * @package Midland_Review_Intel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom table for fetched reviews and the competitor target list.
 */
class MRI_DB {

	/**
	 * Reviews table name (with prefix).
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'mri_reviews';
	}

	/**
	 * Create the reviews table.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				company VARCHAR(190) NOT NULL,
				segment VARCHAR(50) NOT NULL DEFAULT '',
				rating TINYINT UNSIGNED NULL,
				review_date DATE NULL,
				review_text LONGTEXT NULL,
				owner_response LONGTEXT NULL,
				review_hash CHAR(32) NOT NULL,
				fetched_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY review_hash (review_hash),
				KEY company (company)
			) {$charset};"
		);
	}

	/**
	 * Insert a review, skipping duplicates (same company + date + text).
	 *
	 * @param array $row { company, segment, rating, review_date, review_text, owner_response }.
	 * @return bool True if inserted.
	 */
	public static function insert_review( $row ) {
		global $wpdb;

		$text = trim( (string) ( $row['review_text'] ?? '' ) );
		if ( '' === $text ) {
			return false; // Star-only reviews carry no language signal.
		}

		$hash = md5( $row['company'] . '|' . ( $row['review_date'] ?? '' ) . '|' . substr( $text, 0, 300 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . self::table() . // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				' (company, segment, rating, review_date, review_text, owner_response, review_hash, fetched_at)
				 VALUES (%s, %s, %d, %s, %s, %s, %s, %s)',
				$row['company'],
				$row['segment'] ?? '',
				(int) ( $row['rating'] ?? 0 ),
				$row['review_date'] ?: null,
				$text,
				(string) ( $row['owner_response'] ?? '' ),
				$hash,
				current_time( 'mysql' )
			)
		);

		return (bool) $result;
	}

	/**
	 * All reviews, optionally for one company.
	 *
	 * @param string $company Optional company filter.
	 * @return array[]
	 */
	public static function get_reviews( $company = '' ) {
		global $wpdb;
		$table = self::table();
		if ( '' !== $company ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE company = %s ORDER BY review_date DESC", $company ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY company, review_date DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Per-company summary: review count, average rating, negative count.
	 *
	 * @return array[]
	 */
	public static function get_summary() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_results(
			"SELECT company, segment, COUNT(*) AS reviews, ROUND(AVG(rating),1) AS avg_rating,
				SUM(CASE WHEN rating <= 3 THEN 1 ELSE 0 END) AS negative
			 FROM {$table} GROUP BY company, segment ORDER BY reviews DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Delete all stored reviews.
	 */
	public static function truncate() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'TRUNCATE TABLE ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Competitor target list. Seeded with the researched MD/DC market set.
	 *
	 * Each entry: { name, query, segment }. Query must match exactly one
	 * Google Maps listing.
	 *
	 * @return array[]
	 */
	public static function get_competitors() {
		$saved = get_option( 'mri_competitors', null );
		if ( is_array( $saved ) && ! empty( $saved ) ) {
			return $saved;
		}
		return self::default_competitors();
	}

	/**
	 * Persist the competitor list.
	 *
	 * @param array $competitors List of { name, query, segment }.
	 */
	public static function save_competitors( $competitors ) {
		update_option( 'mri_competitors', array_values( $competitors ), false );
	}

	/**
	 * The researched default target list (6 commercial flooring, 7 residential
	 * carpet, plus Midland itself for baseline).
	 *
	 * @return array[]
	 */
	public static function default_competitors() {
		return array(
			array( 'name' => 'Midland Floor Care', 'query' => 'Midland Floor Care, Maryland', 'segment' => 'own' ),
			array( 'name' => 'CB Flooring', 'query' => 'CB Flooring, Columbia, MD', 'segment' => 'commercial' ),
			array( 'name' => 'Metro Flooring Contractors', 'query' => 'Metro Flooring Contractors, Baltimore, MD', 'segment' => 'commercial' ),
			array( 'name' => 'Precision Flooring Services', 'query' => 'Precision Flooring Services, Northern VA', 'segment' => 'commercial' ),
			array( 'name' => 'GreenEdge Commercial Interiors', 'query' => 'GreenEdge Commercial Interiors, Washington DC', 'segment' => 'commercial' ),
			array( 'name' => 'Abbey Commercial Flooring', 'query' => 'Abbey Commercial Flooring, Washington DC', 'segment' => 'commercial' ),
			array( 'name' => 'Direct Solutions Flooring', 'query' => 'Direct Solutions Flooring, Washington DC', 'segment' => 'commercial' ),
			array( 'name' => 'JG Carpet Contractors', 'query' => 'JG Carpet Contractors, Essex, MD', 'segment' => 'residential' ),
			array( 'name' => 'Maryland Carpet and Tile', 'query' => 'Maryland Carpet and Tile, Gaithersburg, MD', 'segment' => 'residential' ),
			array( 'name' => 'PriceCo Floors', 'query' => 'PriceCo Floors, Maryland', 'segment' => 'residential' ),
			array( 'name' => "Moe's Carpet and Flooring Services", 'query' => "Moe's Carpet and Flooring Services, Maryland", 'segment' => 'residential' ),
			array( 'name' => 'The Carpet Center', 'query' => 'The Carpet Center, Maryland', 'segment' => 'residential' ),
			array( 'name' => 'Aladdin Carpet and Floors', 'query' => 'Aladdin Carpet and Floors, Rockville, MD', 'segment' => 'residential' ),
			array( 'name' => 'Classic Carpets Inc', 'query' => 'Classic Carpets, Jessup, MD', 'segment' => 'residential' ),
		);
	}
}
