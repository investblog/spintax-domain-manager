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
            } elseif ($task_type === 'bl:ru') {
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
}