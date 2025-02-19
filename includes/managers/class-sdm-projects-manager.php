<?php
/**
 * File: includes/managers/class-sdm-projects-manager.php
 * Description: Manager for Projects CRUD operations. Handles fetching, adding, editing projects.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SDM_Projects_Manager {

    /**
     * Retrieve all projects from the database.
     *
     * @return array Array of project objects.
     */
    public function get_all_projects() {
        global $wpdb;
        $table = $wpdb->prefix . 'sdm_projects';
        $results = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC" );
        return $results;
    }

    /**
     * Add a new project.
     *
     * @param array $data Form data.
     * @return int|WP_Error Insert ID on success or WP_Error on failure.
     */
    public function add_project( $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sdm_projects';

        // Sanitize input.
        $project_name       = sanitize_text_field( $data['project_name'] );
        $description        = sanitize_textarea_field( $data['description'] );
        $ssl_mode           = in_array( $data['ssl_mode'], array( 'full', 'flexible', 'strict' ) ) ? $data['ssl_mode'] : 'full';
        $monitoring_enabled = isset( $data['monitoring_enabled'] ) && $data['monitoring_enabled'] ? 1 : 0;

        $result = $wpdb->insert(
            $table,
            array(
                'project_name'       => $project_name,
                'description'        => $description,
                'ssl_mode'           => $ssl_mode,
                'monitoring_enabled' => $monitoring_enabled,
                'created_at'         => current_time( 'mysql' ),
                'updated_at'         => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s', '%d', '%s', '%s' )
        );

        if ( false === $result ) {
            return new WP_Error( 'db_insert_error', __( 'Could not insert project into the database', 'spintax-domain-manager' ) );
        }
        return $wpdb->insert_id;
    }
}

/**
 * Ajax handler for adding a project.
 *
 * Action: wp_ajax_sdm_add_project
 */
function sdm_ajax_add_project() {
    // Check nonce.
    check_ajax_referer( 'sdm_add_project_nonce', 'nonce' );

    // Check user capability.
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied', 'spintax-domain-manager' ) );
    }

    $projects_manager = new SDM_Projects_Manager();
    $result = $projects_manager->add_project( $_POST );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }
    wp_send_json_success( array( 'project_id' => $result ) );
}
add_action( 'wp_ajax_sdm_add_project', 'sdm_ajax_add_project' );
