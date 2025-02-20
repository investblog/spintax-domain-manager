<?php
/**
 * File: admin/pages/projects-page.php
 * Description: Displays the Projects interface with a list of projects, inline editing, Ajax deletion, and a form to add new projects.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$projects_manager = new SDM_Projects_Manager();
$projects = $projects_manager->get_all_projects();

// Generate a unified nonce for inline operations
$main_nonce = sdm_create_main_nonce();
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Projects', 'spintax-domain-manager' ); ?></h1>

    <!-- Single container for all notices (add/edit/delete) -->
    <div id="sdm-projects-notice" class="sdm-notice"></div>

    <!-- Projects Table -->
    <table id="sdm-projects-table" class="wp-list-table widefat fixed striped sdm-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'ID', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Project Name', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Description', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'SSL Mode', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Monitoring', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Created At', 'spintax-domain-manager' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'spintax-domain-manager' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( ! empty( $projects ) ) : ?>
                <?php foreach ( $projects as $project ) : ?>
                    <tr id="project-row-<?php echo esc_attr( $project->id ); ?>"
                        data-project-id="<?php echo esc_attr( $project->id ); ?>"
                        data-update-nonce="<?php echo esc_attr( $main_nonce ); ?>">
                        
                        <td class="column-id"><?php echo esc_html( $project->id ); ?></td>
                        
                        <!-- Project Name -->
                        <td class="column-name">
                            <span class="sdm-display-value"><?php echo esc_html( $project->project_name ); ?></span>
                            <input class="sdm-edit-input sdm-hidden" type="text" name="project_name" value="<?php echo esc_attr( $project->project_name ); ?>">
                        </td>
                        
                        <!-- Description -->
                        <td class="column-description">
                            <span class="sdm-display-value"><?php echo esc_html( $project->description ); ?></span>
                            <textarea class="sdm-edit-input sdm-hidden" name="description"><?php echo esc_textarea( $project->description ); ?></textarea>
                        </td>
                        
                        <!-- SSL Mode -->
                        <td class="column-ssl_mode">
                            <span class="sdm-display-value"><?php echo esc_html( $project->ssl_mode ); ?></span>
                            <select class="sdm-edit-input sdm-hidden" name="ssl_mode">
                                <option value="full" <?php selected( $project->ssl_mode, 'full' ); ?>>Full</option>
                                <option value="flexible" <?php selected( $project->ssl_mode, 'flexible' ); ?>>Flexible</option>
                                <option value="strict" <?php selected( $project->ssl_mode, 'strict' ); ?>>Strict</option>
                            </select>
                        </td>
                        
                        <!-- Monitoring -->
                        <td class="column-monitoring">
                            <span class="sdm-display-value">
                                <?php echo $project->monitoring_enabled ? esc_html__( 'Yes', 'spintax-domain-manager' ) : esc_html__( 'No', 'spintax-domain-manager' ); ?>
                            </span>
                            <input class="sdm-edit-input sdm-hidden" type="checkbox" name="monitoring_enabled" value="1" <?php checked( $project->monitoring_enabled, 1 ); ?>>
                        </td>
                        
                        <td class="column-created"><?php echo esc_html( $project->created_at ); ?></td>
                        
                        <td class="column-actions">
                            <a href="#" class="sdm-action-button sdm-edit sdm-edit-project"><?php esc_html_e( 'Edit', 'spintax-domain-manager' ); ?></a>
                            <a href="#" class="sdm-action-button sdm-save sdm-save-project sdm-hidden"><?php esc_html_e( 'Save', 'spintax-domain-manager' ); ?></a> |
                            <a href="#" class="sdm-action-button sdm-delete sdm-delete-project"><?php esc_html_e( 'Delete', 'spintax-domain-manager' ); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr id="no-projects">
                    <td colspan="7"><?php esc_html_e( 'No projects found.', 'spintax-domain-manager' ); ?></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <hr>
    <!-- Add New Project Form -->
    <h2><?php esc_html_e( 'Add New Project', 'spintax-domain-manager' ); ?></h2>
    <form id="sdm-add-project-form" class="sdm-form">
        <?php sdm_nonce_field(); ?>
        <table class="sdm-form-table">
            <tr>
                <th><label for="project_name"><?php esc_html_e( 'Project Name', 'spintax-domain-manager' ); ?></label></th>
                <td><input type="text" name="project_name" id="project_name" required></td>
            </tr>
            <tr>
                <th><label for="description"><?php esc_html_e( 'Description', 'spintax-domain-manager' ); ?></label></th>
                <td><textarea name="description" id="description" rows="4"></textarea></td>
            </tr>
            <tr>
                <th><label for="ssl_mode"><?php esc_html_e( 'SSL Mode', 'spintax-domain-manager' ); ?></label></th>
                <td>
                    <select name="ssl_mode" id="ssl_mode">
                        <option value="full">Full</option>
                        <option value="flexible">Flexible</option>
                        <option value="strict">Strict</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Monitoring Enabled', 'spintax-domain-manager' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="monitoring_enabled" value="1" checked> 
                        <?php esc_html_e( 'Yes', 'spintax-domain-manager' ); ?>
                    </label>
                </td>
            </tr>
        </table>
        <p class="submit">
            <button type="submit" class="button button-primary"><?php esc_html_e( 'Add Project', 'spintax-domain-manager' ); ?></button>
        </p>
    </form>
</div>
