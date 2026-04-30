<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>

<?php if ( ! empty( $schemas ) ) : ?>
<div class="rsseo-pro-panel rsseo-pro-panel--schema">
    <div class="rsseo-pro-panel__header">
        <h2><?php esc_html_e( 'Schema Markup', 'real-smart-seo-pro' ); ?> <span class="rsseo-pro-badge">PRO</span></h2>
        <p><?php
            $pending = count( array_filter( (array) $schemas, function( $s ) { return ! $s->applied; } ) );
            /* translators: 1: pending count, 2: total count */
            printf( esc_html__( '%1$d of %2$d pending.', 'real-smart-seo-pro' ), (int) $pending, count( $schemas ) );
        ?></p>
        <?php if ( $pending > 0 ) : ?>
        <button class="button button-primary rsseo-pro-apply-all-schemas" data-report-id="<?php echo esc_attr( $report->id ); ?>">
            <?php esc_html_e( 'Apply All Schema', 'real-smart-seo-pro' ); ?>
        </button>
        <?php endif; ?>
    </div>

    <table class="wp-list-table widefat fixed striped rsseo-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Type', 'real-smart-seo-pro' ); ?></th>
                <th><?php esc_html_e( 'Target', 'real-smart-seo-pro' ); ?></th>
                <th><?php esc_html_e( 'Preview', 'real-smart-seo-pro' ); ?></th>
                <th><?php esc_html_e( 'Status', 'real-smart-seo-pro' ); ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $schemas as $schema ) :
            $post_label = 0 === (int) $schema->post_id ? __( 'Sitewide', 'real-smart-seo-pro' ) : get_the_title( $schema->post_id );
        ?>
            <tr id="rsseo-schema-<?php echo esc_attr( $schema->id ); ?>" class="<?php echo $schema->applied ? 'rsseo-fix--applied' : ''; ?>">
                <td><span class="rsseo-schema-type"><?php echo esc_html( $schema->schema_type ); ?></span></td>
                <td><?php echo esc_html( $post_label ); ?></td>
                <td><code class="rsseo-schema-preview"><?php echo esc_html( mb_strimwidth( $schema->schema_json, 0, 100, '…' ) ); ?></code></td>
                <td>
                    <?php if ( $schema->applied ) : ?>
                        <span class="rsseo-status rsseo-status--complete"><?php esc_html_e( 'Applied', 'real-smart-seo-pro' ); ?></span>
                    <?php else : ?>
                        <span class="rsseo-status rsseo-status--pending"><?php esc_html_e( 'Pending', 'real-smart-seo-pro' ); ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ( ! $schema->applied ) : ?>
                        <button class="button button-small rsseo-pro-apply-schema" data-schema-id="<?php echo esc_attr( $schema->id ); ?>">
                            <?php esc_html_e( 'Apply', 'real-smart-seo-pro' ); ?>
                        </button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if ( ! empty( $backlinks ) ) : ?>
<div class="rsseo-pro-panel rsseo-pro-panel--backlinks">
    <div class="rsseo-pro-panel__header">
        <h2><?php esc_html_e( 'Backlink Targets', 'real-smart-seo-pro' ); ?> <span class="rsseo-pro-badge">PRO</span></h2>
        <p><?php esc_html_e( 'Hyper-local, high-authority link opportunities specific to your business and location.', 'real-smart-seo-pro' ); ?></p>
    </div>

    <table class="wp-list-table widefat fixed striped rsseo-table">
        <thead>
            <tr>
                <th class="rsseo-col-priority"><?php esc_html_e( '#', 'real-smart-seo-pro' ); ?></th>
                <th><?php esc_html_e( 'Type', 'real-smart-seo-pro' ); ?></th>
                <th><?php esc_html_e( 'Target', 'real-smart-seo-pro' ); ?></th>
                <th><?php esc_html_e( 'Why It Matters', 'real-smart-seo-pro' ); ?></th>
                <th><?php esc_html_e( 'Status', 'real-smart-seo-pro' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $backlinks as $bl ) : ?>
            <tr id="rsseo-bl-<?php echo esc_attr( $bl->id ); ?>">
                <td><?php echo esc_html( $bl->priority ); ?></td>
                <td><span class="rsseo-link-type rsseo-link-type--<?php echo esc_attr( ltrim( $bl->link_type, '.' ) ); ?>"><?php echo esc_html( $bl->link_type ); ?></span></td>
                <td>
                    <?php if ( $bl->target_url ) : ?>
                        <a href="<?php echo esc_url( $bl->target_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $bl->target_name ); ?></a>
                    <?php else : ?>
                        <?php echo esc_html( $bl->target_name ); ?>
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html( $bl->rationale ); ?></td>
                <td>
                    <select class="rsseo-bl-status" data-backlink-id="<?php echo esc_attr( $bl->id ); ?>">
                        <option value="pending"   <?php selected( $bl->status, 'pending' ); ?>><?php esc_html_e( 'Pending', 'real-smart-seo-pro' ); ?></option>
                        <option value="pursuing"  <?php selected( $bl->status, 'pursuing' ); ?>><?php esc_html_e( 'Pursuing', 'real-smart-seo-pro' ); ?></option>
                        <option value="completed" <?php selected( $bl->status, 'completed' ); ?>><?php esc_html_e( 'Got It ✓', 'real-smart-seo-pro' ); ?></option>
                        <option value="skipped"   <?php selected( $bl->status, 'skipped' ); ?>><?php esc_html_e( 'Skip', 'real-smart-seo-pro' ); ?></option>
                    </select>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
