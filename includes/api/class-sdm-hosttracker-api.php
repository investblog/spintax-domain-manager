<?php
/**
 * File: includes/api/class-sdm-hosttracker-api.php
 * Description: Unified integration with HostTracker API for domain monitoring:
 *              keeps the old method signatures but routes to new endpoints.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SDM_HostTracker_API {

    /**
     * Получаем токен (login/password) в виде ранее – без project_id.
     * Если в старой логике credentials приходят «как есть», оставляем.
     */
    public static function get_host_tracker_token($credentials) {
        if (empty($credentials['login']) || empty($credentials['password'])) {
            error_log('HostTracker API error: Username or password not provided.');
            return false;
        }

        $args = array(
            'method'  => 'POST',
            'body'    => json_encode(array(
                "login"    => $credentials['login'],
                "password" => $credentials['password'],
            )),
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json', // Для совместимости
            ),
            'timeout' => 30,
        );

        $response = wp_remote_post('https://api1.host-tracker.com/users/token', $args);

        if (is_wp_error($response)) {
            error_log('HostTracker API error (token): ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        error_log('HostTracker token response body: ' . $body);

        $data = json_decode($body);
        if (!empty($data->ticket)) {
            return $data->ticket; // возвращаем сам токен
        }

        // Иначе логируем ошибку
        if (isset($data->error)) {
            error_log('HostTracker API error (token): ' . $data->error);
        } else {
            error_log('HostTracker API error (token): Unexpected format - ' . $body);
        }
        return false;
    }

    /**
     * Универсальное создание задачи мониторинга. 
     * Старый код вызывал:
     *    SDM_HostTracker_API::create_host_tracker_task($token, $domain_url, $task_type)
     * Мы внутри сделаем «разветвление» для 'RusRegBL', 'Http' и т.д.
     *
     * @param string $token 
     * @param string $domain_url
     * @param string $task_type 'RusRegBL' или 'Http' и т.п.
     * @return string|WP_Error Возвращаем task_id или WP_Error
     */
    public static function create_host_tracker_task($token, $domain_url, $task_type) {
        if (!$token) {
            $msg = 'HostTracker: no token to create ' . $task_type . ' task.';
            error_log($msg);
            return new WP_Error('no_token', $msg);
        }

        // В зависимости от $task_type делаем разные эндпоинты и тело запроса:
        switch ($task_type) {
            case 'RusRegBL':
                // POST /tasks/rusbl, указывая 'taskType'=>'RusRegBL'
                $api_url = 'https://api1.host-tracker.com/tasks/rusbl';
                // Пример тела: (как в «новом» коде)
                $post_body = array(
                    'name'     => $domain_url,
                    'url'      => $domain_url,
                    'taskType' => 'RusRegBL',
                    'interval' => 30,
                    'enabled'  => true,
                    'ignoreWarnings' => true,
                );
                break;

            case 'Http':
                // POST /tasks, указывая 'type'=>'Http', 'regions'=>['Russia'], и т.д.
                // (Этот кусок взят из вашего старого кода, можно поднастроить)
                $api_url = 'https://api1.host-tracker.com/tasks';
                $post_body = array(
                    'url'      => $domain_url,
                    'type'     => 'Http',
                    'interval' => 5,
                    'regions'  => array('Russia'),
                );
                // Можете добавить 'enabled'=>true и т.д. 
                break;

            default:
                // Если встречается неизвестный тип, вернём ошибку,
                // или можете повесить 'Http' по умолчанию
                $msg = "create_host_tracker_task: unknown task_type=$task_type";
                error_log($msg);
                return new WP_Error('unknown_task_type', $msg);
        }

        $args = array(
            'method'  => 'POST',
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => json_encode($post_body),
            'timeout' => 30,
            // 'sslverify' => false, // Если надо отключить SSL-проверку
        );

        $response = wp_remote_post($api_url, $args);

        if (is_wp_error($response)) {
            error_log('HostTracker create task error: ' . $response->get_error_message());
            return new WP_Error('api_error', 'Failed to create HostTracker task: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        $code = wp_remote_retrieve_response_code($response);

        // У RusRegBL при успешном создании обычно HTTP 201
        // У Http может быть 200 или 201 – лучше выводить лог
        error_log("create_host_tracker_task($task_type) response code=$code, body=$body");

        // Ищем ID задачи
        if (!empty($data['id'])) {
            return $data['id'];
        } else {
            return new WP_Error('api_error', 'Invalid response from HostTracker: ' . $body);
        }
    }

    /**
     * Универсальное удаление задачи. Аналогично старому вызову:
     *   SDM_HostTracker_API::delete_host_tracker_task($token, $task_id)
     * Теперь добавим $task_type, если нужно другое поведение для RusRegBL/Http.
     */
    public static function delete_host_tracker_task($token, $task_id, $task_type = 'RusRegBL') {
        if (!$token) {
            error_log("delete_host_tracker_task: no token for $task_id, type=$task_type");
            return false;
        }

        // На практике для RusRegBL и Http – удаление одинаковое:
        // DELETE /tasks?ids=TASK_ID
        // HostTracker API, как правило, не требует отличать тип
        // Но если есть разные эндпоинты, сделайте switch.
        $url = "https://api1.host-tracker.com/tasks?ids=" . urlencode($task_id);

        $args = array(
            'method' => 'DELETE',
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'timeout' => 30,
        );

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            error_log('delete_host_tracker_task error: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        error_log("delete_host_tracker_task($task_id, $task_type) code=$code, body=$body");

        // Успешное удаление – это 200 или 204
        return in_array($code, array(200, 204), true);
    }

    /**
     * Получение всех задач с HostTracker и фильтрация по $task_type.
     * Используется старой логикой:
     *    $tasks = SDM_HostTracker_API::get_host_tracker_tasks($token, 'RusRegBL');
     */
    public static function get_host_tracker_tasks($token, $task_type = 'RusRegBL') {
        if (empty($token)) {
            return false;
        }

        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ),
            'method' => 'GET',
            'timeout' => 30,
        );

        $url = 'https://api1.host-tracker.com/tasks';
        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            error_log('get_host_tracker_tasks error: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data)) {
            error_log("get_host_tracker_tasks: unexpected response - $body");
            return false;
        }

        // Фильтруем по типу
        $filtered_tasks = array_filter($data, function ($task) use ($task_type) {
            if ($task_type === 'RusRegBL') {
                return (isset($task['taskType']) && $task['taskType'] === 'RusRegBL');
            } elseif ($task_type === 'Http') {
                // Пример из старого кода (если нужно искать agentPools 'russia' + keywords)
                return isset($task['taskType']) && $task['taskType'] === 'Http'
                    && isset($task['agentPools']) && in_array('russia', $task['agentPools'])
                    && isset($task['keywords']) && in_array('rkn.gov.ru', $task['keywords'])
                    && in_array('№149-ФЗ', $task['keywords']);
            }
            return false;
        });

        if (empty($filtered_tasks)) {
            return array(); // или false, если хотите
        }

        return array_values($filtered_tasks);
    }
}
