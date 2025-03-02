<?php
/**
 * File: includes/managers/class-sdm-accounts-manager.php
 * Description: Manager for external service accounts CRUD operations (new approach with service_id and additional_params).
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once SDM_PLUGIN_DIR . 'includes/managers/class-sdm-service-types-manager.php'; // Убедимся, что класс подключён

class SDM_Accounts_Manager {
    /**
     * Retrieve all accounts from the database, 
     * including project_name and service_name via LEFT JOIN.
     */
    public function get_all_accounts() {
        global $wpdb;
        $acc_table = $wpdb->prefix . 'sdm_accounts';
        $proj_table = $wpdb->prefix . 'sdm_projects';
        $serv_table = $wpdb->prefix . 'sdm_service_types';

        // LEFT JOIN to get project_name and service_name
        $sql = "
            SELECT 
                a.*, 
                p.project_name AS project_name,
                st.service_name AS service
            FROM {$acc_table} a
            LEFT JOIN {$proj_table} p ON p.id = a.project_id
            LEFT JOIN {$serv_table} st ON st.id = a.service_id
            ORDER BY a.created_at DESC
        ";
        return $wpdb->get_results($sql);
    }

    /**
     * Add a new account (with encryption and additional_params).
     */
    public function create_account($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'sdm_accounts';

        $project_id = absint($data['project_id'] ?? 0);
        $site_id = isset($data['site_id']) && !empty($data['site_id']) ? absint($data['site_id']) : null; // Устанавливаем NULL, если site_id не указано или пустое

        // Get service_id by service_name from sdm_service_types
        $service_name = sanitize_text_field($data['service'] ?? '');
        $service_manager = new SDM_Service_Types_Manager(); // Создаём экземпляр класса
        $service = $service_manager->get_service_by_name($service_name); // Вызываем метод через экземпляр
        if (!$service) {
            return new WP_Error('service_not_found', __('Service not found.', 'spintax-domain-manager'));
        }

        $params = json_decode($service->additional_params, true);
        $required_fields = $params['required_fields'] ?? array();
        $optional_fields = $params['optional_fields'] ?? array();

        // Валидация полей
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                return new WP_Error('missing_required_field', sprintf(__('Required field "%s" is missing.', 'spintax-domain-manager'), $field));
            }
        }

        $account_data = array(
            'project_id' => $project_id,
            'site_id' => $site_id, // Теперь это может быть NULL
            'service_id' => $service->id,
            'account_name' => sanitize_text_field($data['account_name'] ?? ''),
            'email' => sanitize_email($data['email'] ?? ''),
        );

        // Собираем чувствительные данные для шифрования
        $sensitive_data = array();
        foreach (array_merge($required_fields, $optional_fields) as $field) {
            if (isset($data[$field])) {
                $sensitive_data[$field] = sanitize_text_field($data[$field]);
            }
        }

        if (!empty($sensitive_data)) {
            $encrypted_data = sdm_encrypt(json_encode($sensitive_data)); // Используем функцию вместо класса
            $account_data['additional_data_enc'] = $encrypted_data;
        }

        // Обработка старых полей для совместимости с CloudFlare
        if ($service_name === 'CloudFlare (API Key)' || $service_name === 'CloudFlare (OAuth)') {
            if (isset($sensitive_data['email'])) {
                $account_data['email'] = $sensitive_data['email'];
            }
            if (isset($sensitive_data['api_key'])) {
                $account_data['api_key_enc'] = sdm_encrypt($sensitive_data['api_key']); // Используем функцию вместо класса
            }
            if (isset($sensitive_data['client_id'])) {
                $account_data['client_id_enc'] = sdm_encrypt($sensitive_data['client_id']); // Используем функцию вместо класса
            }
            if (isset($sensitive_data['client_secret'])) {
                $account_data['client_secret_enc'] = sdm_encrypt($sensitive_data['client_secret']); // Используем функцию вместо класса
            }
            if (isset($sensitive_data['refresh_token'])) {
                $account_data['refresh_token_enc'] = sdm_encrypt($sensitive_data['refresh_token']); // Используем функцию вместо класса
            }
        }

        $result = $wpdb->insert(
            $table,
            $account_data,
            array('%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        if ($result === false) {
            return new WP_Error('db_insert_error', __('Failed to create account.', 'spintax-domain-manager'));
        }

        return $wpdb->insert_id;
    }

    /**
     * Update an existing account by ID (with encryption and additional_params).
     */
    public function update_account($account_id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'sdm_accounts';

        $account_id = absint($account_id);
        if ($account_id <= 0) {
            return new WP_Error('invalid_id', __('Invalid account ID.', 'spintax-domain-manager'));
        }

        // Получаем старую запись, чтобы сохранить существующие данные
        $old_record = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $account_id));
        if (!$old_record) {
            return new WP_Error('not_found', __('Account not found.', 'spintax-domain-manager'));
        }

        // Получаем сервис по имени
        $service_name = sanitize_text_field($data['service'] ?? '');
        $service_manager = new SDM_Service_Types_Manager();
        $service = $service_manager->get_service_by_name($service_name);
        if (!$service) {
            return new WP_Error('service_not_found', __('Service not found.', 'spintax-domain-manager'));
        }

        $params = json_decode($service->additional_params, true);
        $required_fields = $params['required_fields'] ?? array();
        $optional_fields = $params['optional_fields'] ?? array();

        // Валидация обязательных полей
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                return new WP_Error('missing_required_field', sprintf(__('Required field "%s" is missing.', 'spintax-domain-manager'), $field));
            }
        }

        $account_data = array(
            'service_id' => $service->id,
            'account_name' => sanitize_text_field($data['account_name'] ?? $old_record->account_name), // Сохраняем старое значение, если новое пустое
            'email' => sanitize_email($data['email'] ?? $old_record->email), // Сохраняем старое значение email, если новое пустое
        );

        $site_id = isset($data['site_id']) && !empty($data['site_id']) ? absint($data['site_id']) : null;
        if ($site_id !== null) {
            $account_data['site_id'] = $site_id;
        }

        // Декодируем существующие чувствительные данные для сравнения
        $old_sensitive_data = array();
        if (!empty($old_record->additional_data_enc)) {
            $decrypted_old = sdm_decrypt($old_record->additional_data_enc);
            if ($decrypted_old !== false) {
                $old_sensitive_data = json_decode($decrypted_old, true) ?: array();
            }
        }

        // Собираем новые чувствительные данные, сохраняя старые, если новые пустые
        $sensitive_data = array();
        foreach (array_merge($required_fields, $optional_fields) as $field) {
            if (isset($data[$field]) && !empty($data[$field])) {
                $sensitive_data[$field] = sanitize_text_field($data[$field]); // Сохраняем только непустые новые значения
            } elseif (isset($old_sensitive_data[$field])) {
                $sensitive_data[$field] = $old_sensitive_data[$field]; // Сохраняем старое значение, если новое пустое
            }
        }

        if (!empty($sensitive_data)) {
            $encrypted_data = sdm_encrypt(json_encode($sensitive_data));
            $account_data['additional_data_enc'] = $encrypted_data;
        } elseif (!empty($old_record->additional_data_enc)) {
            // Если новых чувствительных данных нет, сохраняем старые зашифрованные данные
            $account_data['additional_data_enc'] = $old_record->additional_data_enc;
        }

        // Обработка старых полей для совместимости с CloudFlare
        if ($service_name === 'CloudFlare (API Key)' || $service_name === 'CloudFlare (OAuth)') {
            $account_data['email'] = $account_data['email'] ?? $old_record->email; // Убедились, что email сохранён
            if (isset($sensitive_data['api_key']) && !empty($sensitive_data['api_key'])) {
                $account_data['api_key_enc'] = sdm_encrypt($sensitive_data['api_key']);
            } else {
                $account_data['api_key_enc'] = $old_record->api_key_enc; // Сохраняем старый API-ключ, если новый пустой
            }
            if (isset($sensitive_data['client_id']) && !empty($sensitive_data['client_id'])) {
                $account_data['client_id_enc'] = sdm_encrypt($sensitive_data['client_id']);
            } else {
                $account_data['client_id_enc'] = $old_record->client_id_enc; // Сохраняем старый client_id, если новый пустой
            }
            if (isset($sensitive_data['client_secret']) && !empty($sensitive_data['client_secret'])) {
                $account_data['client_secret_enc'] = sdm_encrypt($sensitive_data['client_secret']);
            } else {
                $account_data['client_secret_enc'] = $old_record->client_secret_enc; // Сохраняем старый client_secret, если новый пустой
            }
            if (isset($sensitive_data['refresh_token']) && !empty($sensitive_data['refresh_token'])) {
                $account_data['refresh_token_enc'] = sdm_encrypt($sensitive_data['refresh_token']);
            } else {
                $account_data['refresh_token_enc'] = $old_record->refresh_token_enc; // Сохраняем старый refresh_token, если новый пустой
            }
        }

        $updated = $wpdb->update(
            $table,
            $account_data,
            array('id' => $account_id),
            array('%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s'),
            array('%d')
        );

        if ($updated === false) {
            return new WP_Error('db_update_error', __('Could not update account.', 'spintax-domain-manager'));
        }
        return true;
    }

    /**
     * Delete an account by ID.
     */
    public function delete_account($account_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'sdm_accounts';
        $account_id = absint($account_id);
        if ($account_id <= 0) {
            return new WP_Error('invalid_id', __('Invalid account ID.', 'spintax-domain-manager'));
        }
        $deleted = $wpdb->delete($table, array('id' => $account_id), array('%d'));
        if ($deleted === false) {
            return new WP_Error('db_delete_error', __('Could not delete account.', 'spintax-domain-manager'));
        }
        return true;
    }

    /**
     * Helper: Get service_id by service_name from sdm_service_types.
     */
    private function get_service_id_by_name($service_name) {
        global $wpdb;
        if (empty($service_name)) {
            return new WP_Error('invalid_service', __('Service name is empty.', 'spintax-domain-manager'));
        }

        $table = $wpdb->prefix . 'sdm_service_types';
        $service_id = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM $table WHERE service_name = %s", $service_name)
        );

        if (empty($service_id)) {
            return new WP_Error(
                'service_not_found',
                sprintf(__('Service "%s" not found in service types table.', 'spintax-domain-manager'), $service_name)
            );
        }
        return intval($service_id);
    }

    /**
     * Helper: Get account by ID (public for AJAX access).
     */
    public function get_account_by_id($account_id) { // Изменили с private на public
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sdm_accounts WHERE id = %d", $account_id));
    }
}

