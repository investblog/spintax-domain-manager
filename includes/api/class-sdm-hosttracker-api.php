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
            return false;
        }

        $args = array(
            'body' => array(
                'login' => $credentials['login'],
                'password' => $credentials['password'],
            ),
        );
        $response = wp_remote_post('https://api.host-tracker.com/authenticate', $args);

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return isset($data['token']) ? $data['token'] : false;
    }

    public static function get_host_tracker_tasks($token, $task_type = 'bl:ru') {
        if (empty($token)) {
            return false;
        }

        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
            ),
        );
        $response = wp_remote_get('https://api.host-tracker.com/tasks?type=' . urlencode($task_type), $args);

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return isset($data['tasks']) && !empty($data['tasks']);
    }
}