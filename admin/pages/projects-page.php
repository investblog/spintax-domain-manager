<?php
/**
 * File: admin/pages/projects-page.php
 * Description: Displays the Projects interface with a list of projects and a form to add a new project.
 *
 * Depends on: SDM_Projects_Manager class (located in includes/managers/class-sdm-projects-manager.php)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Instantiate the Projects Manager class.
$projects_manager = new SDM_Projects_Manager();

// Retrieve all projects.
$projects = $projects_manager->get_all_projects();
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Projects', 'spintax-domain-manager' ); ?></h1>
    
    <!-- Projects Table -->
    <table id="sdm-projects-table" class="wp-list-table widefat fixed striped">
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
                    <tr id="project-row-<?php echo esc_attr( $project->id ); ?>">
                        <td><?php echo esc_html( $project->id ); ?></td>
                        <td><?php echo esc_html( $project->project_name ); ?></td>
                        <td><?php echo esc_html( $project->description ); ?></td>
                        <td><?php echo esc_html( $project->ssl_mode ); ?></td>
                        <td><?php echo $project->monitoring_enabled ? esc_html__( 'Yes', 'spintax-domain-manager' ) : esc_html__( 'No', 'spintax-domain-manager' ); ?></td>
                        <td><?php echo esc_html( $project->created_at ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=sdm-projects&action=edit&id=' . $project->id ) ); ?>">
                                <?php esc_html_e( 'Edit', 'spintax-domain-manager' ); ?>
                            </a> | 
                            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sdm_delete_project&id=' . $project->id ), 'sdm_delete_project_' . $project->id ) ); ?>" 
                               onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this project?', 'spintax-domain-manager' ); ?>');">
                                <?php esc_html_e( 'Delete', 'spintax-domain-manager' ); ?>
                            </a>
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
    
    <!-- Add New Project Form -->
    <h2><?php esc_html_e( 'Add New Project', 'spintax-domain-manager' ); ?></h2>
    <form id="sdm-add-project-form">
        <?php wp_nonce_field( 'sdm_add_project_nonce', 'nonce' ); ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="project_name"><?php esc_html_e( 'Project Name', 'spintax-domain-manager' ); ?></label>
                </th>
                <td>
                    <input type="text" name="project_name" id="project_name" class="regular-text" required>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="description"><?php esc_html_e( 'Description', 'spintax-domain-manager' ); ?></label>
                </th>
                <td>
                    <textarea name="description" id="description" rows="5" class="large-text"></textarea>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="ssl_mode"><?php esc_html_e( 'SSL Mode', 'spintax-domain-manager' ); ?></label>
                </th>
                <td>
                    <select name="ssl_mode" id="ssl_mode">
                        <option value="full"><?php esc_html_e( 'Full', 'spintax-domain-manager' ); ?></option>
                        <option value="flexible"><?php esc_html_e( 'Flexible', 'spintax-domain-manager' ); ?></option>
                        <option value="strict"><?php esc_html_e( 'Strict', 'spintax-domain-manager' ); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Monitoring Enabled', 'spintax-domain-manager' ); ?></th>
                <td>
                    <label for="monitoring_enabled">
                        <input type="checkbox" name="monitoring_enabled" id="monitoring_enabled" value="1" checked>
                        <?php esc_html_e( 'Yes', 'spintax-domain-manager' ); ?>
                    </label>
                </td>
            </tr>
        </table>
        <p class="submit">
            <button type="submit" class="button button-primary"><?php esc_html_e( 'Add Project', 'spintax-domain-manager' ); ?></button>
        </p>
    </form>
    
    <div id="sdm-add-project-message"></div>
</div>
