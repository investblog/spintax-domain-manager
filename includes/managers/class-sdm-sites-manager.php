<?php
/**
 * File: includes/managers/class-sdm-sites-manager.php
 * Description: Manager for handling site CRUD operations and domain monitoring.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SDM_Sites_Manager {

        private function delete_domain_hosttracker_task($domain_id, $project_id, $task_id) {
        global $wpdb;

        // Вызываем метод из SDM_HostTracker_API
        $deleted = SDM_HostTracker_API::delete_host_tracker_task_by_project($project_id, $task_id, 'RusRegBL');
        if ($deleted) {
            // Если успешно удалили → обнуляем hosttracker_task_id
            $wpdb->update(
                "{$wpdb->prefix}sdm_domains",
                array('hosttracker_task_id' => null, 'updated_at' => current_time('mysql')),
                array('id' => $domain_id),
                array('%s','%s'),
                array('%d')
            );
            return true;
        }
        return false;
    }


    /**
     * Получение аккаунта по проекту и сервису.
     *
     * @param int $project_id ID проекта.
     * @param string $service_name Название сервиса (например, 'HostTracker').
     * @return object|bool Объект аккаунта или false, если не найден.
     */
    public function get_account_by_project_and_service($project_id, $service_name) {
        global $wpdb;

        $service = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}sdm_service_types WHERE service_name = %s LIMIT 1",
            $service_name
        ));

        if (!$service) {
            error_log('SDM_Accounts_Manager: Service not found: ' . $service_name);
            return false;
        }

        $account = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sdm_accounts 
             WHERE project_id = %d AND service_id = %d LIMIT 1",
            $project_id,
            $service->id
        ));

        if (!$account) {
            error_log('SDM_Accounts_Manager: Account not found for project_id=' . $project_id . ', service=' . $service_name);
        }

        return $account;
    }

    /**
     * Adds a new site and assigns the main domain to it with monitoring settings.
     */
    public function add_site($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'sdm_sites';
        $domains_table = $wpdb->prefix . 'sdm_domains';

        $project_id = isset($data['project_id']) ? absint($data['project_id']) : 0;
        if ($project_id <= 0) {
            return new WP_Error('invalid_project', __('Invalid project ID.', 'spintax-domain-manager'));
        }

        $site_name = isset($data['site_name']) ? sanitize_text_field($data['site_name']) : '';
        if (empty($site_name)) {
            return new WP_Error('invalid_site_name', __('Site name is required.', 'spintax-domain-manager'));
        }

        $server_ip = isset($data['server_ip']) ? sanitize_text_field($data['server_ip']) : '';
        $main_domain = isset($data['main_domain']) ? sanitize_text_field($data['main_domain']) : '';
        if (empty($main_domain)) {
            return new WP_Error('invalid_main_domain', __('Main domain is required.', 'spintax-domain-manager'));
        }

        $language = isset($data['language']) ? sanitize_text_field($data['language']) : '';
        if (empty($language)) {
            return new WP_Error('invalid_language', __('Language is required.', 'spintax-domain-manager'));
        }

        // Настройки мониторинга (по умолчанию наследуем от проекта)
        $monitoring_settings = isset($data['monitoring_settings']) ? json_decode($data['monitoring_settings'], true) : array(
            'enabled' => true,
            'types' => array('RusRegBL' => true, 'Http' => false),
            'regions' => array('Russia')
        );

        // Используем NULL для svg_icon и override_accounts, если их нет
        $svg_icon = null;
        $override_accounts = null;

        // Start transaction for data consistency
        $wpdb->query('START TRANSACTION');

        $result = $wpdb->insert(
            $table,
            array(
                'project_id' => $project_id,
                'site_name' => $site_name,
                'server_ip' => $server_ip,
                'svg_icon' => $svg_icon,
                'override_accounts' => $override_accounts,
                'main_domain' => $main_domain,
                'language' => $language,
                'monitoring_settings' => json_encode($monitoring_settings),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        if (false === $result) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('db_insert_error', __('Could not insert site into database.', 'spintax-domain-manager'));
        }

        $site_id = $wpdb->insert_id;

        // Check if the domain exists before assigning
        $domain_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$domains_table} WHERE domain = %s",
            $main_domain
        ));

        if ($domain_exists <= 0) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('domain_not_found', __('Specified main domain does not exist.', 'spintax-domain-manager'));
        }

        // Assign the main_domain to this site by updating site_id in sdm_domains
        $updated_domain = $wpdb->update(
            $domains_table,
            array('site_id' => $site_id, 'updated_at' => current_time('mysql')),
            array('domain' => $main_domain, 'site_id' => NULL),
            array('%d', '%s'),
            array('%s')
        );

        if (false === $updated_domain) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('db_update_error', __('Could not assign domain to site.', 'spintax-domain-manager'));
        }

        // Создаём задачи мониторинга, если включено
        if ($monitoring_settings['enabled']) {
            foreach ($monitoring_settings['types'] as $type => $enabled) {
                if ($enabled) {
                    $this->create_monitoring_task($site_id, $main_domain, $type);
                }
            }
        }

        $wpdb->query('COMMIT');

        return $site_id;
    }

    /**
     * Update for inline editing and assign the main domain to the site with monitoring settings.
     */
    public function update_site($site_id, $data) {
        global $wpdb;
        $table         = $wpdb->prefix . 'sdm_sites';
        $domains_table = $wpdb->prefix . 'sdm_domains';

        $site_id = absint($site_id);
        if ($site_id <= 0) {
            return new WP_Error('invalid_site_id', __('Invalid site ID.', 'spintax-domain-manager'));
        }

        $old = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $site_id));
        if (!$old) {
            return new WP_Error('not_found', __('Site not found.', 'spintax-domain-manager'));
        }

        $site_name   = isset($data['site_name']) ? sanitize_text_field($data['site_name']) : $old->site_name;
        $main_domain = isset($data['main_domain']) ? sanitize_text_field($data['main_domain']) : $old->main_domain;
        $server_ip   = isset($data['server_ip']) ? sanitize_text_field($data['server_ip']) : $old->server_ip;
        $language    = isset($data['language']) ? sanitize_text_field($data['language']) : $old->language;

        if (empty($main_domain)) {
            return new WP_Error('invalid_main_domain', __('Main domain is required.', 'spintax-domain-manager'));
        }
        if (empty($language)) {
            return new WP_Error('invalid_language', __('Language is required.', 'spintax-domain-manager'));
        }

        // Разбираем входящие настройки мониторинга
        $monitoring_settings = isset($data['monitoring_settings'])
            ? json_decode($data['monitoring_settings'], true)
            : json_decode($old->monitoring_settings, true);

        // Логируем, чтобы убедиться, что к нам реально пришло {"enabled":true,"types":{"RusRegBL":false,"Http":true}} (или что-то ещё).
        error_log('update_site(): RAW incoming $data[monitoring_settings] = ' . print_r($data['monitoring_settings'], true));
        error_log('update_site(): after json_decode => $monitoring_settings = ' . print_r($monitoring_settings, true));

        if (!$monitoring_settings || !is_array($monitoring_settings) 
            || !isset($monitoring_settings['enabled']) 
            || !isset($monitoring_settings['types'])) {
            // Если структура некорректна — подставим дефолт
            $monitoring_settings = array(
                'enabled' => true,
                'types'   => array('RusRegBL' => true, 'Http' => false),
                'regions' => array('Russia')
            );
            error_log('update_site(): monitoring_settings was invalid; used defaults => ' . print_r($monitoring_settings, true));
        } else {
            // Приводим "0"/"1" или false/true к булевым значениям
            $monitoring_settings['types']['RusRegBL'] = !empty($monitoring_settings['types']['RusRegBL']);
            $monitoring_settings['types']['Http']     = !empty($monitoring_settings['types']['Http']);
        }

        // Начинаем транзакцию
        $wpdb->query('START TRANSACTION');

        $updated = $wpdb->update(
            $table,
            array(
                'site_name'           => $site_name,
                'main_domain'         => $main_domain,
                'server_ip'           => $server_ip,
                'language'            => $language,
                'monitoring_settings' => json_encode($monitoring_settings),
                'last_domain'         => ($main_domain !== $old->main_domain) ? $old->main_domain : $old->last_domain,
                'updated_at'          => current_time('mysql'),
            ),
            array('id' => $site_id),
            array('%s','%s','%s','%s','%s','%s','%s'),
            array('%d')
        );

        if (false === $updated) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('db_update_error', __('Could not update site.', 'spintax-domain-manager'));
        }

        // Проверяем, нужно ли прописать новый домен
        if ($main_domain !== $old->main_domain) {
            $domain_exists = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$domains_table} WHERE domain = %s
            ", $main_domain));
            if ($domain_exists <= 0) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('domain_not_found', __('Specified main domain does not exist.', 'spintax-domain-manager'));
            }

            $assign_new = $wpdb->update(
                $domains_table,
                array('site_id' => $site_id, 'updated_at' => current_time('mysql')),
                array('domain' => $main_domain, 'site_id' => NULL),
                array('%d','%s'),
                array('%s','%s')
            );
            if (false === $assign_new) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('db_update_error', __('Could not assign new domain.', 'spintax-domain-manager'));
            }
        }

        $wpdb->query('COMMIT');

        // После коммита перечитываем сайт, чтобы взять актуальную строку
        $site = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sdm_sites WHERE id = %d", $site_id));

        // Проверяем, поменялся ли массив настроек (или домен)
        $old_settings = json_decode($old->monitoring_settings, true);
        $settings_changed = ($main_domain !== $old->main_domain) || ($monitoring_settings !== $old_settings);

        if ($settings_changed) {
            // Удаляем все старые задачи
            $deleteResult = $this->delete_monitoring_tasks($site_id);
            if (is_wp_error($deleteResult)) {
                error_log('update_site WARNING: could not delete old monitoring tasks. ' . $deleteResult->get_error_message());
            }

            if (!empty($monitoring_settings['enabled'])) {
                $httpWanted = !empty($monitoring_settings['types']['Http']);
                $rusregblWanted = !empty($monitoring_settings['types']['RusRegBL']);

                // Логируем окончательные флаги
                error_log(sprintf(
                    'update_site() final: site_id=%d => enabled=%d, Http=%d, RusRegBL=%d',
                    $site_id,
                    $monitoring_settings['enabled'] ? 1 : 0,
                    $httpWanted ? 1 : 0,
                    $rusregblWanted ? 1 : 0
                ));

                // Сначала Http
                if ($httpWanted) {
                    error_log("update_site() => create_monitoring_task(Http)...");
                    $resultHttp = $this->create_monitoring_task($site_id, $main_domain, 'Http');
                    if (is_wp_error($resultHttp)) {
                        error_log("update_site ERROR: cannot create Http => " . $resultHttp->get_error_message());
                    }
                }
                // Затем RusRegBL
                if ($rusregblWanted) {
                    error_log("update_site() => create_monitoring_task(RusRegBL)...");
                    $resultRbl = $this->create_monitoring_task($site_id, $main_domain, 'RusRegBL');
                    if (is_wp_error($resultRbl)) {
                        error_log("update_site ERROR: cannot create RusRegBL => " . $resultRbl->get_error_message());
                    }
                }
            } else {
                error_log("update_site(): monitoring disabled => no tasks created for site_id=$site_id");
            }
        } else {
            error_log("update_site(): no changes in domain or monitoring => skip re-creating tasks for site_id=$site_id");
        }

        return true;
    }


    /**
     * Update site icon
     */
    public function update_site_icon($site_id, $svg_icon) {
        global $wpdb;
        $table = $wpdb->prefix . 'sdm_sites';

        $site_id = absint($site_id);
        if ($site_id <= 0) {
            return new WP_Error('invalid_site_id', __('Invalid site ID.', 'spintax-domain-manager'));
        }

        // Sanitize SVG (allow only SVG tags and attributes)
        $svg_icon = wp_kses($svg_icon, array(
            'svg' => array(
                'width' => true,
                'height' => true,
                'viewBox' => true,
                'fill' => true,
                'stroke' => true,
                'stroke-width' => true,
                'class' => true,
            ),
            'path' => array(
                'd' => true,
                'fill' => true,
                'stroke' => true,
            ),
            'g' => array(),
        ));

        $updated = $wpdb->update(
            $table,
            array(
                'svg_icon' => $svg_icon,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $site_id),
            array('%s', '%s'),
            array('%d')
        );

        if (false === $updated) {
            return new WP_Error('db_update_error', __('Could not update site icon.', 'spintax-domain-manager'));
        }
        return $svg_icon;
    }

    /**
     * Delete a site and update related domains if necessary.
     */
    public function delete_site($site_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'sdm_sites';
        $domains_table = $wpdb->prefix . 'sdm_domains';

        $site_id = absint($site_id);
        if ($site_id <= 0) {
            return new WP_Error('invalid_site_id', __('Invalid site ID.', 'spintax-domain-manager'));
        }

        // Start transaction to ensure data consistency
        $wpdb->query('START TRANSACTION');

        // Удаляем все задачи мониторинга перед удалением сайта
        $this->delete_monitoring_tasks($site_id);

        // Delete the site
        $deleted = $wpdb->delete(
            $table,
            array('id' => $site_id),
            array('%d')
        );

        if (false === $deleted) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('db_delete_error', __('Could not delete site from database.', 'spintax-domain-manager'));
        }

        // Unassign related domains (set site_id to NULL)
        $updated_domains = $wpdb->update(
            $domains_table,
            array('site_id' => NULL, 'updated_at' => current_time('mysql')),
            array('site_id' => $site_id),
            array('%s', '%s'),
            array('%d')
        );

        if (false === $updated_domains) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('db_update_error', __('Could not unassign domains from the site.', 'spintax-domain-manager'));
        }

        $wpdb->query('COMMIT');

        return true;
    }



    /**
     * Удаление всех задач мониторинга для сайта.
     *
     * @param int $site_id ID сайта.
     * @return bool|WP_Error True при успехе или ошибка.
     */
    public function delete_monitoring_tasks($site_id) {
        global $wpdb;

        $domains = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sdm_domains WHERE site_id = %d", $site_id));
        if (empty($domains)) {
            return true;
        }

        // Проверяем, есть ли задачи для удаления
        $has_tasks = false;
        foreach ($domains as $domain) {
            if (!empty($domain->hosttracker_task_id)) {
                $has_tasks = true;
                break;
            }
        }
        if (!$has_tasks) {
            return true;
        }

        $account_manager = new SDM_Accounts_Manager();
        $account = $account_manager->get_account_by_project_and_service($domains[0]->project_id, 'HostTracker');
        if (!$account) {
            error_log('delete_monitoring_tasks: HostTracker account not found for project_id=' . $domains[0]->project_id);
            return true;
        }

        $credentials = json_decode(sdm_decrypt($account->additional_data_enc), true);
        require_once SDM_PLUGIN_DIR . 'includes/api/class-sdm-hosttracker-api.php';
        $token = SDM_HostTracker_API::get_host_tracker_token($credentials);
        if (!$token) {
            error_log('delete_monitoring_tasks: Failed to authenticate with HostTracker');
            return new WP_Error('token_error', __('Failed to authenticate with HostTracker.', 'spintax-domain-manager'));
        }

        foreach ($domains as $domain) {
            if (!empty($domain->hosttracker_task_id)) {
                $result = SDM_HostTracker_API::delete_host_tracker_task($token, $domain->hosttracker_task_id);
                if (is_wp_error($result)) {
                    error_log('delete_monitoring_tasks: Failed to delete task ID ' . $domain->hosttracker_task_id . ': ' . $result->get_error_message());
                    continue;
                }
                $wpdb->update(
                    $wpdb->prefix . 'sdm_domains',
                    array('hosttracker_task_id' => null),
                    array('id' => $domain->id),
                    array('%s'),
                    array('%d')
                );
            }
        }

        return true;
    }
    /**
     * Обновление статуса мониторинга для сайта.
     *
     * @param int $site_id ID сайта.
     * @return bool|WP_Error True при успехе или ошибка.
     */
    public function update_monitoring_status($site_id) {
        global $wpdb;

        $site = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sdm_sites WHERE id = %d", $site_id));
        if (!$site) {
            return new WP_Error('site_not_found', __('Site not found.', 'spintax-domain-manager'));
        }

        $domains = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sdm_domains WHERE site_id = %d", $site_id));
        if (empty($domains)) {
            return true; // Нет доменов для проверки
        }

        $account_manager = new SDM_Accounts_Manager();
        $account = $account_manager->get_account_by_project_and_service($site->project_id, 'HostTracker');
        if (!$account) {
            return new WP_Error('account_not_found', __('HostTracker account not found for this project.', 'spintax-domain-manager'));
        }

        $credentials = json_decode(sdm_decrypt($account->additional_data_enc), true);
        require_once SDM_PLUGIN_DIR . 'includes/api/class-sdm-hosttracker-api.php';
        $token = SDM_HostTracker_API::get_host_tracker_token($credentials);
        if (!$token) {
            return new WP_Error('token_error', __('Failed to authenticate with HostTracker.', 'spintax-domain-manager'));
        }

        $monitoring_settings = json_decode($site->monitoring_settings, true);
        foreach ($domains as $domain) {
            if (!empty($domain->hosttracker_task_id)) {
                $tasks = SDM_HostTracker_API::get_host_tracker_tasks($token, 'RusRegBL');
                $task_found = false;
                foreach ($tasks as $task) {
                    if ($task['id'] === $domain->hosttracker_task_id) {
                        $is_blocked = !$task['lastState']; // если lastState == true => домен Up => не заблокирован
                        $wpdb->update(
                            $wpdb->prefix . 'sdm_domains',
                            array(
                                'is_blocked_provider' => $is_blocked ? 1 : 0,
                                'last_checked' => current_time('mysql'),
                                'updated_at' => current_time('mysql'),
                            ),
                            array('id' => $domain->id),
                            array('%d', '%s', '%s'),
                            array('%d')
                        );
                        $task_found = true;
                        break;
                    }
                }

                if (!$task_found) {
                    $tasks = SDM_HostTracker_API::get_host_tracker_tasks($token, 'Http');
                    foreach ($tasks as $task) {
                        if ($task['id'] === $domain->hosttracker_task_id) {
                            $is_blocked = !$task['lastState']; // Для Http: false означает блокировку
                            $wpdb->update(
                                $wpdb->prefix . 'sdm_domains',
                                array(
                                    'is_blocked_provider' => $is_blocked ? 0 : 1,
                                    'last_checked' => current_time('mysql'),
                                    'updated_at' => current_time('mysql'),
                                ),
                                array('id' => $domain->id),
                                array('%d', '%s', '%s'),
                                array('%d')
                            );
                            break;
                        }
                    }
                }
            }
        }

        return true;
    }

    public function create_monitoring_task($site_id, $domain_url, $task_type = 'RusRegBL') {
    global $wpdb;

    // 1) Проверяем, что сайт есть
    $site = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}sdm_sites WHERE id = %d",
        $site_id
    ));
    if (!$site) {
        error_log("create_monitoring_task ERROR: site_id=$site_id not found");
        return new WP_Error('site_not_found', __('Site not found.', 'spintax-domain-manager'));
    }

    // 2) Проверяем, что домен есть и привязан к этому сайту
    $domain = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}sdm_domains 
         WHERE domain = %s 
           AND site_id = %d 
         LIMIT 1",
        $domain_url,
        $site_id
    ));
    if (!$domain) {
        error_log("create_monitoring_task($task_type) ERROR: domain=$domain_url not found or not assigned to site_id=$site_id");
        return new WP_Error('domain_not_found', __('Domain not found for this site.', 'spintax-domain-manager'));
    }

    // 3) Проверяем, что проект существует
    $project = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}sdm_projects 
         WHERE id = %d",
        $site->project_id
    ));
    if (!$project) {
        error_log("create_monitoring_task($task_type) ERROR: project_id=$site->project_id not found");
        return new WP_Error('project_not_found', __('Project not found.', 'spintax-domain-manager'));
    }

    // 4) Проверяем настройки мониторинга
    $monitoring_settings = json_decode($site->monitoring_settings, true);
    $monitoring_enabled = $project->monitoring_enabled;
    if (!empty($monitoring_settings)) {
        $monitoring_enabled = isset($monitoring_settings['enabled']) ? (bool)$monitoring_settings['enabled'] : $monitoring_enabled;
        if (!$monitoring_enabled || empty($monitoring_settings['types'][$task_type])) {
            error_log("create_monitoring_task($task_type) => disabled or [$task_type] empty => site_id=$site_id domain=$domain_url");
            return new WP_Error('monitoring_disabled', __('Monitoring is disabled for this site or task type.', 'spintax-domain-manager'));
        }
    } elseif (!$monitoring_enabled) {
        error_log("create_monitoring_task($task_type) => project monitoring disabled => site_id=$site_id domain=$domain_url");
        return new WP_Error('monitoring_disabled', __('Monitoring is disabled for this project.', 'spintax-domain-manager'));
    }

    // 5) Язык как тег
    $language_code = '';
    if (!empty($site->language)) {
        $lang_parts = explode('_', $site->language);
        $language_code = strtolower($lang_parts[0]); 
    }

    // 6) Получаем учётку HostTracker
    $account_manager = new SDM_Accounts_Manager();
    $account = $account_manager->get_account_by_project_and_service($site->project_id, 'HostTracker');
    if (!$account) {
        error_log("create_monitoring_task($task_type) ERROR: no HostTracker account for project_id=$site->project_id");
        return new WP_Error('account_not_found', __('HostTracker account not found for this project.', 'spintax-domain-manager'));
    }

    $credentials = json_decode(sdm_decrypt($account->additional_data_enc), true);
    require_once SDM_PLUGIN_DIR . 'includes/api/class-sdm-hosttracker-api.php';
    $token = SDM_HostTracker_API::get_host_tracker_token($credentials);
    if (!$token) {
        error_log("create_monitoring_task($task_type) ERROR: Could not get token => site_id=$site_id domain=$domain_url");
        return new WP_Error('token_error', __('Failed to authenticate with HostTracker.', 'spintax-domain-manager'));
    }

    // 7) Вызываем создание задачи
    error_log("create_monitoring_task($task_type) => calling create_host_tracker_task for domain=$domain_url site_id=$site_id");
    $task_id = SDM_HostTracker_API::create_host_tracker_task($token, $domain_url, $task_type, $language_code);
    if (is_wp_error($task_id)) {
        error_log("create_monitoring_task($task_type) => WP_Error: " . $task_id->get_error_message() . " site_id=$site_id");
        return $task_id;
    }

    // 8) Записываем в базу
    $wpdb->update(
        "{$wpdb->prefix}sdm_domains",
        array('hosttracker_task_id' => $task_id),
        array('id' => $domain->id),
        array('%s'),
        array('%d')
    );
    error_log("create_monitoring_task($task_type) => success => assigned task_id=$task_id to domain_id=$domain->id (domain=$domain_url)");

    return true;
}

}

