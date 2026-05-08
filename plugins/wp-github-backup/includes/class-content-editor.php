<?php
/**
 * Content Editor — manage posts, pages, metadata, structured data, and URLs.
 *
 * @package WPGitHubBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WGB_Content_Editor {

	/**
	 * Get a list of posts or pages.
	 *
	 * @param string $post_type 'post' or 'page'.
	 * @param int    $paged     Page number.
	 * @param int    $per_page  Items per page.
	 * @param string $search    Search term.
	 * @return array
	 */
	public static function get_items( $post_type = 'post', $paged = 1, $per_page = 20, $search = '' ) {
		$args = array(
			'post_type'      => sanitize_key( $post_type ),
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		$query = new WP_Query( $args );
		$items = array();

		foreach ( $query->posts as $p ) {
			$items[] = array(
				'ID'         => $p->ID,
				'title'      => $p->post_title,
				'status'     => $p->post_status,
				'date'       => $p->post_date,
				'modified'   => $p->post_modified,
				'slug'       => $p->post_name,
				'permalink'  => get_permalink( $p->ID ),
				'author'     => get_the_author_meta( 'display_name', $p->post_author ),
			);
		}

		return array(
			'items'       => $items,
			'total'       => $query->found_posts,
			'total_pages' => $query->max_num_pages,
			'page'        => $paged,
		);
	}

	/**
	 * Get a single post/page with all editable data.
	 *
	 * @param int $post_id Post ID.
	 * @return array|WP_Error
	 */
	public static function get_item( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'wp-github-backup' ) );
		}

		// Core fields.
		$data = array(
			'ID'            => $post->ID,
			'post_type'     => $post->post_type,
			'title'         => $post->post_title,
			'content'       => $post->post_content,
			'excerpt'       => $post->post_excerpt,
			'status'        => $post->post_status,
			'slug'          => $post->post_name,
			'permalink'     => get_permalink( $post->ID ),
			'date'          => $post->post_date,
			'modified'      => $post->post_modified,
			'author_id'     => $post->post_author,
			'author'        => get_the_author_meta( 'display_name', $post->post_author ),
		);

		// Categories & tags (posts only).
		if ( 'post' === $post->post_type ) {
			$data['categories'] = wp_get_post_categories( $post->ID, array( 'fields' => 'all' ) );
			$data['tags']       = wp_get_post_tags( $post->ID, array( 'fields' => 'all' ) );
		}

		// Featured image.
		$thumb_id = get_post_thumbnail_id( $post->ID );
		$data['featured_image_id']  = $thumb_id ? $thumb_id : 0;
		$data['featured_image_url'] = $thumb_id ? wp_get_attachment_url( $thumb_id ) : '';

		// Custom meta fields (exclude internal/hidden ones).
		$all_meta  = get_post_meta( $post->ID );
		$meta_list = array();
		foreach ( $all_meta as $key => $values ) {
			// Skip hidden meta that starts with _ (WordPress internals).
			if ( 0 === strpos( $key, '_' ) ) {
				continue;
			}
			$meta_list[] = array(
				'key'   => $key,
				'value' => count( $values ) === 1 ? $values[0] : $values,
			);
		}
		$data['meta'] = $meta_list;

		// Structured data (JSON-LD) stored as post meta.
		$schema = get_post_meta( $post->ID, '_wgb_schema_json_ld', true );
		$data['schema_json_ld'] = $schema ? $schema : '';

		return $data;
	}

	/**
	 * Update a post/page with provided data.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    Fields to update.
	 * @return array|WP_Error
	 */
	public static function update_item( $post_id, $data ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'wp-github-backup' ) );
		}

		$post_data = array( 'ID' => $post_id );

		// Core fields.
		if ( isset( $data['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $data['title'] );
		}
		if ( isset( $data['content'] ) ) {
			$post_data['post_content'] = wp_kses_post( $data['content'] );
		}
		if ( isset( $data['excerpt'] ) ) {
			$post_data['post_excerpt'] = sanitize_textarea_field( $data['excerpt'] );
		}
		if ( isset( $data['status'] ) ) {
			$allowed = array( 'publish', 'draft', 'pending', 'private', 'future' );
			if ( in_array( $data['status'], $allowed, true ) ) {
				$post_data['post_status'] = $data['status'];
			}
		}

		// URL / slug.
		if ( isset( $data['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $data['slug'] );
		}

		$result = wp_update_post( $post_data, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Featured image.
		if ( isset( $data['featured_image_id'] ) ) {
			$img_id = absint( $data['featured_image_id'] );
			if ( $img_id > 0 ) {
				set_post_thumbnail( $post_id, $img_id );
			} else {
				delete_post_thumbnail( $post_id );
			}
		}

		// Custom meta fields.
		if ( isset( $data['meta'] ) && is_array( $data['meta'] ) ) {
			foreach ( $data['meta'] as $meta_item ) {
				if ( empty( $meta_item['key'] ) ) {
					continue;
				}
				$key = sanitize_key( $meta_item['key'] );
				// Skip internal keys.
				if ( 0 === strpos( $key, '_' ) ) {
					continue;
				}
				if ( isset( $meta_item['_delete'] ) && $meta_item['_delete'] ) {
					delete_post_meta( $post_id, $key );
				} else {
					update_post_meta( $post_id, $key, sanitize_text_field( $meta_item['value'] ) );
				}
			}
		}

		// Structured data (JSON-LD).
		if ( isset( $data['schema_json_ld'] ) ) {
			$schema = wp_unslash( $data['schema_json_ld'] );
			// Validate JSON if not empty.
			if ( ! empty( $schema ) ) {
				$decoded = json_decode( $schema );
				if ( null === $decoded && json_last_error() !== JSON_ERROR_NONE ) {
					return new WP_Error( 'invalid_json', __( 'Schema JSON-LD is not valid JSON.', 'wp-github-backup' ) );
				}
				update_post_meta( $post_id, '_wgb_schema_json_ld', $schema );
			} else {
				delete_post_meta( $post_id, '_wgb_schema_json_ld' );
			}
		}

		// Categories (posts only).
		if ( 'post' === $post->post_type && isset( $data['category_ids'] ) ) {
			$cat_ids = array_map( 'absint', (array) $data['category_ids'] );
			wp_set_post_categories( $post_id, $cat_ids );
		}

		// Tags (posts only).
		if ( 'post' === $post->post_type && isset( $data['tags'] ) ) {
			$tags = sanitize_text_field( $data['tags'] );
			wp_set_post_tags( $post_id, $tags );
		}

		return self::get_item( $post_id );
	}

	/**
	 * Output schema JSON-LD in the head for posts that have it.
	 *
	 * Deduplicates schema types that are already output by WCM_SEO
	 * (HomeAndConstructionBusiness, LocalBusiness, WebSite) or Yoast
	 * to prevent Google "Duplicate field" errors.
	 */
	public static function output_schema_json_ld() {
		if ( ! is_singular() ) {
			return;
		}
		$schema = get_post_meta( get_the_ID(), '_wgb_schema_json_ld', true );
		if ( empty( $schema ) ) {
			return;
		}

		$decoded = json_decode( $schema, true );
		if ( ! is_array( $decoded ) ) {
			return;
		}

		// Types already output by WCM_SEO::output_structured_data().
		$skip_types = array(
			'HomeAndConstructionBusiness',
			'LocalBusiness',
			'WebSite',
		);

		// If Yoast is active, it may output its own FAQPage schema — skip ours.
		if ( defined( 'WPSEO_VERSION' ) ) {
			$skip_types[] = 'FAQPage';
		}

		// Filter: if top-level is a list of schema objects, deduplicate.
		if ( isset( $decoded[0] ) && is_array( $decoded[0] ) ) {
			$filtered = array();
			foreach ( $decoded as $obj ) {
				$type = $obj['@type'] ?? '';
				// Handle array types like ["HomeAndConstructionBusiness", "LocalBusiness"].
				$types = is_array( $type ) ? $type : array( $type );
				$dominated = false;
				foreach ( $types as $t ) {
					if ( in_array( $t, $skip_types, true ) ) {
						$dominated = true;
						break;
					}
				}
				if ( ! $dominated ) {
					$filtered[] = $obj;
				}
			}
			$decoded = $filtered;
		} else {
			// Single schema object.
			$type  = $decoded['@type'] ?? '';
			$types = is_array( $type ) ? $type : array( $type );
			foreach ( $types as $t ) {
				if ( in_array( $t, $skip_types, true ) ) {
					return;
				}
			}
			$decoded = array( $decoded );
		}

		if ( empty( $decoded ) ) {
			return;
		}

		// Output each remaining schema as its own block.
		foreach ( $decoded as $obj ) {
			if ( ! isset( $obj['@context'] ) ) {
				$obj['@context'] = 'https://schema.org';
			}
			echo '<script type="application/ld+json">' . wp_json_encode( $obj, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
		}
	}
}