// AJAX-обработчики вне класса
function sdm_ajax_fetch_accounts() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'spintax-domain-manager'));
    }
    sdm_check_main_nonce();

    $manager = new SDM_Accounts_Manager();
    $accounts = $manager->get_all_accounts();
    wp_send_json_success(array('accounts' => $accounts));
}
add_action('wp_ajax_sdm_fetch_accounts', 'sdm_ajax_fetch_accounts');

function sdm_ajax_create_sdm_account() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'spintax-domain-manager'));
    }
    sdm_check_main_nonce();

    error_log('Creating account with data: ' . print_r($_POST, true)); // Отладка
    $manager = new SDM_Accounts_Manager();
    $result = $manager->create_account($_POST);

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }
    wp_send_json_success(array('message' => __('Account created successfully.', 'spintax-domain-manager'), 'account_id' => $result));
}
add_action('wp_ajax_sdm_create_sdm_account', 'sdm_ajax_create_sdm_account');

function sdm_ajax_update_sdm_account() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'spintax-domain-manager'));
    }
    sdm_check_main_nonce();

    $account_id = absint($_POST['account_id'] ?? 0);
    error_log('Updating account ' . $account_id . ' with data: ' . print_r($_POST, true)); // Отладка
    $manager = new SDM_Accounts_Manager();
    $result = $manager->update_account($account_id, $_POST);

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }
    wp_send_json_success(array('message' => __('Account updated successfully.', 'spintax-domain-manager')));
}
add_action('wp_ajax_sdm_update_sdm_account', 'sdm_ajax_update_sdm_account');

