<?php
/* File: includes/database.php */

if (!defined('ABSPATH')) {
    exit;
}

function sdm_create_tables() {
    global $wpdb;
    $charset_collate = "DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci";

    // 1) Projects table: top-level grouping with global settings.
    $projects_sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sdm_projects (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        project_name VARCHAR(255) NOT NULL,
        description TEXT DEFAULT NULL,
        cf_settings JSON DEFAULT NULL,
        ssl_mode ENUM('full','flexible','strict') DEFAULT 'full',
        monitoring_enabled BOOLEAN NOT NULL DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) $charset_collate;";

    // 2) Sites table: sites attached to a project with monitoring settings.
    $sites_sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sdm_sites (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        project_id BIGINT UNSIGNED NULL DEFAULT NULL,
        site_name VARCHAR(255) NOT NULL,
        server_ip VARCHAR(45) DEFAULT NULL,
        svg_icon TEXT DEFAULT NULL,
        override_accounts JSON DEFAULT NULL,
        main_domain VARCHAR(255) DEFAULT NULL,
        last_domain VARCHAR(255) DEFAULT NULL,
        language VARCHAR(10) DEFAULT NULL,
        monitoring_settings JSON DEFAULT NULL COMMENT 'JSON for monitoring settings (e.g., enabled types: RusRegBL, Http)',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY project_id (project_id)
    ) $charset_collate;";

    // 3) Domains table: domains attached to a project and optionally a site with monitoring data.
    $domains_sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sdm_domains (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        project_id BIGINT UNSIGNED NOT NULL,
        site_id BIGINT UNSIGNED DEFAULT NULL,
        domain VARCHAR(255) NOT NULL,
        cf_zone_id VARCHAR(50) DEFAULT NULL,
        abuse_status ENUM('clean','phishing','malware','spam','other') DEFAULT 'clean',
        is_blocked_provider BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Monitoring result: 1 for RusRegBL (blocked), 0 for Http (unavailable)',
        is_blocked_government BOOLEAN NOT NULL DEFAULT FALSE,
        status ENUM('active','expired','available') NOT NULL DEFAULT 'active',
        last_checked TIMESTAMP NULL DEFAULT NULL,
        hosttracker_task_id VARCHAR(255) DEFAULT NULL COMMENT 'ID of the HostTracker task for monitoring',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (project_id) REFERENCES {$wpdb->prefix}sdm_projects(id) ON DELETE CASCADE,
        FOREIGN KEY (site_id) REFERENCES {$wpdb->prefix}sdm_sites(id) ON DELETE SET NULL
    ) $charset_collate;";

    // 4) Service Types table: available external service types.
    $service_types_sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sdm_service_types (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        service_name VARCHAR(50) NOT NULL,
        auth_method VARCHAR(50) DEFAULT NULL,
        additional_params TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) $charset_collate;";

    // 5) Accounts table: external service accounts linked to a project.
    $accounts_sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sdm_accounts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        project_id BIGINT UNSIGNED NULL DEFAULT NULL,
        site_id BIGINT UNSIGNED DEFAULT NULL,
        service_id BIGINT UNSIGNED NOT NULL,
        account_name VARCHAR(255) DEFAULT NULL,
        email VARCHAR(255) DEFAULT NULL,
        api_key_enc TEXT DEFAULT NULL,
        client_id_enc TEXT DEFAULT NULL,
        client_secret_enc TEXT DEFAULT NULL,
        refresh_token_enc TEXT DEFAULT NULL,
        additional_data_enc LONGTEXT DEFAULT NULL,
        last_tested_at DATETIME DEFAULT NULL,
        last_test_result VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (project_id) REFERENCES {$wpdb->prefix}sdm_projects(id) ON DELETE CASCADE,
        FOREIGN KEY (site_id) REFERENCES {$wpdb->prefix}sdm_sites(id) ON DELETE SET NULL,
        FOREIGN KEY (service_id) REFERENCES {$wpdb->prefix}sdm_service_types(id) ON DELETE RESTRICT
    ) $charset_collate;";

    // 6) Email Forwarding table: stores email forwarding data for domains.
    $email_forwarding_sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sdm_email_forwarding (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        domain_id BIGINT UNSIGNED NOT NULL,
        email_address VARCHAR(255) NOT NULL,
        password VARCHAR(255) NOT NULL,
        catch_all_enabled TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (domain_id) REFERENCES {$wpdb->prefix}sdm_domains(id) ON DELETE CASCADE
    ) $charset_collate;";

    // 7) Redirects table: redirect rules associated with a domain.
    $redirects_sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sdm_redirects (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        domain_id BIGINT UNSIGNED NOT NULL,
        source_url VARCHAR(255) NOT NULL,
        target_url VARCHAR(1024) NOT NULL,
        type ENUM('301','302') NOT NULL DEFAULT '301',
        redirect_type ENUM('main','glue','hidden') NOT NULL DEFAULT 'main',
        preserve_query_string BOOLEAN NOT NULL DEFAULT TRUE,
        user_agent TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY domain_id (domain_id),
        FOREIGN KEY (domain_id) REFERENCES {$wpdb->prefix}sdm_domains(id) ON DELETE CASCADE
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($projects_sql);
    dbDelta($sites_sql);
    dbDelta($domains_sql);
    dbDelta($service_types_sql);
    dbDelta($accounts_sql);
    dbDelta($email_forwarding_sql); // Добавляем создание новой таблицы
    dbDelta($redirects_sql);

    // Добавляем начальные записи в sdm_service_types, если они ещё не существуют
    $service_types_inserts = array(
        $wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}sdm_service_types (id, service_name, auth_method, additional_params, created_at, updated_at) 
            VALUES
                (1, %s, %s, %s, NOW(), NOW()),
                (2, %s, %s, %s, NOW(), NOW()),
                (3, %s, %s, %s, NOW(), NOW()),
                (4, %s, %s, %s, NOW(), NOW()),
                (5, %s, %s, %s, NOW(), NOW()),
                (6, %s, %s, %s, NOW(), NOW()),
                (7, %s, %s, %s, NOW(), NOW()),
                (8, %s, %s, %s, NOW(), NOW()),
                (9, %s, %s, %s, NOW(), NOW())
            ON DUPLICATE KEY UPDATE 
                service_name = VALUES(service_name), 
                auth_method = VALUES(auth_method), 
                additional_params = VALUES(additional_params), 
                updated_at = NOW()",
            'CloudFlare (API Key)', 'Global API Key', json_encode(array(
                "required_fields" => array("email", "api_key"),
                "optional_fields" => array()
            ), JSON_UNESCAPED_SLASHES),
            'CloudFlare (OAuth)', 'OAuth (Client ID, Client Secret, Refresh Token)', json_encode(array(
                "required_fields" => array("email", "client_id", "client_secret", "refresh_token"),
                "optional_fields" => array("api_key")
            ), JSON_UNESCAPED_SLASHES),
            'HostTracker', 'Username/Password', json_encode(array(
                "required_fields" => array("login", "password"),
                "optional_fields" => array("api_key")
            ), JSON_UNESCAPED_SLASHES),
            'NameCheap', 'Username & API', json_encode(array(
                "required_fields" => array("username", "api_key"),
                "optional_fields" => array("api_ip")
            ), JSON_UNESCAPED_SLASHES),
            'NameSilo', 'Username & API', json_encode(array(
                "required_fields" => array("username", "api_key"),
                "optional_fields" => array("api_ip")
            ), JSON_UNESCAPED_SLASHES),
            'Yandex', 'User ID & OAuth Token', json_encode(array(
                "required_fields" => array("user_id", "oauth_token"),
                "optional_fields" => array()
            ), JSON_UNESCAPED_SLASHES),
            'Google', 'API Key, Client ID, Client Secret, Refresh Token', json_encode(array(
                "required_fields" => array("api_key", "client_id", "client_secret", "refresh_token"),
                "optional_fields" => array("project_id")
            ), JSON_UNESCAPED_SLASHES),
            'XMLStock', 'User ID & API Key', json_encode(array(
                "required_fields" => array("user_id", "api_key"),
                "optional_fields" => array("endpoint")
            ), JSON_UNESCAPED_SLASHES),
            'Mail-in-a-Box', 'Email/Password', json_encode(array(
                "required_fields" => array("email", "password", "server_url"),
                "optional_fields" => array()
            ), JSON_UNESCAPED_SLASHES)
        )
    );

    // Выполняем вставку только если записи не существуют
    foreach ($service_types_inserts as $insert) {
        $result = $wpdb->query($insert);
        if ($result === false) {
            error_log('Error inserting service types: ' . $wpdb->last_error);
        }
    }

    // Установить автоинкремент на 10, если записи успешно добавлены
    $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sdm_service_types");
    if ($count === 9) {
        $wpdb->query("ALTER TABLE {$wpdb->prefix}sdm_service_types AUTO_INCREMENT = 10");
    }

    // Проверка и коррекция JSON в additional_params после вставки
    $services = $wpdb->get_results("SELECT id, additional_params FROM {$wpdb->prefix}sdm_service_types");
    foreach ($services as $service) {
        $params = json_decode($service->additional_params, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('Invalid JSON in additional_params for service ID ' . $service->id . ': ' . $service->additional_params);
            $corrected_params = str_replace('\"', '"', $service->additional_params);
            $corrected_params = json_encode(json_decode($corrected_params, true), JSON_UNESCAPED_SLASHES);
            $wpdb->update(
                $wpdb->prefix . 'sdm_service_types',
                array('additional_params' => $corrected_params),
                array('id' => $service->id),
                array('%s'),
                array('%d')
            );
        }
    }

    // Миграция старых аккаунтов CloudFlare
    $cloudflare_accounts = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sdm_accounts WHERE service_id IN (1, 2)");
    if (!empty($cloudflare_accounts)) {
        foreach ($cloudflare_accounts as $account) {
            $data = array();
            $is_api_key_account = !empty($account->api_key_enc) && empty($account->client_id_enc) && empty($account->client_secret_enc) && empty($account->refresh_token_enc);
            $is_oauth_account = !empty($account->client_id_enc) && !empty($account->client_secret_enc) && !empty($account->refresh_token_enc);

            if ($is_api_key_account) {
                $data['email'] = $account->email;
                $data['api_key'] = sdm_decrypt($account->api_key_enc);
                $new_service_id = 1; // "CloudFlare (API Key)"
            } elseif ($is_oauth_account) {
                $data['email'] = $account->email;
                $data['client_id'] = sdm_decrypt($account->client_id_enc);
                $data['client_secret'] = sdm_decrypt($account->client_secret_enc);
                $data['refresh_token'] = sdm_decrypt($account->refresh_token_enc);
                $new_service_id = 2; // "CloudFlare (OAuth)"
            }

            if (!empty($data)) {
                $encrypted_data = sdm_encrypt(json_encode($data));
                $wpdb->update(
                    "{$wpdb->prefix}sdm_accounts",
                    array(
                        'additional_data_enc' => $encrypted_data,
                        'service_id' => $new_service_id
                    ),
                    array('id' => $account->id),
                    array('%s', '%d'),
                    array('%d')
                );
            }
        }
    }

    // Миграция и добавление новых полей в wp_sdm_domains
    $domains_table = $wpdb->prefix . 'sdm_domains';
    $existing_columns = $wpdb->get_col("SHOW COLUMNS FROM $domains_table");
    if (!in_array('hosttracker_task_id', $existing_columns)) {
        $wpdb->query("ALTER TABLE $domains_table ADD COLUMN hosttracker_task_id VARCHAR(255) DEFAULT NULL COMMENT 'ID of the HostTracker task for monitoring'");
    }

    // Миграция и добавление новых полей в wp_sdm_sites
    $sites_table = $wpdb->prefix . 'sdm_sites';
    $existing_columns = $wpdb->get_col("SHOW COLUMNS FROM $sites_table");
    if (!in_array('monitoring_settings', $existing_columns)) {
        $wpdb->query("ALTER TABLE $sites_table ADD COLUMN monitoring_settings JSON DEFAULT NULL COMMENT 'JSON for monitoring settings (e.g., enabled types: RusRegBL, Http)'");
    }

    // Миграция project_id в wp_sdm_accounts: делаем поле допускающим NULL
    $accounts_table = $wpdb->prefix . 'sdm_accounts';
    $column_info = $wpdb->get_row("SHOW COLUMNS FROM $accounts_table LIKE 'project_id'");
    if ($column_info && strpos($column_info->Type, 'bigint') !== false && $column_info->Null === 'NO') {
        $wpdb->query("ALTER TABLE $accounts_table MODIFY project_id BIGINT UNSIGNED NULL DEFAULT NULL");
    }
}

function sdm_remove_tables() {
    global $wpdb;
    // Drop tables in order to respect foreign key constraints.
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sdm_redirects");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sdm_email_forwarding"); // Добавляем удаление новой таблицы
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sdm_accounts");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sdm_service_types");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sdm_domains");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sdm_sites");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sdm_projects");
}