<?php
/**
 * File: includes/managers/class-sdm-projects-manager.php
 * Description: Manager for Projects CRUD operations + Ajax handlers.
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
        return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC" );
    }

    /**
     * Return the total count of projects.
     *
     * @return int Count of projects.
     */
    public function count_projects() {
        global $wpdb;
        $table = $wpdb->prefix . 'sdm_projects';
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
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

        $project_name       = sanitize_text_field( $data['project_name'] );
        $description        = sanitize_textarea_field( $data['description'] );
        $ssl_mode           = in_array( $data['ssl_mode'], array( 'full', 'flexible', 'strict' ), true ) ? $data['ssl_mode'] : 'full';
        $monitoring_enabled = ! empty( $data['monitoring_enabled'] ) ? 1 : 0;

        $res = $wpdb->insert(
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

        if ( false === $res ) {
            return new WP_Error( 'db_insert_error', __( 'Could not insert project.', 'spintax-domain-manager' ) );
        }
        return $wpdb->insert_id;
    }

    /**
     * Update an existing project by ID.
     *
     * @param int   $project_id
     * @param array $data Form data.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function update_project( $project_id, $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sdm_projects';

        $project_id = absint( $project_id );
        if ( $project_id <= 0 ) {
            return new WP_Error( 'invalid_id', __( 'Invalid project ID.', 'spintax-domain-manager' ) );
        }

        $project_name       = sanitize_text_field( $data['project_name'] );
        $description        = sanitize_textarea_field( $data['description'] );
        $ssl_mode           = in_array( $data['ssl_mode'], array( 'full', 'flexible', 'strict' ), true ) ? $data['ssl_mode'] : 'full';
        $monitoring_enabled = ! empty( $data['monitoring_enabled'] ) ? 1 : 0;

        $updated = $wpdb->update(
            $table,
            array(
                'project_name'       => $project_name,
                'description'        => $description,
                'ssl_mode'           => $ssl_mode,
                'monitoring_enabled' => $monitoring_enabled,
                'updated_at'         => current_time( 'mysql' ),
            ),
            array( 'id' => $project_id ),
            array( '%s', '%s', '%s', '%d', '%s' ),
            array( '%d' )
        );

        if ( false === $updated ) {
            return new WP_Error( 'db_update_error', __( 'Could not update project.', 'spintax-domain-manager' ) );
        }
        return true;
    }

    /**
     * Delete a project by ID.
     *
     * @param int $project_id
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function delete_project( $project_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sdm_projects';
        $project_id = absint( $project_id );
        if ( $project_id <= 0 ) {
            return new WP_Error( 'invalid_id', __( 'Invalid project ID.', 'spintax-domain-manager' ) );
        }
        $deleted = $wpdb->delete( $table, array( 'id' => $project_id ), array( '%d' ) );
        if ( false === $deleted ) {
            return new WP_Error( 'db_delete_error', __( 'Could not delete project.', 'spintax-domain-manager' ) );
        }
        return true;
    }
}

/**
 * Ajax: Add Project
 * Action: wp_ajax_sdm_add_project
 */
function sdm_ajax_add_project() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();

    $manager = new SDM_Projects_Manager();
    $result  = $manager->add_project( $_POST );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }
    wp_send_json_success( array( 'project_id' => $result ) );
}
add_action( 'wp_ajax_sdm_add_project', 'sdm_ajax_add_project' );

/**
 * Ajax: Update Project
 * Action: wp_ajax_sdm_update_project
 */
function sdm_ajax_update_project() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();

    $project_id = isset( $_POST['project_id'] ) ? absint( $_POST['project_id'] ) : 0;

    $manager = new SDM_Projects_Manager();
    $result  = $manager->update_project( $project_id, $_POST );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }
    wp_send_json_success( array( 'message' => __( 'Project updated successfully.', 'spintax-domain-manager' ) ) );
}
add_action( 'wp_ajax_sdm_update_project', 'sdm_ajax_update_project' );

/**
 * Ajax: Delete Project
 * Action: wp_ajax_sdm_delete_project
 */
function sdm_ajax_delete_project() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();
    $project_id = isset( $_POST['project_id'] ) ? absint( $_POST['project_id'] ) : 0;
    $manager = new SDM_Projects_Manager();
    $result = $manager->delete_project( $project_id );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }
    wp_send_json_success( array( 'message' => __( 'Project deleted successfully.', 'spintax-domain-manager' ) ) );
}
add_action( 'wp_ajax_sdm_delete_project', 'sdm_ajax_delete_project' );
