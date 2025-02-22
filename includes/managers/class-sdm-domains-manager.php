<?php
/**
 * File: includes/managers/class-sdm-domains-manager.php
 * Description: Manager for handling domains operations, including fetching zones from CloudFlare and syncing to local DB.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SDM_Domains_Manager {

    /**
     * Fetches zones (domains) from CloudFlare for a given project
     * and syncs them into the sdm_domains table.
     *
     * @param int $project_id
     * @return array Associative array with keys:
     *               - 'zones' => array of zones from CF
     *               - 'inserted' => number of newly inserted records
     *               - 'updated' => number of updated records
     */
    public function fetch_and_sync_project_domains( $project_id ) {
        global $wpdb;

        $project_id = absint( $project_id );
        if ( $project_id <= 0 ) {
            return array(
                'error' => __( 'Invalid project ID.', 'spintax-domain-manager' )
            );
        }

        // 1) Получаем ID сервиса CloudFlare из таблицы sdm_service_types
        $cf_service_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}sdm_service_types WHERE service_name = %s",
            'cloudflare'
        ) );
        if ( empty( $cf_service_id ) ) {
            return array(
                'error' => __( 'Cloudflare service is not configured.', 'spintax-domain-manager' )
            );
        }

        // 2) Получаем CloudFlare аккаунт для данного проекта
        $account = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sdm_accounts 
             WHERE project_id = %d 
               AND service_id = %d 
             LIMIT 1",
            $project_id,
            $cf_service_id
        ) );
        if ( ! $account ) {
            return array(
                'error' => __( 'No CloudFlare account found for this project.', 'spintax-domain-manager' )
            );
        }

        // 3) Проверяем, что в аккаунте заполнены необходимые поля
        if ( empty( $account->email ) || empty( $account->api_key_enc ) ) {
            return array(
                'error' => __( 'Incomplete CloudFlare credentials in account.', 'spintax-domain-manager' )
            );
        }

        // 4) Дешифруем API-ключ
        $api_key = sdm_decrypt( $account->api_key_enc );

        $credentials = array(
            'email'   => $account->email,
            'api_key' => $api_key,
        );

        // 5) Подключаем класс CloudFlare API
        require_once SDM_PLUGIN_DIR . 'includes/api/class-sdm-cloudflare-api.php';
        $cf_api = new SDM_Cloudflare_API( $credentials );

        // 6) Получаем список зон
        $zones = $cf_api->get_zones();
        if ( is_wp_error( $zones ) ) {
            return array(
                'error' => $zones->get_error_message()
            );
        }

        // 7) Синхронизуем с локальной таблицей sdm_domains
        $inserted = 0;
        $updated  = 0;

        foreach ( $zones as $zone ) {
            // Пример структуры $zone: 
            // [
            //   'id' => 'cf_zone_id',
            //   'name' => 'example.com',
            //   'status' => 'active', // или 'pending', 'paused' и т.д.
            //   ...
            // ]

            // Проверяем, есть ли уже такая запись в sdm_domains для этого проекта
            $existing_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}sdm_domains
                 WHERE project_id = %d
                   AND domain = %s
                 LIMIT 1",
                $project_id,
                $zone['name']
            ) );

            // Формируем данные для вставки/обновления
            $data = array(
                'project_id'  => $project_id,
                'domain'      => $zone['name'],
                'cf_zone_id'  => $zone['id'],
                'status'      => isset($zone['status']) ? $zone['status'] : 'active',
                'updated_at'  => current_time('mysql'),
            );

            // Вставляем/обновляем
            if ( $existing_id ) {
                // update
                $wpdb->update(
                    $wpdb->prefix . 'sdm_domains',
                    $data,
                    array( 'id' => $existing_id ),
                    array( '%d','%s','%s','%s','%s' ),
                    array( '%d' )
                );
                $updated++;
            } else {
                // insert
                $data['created_at'] = current_time('mysql');
                $wpdb->insert(
                    $wpdb->prefix . 'sdm_domains',
                    $data,
                    array( '%d','%s','%s','%s','%s','%s' )
                );
                $inserted++;
            }
        }

        return array(
            'zones'    => $zones,
            'inserted' => $inserted,
            'updated'  => $updated,
        );
    }

    /**
     * Assigns multiple domains to a site.
     *
     * @param array $domain_ids Array of domain IDs to assign.
     * @param int $site_id The ID of the site to assign domains to.
     * @return array Associative array with keys:
     *               - 'success' => number of successfully assigned domains
     *               - 'failed' => number of failed assignments (due to conflicts or other errors)
     *               - 'message' => status message
     */
    public function assign_domains_to_site( $domain_ids, $site_id ) {
        global $wpdb;

        $site_id = absint( $site_id );
        if ( $site_id <= 0 ) {
            return array(
                'success' => 0,
                'failed' => count( $domain_ids ),
                'message' => __( 'Invalid site ID.', 'spintax-domain-manager' )
            );
        }

        $success = 0;
        $failed = 0;
        $messages = array();

        foreach ( $domain_ids as $domain_id ) {
            $domain_id = absint( $domain_id );
            if ( $domain_id <= 0 ) {
                $failed++;
                continue;
            }

            // Check if the domain is already assigned to another site
            $current_site_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT site_id FROM {$wpdb->prefix}sdm_domains WHERE id = %d",
                $domain_id
            ));

            if ( $current_site_id && $current_site_id != $site_id ) {
                $failed++;
                $messages[] = sprintf( __( 'Domain ID %d is already assigned to another site.', 'spintax-domain-manager' ), $domain_id );
                continue;
            }

            // Allow blocked domains to be assigned to sites (but not as main domains)
            // No check for is_blocked_provider or is_blocked_government here

            // Update the domain to assign it to the site
            $updated = $wpdb->update(
                $wpdb->prefix . 'sdm_domains',
                array( 'site_id' => $site_id, 'updated_at' => current_time('mysql') ),
                array( 'id' => $domain_id ),
                array( '%d', '%s' ),
                array( '%d' )
            );

            if ( false !== $updated ) {
                $success++;
            } else {
                $failed++;
                $messages[] = sprintf( __( 'Failed to assign Domain ID %d.', 'spintax-domain-manager' ), $domain_id );
            }
        }

        $message = sprintf(
            __( '%d domains assigned successfully, %d failed.', 'spintax-domain-manager' ),
            $success,
            $failed
        );
        if ( ! empty( $messages ) ) {
            $message .= ' ' . implode( ' ', $messages );
        }

        return array(
            'success' => $success,
            'failed' => $failed,
            'message' => $message
        );
    }

    /**
     * Unassigns a single domain from its site.
     *
     * @param int $domain_id The ID of the domain to unassign.
     * @return array Associative array with keys:
     *               - 'success' => boolean (true if unassigned, false otherwise)
     *               - 'message' => status message
     */
    public function unassign_domain( $domain_id ) {
        global $wpdb;

        $domain_id = absint( $domain_id );
        if ( $domain_id <= 0 ) {
            return array(
                'success' => false,
                'message' => __( 'Invalid domain ID.', 'spintax-domain-manager' )
            );
        }

        // Check if the domain is assigned to any site
        $current_site_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT site_id FROM {$wpdb->prefix}sdm_domains WHERE id = %d",
            $domain_id
        ));

        if ( empty( $current_site_id ) ) {
            return array(
                'success' => false,
                'message' => __( 'Domain is not assigned to any site.', 'spintax-domain-manager' )
            );
        }

        // Check if the domain is the main domain for its site
        $is_main_domain = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sdm_sites 
             WHERE site_id = %d 
               AND main_domain = (SELECT domain FROM {$wpdb->prefix}sdm_domains WHERE id = %d)",
            $current_site_id,
            $domain_id
        ));

        if ( $is_main_domain > 0 ) {
            return array(
                'success' => false,
                'message' => __( 'Cannot unassign a main domain. Use site settings to change the main domain.', 'spintax-domain-manager' )
            );
        }

        // Unassign the domain (set site_id to NULL)
        $updated = $wpdb->update(
            $wpdb->prefix . 'sdm_domains',
            array( 'site_id' => NULL, 'updated_at' => current_time('mysql') ),
            array( 'id' => $domain_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        if ( false !== $updated ) {
            return array(
                'success' => true,
                'message' => __( 'Domain unassigned successfully.', 'spintax-domain-manager' )
            );
        } else {
            return array(
                'success' => false,
                'message' => __( 'Failed to unassign domain.', 'spintax-domain-manager' )
            );
        }
    }

    /**
     * Deletes a domain.
     *
     * @param int $domain_id The ID of the domain to delete.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function delete_domain( $domain_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sdm_domains';

        $domain_id = absint( $domain_id );
        if ( $domain_id <= 0 ) {
            return new WP_Error( 'invalid_domain_id', __( 'Invalid domain ID.', 'spintax-domain-manager' ) );
        }

        // Start transaction to ensure data consistency
        $wpdb->query( 'START TRANSACTION' );

        // Delete the domain
        $deleted = $wpdb->delete(
            $table,
            array( 'id' => $domain_id ),
            array( '%d' )
        );

        if ( false === $deleted ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'db_delete_error', __( 'Could not delete domain from database.', 'spintax-domain-manager' ) );
        }

        $wpdb->query( 'COMMIT' );

        return true;
    }

    /**
     * Returns the total number of domains in the sdm_domains table.
     *
     * @return int Total count of domains.
     */
    public function count_domains() {
        global $wpdb;
        $table = $wpdb->prefix . 'sdm_domains';
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        return intval($count);
    }
}/*<-- Last! */

