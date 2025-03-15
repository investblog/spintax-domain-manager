<?php
/**
 * File: includes/cron.php
 * Description: Реализация крон-задач для Spintax Domain Manager.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Добавляем новый интервал для крон-задачи: один раз в час.
 */
function sdm_cron_add_interval( $schedules ) {
    $schedules['one_hour'] = array(
        'interval' => 3600, // 3600 секунд = 1 час
        'display'  => __('Once Every Hour', 'spintax-domain-manager')
    );
    return $schedules;
}
add_filter('cron_schedules', 'sdm_cron_add_interval');

/**
 * Регистрация крон-события при активации плагина.
 */
function sdm_activate_cron() {
    if ( ! wp_next_scheduled( 'sdm_cron_check_sites' ) ) {
        wp_schedule_event( time(), 'one_hour', 'sdm_cron_check_sites' );
    }
    if ( ! wp_next_scheduled( 'sdm_cron_check_cloudflare_abuse' ) ) {
        wp_schedule_event( time(), 'one_hour', 'sdm_cron_check_cloudflare_abuse' );
    }
}
register_activation_hook( SDM_PLUGIN_DIR . 'spintax-domain-manager.php', 'sdm_activate_cron' );

/**
 * Отмена крон-события при деактивации плагина.
 */
function sdm_deactivate_cron() {
    $timestamp = wp_next_scheduled( 'sdm_cron_check_sites' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'sdm_cron_check_sites' );
    }
    $timestamp = wp_next_scheduled( 'sdm_cron_check_cloudflare_abuse' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'sdm_cron_check_cloudflare_abuse' );
    }
}
register_deactivation_hook( SDM_PLUGIN_DIR . 'spintax-domain-manager.php', 'sdm_deactivate_cron' );

/**
 * Функция обработки крон-задачи для обновления статуса сайтов (HostTracker).
 */
function sdm_cron_check_sites() {
    global $wpdb;
    error_log('sdm_cron_check_sites: Started');

    // Выбираем все сайты, где monitoring_settings не пустой.
    $sites = $wpdb->get_results("SELECT id, monitoring_settings FROM {$wpdb->prefix}sdm_sites");
    if ( empty( $sites ) ) {
        error_log('sdm_cron_check_sites: No sites found.');
        return;
    }

    $manager = new SDM_Sites_Manager();

    foreach ( $sites as $site ) {
        // Попытка распарсить настройки мониторинга.
        $settings = json_decode( $site->monitoring_settings, true );
        if ( ! $settings || ! isset( $settings['enabled'] ) || ! $settings['enabled'] ) {
            error_log("sdm_cron_check_sites: Skipping site ID {$site->id} - monitoring disabled or invalid.");
            continue;
        }
        // Если ни один тип не включён — пропускаем
        if ( empty( $settings['types'] ) || ( empty( $settings['types']['RusRegBL'] ) && empty( $settings['types']['Http'] ) ) ) {
            error_log("sdm_cron_check_sites: Skipping site ID {$site->id} - no monitoring types enabled.");
            continue;
        }

        $result = $manager->update_monitoring_status( $site->id );
        if ( is_wp_error( $result ) ) {
            error_log("sdm_cron_check_sites: Error updating site ID {$site->id} - " . $result->get_error_message());
        } else {
            error_log("sdm_cron_check_sites: Successfully updated site ID {$site->id}");
        }
    }

    error_log('sdm_cron_check_sites: Finished');
}
add_action('sdm_cron_check_sites', 'sdm_cron_check_sites');

/**
 * Функция обработки крон-задачи для проверки доменов через CloudFlare и обновления abuse_status.
 */
function sdm_cron_check_cloudflare_abuse() {
    global $wpdb;
    error_log('sdm_cron_check_cloudflare_abuse: Started');

    // Выбираем домены, привязанные к сайтам с активным статусом
    $domains = $wpdb->get_results("
        SELECT id, domain, cf_zone_id, abuse_status, site_id
        FROM {$wpdb->prefix}sdm_domains
        WHERE site_id IS NOT NULL
          AND status = 'active'
    ");
    if ( empty( $domains ) ) {
        error_log('sdm_cron_check_cloudflare_abuse: No domains to process.');
        return;
    }

    // Для каждого домена получаем данные из CloudFlare и обновляем abuse_status
    foreach ( $domains as $domain_obj ) {
        // Получаем project_id через связь со сайтом
        $project_id = $wpdb->get_var( $wpdb->prepare("
            SELECT project_id
            FROM {$wpdb->prefix}sdm_sites
            WHERE id = %d
        ", $domain_obj->site_id) );
        if ( ! $project_id ) {
            error_log("sdm_cron_check_cloudflare_abuse: No project_id for domain {$domain_obj->domain}");
            continue;
        }

        // Получаем креденшелы CloudFlare для проекта
        require_once SDM_PLUGIN_DIR . 'includes/api/class-sdm-cloudflare-api.php';
        $cf_credentials = SDM_Cloudflare_API::get_project_cf_credentials( $project_id );
        if ( is_wp_error( $cf_credentials ) ) {
            error_log("sdm_cron_check_cloudflare_abuse: Error getting CF credentials for project_id={$project_id}: " . $cf_credentials->get_error_message());
            continue;
        }
        $cf_api = new SDM_Cloudflare_API( $cf_credentials );

        // Получаем зону: если cf_zone_id задан, используем его, иначе ищем по доменному имени
        if ( ! empty( $domain_obj->cf_zone_id ) ) {
            $zone_response = $cf_api->api_request_extended("zones/{$domain_obj->cf_zone_id}", [], 'GET');
            if ( is_wp_error( $zone_response ) || empty( $zone_response['result'] ) ) {
                error_log("sdm_cron_check_cloudflare_abuse: Zone not found or invalid response for domain {$domain_obj->domain}");
                continue;
            }
            $zone = $zone_response['result'];
        } else {
            if ( method_exists($cf_api, 'get_zone_by_domain') ) {
                $zone = $cf_api->get_zone_by_domain( $domain_obj->domain );
                if ( is_wp_error( $zone ) || ! $zone ) {
                    error_log("sdm_cron_check_cloudflare_abuse: Zone not found for domain {$domain_obj->domain}");
                    continue;
                }
            } else {
                error_log("sdm_cron_check_cloudflare_abuse: get_zone_by_domain method not available for domain {$domain_obj->domain}");
                continue;
            }
        }

        // Извлекаем meta.phishing_detected
        $phishing_detected = ( ! empty($zone['meta']['phishing_detected']) ) ? 'phishing' : 'clean';

        // Обновляем abuse_status в базе, если изменился
        if ( $domain_obj->abuse_status !== $phishing_detected ) {
            $wpdb->update(
                "{$wpdb->prefix}sdm_domains",
                array(
                    'abuse_status' => $phishing_detected,
                    'last_checked' => current_time('mysql'),
                    'updated_at'   => current_time('mysql'),
                ),
                array('id' => $domain_obj->id),
                array('%s', '%s', '%s'),
                array('%d')
            );
            error_log("sdm_cron_check_cloudflare_abuse: Updated domain {$domain_obj->domain} abuse_status to {$phishing_detected}");
        }
    }

    error_log('sdm_cron_check_cloudflare_abuse: Finished');
}
add_action('sdm_cron_check_cloudflare_abuse', 'sdm_cron_check_cloudflare_abuse');
