<?php
/**
 * Plugin Name: Midland Contractor Gallery
 * Plugin URI:  https://tagglefish.com/
 * Description: Native WordPress gallery for a contractor's work photos hosted on pCloud public links. Renders a responsive grid + lightbox via the [contractor_gallery code="..."] shortcode. No pCloud OAuth needed — uses the public-link endpoints.
 * Version:     1.0.0
 * Author:      TaggleFish
 * License:     GPL v2 or later
 * Text Domain: midland-contractor-gallery
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MCG_VERSION', '1.0.0' );
define( 'MCG_URL',     plugin_dir_url( __FILE__ ) );
define( 'MCG_PATH',    plugin_dir_path( __FILE__ ) );
define( 'MCG_API',     'https://api.pcloud.com' );

add_shortcode( 'contractor_gallery', 'mcg_render_shortcode' );
add_action( 'wp_enqueue_scripts', 'mcg_register_assets' );
add_action( 'admin_post_mcg_flush_cache', 'mcg_handle_flush_cache' );
add_action( 'admin_menu', 'mcg_add_admin_menu' );

function mcg_register_assets() {
    wp_register_style( 'midland-contractor-gallery', MCG_URL . 'assets/gallery.css', array(), MCG_VERSION );
    wp_register_script( 'midland-contractor-gallery', MCG_URL . 'assets/gallery.js', array(), MCG_VERSION, true );
}

/**
 * Render the [contractor_gallery] shortcode.
 *
 * Attributes:
 *   code      (required) pCloud public link code (the part after ?code= in the share URL).
 *   columns   Desired column count at desktop widths. Default 4.
 *   gap       Px gap between thumbs. Default 8.
 *   thumb     Thumbnail size in NNNxNNN format. Default 320x320.
 *   full      Lightbox image size in NNNxNNN format. Default 1600x1600.
 *   cache     Seconds to cache the folder listing. Default 3600 (1 hour). 0 disables caching.
 *   sort      "name" | "newest" | "oldest" | "pcloud" (pCloud's own order). Default "pcloud".
 *   limit     Cap the number of images rendered. 0 means all. Default 0.
 */
