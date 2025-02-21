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
}

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
