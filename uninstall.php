<?php
/**
 * File: uninstall.php
 * Path: uninstall.php
 *
 * Handles cleanup (database removal) for the Spintax Domain Manager plugin.
 *
 * This file drops the following tables:
 * - sdm_redirects
 * - sdm_accounts
 * - sdm_service_types
 * - sdm_domains
 * - sdm_sites
 * - sdm_projects
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sdm_redirects");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sdm_accounts");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sdm_service_types");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sdm_domains");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sdm_sites");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sdm_projects");
