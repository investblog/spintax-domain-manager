<?php
/**
 * File: admin/pages/services-page.php
 * Description: Allows adding/editing/deleting entries in sdm_service_types.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$service_manager = new SDM_Service_Types_Manager();
$services        = $service_manager->get_all_services();

$main_nonce = sdm_create_main_nonce();
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Manage Services', 'spintax-domain-manager' ); ?></h1>

    <div id="sdm-services-notice" class="sdm-notice"></div>

    <!-- Services Table -->
    <table id="sdm-services-table" class="wp-list-table widefat fixed striped sdm-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'ID', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Service Name', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Auth Method', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Additional Params', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'spintax-domain-manager' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if ( ! empty( $services ) ) : ?>
            <?php foreach ( $services as $srv ) : ?>
                <tr data-service-id="<?php echo esc_attr( $srv->id ); ?>"
                    data-update-nonce="<?php echo esc_attr( $main_nonce ); ?>">
                    <td><?php echo esc_html( $srv->id ); ?></td>
                    <td class="column-service-name">
                        <span class="sdm-display-value"><?php echo esc_html( $srv->service_name ); ?></span>
                        <input class="sdm-edit-input sdm-hidden" type="text" name="service_name" value="<?php echo esc_attr( $srv->service_name ); ?>">
                    </td>
                    <td class="column-auth-method">
                        <span class="sdm-display-value"><?php echo esc_html( $srv->auth_method ); ?></span>
                        <input class="sdm-edit-input sdm-hidden" type="text" name="auth_method" value="<?php echo esc_attr( $srv->auth_method ); ?>">
                    </td>
                    <td class="column-additional-params">
                        <span class="sdm-display-value"><?php echo esc_html( $srv->additional_params ); ?></span>
                        <textarea class="sdm-edit-input sdm-hidden" name="additional_params" rows="2"><?php echo esc_textarea( $srv->additional_params ); ?></textarea>
                    </td>
                    <td class="column-actions">
                        <a href="#" class="sdm-action-button sdm-edit sdm-edit-service"><?php esc_html_e( 'Edit', 'spintax-domain-manager' ); ?></a>
                        <a href="#" class="sdm-action-button sdm-save sdm-save-service sdm-hidden"><?php esc_html_e( 'Save', 'spintax-domain-manager' ); ?></a> |
                        <a href="#" class="sdm-action-button sdm-delete sdm-delete-service"><?php esc_html_e( 'Delete', 'spintax-domain-manager' ); ?></a>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else : ?>
            <tr><td colspan="5"><?php esc_html_e( 'No services found.', 'spintax-domain-manager' ); ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <hr>

    <!-- Add New Service Form -->
    <h2><?php esc_html_e( 'Add New Service', 'spintax-domain-manager' ); ?></h2>
    <form id="sdm-add-service-form" class="sdm-form">
        <?php sdm_nonce_field(); ?>
        <table class="sdm-form-table">
            <tr>
                <th><label for="service_name"><?php esc_html_e( 'Service Name', 'spintax-domain-manager' ); ?></label></th>
                <td><input type="text" name="service_name" id="service_name" required></td>
            </tr>
            <tr>
                <th><label for="auth_method"><?php esc_html_e( 'Auth Method (optional)', 'spintax-domain-manager' ); ?></label></th>
                <td><input type="text" name="auth_method" id="auth_method"></td>
            </tr>
            <tr>
                <th><label for="additional_params"><?php esc_html_e( 'Additional Params (JSON)', 'spintax-domain-manager' ); ?></label></th>
                <td><textarea name="additional_params" id="additional_params" rows="3"></textarea></td>
            </tr>
        </table>
        <p class="submit">
            <button type="submit" class="button button-primary"><?php esc_html_e( 'Add Service', 'spintax-domain-manager' ); ?></button>
        </p>
    </form>
</div>