function mcg_render_shortcode( $atts ) {
    $atts = shortcode_atts(
        array(
            'code'    => '',
            'columns' => '4',
            'gap'     => '8',
            'thumb'   => '320x320',
            'full'    => '1600x1600',
            'cache'   => '3600',
            'sort'    => 'pcloud',
            'limit'   => '0',
        ),
        $atts,
        'contractor_gallery'
    );

    $code = preg_replace( '/[^A-Za-z0-9]/', '', (string) $atts['code'] );
    if ( '' === $code ) {
        return mcg_admin_only_error( 'Missing required <code>code</code> attribute.' );
    }

    $columns = max( 1, min( 8, (int) $atts['columns'] ) );
    $gap     = max( 0, min( 64, (int) $atts['gap'] ) );
    $thumb   = preg_match( '/^\d{2,4}x\d{2,4}$/', $atts['thumb'] ) ? $atts['thumb'] : '320x320';
    $full    = preg_match( '/^\d{2,4}x\d{2,4}$/', $atts['full'] )  ? $atts['full']  : '1600x1600';
    $cache   = max( 0, (int) $atts['cache'] );
    $sort    = in_array( $atts['sort'], array( 'name', 'newest', 'oldest', 'pcloud' ), true ) ? $atts['sort'] : 'pcloud';
    $limit   = max( 0, (int) $atts['limit'] );

    $images = mcg_fetch_images( $code, $cache );
    if ( is_wp_error( $images ) ) {
        return mcg_admin_only_error( esc_html( $images->get_error_message() ) );
    }
    if ( empty( $images ) ) {
        return mcg_admin_only_error( 'pCloud folder returned no images.' );
    }

    if ( 'name' === $sort ) {
        usort( $images, function ( $a, $b ) {
            return strnatcasecmp( $a['name'], $b['name'] );
        } );
    } elseif ( 'newest' === $sort ) {
        usort( $images, function ( $a, $b ) {
            return $b['modified'] <=> $a['modified'];
        } );
    } elseif ( 'oldest' === $sort ) {
        usort( $images, function ( $a, $b ) {
            return $a['modified'] <=> $b['modified'];
        } );
    }

    if ( $limit > 0 ) {
        $images = array_slice( $images, 0, $limit );
    }

    wp_enqueue_style( 'midland-contractor-gallery' );
    wp_enqueue_script( 'midland-contractor-gallery' );

    $gallery_id = 'mcg-' . wp_generate_uuid4();

    $items_html = '';
    foreach ( $images as $img ) {
        $thumb_url = mcg_build_thumb_url( $code, $img['fileid'], $thumb, true );
        $full_url  = mcg_build_thumb_url( $code, $img['fileid'], $full, false );
        $alt       = $img['name'];

        $items_html .= sprintf(
            '<button type="button" class="mcg-item" data-full="%1$s" data-caption="%2$s" aria-label="%3$s">'
            . '<img src="%4$s" alt="%2$s" loading="lazy" decoding="async" />'
            . '</button>',
            esc_url( $full_url ),
            esc_attr( $alt ),
            esc_attr__( 'Open image', 'midland-contractor-gallery' ),
            esc_url( $thumb_url )
        );
    }

    $grid_style = sprintf(
        '--mcg-cols:%d;--mcg-gap:%dpx;',
        $columns,
        $gap
    );

    return sprintf(
        '<div id="%1$s" class="mcg-gallery" style="%2$s" data-count="%3$d">%4$s</div>',
        esc_attr( $gallery_id ),
        esc_attr( $grid_style ),
        count( $images ),
        $items_html
    );
}

/**
 * Fetch the public folder listing from pCloud and normalize it to a flat
 * list of images. Caches the result in a transient.
 *
 * @return array<int, array{fileid:int,name:string,modified:int}>|WP_Error
 */
function mcg_fetch_images( $code, $cache_seconds ) {
    $transient = 'mcg_listing_' . md5( $code );

    if ( $cache_seconds > 0 ) {
        $cached = get_transient( $transient );
        if ( is_array( $cached ) ) {
            return $cached;
        }
    }

    $url      = MCG_API . '/showpublink?code=' . rawurlencode( $code );
    $response = wp_remote_get( $url, array( 'timeout' => 15 ) );

    if ( is_wp_error( $response ) ) {
        return $response;
    }
    $code_http = wp_remote_retrieve_response_code( $response );
    if ( 200 !== (int) $code_http ) {
        return new WP_Error( 'mcg_http', 'pCloud HTTP ' . $code_http );
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $body ) || ! isset( $body['result'] ) ) {
        return new WP_Error( 'mcg_bad_response', 'Malformed pCloud response.' );
    }
    if ( 0 !== (int) $body['result'] ) {
        $err = isset( $body['error'] ) ? (string) $body['error'] : 'pCloud error ' . $body['result'];
        return new WP_Error( 'mcg_pcloud_error', $err );
    }

    $images = array();
    if ( isset( $body['metadata']['contents'] ) && is_array( $body['metadata']['contents'] ) ) {
        mcg_walk_contents( $body['metadata']['contents'], $images );
    }

    if ( $cache_seconds > 0 ) {
        set_transient( $transient, $images, $cache_seconds );
    }
    return $images;
}

/**
 * Recursively flatten pCloud folder contents and keep only image files.
 */