/**
 * AJAX Handler: Update Site
 */
function sdm_ajax_update_site() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied.', 'spintax-domain-manager')));
        return;
    }

    $nonce = isset($_POST['sdm_main_nonce_field']) ? $_POST['sdm_main_nonce_field'] : '';
    error_log('sdm_ajax_update_site: nonce=' . $nonce);

    if (!wp_verify_nonce($nonce, SDM_NONCE_ACTION)) {
        wp_send_json_error(array('message' => __('Invalid nonce.', 'spintax-domain-manager')));
        return;
    }

    $site_id = isset($_POST['site_id']) ? absint($_POST['site_id']) : 0;
    error_log('sdm_ajax_update_site: site_id=' . $site_id . ', data=' . print_r($_POST, true));

    // -- ВАЖНО: Убираем экранирование из поля monitoring_settings (если оно есть).
    if (isset($_POST['monitoring_settings'])) {
        $_POST['monitoring_settings'] = wp_unslash($_POST['monitoring_settings']);
    }

    $manager = new SDM_Sites_Manager();
    $result = $manager->update_site($site_id, $_POST);

    if (is_wp_error($result)) {
        error_log('sdm_ajax_update_site: error=' . $result->get_error_message());
        wp_send_json_error(array('message' => $result->get_error_message()));
    } else {
        wp_send_json_success(array('message' => __('Site updated successfully.', 'spintax-domain-manager')));
    }
}
add_action('wp_ajax_sdm_update_site', 'sdm_ajax_update_site');


