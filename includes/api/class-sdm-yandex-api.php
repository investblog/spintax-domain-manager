<?php
if (!defined('ABSPATH')) {
    exit;
}

class SDM_Yandex_API {

    /**
     * Проверить валидность токена и user_id (запрос списка хостов).
     *
     * @param string $token
     * @param string $user_id
     * @return array [ 'success' => bool, 'message' => string, ... ]
     */
    public static function check_credentials($token, $user_id) {
        $url = "https://api.webmaster.yandex.net/v4/user/$user_id/hosts/";
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'OAuth ' . $token,
            ),
            'timeout' => 25
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message()
            );
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['hosts'])) {
            return array(
                'success' => true,
                'message' => 'Yandex API token is valid.'
            );
        } else {
            return array(
                'success' => false,
                'error' => 'Yandex API token is not valid.',
                'response' => $data
            );
        }
    }

    /**
     * Получить host_id для домена (если уже добавлен в вебмастер).
     *
     * @param string $token
     * @param string $user_id
     * @param string $domain
     * @return string|false
     */
    public static function get_host_id($token, $user_id, $domain) {
        $url = "https://api.webmaster.yandex.net/v4/user/$user_id/hosts/";
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'OAuth ' . $token,
            ),
            'timeout' => 25
        ));

        if (is_wp_error($response)) {
            error_log("Error retrieving Yandex host list: " . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['hosts'])) {
            return false;
        }

        foreach ($data['hosts'] as $host) {
            // В Яндексе урлы обычно сохраняются с завершающим слешем
            if (isset($host['unicode_host_url']) && $host['unicode_host_url'] === "https://{$domain}/") {
                return $host['host_id'];
            }
        }
        return false;
    }

    /**
     * Добавить домен в Яндекс.Вебмастер (если отсутствует).
     *
     * @param string $token
     * @param string $user_id
     * @param string $domain
     * @return bool true, если домен успешно добавлен (или уже есть)
     */
    public static function add_domain($token, $user_id, $domain) {
        // Сначала проверим, не добавлен ли уже
        $existing = self::get_host_id($token, $user_id, $domain);
        if ($existing) {
            // Уже есть
            return true;
        }

        $url = "https://api.webmaster.yandex.net/v4/user/$user_id/hosts";
        $body = json_encode(array(
            'host_url' => "https://{$domain}"
        ));

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'OAuth ' . $token,
                'Content-Type'  => 'application/json'
            ),
            'body'    => $body,
            'timeout' => 25
        ));

        if (is_wp_error($response)) {
            error_log("Error adding domain $domain: " . $response->get_error_message());
            return false;
        }

        $resp_body = wp_remote_retrieve_body($response);
        $data = json_decode($resp_body, true);

        if (!empty($data['host_id'])) {
            return true;
        } else {
            error_log("Error adding domain $domain to Yandex Webmaster: " . json_encode($data));
            return false;
        }
    }

    /**
     * Инициировать проверку в Яндекс.Вебмастере (DNS-верификация).
     * 
     * @param string $token
     * @param string $user_id
     * @param string $domain
     * @return string|false verification_uin или false при ошибке
     */
    public static function init_verification($token, $user_id, $domain) {
        // Убедимся, что домен есть в вебмастере
        $host_id = self::get_host_id($token, $user_id, $domain);
        if (!$host_id) {
            // Сначала пытаемся добавить
            $added = self::add_domain($token, $user_id, $domain);
            if (!$added) {
                return false;
            }
            // Повторяем получение host_id
            $host_id = self::get_host_id($token, $user_id, $domain);
        }

        if (!$host_id) {
            return false;
        }

        // Отправляем запрос на верификацию
        $url = "https://api.webmaster.yandex.net/v4/user/$user_id/hosts/$host_id/verification?verification_type=DNS";
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'OAuth ' . $token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 25
        ));

        if (is_wp_error($response)) {
            error_log("Error initializing verification for $domain: " . $response->get_error_message());
            return false;
        }

        $resp_body = wp_remote_retrieve_body($response);
        $data = json_decode($resp_body, true);

        if (isset($data['verification_uin'])) {
            return $data['verification_uin'];
        }
        return false;
    }
}