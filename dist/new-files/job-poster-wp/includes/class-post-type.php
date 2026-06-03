<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DPJP_Post_Type {
    public static function register(): void {
        register_post_type( 'dpjp_job', [
            'labels' => [
                'name'          => 'Job Listings',
                'singular_name' => 'Job Listing',
                'add_new'       => 'Add New Job',
                'add_new_item'  => 'Add New Job Listing',
                'edit_item'     => 'Edit Job Listing',
                'menu_name'     => 'Job Listings',
            ],
            'public'          => true,
            'has_archive'     => false, // Don't create /jobs/ archive — lets your custom /jobs/ page work
            'rewrite'         => [ 'slug' => 'jobs', 'with_front' => false ],
            'supports'        => [ 'title', 'editor', 'thumbnail' ],
            'show_in_rest'    => true,
            'menu_icon'       => 'dashicons-hammer',
            'menu_position'   => 25,
        ] );
    }
}