/**
 * AJAX Handler: Add Site
 * Action: wp_ajax_sdm_add_site
 */
function sdm_ajax_add_site() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'spintax-domain-manager'));
    }
    sdm_check_main_nonce();

    // ВАЖНО: убираем экранирование в поле monitoring_settings
    if (isset($_POST['monitoring_settings'])) {
        $_POST['monitoring_settings'] = wp_unslash($_POST['monitoring_settings']);
    }

    $manager = new SDM_Sites_Manager();
    $result = $manager->add_site($_POST);

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }
    wp_send_json_success(array('site_id' => $result));
}
add_action('wp_ajax_sdm_add_site', 'sdm_ajax_add_site');


/**
 * AJAX Handler: Update Site Icon
 */
function sdm_ajax_update_site_icon() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'spintax-domain-manager'));
    }
    sdm_check_main_nonce();

    $site_id = isset($_POST['site_id']) ? absint($_POST['site_id']) : 0;
    $svg_icon = isset($_POST['svg_icon']) ? wp_unslash($_POST['svg_icon']) : '';

    $manager = new SDM_Sites_Manager();
    $result = $manager->update_site_icon($site_id, $svg_icon);

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }
    wp_send_json_success(array(
        'message' => __('Icon updated successfully.', 'spintax-domain-manager'),
        'svg_icon' => $result
    ));
}
add_action('wp_ajax_sdm_update_site_icon', 'sdm_ajax_update_site_icon');