/**
 * AJAX Handler: Fetch and sync domains (zones) for a project from CloudFlare,
 * then store them in the sdm_domains table.
 */
function sdm_ajax_fetch_domains() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();

    $project_id = isset( $_POST['project_id'] ) ? absint( $_POST['project_id'] ) : 0;
    if ( $project_id <= 0 ) {
        wp_send_json_error( __( 'Invalid project ID.', 'spintax-domain-manager' ) );
    }

    $manager = new SDM_Domains_Manager();
    $result  = $manager->fetch_and_sync_project_domains( $project_id );

    // Если вернулся массив с ключом 'error', отправляем ошибку
    if ( isset( $result['error'] ) ) {
        wp_send_json_error( $result['error'] );
    }

    // Иначе успех: zones, inserted, updated
    $count_zones = count( $result['zones'] );
    $message = sprintf(
        __( 'Fetched %d domains from CloudFlare. Inserted: %d, Updated: %d.', 'spintax-domain-manager' ),
        $count_zones,
        $result['inserted'],
        $result['updated']
    );

    wp_send_json_success( array(
        'message' => $message,
        'count'   => $count_zones,
        'inserted'=> $result['inserted'],
        'updated' => $result['updated'],
    ) );
}
add_action( 'wp_ajax_sdm_fetch_domains', 'sdm_ajax_fetch_domains' );

