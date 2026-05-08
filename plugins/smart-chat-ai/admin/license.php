<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_options' ) ) { return; }

$license_manager = new SCAI_License_Manager();
$info            = $license_manager->get_license_info();
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Smart Chat AI License', 'smart-chat-ai' ); ?></h1>

    <div class="card" style="max-width:600px;padding:20px;">
        <h2><?php esc_html_e( 'License Activation', 'smart-chat-ai' ); ?></h2>

        <?php if ( 'active' === $info['status'] ) : ?>
            <div class="notice notice-success inline"><p><strong><?php esc_html_e( 'License is active.', 'smart-chat-ai' ); ?></strong></p></div>
            <?php if ( $info['expires'] ) : ?>
                <p><?php esc_html_e( 'Expires:', 'smart-chat-ai' ); ?> <?php echo esc_html( $info['expires'] ); ?></p>
            <?php endif; ?>
        <?php else : ?>
            <div class="notice notice-warning inline"><p><?php esc_html_e( 'License is not active. Enter your key below to enable the chat widget.', 'smart-chat-ai' ); ?></p></div>
        <?php endif; ?>

        <table class="form-table">
            <tr>
                <th><label for="smart-chat-license-key"><?php esc_html_e( 'License Key', 'smart-chat-ai' ); ?></label></th>
                <td>
                    <input type="text" id="smart-chat-license-key" class="regular-text" value="<?php echo esc_attr( $info['key'] ); ?>" placeholder="XXXX-XXXX-XXXX-XXXX">
                </td>
            </tr>
        </table>

        <p>
            <button type="button" id="smart-chat-activate-btn" class="button button-primary"><?php esc_html_e( 'Activate License', 'smart-chat-ai' ); ?></button>
            <span id="smart-chat-license-result"></span>
        </p>

        <p class="description"><?php esc_html_e( "Don't have a license?", 'smart-chat-ai' ); ?> <a href="https://tagglefish.com" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get Smart Chat AI', 'smart-chat-ai' ); ?></a></p>
    </div>
</div>