/**
 * AJAX Handler: Delete Site
 */
function sdm_ajax_delete_site() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'spintax-domain-manager'));
    }
    sdm_check_main_nonce();

    $site_id = isset($_POST['site_id']) ? absint($_POST['site_id']) : 0;
    if ($site_id <= 0) {
        wp_send_json_error(__('Invalid site ID.', 'spintax-domain-manager'));
    }

    $manager = new SDM_Sites_Manager();
    $result = $manager->delete_site($site_id);

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }
    wp_send_json_success(array('message' => __('Site deleted successfully.', 'spintax-domain-manager')));
}
add_action('wp_ajax_sdm_delete_site', 'sdm_ajax_delete_site');

/**
 * AJAX Handler: Get Non-Blocked Domains for a Project
 */
function sdm_ajax_get_non_blocked_domains() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'spintax-domain-manager'));
    }
    sdm_check_main_nonce();

    $project_id = isset($_POST['project_id']) ? absint($_POST['project_id']) : 0;
    $term = isset($_POST['term']) ? sanitize_text_field($_POST['term']) : '';

    if ($project_id <= 0) {
        wp_send_json_error(__('Invalid project ID.', 'spintax-domain-manager'));
    }

    global $wpdb;
    $prefix = $wpdb->prefix;

    $domains = $wpdb->get_col($wpdb->prepare(
        "SELECT domain FROM {$prefix}sdm_domains 
         WHERE project_id = %d 
           AND site_id IS NULL 
           AND status = 'active' 
           AND is_blocked_provider = 0 
           AND is_blocked_government = 0" .
           ($term ? " AND domain LIKE %s" : ""),
        $project_id,
        $term ? '%' . $wpdb->esc_like($term) . '%' : ''
    ));

    if (empty($domains)) {
        wp_send_json_success(array());
    }

    wp_send_json_success($domains);
}
add_action('wp_ajax_sdm_get_non_blocked_domains', 'sdm_ajax_get_non_blocked_domains');

