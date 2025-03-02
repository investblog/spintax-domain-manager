<?php
/**
 * File: includes/managers/class-sdm-service-types-manager.php
 * Description: Manager for sdm_service_types (list of external services).
 */

if (!defined('ABSPATH')) {
    exit;
}

class SDM_Service_Types_Manager {
    /**
     * Get all services from the sdm_service_types table.
     */
    public function get_all_services() {
        global $wpdb;
        $table = $wpdb->prefix . 'sdm_service_types';
        $sql = "SELECT * FROM {$table} ORDER BY id ASC";
        return $wpdb->get_results($sql);
    }

    /**
     * Get a service by name.
     */
    public function get_service_by_name($service_name) {
        global $wpdb;
        if (empty($service_name)) {
            return false;
        }

        $table = $wpdb->prefix . 'sdm_service_types';
        $service = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE service_name = %s", $service_name)
        );

        return $service ? $service : false;
    }

    /**
     * Get a service by ID.
     */
    public function get_service_by_id($service_id) {
        global $wpdb;
        $service_id = absint($service_id);
        if ($service_id <= 0) {
            return false;
        }

        $table = $wpdb->prefix . 'sdm_service_types';
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE id = %d", $service_id)
        );
    }

    /**
     * Add a new service.
     * @param array $data (service_name, auth_method, additional_params)
     * @return int|WP_Error ID of inserted row or WP_Error
     */
    public function add_service($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'sdm_service_types';

        $service_name = isset($data['service_name']) ? sanitize_text_field($data['service_name']) : '';
        if (empty($service_name)) {
            return new WP_Error('invalid_service_name', __('Service name is required.', 'spintax-domain-manager'));
        }

        // Check if this service_name already exists
        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE service_name = %s", $service_name));
        if ($exists) {
            return new WP_Error('duplicate_service', __('This service name already exists.', 'spintax-domain-manager'));
        }

        $auth_method = isset($data['auth_method']) ? sanitize_text_field($data['auth_method']) : '';
        $additional_params = isset($data['additional_params']) ? wp_json_encode(json_decode($data['additional_params'], true)) : '';

        $result = $wpdb->insert(
            $table,
            array(
                'service_name' => $service_name,
                'auth_method' => $auth_method,
                'additional_params' => $additional_params,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );

        if (false === $result) {
            return new WP_Error('db_insert_error', __('Could not insert service.', 'spintax-domain-manager'));
        }
        return $wpdb->insert_id;
    }

    /**
     * Update a service by ID.
     */
    public function update_service($service_id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'sdm_service_types';

        $service_id = absint($service_id);
        if ($service_id <= 0) {
            return new WP_Error('invalid_id', __('Invalid service ID.', 'spintax-domain-manager'));
        }

        // Check if record exists
        $old = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $service_id));
        if (!$old) {
            return new WP_Error('not_found', __('Service not found.', 'spintax-domain-manager'));
        }

        $service_name = isset($data['service_name']) ? sanitize_text_field($data['service_name']) : $old->service_name;
        $auth_method = isset($data['auth_method']) ? sanitize_text_field($data['auth_method']) : $old->auth_method;
        $additional_params = isset($data['additional_params']) ? wp_json_encode(json_decode($data['additional_params'], true)) : $old->additional_params;

        // Optional: check duplicates if service_name changed
        if ($service_name !== $old->service_name) {
            $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE service_name = %s", $service_name));
            if ($exists) {
                return new WP_Error('duplicate_service', __('This service name already exists.', 'spintax-domain-manager'));
            }
        }

        $updated = $wpdb->update(
            $table,
            array(
                'service_name' => $service_name,
                'auth_method' => $auth_method,
                'additional_params' => $additional_params,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $service_id),
            array('%s', '%s', '%s', '%s'),
            array('%d')
        );

        if (false === $updated) {
            return new WP_Error('db_update_error', __('Could not update service.', 'spintax-domain-manager'));
        }
        return true;
    }

    /**
     * Delete a service by ID.
     */
    public function delete_service($service_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'sdm_service_types';
        $service_id = absint($service_id);
        if ($service_id <= 0) {
            return new WP_Error('invalid_id', __('Invalid service ID.', 'spintax-domain-manager'));
        }

        // Optional: Check if there are any accounts referencing this service_id
        // If needed, block deletion or cascade the changes.

        $deleted = $wpdb->delete($table, array('id' => $service_id), array('%d'));
        if (false === $deleted) {
            return new WP_Error('db_delete_error', __('Could not delete service.', 'spintax-domain-manager'));
        }
        return true;
    }
}

// AJAX-обработчики вне класса
function sdm_ajax_add_service() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'spintax-domain-manager'));
    }
    sdm_check_main_nonce();

    $manager = new SDM_Service_Types_Manager();
    $res = $manager->add_service($_POST);
    if (is_wp_error($res)) {
        wp_send_json_error($res->get_error_message());
    }
    wp_send_json_success(array('service_id' => $res));
}
add_action('wp_ajax_sdm_add_service', 'sdm_ajax_add_service');

function sdm_ajax_update_service() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'spintax-domain-manager'));
    }
    sdm_check_main_nonce();

    $service_id = isset($_POST['service_id']) ? absint($_POST['service_id']) : 0;
    $manager = new SDM_Service_Types_Manager();
    $res = $manager->update_service($service_id, $_POST);
    if (is_wp_error($res)) {
        wp_send_json_error($res->get_error_message());
    }
    wp_send_json_success(array('message' => __('Service updated.', 'spintax-domain-manager')));
}
add_action('wp_ajax_sdm_update_service', 'sdm_ajax_update_service');

function sdm_ajax_delete_service() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'spintax-domain-manager'));
    }
    sdm_check_main_nonce();

    $service_id = isset($_POST['service_id']) ? absint($_POST['service_id']) : 0;
    $manager = new SDM_Service_Types_Manager();
    $res = $manager->delete_service($service_id);
    if (is_wp_error($res)) {
        wp_send_json_error($res->get_error_message());
    }
    wp_send_json_success(array('message' => __('Service deleted.', 'spintax-domain-manager')));
}
add_action('wp_ajax_sdm_delete_service', 'sdm_ajax_delete_service');