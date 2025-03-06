<?php
/**
 * File: includes/api/class-sdm-hosttracker-api.php
 * Description: Handles integration with HostTracker API for domain monitoring.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SDM_HostTracker_API {
    private $credentials;

    public function __construct($credentials = array()) {
        $this->credentials = $credentials;
    }

    public static function get_host_tracker_token($credentials) {
        if (empty($credentials['login']) || empty($credentials['password'])) {
            error_log('HostTracker API error: Username or password not provided.');
            return false;
        }

        $args = array(
            'method' => 'POST',
            'body' => json_encode(array("login" => $credentials['login'], "password" => $credentials['password'])),
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept' => 'application/json', // Добавляем заголовок Accept для совместимости
            ),
            'timeout' => 30, // Увеличиваем таймаут для надёжности
        );

        $response = wp_remote_post('https://api1.host-tracker.com/users/token', $args);

        if (is_wp_error($response)) {
            error_log('HostTracker API error (token): ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        error_log('HostTracker token response body: ' . $body); // Отладка тела ответа
        $data = json_decode($body);

        if (!empty($data->ticket)) {
            return $data->ticket;
        }

        // Проверяем, есть ли ошибка в ответе
        if (isset($data->error)) {
            error_log('HostTracker API error (token): ' . $data->error);
        } else {
            error_log('HostTracker API error (token): Unexpected response format - ' . $body);
        }
        return false;
    }

    public static function get_host_tracker_tasks($token, $task_type = 'RusRegBL') {
        if (empty($token)) {
            return false;
        }

        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
            'method' => 'GET',
        );

        $url = 'https://api1.host-tracker.com/tasks';

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        if (!is_array($data)) {
            return false;
        }

        $filtered_tasks = array_filter($data, function ($task) use ($task_type) {
            if ($task_type === 'RusRegBL') {
                return isset($task['taskType']) && $task['taskType'] === 'RusRegBL';
            } elseif ($task_type === 'Http') { // Заменили bl:ru на Http
                return isset($task['taskType']) && $task['taskType'] === 'Http' &&
                       isset($task['agentPools']) && in_array('russia', $task['agentPools']) &&
                       isset($task['keywords']) && in_array('rkn.gov.ru', $task['keywords']) && in_array('№149-ФЗ', $task['keywords']);
            }
            return false;
        });

        if (empty($filtered_tasks)) {
            return false;
        }

        return array_values($filtered_tasks);
    }

    /**
     * Create a new monitoring task in HostTracker.
     *
     * @param string $token HostTracker API token.
     * @param string $domain_url Domain to monitor.
     * @param string $task_type Type of monitoring (e.g., 'RusRegBL', 'Http').
     * @return string|WP_Error Task ID or WP_Error on failure.
     */
    public static function create_host_tracker_task($token, $domain_url, $task_type) {
        $api_url = 'https://api.host-tracker.com/tasks'; // Проверь точный URL в документации HostTracker
        $headers = array(
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
        );

        $body = json_encode(array(
            'url' => $domain_url,
            'type' => $task_type,
            'interval' => 5, // Интервал в минутах, настрой под документацию
            'regions' => array('Russia'), // Настрой регионы
        ));

        $args = array(
            'headers' => $headers,
            'body' => $body,
            'timeout' => 30, // Увеличим таймаут до 30 секунд
            'sslverify' => false, // Отключим проверку SSL для тестов (включить на продакшене)
        );

        $response = wp_remote_post($api_url, $args);

        if (is_wp_error($response)) {
            error_log('HostTracker create task error: ' . $response->get_error_message());
            return new WP_Error('api_error', __('Failed to create HostTracker task: ' . $response->get_error_message(), 'spintax-domain-manager'));
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['id'])) {
            return $data['id'];
        } else {
            error_log('HostTracker create task response: ' . $body);
            return new WP_Error('api_error', __('Invalid response from HostTracker.', 'spintax-domain-manager'));
        }
    }
}