/**
 * AJAX Handler: Assign Domains to Site
 */
function sdm_ajax_assign_domains_to_site() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();

    $domain_ids = isset( $_POST['domain_ids'] ) ? json_decode( stripslashes( $_POST['domain_ids'] ), true ) : array();
    $site_id = isset( $_POST['site_id'] ) ? absint( $_POST['site_id'] ) : 0;

    if ( empty( $domain_ids ) || $site_id <= 0 ) {
        wp_send_json_error( __( 'Invalid data provided.', 'spintax-domain-manager' ) );
    }

    $manager = new SDM_Domains_Manager();
    $result = $manager->assign_domains_to_site( $domain_ids, $site_id );

    if ( $result['success'] > 0 || $result['failed'] > 0 ) {
        wp_send_json_success( array( 'message' => $result['message'] ) );
    } else {
        wp_send_json_error( $result['message'] );
    }
}
add_action( 'wp_ajax_sdm_assign_domains_to_site', 'sdm_ajax_assign_domains_to_site' );

/**
 * AJAX Handler: Delete Domain
 */
function sdm_ajax_delete_domain() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();

    $domain_id = isset( $_POST['domain_id'] ) ? absint( $_POST['domain_id'] ) : 0;
    if ( $domain_id <= 0 ) {
        wp_send_json_error( __( 'Invalid domain ID.', 'spintax-domain-manager' ) );
    }

    $manager = new SDM_Domains_Manager();
    $result = $manager->delete_domain( $domain_id );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }
    wp_send_json_success( array( 'message' => __( 'Domain deleted successfully.', 'spintax-domain-manager' ) ) );
}
add_action( 'wp_ajax_sdm_delete_domain', 'sdm_ajax_delete_domain' );

/**
 * AJAX Handler: Unassign a Single Domain
 */
function sdm_ajax_unassign_domain() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();

    $domain_id = isset( $_POST['domain_id'] ) ? absint( $_POST['domain_id'] ) : 0;
    if ( $domain_id <= 0 ) {
        wp_send_json_error( __( 'Invalid domain ID.', 'spintax-domain-manager' ) );
    }

    $manager = new SDM_Domains_Manager();
    $result = $manager->unassign_domain( $domain_id );

    if ( $result['success'] ) {
        wp_send_json_success( array( 'message' => $result['message'] ) );
    } else {
        wp_send_json_error( $result['message'] );
    }
}
add_action( 'wp_ajax_sdm_unassign_domain', 'sdm_ajax_unassign_domain' );

