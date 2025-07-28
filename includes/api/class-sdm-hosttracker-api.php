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
     * Map two-letter language codes to HostTracker agent pools.
     *
     * @param string $lang Two letter language code (e.g. 'ru').
     * @return array Array of agent pools.
     */
    private static $available_pools = array(
        // Available top-level HostTracker pools as of 2025
        'africa', 'allworld', 'asia', 'australia', 'easteurope', 'iran',
        'northamerica', 'russia', 'southamerica', 'waterfall', 'westeurope'
    );

    private static function agent_pool_exists($pool) {
        return in_array($pool, self::$available_pools, true);
    }

    private static function map_language_to_agent_pools($lang) {
        $lang = strtolower($lang);
        switch ($lang) {
            case 'ru':
                return array('russia');
            case 'tr':
                return self::agent_pool_exists('turkey') ? array('turkey') : array('easteurope');
            case 'cn':
                return self::agent_pool_exists('china') ? array('china') : array('asia');
            case 'ir':
                return array('iran');
            case 'sa':
                return self::agent_pool_exists('saudiarabia') ? array('saudiarabia') : array('asia');
            case 'ae':
                return self::agent_pool_exists('uae') ? array('uae') : array('asia');
            case 'by':
                return self::agent_pool_exists('belarus') ? array('belarus') : array('easteurope');
            case 'kz':
                return self::agent_pool_exists('kazakhstan') ? array('kazakhstan') : array('asia');
            case 'en':
                return self::agent_pool_exists('usa') ? array('usa') : array('northamerica');
            case 'es':
                return self::agent_pool_exists('spain') ? array('spain') : array('westeurope');
            case 'fr':
                return self::agent_pool_exists('france') ? array('france') : array('westeurope');
            case 'pl':
                return self::agent_pool_exists('poland') ? array('poland') : array('easteurope');
            default:
                return array('westeurope');
        }
    }

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
     *
     * @param string      $token         Токен HostTracker.
     * @param string      $domain_url    URL домена.
     * @param string      $task_type     Тип задачи ('RusRegBL' или 'Http').
     * @param string|null $language_code Язык (например, 'ru') для передачи в качестве тега.
     * @return string|WP_Error Возвращает task_id или WP_Error.
     */
    public static function create_host_tracker_task($token, $domain_url, $task_type, $language_code = null) {
        if (!$token) {
            $msg = 'HostTracker: no token to create ' . $task_type . ' task.';
            error_log($msg);
            return new WP_Error('no_token', $msg);
        }

        // 1) Получаем список подтверждённых контактов
        $contacts = self::get_host_tracker_contacts($token);
        $contact_ids = array();
        if (!is_wp_error($contacts) && !empty($contacts)) {
            // Извлекаем только ID контактов
            $contact_ids = array_column($contacts, 'id');
        } else {
            error_log('No confirmed contacts found (or error fetching) for domain=' . $domain_url);
        }

        // 2) Формируем URL и тело запроса в зависимости от типа задачи
        switch ($task_type) {
            case 'RusRegBL':
                $api_url = 'https://api1.host-tracker.com/tasks/rusbl';
                $post_body = array(
                    'name'           => $domain_url,
                    'url'            => $domain_url,
                    'taskType'       => 'RusRegBL',
                    'interval'       => 30,          // интервал в минутах
                    'enabled'        => true,
                    'ignoreWarnings' => true,
                );
                break;

            case 'Http':
                // Для HTTP задач выбираем пулы агентов по языку сайта
                $api_url    = 'https://api1.host-tracker.com/tasks/http';
                $agentPools = self::map_language_to_agent_pools($language_code);
                $post_body  = array(
                    "url"                     => $domain_url,
                    "httpMethod"              => "Get",
                    "followRedirect"          => true,
                    "treat300AsError"         => false,
                    "checkDnsbl"              => false,
                    "checkDomainExpiration"   => false,
                    "checkCertificateExpiration" => false,
                    "timeout"                 => 40000,         // миллисекунды
                    "keywords"                => array("rkn.gov.ru", "№149-ФЗ"),
                    "keywordMode"             => "ReverseAny",
                    "interval"                => 60,            // интервал в минутах
                    "enabled"                 => true,
                    "agentPools"              => $agentPools
                );
                break;

            default:
                $msg = "create_host_tracker_task: unknown task_type=$task_type";
                error_log($msg);
                return new WP_Error('unknown_task_type', $msg);
        }

        // 3) Добавляем подписки, если есть контакты
        if (!empty($contact_ids)) {
            $post_body['subscriptions'] = array(
                array(
                    'alertTypes' => array('Down'),
                    'contactIds' => $contact_ids,
                )
            );
        }

        // 4) Добавляем теги (например, язык домена)
        if (!empty($language_code)) {
            $post_body['tags'] = array($language_code);
        }

        // 5) Выполняем запрос к HostTracker
        $args = array(
            'method'  => 'POST',
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => json_encode($post_body),
            'timeout' => 30,
        );

        $response = wp_remote_post($api_url, $args);
        if (is_wp_error($response)) {
            error_log('HostTracker create task error: ' . $response->get_error_message());
            return new WP_Error('api_error', 'Failed to create HostTracker task: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        $code = wp_remote_retrieve_response_code($response);

        error_log("create_host_tracker_task($task_type) response code=$code, body=$body");

        if (!empty($data['id'])) {
            return $data['id'];
        } 
        return new WP_Error('api_error', 'Invalid response from HostTracker: ' . $body);
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

    public static function sync_rusregbl_for_domain($domain_id) {
        global $wpdb;

        $dom_table  = $wpdb->prefix . 'sdm_domains';
        $site_table = $wpdb->prefix . 'sdm_sites';

        // Получаем запись о домене
        $dom_row = $wpdb->get_row($wpdb->prepare("
            SELECT * 
              FROM $dom_table
             WHERE id = %d
        ", $domain_id));

        if (!$dom_row) {
            return; // Нет такого домена
        }

        // 1) Если домен отвязан, но имеет hosttracker_task_id — удаляем задачу
        if (empty($dom_row->site_id) && !empty($dom_row->hosttracker_task_id)) {
            $task_data = maybe_unserialize($dom_row->hosttracker_task_id);
            if (is_array($task_data)) {
                $task_id_value = $task_data['RusRegBL'] ?? null;
            } else {
                $task_id_value = $dom_row->hosttracker_task_id;
            }

            // Получаем токен/логин-пароль по проекту:
            // (предполагаем, что project_id есть в таблице sdm_domains)
            $token = SDM_HostTracker_API::get_token_for_project($dom_row->project_id);
            if ($token) {
                if ($task_id_value) {
                    $ok = SDM_HostTracker_API::delete_host_tracker_task($token, $task_id_value, 'RusRegBL');
                } else {
                    $ok = false;
                }
                if ($ok) {
                    $wpdb->update(
                        $dom_table,
                        array(
                            'hosttracker_task_id' => null,
                            'updated_at'          => current_time('mysql')
                        ),
                        array('id' => $domain_id),
                        array('%s','%s'),
                        array('%d')
                    );
                } else {
                    error_log("sync_rusregbl_for_domain: failed to delete task {$dom_row->hosttracker_task_id} for domain ID=$domain_id");
                }
            } else {
                error_log("sync_rusregbl_for_domain: no token for project_id={$dom_row->project_id}");
            }
            return;
        }

        // 2) Если домен привязан к сайту (site_id != NULL)
        if (!empty($dom_row->site_id)) {
            $site = $wpdb->get_row($wpdb->prepare("
                SELECT project_id, monitoring_settings
                  FROM $site_table
                 WHERE id = %d
            ", $dom_row->site_id));

            if (!$site) {
                return; // Странная ситуация, но сайта нет
            }

            // Смотрим, включён ли RusRegBL в настройках
            $settings = json_decode($site->monitoring_settings, true);
            $rusregbl_enabled = (
                !empty($settings['enabled']) &&
                !empty($settings['types']['RusRegBL'])
            );

            // 2a) Включён, но нет task_id → создаём
            $task_data = maybe_unserialize($dom_row->hosttracker_task_id);
            $rus_task_id = is_array($task_data) ? ($task_data['RusRegBL'] ?? null) : $dom_row->hosttracker_task_id;

            if ($rusregbl_enabled && empty($rus_task_id)) {
                $token = SDM_HostTracker_API::get_token_for_project($site->project_id);
                if ($token) {
                    $task_id = SDM_HostTracker_API::create_host_tracker_task($token, $dom_row->domain, 'RusRegBL');
                    if (!is_wp_error($task_id)) {
                        $wpdb->update(
                            $dom_table,
                            array('hosttracker_task_id' => maybe_serialize(array_merge(is_array($task_data) ? $task_data : array(), array('RusRegBL' => $task_id))), 'updated_at' => current_time('mysql')),
                            array('id' => $domain_id),
                            array('%s','%s'),
                            array('%d')
                        );
                    } else {
                        error_log("sync_rusregbl_for_domain: create task error for domain {$dom_row->domain}: " . $task_id->get_error_message());
                    }
                } else {
                    error_log("sync_rusregbl_for_domain: no token for project_id={$site->project_id}");
                }
            }
            // 2b) Выключен, но task_id есть → удаляем
            elseif (!$rusregbl_enabled && !empty($rus_task_id)) {
                $token = SDM_HostTracker_API::get_token_for_project($site->project_id);
                if ($token) {
                    $ok = SDM_HostTracker_API::delete_host_tracker_task($token, $rus_task_id, 'RusRegBL');
                    if ($ok) {
                        if (is_array($task_data)) {
                            unset($task_data['RusRegBL']);
                            $new_val = empty($task_data) ? null : maybe_serialize($task_data);
                        } else {
                            $new_val = null;
                        }
                        $wpdb->update(
                            $dom_table,
                            array('hosttracker_task_id' => $new_val, 'updated_at' => current_time('mysql')),
                            array('id' => $domain_id),
                            array('%s','%s'),
                            array('%d')
                        );
                    } else {
                        error_log("sync_rusregbl_for_domain: failed to delete task {$dom_row->hosttracker_task_id} for domain={$dom_row->domain}");
                    }
                } else {
                    error_log("sync_rusregbl_for_domain: no token for project_id={$site->project_id}");
                }
            }
            // Иначе — ничего не делаем (либо уже есть задача, либо она не нужна)
        }
    }


    public static function delete_host_tracker_task_by_project($project_id, $task_id, $task_type = 'RusRegBL')
    {
        // 1) Получаем токен для HostTracker, используя уже существующий метод get_token_for_project(...)
        $token = self::get_token_for_project($project_id);
        if (!$token) {
            error_log("delete_host_tracker_task_by_project: no token for project_id=$project_id");
            return false;
        }

        // 2) Вызываем старый метод delete_host_tracker_task, в котором идёт запрос DELETE /tasks?ids=...
        $ok = self::delete_host_tracker_task($token, $task_id, $task_type);
        if (!$ok) {
            error_log("delete_host_tracker_task_by_project: failed to delete $task_type task_id=$task_id for project_id=$project_id");
        }
        return $ok;
    }

    public static function get_token_for_project($project_id)
    {
        global $wpdb;

        // 1) Ищем service_id HostTracker в sdm_service_types
        $service_row = $wpdb->get_row("SELECT id FROM {$wpdb->prefix}sdm_service_types WHERE service_name='HostTracker' LIMIT 1");
        if (!$service_row) {
            error_log("get_token_for_project: HostTracker service not found");
            return false;
        }

        // 2) Ищем аккаунт HostTracker в sdm_accounts
        $account_enc = $wpdb->get_row($wpdb->prepare("
            SELECT additional_data_enc 
              FROM {$wpdb->prefix}sdm_accounts
             WHERE project_id = %d
               AND service_id  = %d
             LIMIT 1
        ", $project_id, $service_row->id));

        if (!$account_enc) {
            error_log("get_token_for_project: no HostTracker account for project_id=$project_id");
            return false;
        }

        // 3) Расшифровываем JSON c логином/паролем
        $decrypted = sdm_decrypt($account_enc->additional_data_enc);
        $credentials = json_decode($decrypted, true);
        if (empty($credentials['login']) || empty($credentials['password'])) {
            error_log("get_token_for_project: invalid login/password in account for project_id=$project_id");
            return false;
        }

        // 4) Получаем токен
        return self::get_host_tracker_token($credentials);
    }

    public static function get_host_tracker_contacts($token) {
        if (!$token) {
            return new WP_Error('no_token', 'No HostTracker token provided.');
        }

        $url = 'https://api1.host-tracker.com/contacts'; // эндпоинт для контактов
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json'
            ),
            'method'  => 'GET',
            'timeout' => 30
        );

        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            return new WP_Error('ht_contacts_error', 'Error fetching HostTracker contacts: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            return new WP_Error('ht_contacts_error', "Failed to fetch contacts (HTTP $code): $body");
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return new WP_Error('ht_contacts_error', 'Unexpected response while fetching contacts: ' . $body);
        }

        // Оставим только подтверждённые контакты (confirmed=true),
        // чтобы не «сломать» задачу подписками на непроверенные адреса
        $confirmed = array_filter($data, function($c){
            return !empty($c['confirmed']);
        });

        return array_values($confirmed); // возвращаем «чистый» индексированный массив
    }

    /**
     * Универсальное удаление задачи.
     *
     * @param string $token  Токен HostTracker.
     * @param string $task_id Идентификатор задачи.
     * @param string $task_type Тип задачи ('RusRegBL' или 'Http').
     * @return bool True, если удаление прошло успешно, иначе false.
     */
    public static function delete_host_tracker_task($token, $task_id, $task_type = 'RusRegBL') {
        if (!$token) {
            error_log("delete_host_tracker_task: no token for task_id=$task_id, type=$task_type");
            return false;
        }
        
        // Формируем URL для удаления задачи
        $url = "https://api1.host-tracker.com/tasks?ids=" . urlencode($task_id);
        
        $args = array(
            'method'  => 'DELETE',
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'timeout' => 30,
        );
        
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            error_log("delete_host_tracker_task error: " . $response->get_error_message());
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        error_log("delete_host_tracker_task($task_id, $task_type) code=$code, body=$body");
        
        // Успешное удаление должно вернуть 200 или 204
        return in_array($code, array(200, 204), true);
    }



}
