<?php
/**
 * Gravity-Forms-style admin list for Smart Forms.
 *
 * Built on WordPress core's WP_List_Table so the screen gets the familiar
 * chrome operators expect: checkboxes + bulk actions, status filter views
 * (All / Active / Inactive / Trash), a search box, sortable columns, and
 * per-row hover actions (Edit | Entries | Duplicate | Trash).
 *
 * The controller (SFCO_Admin::render_forms_page) handles the action requests;
 * this class is purely the presentation + data prep layer.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class SFCO_Forms_List_Table extends WP_List_Table {

    /** Tallies for the status filter links, filled during prepare_items(). */
    private $status_counts = array( 'all' => 0, 'active' => 0, 'inactive' => 0, 'trash' => 0 );

    public function __construct() {
        parent::__construct( array(
            'singular' => 'form',
            'plural'   => 'forms',
            'ajax'     => false,
        ) );
    }

    public function get_columns() {
        return array(
            'cb'         => '<input type="checkbox" />',
            'sf_status'  => __( 'Status', 'smart-forms-for-midland' ),
            'title'      => __( 'Title', 'smart-forms-for-midland' ),
            'id'         => __( 'ID', 'smart-forms-for-midland' ),
            'entries'    => __( 'Entries', 'smart-forms-for-midland' ),
            'views'      => __( 'Views', 'smart-forms-for-midland' ),
            'conversion' => __( 'Conversion', 'smart-forms-for-midland' ),
            'shortcode'  => __( 'Shortcode', 'smart-forms-for-midland' ),
        );
    }

    protected function get_sortable_columns() {
        return array(
            'sf_status'  => array( 'sf_status', false ),
            'title'      => array( 'title', false ),
            'id'         => array( 'id', true ),
            'entries'    => array( 'entries', false ),
            'views'      => array( 'views', false ),
            'conversion' => array( 'conversion', false ),
        );
    }

    public function no_items() {
        esc_html_e( 'No forms found.', 'smart-forms-for-midland' );
    }

    protected function column_cb( $item ) {
        return sprintf( '<input type="checkbox" name="form[]" value="%d" />', (int) $item['id'] );
    }

    protected function column_sf_status( $item ) {
        $map = array(
            'active'   => array( '#d1fae5', '#065f46', '● ' . __( 'Active', 'smart-forms-for-midland' ) ),
            'inactive' => array( '#f3f4f6', '#6b7280', '○ ' . __( 'Inactive', 'smart-forms-for-midland' ) ),
            'trash'    => array( '#fde2e1', '#7a1d1d', __( 'Trash', 'smart-forms-for-midland' ) ),
        );
        $s = $map[ $item['sf_status'] ] ?? $map['inactive'];
        return sprintf(
            '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:600;background:%s;color:%s;">%s</span>',
            esc_attr( $s[0] ),
            esc_attr( $s[1] ),
            esc_html( $s[2] )
        );
    }

    protected function column_title( $item ) {
        $edit_url = admin_url( 'admin.php?page=smart-forms-edit-form&form_id=' . (int) $item['id'] );

        $actions = array();
        if ( 'trash' === $item['sf_status'] ) {
            $actions['restore'] = sprintf(
                '<a href="%s">%s</a>',
                esc_url( $this->action_url( 'restore', $item['id'] ) ),
                esc_html__( 'Restore', 'smart-forms-for-midland' )
            );
            $actions['delete'] = sprintf(
                '<a href="%s" style="color:#b32d2e;" onclick="return confirm(%s);">%s</a>',
                esc_url( $this->action_url( 'delete', $item['id'] ) ),
                esc_attr( wp_json_encode( __( 'Delete this form permanently? Collected entries are kept, but this cannot be undone.', 'smart-forms-for-midland' ) ) ),
                esc_html__( 'Delete Permanently', 'smart-forms-for-midland' )
            );
        } else {
            $entries_url = admin_url( 'admin.php?page=smart-forms-form-entries&form_id=' . (int) $item['id'] );

            $actions['edit']      = sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'smart-forms-for-midland' ) );
            $actions['entries']   = sprintf( '<a href="%s">%s</a>', esc_url( $entries_url ), esc_html__( 'Entries', 'smart-forms-for-midland' ) );
            $actions['duplicate'] = sprintf( '<a href="%s">%s</a>', esc_url( $this->action_url( 'duplicate', $item['id'] ) ), esc_html__( 'Duplicate', 'smart-forms-for-midland' ) );

            $toggle_label = ( 'active' === $item['sf_status'] )
                ? __( 'Deactivate', 'smart-forms-for-midland' )
                : __( 'Activate', 'smart-forms-for-midland' );
            $actions['toggle'] = sprintf( '<a href="%s">%s</a>', esc_url( $this->action_url( 'toggle', $item['id'] ) ), esc_html( $toggle_label ) );

            $actions['trash'] = sprintf(
                '<a href="%s" style="color:#b32d2e;">%s</a>',
                esc_url( $this->action_url( 'trash', $item['id'] ) ),
                esc_html__( 'Trash', 'smart-forms-for-midland' )
            );
        }

        $title = sprintf( '<strong><a href="%s">%s</a></strong>', esc_url( $edit_url ), esc_html( $item['title'] ) );
        return $title . $this->row_actions( $actions );
    }

    protected function column_default( $item, $column_name ) {
        switch ( $column_name ) {
            case 'id':
                return (int) $item['id'];
            case 'entries':
                $url = admin_url( 'admin.php?page=smart-forms-form-entries&form_id=' . (int) $item['id'] );
                return sprintf( '<a href="%s">%d</a>', esc_url( $url ), (int) $item['entries'] );
            case 'views':
                return (int) $item['views'];
            case 'conversion':
                return esc_html( $item['conversion'] ) . '%';
            case 'shortcode':
                return '<code style="background:#f6f7f7;padding:3px 8px;border-radius:3px;">[sfco_form id="' . (int) $item['id'] . '"]</code>';
            default:
                return '';
        }
    }

    protected function get_bulk_actions() {
        if ( 'trash' === $this->current_status() ) {
            return array(
                'restore' => __( 'Restore', 'smart-forms-for-midland' ),
                'delete'  => __( 'Delete Permanently', 'smart-forms-for-midland' ),
            );
        }
        return array(
            'activate'   => __( 'Activate', 'smart-forms-for-midland' ),
            'deactivate' => __( 'Deactivate', 'smart-forms-for-midland' ),
            'trash'      => __( 'Move to Trash', 'smart-forms-for-midland' ),
        );
    }

    protected function get_views() {
        $current = $this->current_status();
        $base    = admin_url( 'admin.php?page=smart-forms' );

        $link = function ( $key, $label, $count ) use ( $current, $base ) {
            $url   = ( 'all' === $key ) ? $base : add_query_arg( 'status', $key, $base );
            $class = ( $current === $key ) ? ' class="current"' : '';
            return sprintf(
                '<a href="%s"%s>%s <span class="count">(%d)</span></a>',
                esc_url( $url ),
                $class,
                esc_html( $label ),
                (int) $count
            );
        };

        return array(
            'all'      => $link( 'all', __( 'All', 'smart-forms-for-midland' ), $this->status_counts['all'] ),
            'active'   => $link( 'active', __( 'Active', 'smart-forms-for-midland' ), $this->status_counts['active'] ),
            'inactive' => $link( 'inactive', __( 'Inactive', 'smart-forms-for-midland' ), $this->status_counts['inactive'] ),
            'trash'    => $link( 'trash', __( 'Trash', 'smart-forms-for-midland' ), $this->status_counts['trash'] ),
        );
    }

    public function prepare_items() {
        $this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

        // Pull every form, normalise status, and attach stats.
        $items = array();
        foreach ( (array) SFCO_Database::get_forms() as $form ) {
            $stats = SFCO_Database::get_form_stats( $form->id );
            $st    = in_array( $form->status, array( 'active', 'inactive', 'trash' ), true ) ? $form->status : 'inactive';

            $items[] = array(
                'id'         => (int) $form->id,
                'title'      => (string) $form->title,
                'sf_status'  => $st,
                'entries'    => (int) $stats['entries'],
                'views'      => (int) $stats['views'],
                'conversion' => $stats['conversion'],
            );

            if ( 'trash' === $st ) {
                $this->status_counts['trash']++;
            } else {
                $this->status_counts['all']++;
                $this->status_counts[ $st ]++;
            }
        }

        // Status filter ("All" hides trash, like Gravity).
        $status = $this->current_status();
        $items  = array_filter( $items, function ( $i ) use ( $status ) {
            return ( 'all' === $status ) ? ( 'trash' !== $i['sf_status'] ) : ( $i['sf_status'] === $status );
        } );

        // Search on title or exact ID.
        $search = isset( $_REQUEST['s'] ) ? trim( (string) wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( '' !== $search ) {
            $needle = strtolower( $search );
            $items  = array_filter( $items, function ( $i ) use ( $needle ) {
                return false !== strpos( strtolower( $i['title'] ), $needle ) || (string) $i['id'] === $needle;
            } );
        }

        // Sort.
        $orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : 'id'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order   = ( isset( $_REQUEST['order'] ) && 'desc' === strtolower( (string) $_REQUEST['order'] ) ) ? 'desc' : 'asc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! in_array( $orderby, array( 'id', 'title', 'entries', 'views', 'conversion', 'sf_status' ), true ) ) {
            $orderby = 'id';
        }
        usort( $items, function ( $a, $b ) use ( $orderby, $order ) {
            $av = $a[ $orderby ];
            $bv = $b[ $orderby ];
            $cmp = ( is_numeric( $av ) && is_numeric( $bv ) ) ? ( $av <=> $bv ) : strcasecmp( (string) $av, (string) $bv );
            return ( 'desc' === $order ) ? -$cmp : $cmp;
        } );

        // Paginate.
        $per_page = 20;
        $total    = count( $items );
        $page     = $this->get_pagenum();
        $this->items = array_slice( array_values( $items ), ( $page - 1 ) * $per_page, $per_page );

        $this->set_pagination_args( array(
            'total_items' => $total,
            'per_page'    => $per_page,
            'total_pages' => max( 1, (int) ceil( $total / $per_page ) ),
        ) );
    }

    private function current_status() {
        $status = isset( $_REQUEST['status'] ) ? sanitize_key( $_REQUEST['status'] ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return in_array( $status, array( 'all', 'active', 'inactive', 'trash' ), true ) ? $status : 'all';
    }

    private function action_url( $action, $id ) {
        return wp_nonce_url(
            admin_url( 'admin.php?page=smart-forms&sfco_action=' . $action . '&form_id=' . (int) $id ),
            'sfco_forms_action'
        );
    }
}