function mcg_walk_contents( array $contents, array &$out ) {
    foreach ( $contents as $entry ) {
        if ( ! empty( $entry['isfolder'] ) ) {
            if ( isset( $entry['contents'] ) && is_array( $entry['contents'] ) ) {
                mcg_walk_contents( $entry['contents'], $out );
            }
            continue;
        }
        $cat = isset( $entry['category'] ) ? (int) $entry['category'] : 0; // 1 = image in pCloud's scheme
        $is_image = ( 1 === $cat );
        if ( ! $is_image && isset( $entry['name'] ) ) {
            $is_image = (bool) preg_match( '/\.(jpe?g|png|gif|webp|avif|bmp|tiff?|heic|heif)$/i', (string) $entry['name'] );
        }
        if ( ! $is_image ) {
            continue;
        }
        $out[] = array(
            'fileid'   => isset( $entry['fileid'] ) ? (int) $entry['fileid'] : 0,
            'name'     => isset( $entry['name'] ) ? (string) $entry['name'] : '',
            'modified' => isset( $entry['modified'] ) ? strtotime( (string) $entry['modified'] ) : 0,
        );
    }
}

function mcg_build_thumb_url( $code, $fileid, $size, $crop = true ) {
    // crop=1 fills the box (used for square grid thumbs).
    // crop=0 preserves the original aspect (used for the lightbox image).
    return add_query_arg(
        array(
            'code'   => $code,
            'fileid' => (int) $fileid,
            'size'   => $size,
            'crop'   => $crop ? 1 : 0,
            'type'   => 'auto',
        ),
        MCG_API . '/getpubthumb'
    );
}

function mcg_admin_only_error( $message ) {
    if ( ! current_user_can( 'edit_posts' ) ) {
        return '';
    }
    return '<p class="mcg-error"><strong>contractor_gallery:</strong> ' . $message . '</p>';
}

function mcg_add_admin_menu() {
    add_management_page(
        __( 'Contractor Gallery', 'midland-contractor-gallery' ),
        __( 'Contractor Gallery', 'midland-contractor-gallery' ),
        'manage_options',
        'midland-contractor-gallery',
        'mcg_render_admin_page'
    );
}

function mcg_render_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $action  = admin_url( 'admin-post.php' );
    $nonce   = wp_create_nonce( 'mcg_flush_cache' );
    $flushed = isset( $_GET['flushed'] ) ? (int) $_GET['flushed'] : 0;
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Midland Contractor Gallery', 'midland-contractor-gallery' ); ?></h1>
        <p><?php esc_html_e( 'Renders pCloud public folders as a native WP gallery via the [contractor_gallery] shortcode.', 'midland-contractor-gallery' ); ?></p>

        <h2><?php esc_html_e( 'Shortcode', 'midland-contractor-gallery' ); ?></h2>
        <pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;display:inline-block">[contractor_gallery code="VZHpIO5Z7KvjcxUp7Dho35lBGEeKe0uTT2CV" columns="4" sort="newest"]</pre>

        <h2><?php esc_html_e( 'Cache', 'midland-contractor-gallery' ); ?></h2>
        <p><?php esc_html_e( 'Folder listings are cached per pCloud code (default 1 hour). Flush after uploading new photos to pCloud so the gallery picks them up.', 'midland-contractor-gallery' ); ?></p>
        <?php if ( $flushed ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Cache flushed.', 'midland-contractor-gallery' ); ?></p></div>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url( $action ); ?>">
            <input type="hidden" name="action" value="mcg_flush_cache" />
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />
            <p><button type="submit" class="button button-secondary"><?php esc_html_e( 'Flush all gallery caches', 'midland-contractor-gallery' ); ?></button></p>
        </form>
    </div>
    <?php
}

function mcg_handle_flush_cache() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Forbidden', 403 );
    }
    check_admin_referer( 'mcg_flush_cache' );

    global $wpdb;
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_mcg\\_listing\\_%' OR option_name LIKE '\\_transient\\_timeout\\_mcg\\_listing\\_%'" );

    wp_safe_redirect( add_query_arg( array( 'page' => 'midland-contractor-gallery', 'flushed' => 1 ), admin_url( 'tools.php' ) ) );
    exit;
}
