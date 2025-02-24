<?php
/* File: includes/database.php */

if ( ! defined( 'ABSPATH' ) ) {
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

    // 2) Sites table: sites attached to a project.
    // Добавлены поля: main_domain, last_domain, language.
    $sites_sql = "CREATE TABLE {$wpdb->prefix}sdm_sites (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        project_id BIGINT UNSIGNED NOT NULL,
        site_name VARCHAR(255) NOT NULL,
        server_ip VARCHAR(45) DEFAULT NULL,
        svg_icon TEXT DEFAULT NULL,
        override_accounts JSON DEFAULT NULL,
        main_domain VARCHAR(255) DEFAULT NULL,
        last_domain VARCHAR(255) DEFAULT NULL,
        language VARCHAR(10) DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY project_id (project_id)
    ) $charset_collate;";


    // 3) Domains table: domains attached to a project and optionally a site.
    $domains_sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sdm_domains (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        project_id BIGINT UNSIGNED NOT NULL,
        site_id BIGINT UNSIGNED DEFAULT NULL,
        domain VARCHAR(255) NOT NULL,
        cf_zone_id VARCHAR(50) DEFAULT NULL,
        abuse_status ENUM('clean','phishing','malware','spam','other') DEFAULT 'clean',
        is_blocked_provider BOOLEAN NOT NULL DEFAULT FALSE,
        is_blocked_government BOOLEAN NOT NULL DEFAULT FALSE,
        status ENUM('active','expired','available') NOT NULL DEFAULT 'active',
        last_checked TIMESTAMP NULL DEFAULT NULL,
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
        project_id BIGINT UNSIGNED NOT NULL,
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

    // 6) Redirects table: redirect rules associated with a domain.
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
        UNIQUE KEY domain_id (domain_id),  -- Гарантирует одну запись на каждый domain_id
        FOREIGN KEY (domain_id) REFERENCES {$wpdb->prefix}sdm_domains(id) ON DELETE CASCADE
    ) $charset_collate;";


    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $projects_sql );
    dbDelta( $sites_sql );
    dbDelta( $domains_sql );
    dbDelta( $service_types_sql );
    dbDelta( $accounts_sql );
    dbDelta( $redirects_sql );
}

function sdm_remove_tables() {
    global $wpdb;
    // Drop tables in order to respect foreign key constraints.
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sdm_redirects");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sdm_accounts");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sdm_service_types");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sdm_domains");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sdm_sites");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sdm_projects");
}