function sdm_ajax_delete_sdm_account() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'spintax-domain-manager'));
    }
    sdm_check_main_nonce();

    $account_id = absint($_POST['account_id'] ?? 0);
    $manager = new SDM_Accounts_Manager();
    $result = $manager->delete_account($account_id);

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }
    wp_send_json_success(array('message' => __('Account deleted successfully.', 'spintax-domain-manager')));
}
add_action('wp_ajax_sdm_delete_sdm_account', 'sdm_ajax_delete_sdm_account');

function sdm_ajax_test_sdm_account() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'spintax-domain-manager'));
    }
    sdm_check_main_nonce();

    $account_id = absint($_POST['account_id'] ?? 0);
    $manager = new SDM_Accounts_Manager();
    $account = $manager->get_account_by_id($account_id);
    if (!$account) {
        wp_send_json_error(__('Account not found.', 'spintax-domain-manager'));
    }

    $service_manager = new SDM_Service_Types_Manager();
    $service = $service_manager->get_service_by_id($account->service_id);
    if (!$service) {
        wp_send_json_error(__('Service not found.', 'spintax-domain-manager'));
    }

    $params = json_decode($service->additional_params, true);
    $credentials = array();
    if (!empty($account->additional_data_enc)) {
        $sensitive_data = json_decode(sdm_decrypt($account->additional_data_enc), true); // Используем функцию вместо класса
        if (json_last_error() === JSON_ERROR_NONE) {
            $credentials = $sensitive_data;
        }
    }

    // Тестирование подключения (пример для CloudFlare)
    if ($service->service_name === 'CloudFlare (API Key)' || $service->service_name === 'CloudFlare (OAuth)') {
        if (empty($credentials['email']) || ($service->service_name === 'CloudFlare (API Key)' && empty($credentials['api_key']))) {
            wp_send_json_error(__('Incomplete CloudFlare credentials.', 'spintax-domain-manager'));
        }
        require_once SDM_PLUGIN_DIR . 'includes/api/class-sdm-cloudflare-api.php';
        $cf_api = new SDM_Cloudflare_API(array(
            'email' => $credentials['email'],
            'api_key' => $credentials['api_key'] ?? '',
            'client_id' => $credentials['client_id'] ?? '',
            'client_secret' => $credentials['client_secret'] ?? '',
            'refresh_token' => $credentials['refresh_token'] ?? ''
        ));
        $result = $cf_api->test_connection();
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        $wpdb->update(
            $wpdb->prefix . 'sdm_accounts',
            array(
                'last_tested_at' => current_time('mysql'),
                'last_test_result' => 'Success'
            ),
            array('id' => $account_id),
            array('%s', '%s'),
            array('%d')
        );
        wp_send_json_success(array('message' => __('Connection tested successfully.', 'spintax-domain-manager')));
    } elseif ($service->service_name === 'HostTracker') {
        // Логика для HostTracker (получение токена и проверка задач)
        $token = SDM_HostTracker_API::get_host_tracker_token($credentials);
        if (!$token) {
            wp_send_json_error(__('Failed to authenticate with HostTracker.', 'spintax-domain-manager'));
        }
        $tasks = SDM_HostTracker_API::get_host_tracker_tasks($token, $credentials['task_type'] ?? 'bl:ru');
        if (!$tasks) {
            wp_send_json_error(__('Failed to fetch tasks from HostTracker.', 'spintax-domain-manager'));
        }
        $wpdb->update(
            $wpdb->prefix . 'sdm_accounts',
            array(
                'last_tested_at' => current_time('mysql'),
                'last_test_result' => 'Success'
            ),
            array('id' => $account_id),
            array('%s', '%s'),
            array('%d')
        );
        wp_send_json_success(array('message' => __('Connection tested successfully.', 'spintax-domain-manager')));
    } else {
        wp_send_json_error(__('Testing not implemented for this service.', 'spintax-domain-manager'));
    }
}
add_action('wp_ajax_sdm_test_sdm_account', 'sdm_ajax_test_sdm_account');

