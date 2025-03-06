<?php
/**
 * File: includes/cron.php
 * Description: Handles WP Cron tasks for site monitoring.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!wp_next_scheduled('sdm_check_site_monitoring')) {
    wp_schedule_event(time(), 'hourly', 'sdm_check_site_monitoring');
}
add_action('sdm_check_site_monitoring', 'sdm_check_site_monitoring_callback');

function sdm_check_site_monitoring_callback() {
    global $wpdb;
    $sites = $wpdb->get_results("SELECT id FROM {$wpdb->prefix}sdm_sites WHERE main_domain IS NOT NULL");
    $sites_manager = new SDM_Sites_Manager();

    foreach ($sites as $site) {
        $sites_manager->update_monitoring_status($site->id);
    }
}