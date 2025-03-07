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
    if (!wp_next_scheduled('sdm_cron_check_sites')) {
        wp_schedule_event(time(), 'one_hour', 'sdm_cron_check_sites');
    }
}
register_activation_hook(SDM_PLUGIN_DIR . 'spintax-domain-manager.php', 'sdm_activate_cron');

/**
 * Отмена крон-события при деактивации плагина.
 */
function sdm_deactivate_cron() {
    $timestamp = wp_next_scheduled('sdm_cron_check_sites');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'sdm_cron_check_sites');
    }
}
register_deactivation_hook(SDM_PLUGIN_DIR . 'spintax-domain-manager.php', 'sdm_deactivate_cron');

/**
 * Функция обработки крон-задачи.
 * Обрабатывает все сайты, у которых в monitoring_settings включён мониторинг.
 */
function sdm_cron_check_sites() {
    global $wpdb;
    error_log('sdm_cron_check_sites: Started');

    // Выбираем все сайты, где monitoring_settings не пустой.
    $sites = $wpdb->get_results("SELECT id, monitoring_settings FROM {$wpdb->prefix}sdm_sites");
    if (empty($sites)) {
        error_log('sdm_cron_check_sites: No sites found.');
        return;
    }

    $manager = new SDM_Sites_Manager();

    foreach ($sites as $site) {
        // Попытка распарсить настройки мониторинга.
        $settings = json_decode($site->monitoring_settings, true);
        if (!$settings || !isset($settings['enabled']) || !$settings['enabled']) {
            // Если мониторинг выключен или настройки некорректны — пропускаем
            error_log("sdm_cron_check_sites: Skipping site ID {$site->id} - monitoring disabled or invalid.");
            continue;
        }
        // Если ни один тип не включён — тоже пропускаем
        if (empty($settings['types']) || (empty($settings['types']['RusRegBL']) && empty($settings['types']['Http']))) {
            error_log("sdm_cron_check_sites: Skipping site ID {$site->id} - no monitoring types enabled.");
            continue;
        }

        // Обновляем статус мониторинга для данного сайта
        $result = $manager->update_monitoring_status($site->id);
        if (is_wp_error($result)) {
            error_log("sdm_cron_check_sites: Error updating site ID {$site->id} - " . $result->get_error_message());
        } else {
            error_log("sdm_cron_check_sites: Successfully updated site ID {$site->id}");
        }
    }

    error_log('sdm_cron_check_sites: Finished');
}
add_action('sdm_cron_check_sites', 'sdm_cron_check_sites');
