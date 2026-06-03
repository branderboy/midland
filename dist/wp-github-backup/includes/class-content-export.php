<?php
/**
 * Content export class for WP GitHub Backup.
 *
 * Exports WordPress posts and pages as individual HTML files
 * that can be pushed to GitHub for readable backup.
 *
 * @package WPGitHubBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WGB_Content_Export {

	/**
	 * Export all published posts as individual HTML files.
	 *
	 * @return array Array of file data with 'repo_path' and 'content' keys.
	 */
	public static function export_posts() {
		return self::export_post_type( 'post', 'posts' );
	}

	/**
	 * Export all published pages as individual HTML files.
	 *
	 * @return array Array of file data with 'repo_path' and 'content' keys.
	 */
	public static function export_pages() {
		return self::export_post_type( 'page', 'pages' );
	}

	/**
	 * Export all published items of a given post type.
	 *
	 * @param string $post_type WordPress post type.
	 * @param string $directory Directory name in the repo.
	 * @return array Array of file data.
	 */
	private static function export_post_type( $post_type, $directory ) {
		$items = get_posts( array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'ASC',
		) );

		$files = array();

		foreach ( $items as $item ) {
			$slug     = sanitize_file_name( $item->post_name );
			$filename = $slug . '.html';

			$html = self::build_html( $item );

			$files[] = array(
				'repo_path' => $directory . '/' . $filename,
				'content'   => $html,
			);
		}

		return $files;
	}

	/**
	 * Build an HTML representation of a post/page.
	 *
	 * @param WP_Post $post The post object.
	 * @return string HTML content.
	 */
	private static function build_html( $post ) {
		$title     = esc_html( $post->post_title );
		$content   = apply_filters( 'the_content', $post->post_content );
		$date      = $post->post_date;
		$modified  = $post->post_modified;
		$author_id = $post->post_author;
		$author    = get_the_author_meta( 'display_name', $author_id );
		$permalink = get_permalink( $post->ID );
		$excerpt   = esc_html( $post->post_excerpt );
		$type      = $post->post_type;

		// Get categories and tags for posts.
		$categories = '';
		$tags       = '';
		if ( 'post' === $type ) {
			$cats = get_the_category( $post->ID );
			if ( ! empty( $cats ) ) {
				$cat_names  = wp_list_pluck( $cats, 'name' );
				$categories = esc_html( implode( ', ', $cat_names ) );
			}

			$post_tags = get_the_tags( $post->ID );
			if ( ! empty( $post_tags ) ) {
				$tag_names = wp_list_pluck( $post_tags, 'name' );
				$tags      = esc_html( implode( ', ', $tag_names ) );
			}
		}

		// Get featured image URL.
		$thumbnail = '';
		if ( has_post_thumbnail( $post->ID ) ) {
			$thumbnail = get_the_post_thumbnail_url( $post->ID, 'full' );
		}

		// Pull the SEO <title> from Yoast meta if available; fall back to post_title.
		$seo_title_meta = get_post_meta( $post->ID, '_yoast_wpseo_title', true );
		$seo_title      = '' !== $seo_title_meta ? esc_html( $seo_title_meta ) : $title;

		$html  = "<!DOCTYPE html>\n<html>\n<head>\n";
		$html .= "<meta charset=\"UTF-8\">\n";
		$html .= "<title>{$seo_title}</title>\n";
		// Admin-facing post_title — kept separate so round-tripping a deploy never
		// clobbers a clean WP admin title with the long SEO string.
		$html .= "<meta name=\"admin-title\" content=\"{$title}\">\n";
		$html .= "<meta name=\"date\" content=\"{$date}\">\n";
		$html .= "<meta name=\"modified\" content=\"{$modified}\">\n";
		$html .= "<meta name=\"author\" content=\"" . esc_attr( $author ) . "\">\n";
		$html .= "<meta name=\"type\" content=\"{$type}\">\n";

		if ( ! empty( $permalink ) ) {
			$html .= "<meta name=\"permalink\" content=\"" . esc_url( $permalink ) . "\">\n";
		}
		if ( ! empty( $excerpt ) ) {
			$html .= "<meta name=\"excerpt\" content=\"{$excerpt}\">\n";
		}
		if ( ! empty( $categories ) ) {
			$html .= "<meta name=\"categories\" content=\"{$categories}\">\n";
		}
		if ( ! empty( $tags ) ) {
			$html .= "<meta name=\"tags\" content=\"{$tags}\">\n";
		}
		if ( ! empty( $thumbnail ) ) {
			$html .= "<meta name=\"featured-image\" content=\"" . esc_url( $thumbnail ) . "\">\n";
		}

		// Elementor page data — the actual source of truth for rendering.
		// Without round-tripping this, edits to post_content never reach the
		// frontend because Elementor renders from _elementor_data, not from
		// post_content.
		$elementor_data = get_post_meta( $post->ID, '_elementor_data', true );
		if ( ! empty( $elementor_data ) && '[]' !== $elementor_data ) {
			$html .= "<script type=\"application/x-elementor-data\">\n"
				. base64_encode( is_string( $elementor_data ) ? $elementor_data : wp_json_encode( $elementor_data ) )
				. "\n</script>\n";
		}

		$elementor_page_settings = get_post_meta( $post->ID, '_elementor_page_settings', true );
		if ( ! empty( $elementor_page_settings ) ) {
			$serialized = is_array( $elementor_page_settings ) ? wp_json_encode( $elementor_page_settings ) : $elementor_page_settings;
			$html .= "<script type=\"application/x-elementor-page-settings\">\n"
				. base64_encode( $serialized )
				. "\n</script>\n";
		}

		$elementor_edit_mode = get_post_meta( $post->ID, '_elementor_edit_mode', true );
		if ( ! empty( $elementor_edit_mode ) ) {
			$html .= "<meta name=\"elementor-edit-mode\" content=\"" . esc_attr( $elementor_edit_mode ) . "\">\n";
		}

		$elementor_version = get_post_meta( $post->ID, '_elementor_version', true );
		if ( ! empty( $elementor_version ) ) {
			$html .= "<meta name=\"elementor-version\" content=\"" . esc_attr( $elementor_version ) . "\">\n";
		}

		$elementor_template_type = get_post_meta( $post->ID, '_elementor_template_type', true );
		if ( ! empty( $elementor_template_type ) ) {
			$html .= "<meta name=\"elementor-template-type\" content=\"" . esc_attr( $elementor_template_type ) . "\">\n";
		}

		$html .= "</head>\n<body>\n";
		$html .= "<h1>{$title}</h1>\n";
		$html .= "<div class=\"meta\">\n";
		$html .= "  <p>Author: " . esc_html( $author ) . "</p>\n";
		$html .= "  <p>Published: {$date}</p>\n";
		$html .= "  <p>Last Modified: {$modified}</p>\n";

		if ( ! empty( $categories ) ) {
			$html .= "  <p>Categories: {$categories}</p>\n";
		}
		if ( ! empty( $tags ) ) {
			$html .= "  <p>Tags: {$tags}</p>\n";
		}

		$html .= "</div>\n";
		$html .= "<div class=\"content\">\n{$content}\n</div>\n";
		$html .= "</body>\n</html>\n";

		return $html;
	}
}
