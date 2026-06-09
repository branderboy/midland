<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>

<?php if ( ! empty( $schemas ) ) : ?>
<div class="rsseo-pro-panel rsseo-pro-panel--schema">
    <div class="rsseo-pro-panel__header">
        <h2><?php esc_html_e( 'Schema Markup', 'real-smart-seo' ); ?> <span class="rsseo-pro-badge">PRO</span></h2>
        <p><?php
            $pending = count( array_filter( (array) $schemas, function( $s ) { return ! $s->applied; } ) );
            /* translators: 1: pending count, 2: total count */
            printf( esc_html__( '%1$d of %2$d pending.', 'real-smart-seo' ), (int) $pending, count( $schemas ) );
        ?></p>
        <?php if ( $pending > 0 ) : ?>
        <button class="button button-primary rsseo-pro-apply-all-schemas" data-report-id="<?php echo esc_attr( $report->id ); ?>">
            <?php esc_html_e( 'Apply All Schema', 'real-smart-seo' ); ?>
        </button>
        <?php endif; ?>
    </div>

    <table class="wp-list-table widefat fixed striped rsseo-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Type', 'real-smart-seo' ); ?></th>
                <th><?php esc_html_e( 'Target', 'real-smart-seo' ); ?></th>
                <th><?php esc_html_e( 'Preview', 'real-smart-seo' ); ?></th>
                <th><?php esc_html_e( 'Status', 'real-smart-seo' ); ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $schemas as $schema ) :
            $post_label = 0 === (int) $schema->post_id ? __( 'Sitewide', 'real-smart-seo' ) : get_the_title( $schema->post_id );
        ?>
            <tr id="rsseo-schema-<?php echo esc_attr( $schema->id ); ?>" class="<?php echo $schema->applied ? 'rsseo-fix--applied' : ''; ?>">
                <td><span class="rsseo-schema-type"><?php echo esc_html( $schema->schema_type ); ?></span></td>
                <td><?php echo esc_html( $post_label ); ?></td>
                <td><code class="rsseo-schema-preview"><?php echo esc_html( mb_strimwidth( $schema->schema_json, 0, 100, '…' ) ); ?></code></td>
                <td>
                    <?php if ( $schema->applied ) : ?>
                        <span class="rsseo-status rsseo-status--complete"><?php esc_html_e( 'Applied', 'real-smart-seo' ); ?></span>
                    <?php else : ?>
                        <span class="rsseo-status rsseo-status--pending"><?php esc_html_e( 'Pending', 'real-smart-seo' ); ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ( ! $schema->applied ) : ?>
                        <button class="button button-small rsseo-pro-apply-schema" data-schema-id="<?php echo esc_attr( $schema->id ); ?>">
                            <?php esc_html_e( 'Apply', 'real-smart-seo' ); ?>
                        </button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
