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
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('Invalid JSON in additional_params for service ' . $service_name . ': ' . $service->additional_params);
            return new WP_Error('invalid_service_params', __('Invalid service configuration.', 'spintax-domain-manager'));
        }
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

        // Собираем чувствительные данные для шифрования с отладкой
        $sensitive_data = array();
        foreach (array_merge($required_fields, $optional_fields) as $field) {
            if (isset($data[$field])) {
                $value = sanitize_text_field($data[$field]);
                $sensitive_data[$field] = $value;
                error_log('Sensitive data for ' . $field . ': ' . $value); // Отладка
            }
        }
        error_log('Full sensitive data: ' . print_r($sensitive_data, true)); // Отладка

        if (!empty($sensitive_data)) {
            $json_sensitive = json_encode($sensitive_data);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log('JSON encode error for sensitive data: ' . json_last_error_msg());
                return new WP_Error('json_error', __('Failed to encode sensitive data.', 'spintax-domain-manager'));
            }
            $encrypted_data = sdm_encrypt($json_sensitive); // Используем функцию вместо класса
            error_log('Encrypted data: ' . $encrypted_data); // Отладка
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
            $error = $wpdb->last_error;
            error_log('Database insert error: ' . $error);
            return new WP_Error('db_insert_error', __('Failed to create account. Database error: ' . $error, 'spintax-domain-manager'));
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

        // Получаем старую запись, чтобы проверить её существование, но не используем для сохранения данных
        $old_record = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $account_id));
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
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('invalid_service_params', __('Invalid service configuration.', 'spintax-domain-manager'));
        }
        $required_fields = $params['required_fields'] ?? array();
        $optional_fields = $params['optional_fields'] ?? array();

        // Валидация обязательных полей (просто проверяем, что они существуют)
        foreach ($required_fields as $field) {
            if (!isset($data[$field])) {
                return new WP_Error('missing_required_field', sprintf(__('Required field "%s" is missing.', 'spintax-domain-manager'), $field));
            }
        }

        $account_data = array(
            'service_id' => $service->id,
            'account_name' => sanitize_text_field($data['account_name'] ?? ''),
            'email' => sanitize_email($data['email'] ?? ''),
        );

        $site_id = isset($data['site_id']) ? absint($data['site_id']) : null;
        if ($site_id !== null) {
            $account_data['site_id'] = $site_id;
        }

        // Собираем все чувствительные данные для шифрования, включая пустые значения
        $sensitive_data = array();
        foreach (array_merge($required_fields, $optional_fields) as $field) {
            if (isset($data[$field])) {
                $sensitive_data[$field] = sanitize_text_field($data[$field]); // Сохраняем все значения, даже пустые
            }
        }

        // Если есть чувствительные данные, шифруем их в additional_data_enc
        if (!empty($sensitive_data)) {
            $json_sensitive = json_encode($sensitive_data);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return new WP_Error('json_error', __('Failed to encode sensitive data.', 'spintax-domain-manager'));
            }
            $encrypted_data = sdm_encrypt($json_sensitive); // Используем функцию вместо класса
            $account_data['additional_data_enc'] = (string)$encrypted_data; // Явно приводим к строке
        }

        // Обработка полей для совместимости с CloudFlare (сохраняем все данные, даже пустые)
        if ($service_name === 'CloudFlare (API Key)' || $service_name === 'CloudFlare (OAuth)') {
            $account_data['email'] = $account_data['email'] ?? ''; // Убедились, что email сохранён
            if (isset($sensitive_data['api_key'])) {
                $account_data['api_key_enc'] = sdm_encrypt($sensitive_data['api_key'] ?? '');
            }
            if (isset($sensitive_data['client_id'])) {
                $account_data['client_id_enc'] = sdm_encrypt($sensitive_data['client_id'] ?? '');
            }
            if (isset($sensitive_data['client_secret'])) {
                $account_data['client_secret_enc'] = sdm_encrypt($sensitive_data['client_secret'] ?? '');
            }
            if (isset($sensitive_data['refresh_token'])) {
                $account_data['refresh_token_enc'] = sdm_encrypt($sensitive_data['refresh_token'] ?? '');
            }
        }

        // Начинаем транзакцию
        $wpdb->query('START TRANSACTION');

        try {
            // Формируем список полей и значений для обновления, исключая неиспользуемые поля
            $update_fields = array();
            $format = array();
            $where_format = array('%d'); // WHERE id

            foreach ($account_data as $field => $value) {
                $update_fields[$field] = $value;
                if ($field === 'service_id') {
                    $format[] = '%d';
                } elseif ($field === 'site_id') {
                    $format[] = '%d';
                } else {
                    $format[] = '%s'; // Для всех строковых полей, включая NULL
                }
            }

            // Подготавливаем и выполняем запрос с использованием $wpdb->prepare
            $updated = $wpdb->update(
                $table,
                $update_fields,
                array('id' => $account_id),
                $format,
                $where_format
            );

            if ($updated === false) {
                throw new Exception('Update failed: ' . $wpdb->last_error);
            }

            // Если обновление успешно, фиксируем транзакцию
            $wpdb->query('COMMIT');
            return true;
        } catch (Exception $e) {
            // В случае ошибки откатываем транзакцию
            $wpdb->query('ROLLBACK');
            $error = $e->getMessage();
            return new WP_Error('db_update_error', __('Could not update account. Database error: ' . $error, 'spintax-domain-manager'));
        }
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
        $error_message = $result->get_error_message();
        error_log('Update failed for account ' . $account_id . ': ' . $error_message);
        wp_send_json_error($error_message);
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

    error_log('Testing account ' . $account_id . ' for service: ' . $service->service_name); // Отладка

    $params = json_decode($service->additional_params, true);
    $credentials = array();
    if (!empty($account->additional_data_enc)) {
        $decrypted = sdm_decrypt($account->additional_data_enc);
        error_log('Decrypted data for account ' . $account_id . ': ' . ($decrypted !== false ? $decrypted : 'Decryption failed')); // Отладка
        if ($decrypted !== false) {
            $sensitive_data = json_decode($decrypted, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $credentials = $sensitive_data;
            } else {
                error_log('JSON decode error for account ' . $account_id . ': ' . json_last_error_msg());
                wp_send_json_error(__('Failed to decode account credentials.', 'spintax-domain-manager'));
                return;
            }
        } else {
            wp_send_json_error(__('Failed to decrypt account credentials.', 'spintax-domain-manager'));
            return;
        }
    } else {
        error_log('No additional_data_enc for account ' . $account_id);
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