function sdm_ajax_get_service_params() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'spintax-domain-manager'));
    }
    sdm_check_main_nonce();

    $service_name = sanitize_text_field($_POST['service'] ?? '');
    $service_manager = new SDM_Service_Types_Manager();
    $service = $service_manager->get_service_by_name($service_name);
    if (!$service) {
        wp_send_json_error(__('Service not found.', 'spintax-domain-manager'));
    }
    error_log('Service params for ' . $service_name . ': ' . $service->additional_params); // Отладка
    wp_send_json_success(array('params' => json_decode($service->additional_params, true)));
}
add_action('wp_ajax_sdm_get_service_params', 'sdm_ajax_get_service_params');

function sdm_ajax_get_account_details() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'spintax-domain-manager'));
        return;
    }
    sdm_check_main_nonce();

    $account_id = absint($_POST['account_id'] ?? 0);
    error_log('Fetching account details for ID: ' . $account_id); // Отладка

    $manager = new SDM_Accounts_Manager();
    $account = $manager->get_account_by_id($account_id); // Теперь метод public

    if (!$account) {
        error_log('Account not found for ID: ' . $account_id);
        wp_send_json_error(__('Account not found.', 'spintax-domain-manager'));
        return;
    }

    // Декодируем зашифрованные данные, если есть, с отладкой
    $sensitive_data = array();
    if (!empty($account->additional_data_enc)) {
        $decrypted = sdm_decrypt($account->additional_data_enc);
        error_log('Decrypted data: ' . ($decrypted !== false ? $decrypted : 'Decryption failed')); // Отладка
        if ($decrypted !== false) {
            $sensitive_data = json_decode($decrypted, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log('JSON decode error: ' . json_last_error_msg());
                $sensitive_data = array(); // Устанавливаем пустой массив в случае ошибки
            }
        }
    }

    $service_manager = new SDM_Service_Types_Manager();
    $service = $service_manager->get_service_by_id($account->service_id);
    if (!$service) {
        error_log('Service not found for service_id: ' . $account->service_id);
        wp_send_json_error(__('Service not found.', 'spintax-domain-manager'));
        return;
    }

    $account_data = array(
        'id' => $account->id,
        'project_id' => $account->project_id,
        'service' => $service->service_name,
        'account_name' => $account->account_name,
        'email' => $account->email,
    );

    // Добавляем чувствительные данные из additional_data_enc
    $account_data = array_merge($account_data, $sensitive_data);

    error_log('Account data sent: ' . print_r($account_data, true)); // Отладка
    wp_send_json_success(array('account' => $account_data));
}
add_action('wp_ajax_sdm_get_account_details', 'sdm_ajax_get_account_details');