/**
 * AJAX Handler: Get Non-Blocked Domains for a Site
 */
function sdm_ajax_get_non_blocked_domains_for_site() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'spintax-domain-manager'));
    }
    sdm_check_main_nonce();

    $project_id = isset($_POST['project_id']) ? absint($_POST['project_id']) : 0;
    $site_id = isset($_POST['site_id']) ? absint($_POST['site_id']) : 0;
    $term = isset($_POST['term']) ? sanitize_text_field($_POST['term']) : '';

    if ($project_id <= 0) {
        wp_send_json_error(__('Invalid project ID.', 'spintax-domain-manager'));
    }

    global $wpdb;
    $prefix = $wpdb->prefix;

    // Get domains for the project that are active and not blocked
    $domains_query = "SELECT domain FROM {$prefix}sdm_domains 
                     WHERE project_id = %d 
                       AND status = 'active' 
                       AND is_blocked_provider = 0 
                       AND is_blocked_government = 0";
    $params = array($project_id);

    // If site_id is provided, include domains assigned to this site or unassigned
    if ($site_id > 0) {
        $domains_query .= " AND (site_id IS NULL OR site_id = %d)";
        $params[] = $site_id;
    }

    // Add search term if provided
    if ($term) {
        $domains_query .= " AND domain LIKE %s";
        $params[] = '%' . $wpdb->esc_like($term) . '%';
    }

    $domains = $wpdb->get_col($wpdb->prepare($domains_query, $params));

    // Filter out duplicates and sort
    $domains = array_unique($domains);
    sort($domains);

    if (empty($domains)) {
        wp_send_json_success(array());
    }

    wp_send_json_success($domains);
}
add_action('wp_ajax_sdm_get_non_blocked_domains_for_site', 'sdm_ajax_get_non_blocked_domains_for_site');