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
        $cf_service_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}sdm_service_types 
             WHERE service_name IN (%s, %s) 
             LIMIT 1",
            'CloudFlare (API Key)', 'CloudFlare (OAuth)'
        ));
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
             WHERE id = %d
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

        public function mass_update_domains($domain_ids, $action, $options = []) {
        global $wpdb;
        
        $success = 0;
        $failed = 0;

        foreach ($domain_ids as $domain_id) {
            $domain_id = absint($domain_id);
            $data = ['updated_at' => current_time('mysql')];

            switch ($action) {
                case 'set_abuse_status':
                    $abuse_status = $options['abuse_status'] ?? 'clean';
                    if (in_array($abuse_status, ['clean', 'phishing', 'malware', 'spam', 'other'])) {
                        $data['abuse_status'] = $abuse_status;
                    }
                    break;
                case 'set_blocked_provider':
                    $data['is_blocked_provider'] = 1;
                    $data['is_blocked_government'] = 0;
                    break;
                case 'set_blocked_government':
                    $data['is_blocked_provider'] = 0;
                    $data['is_blocked_government'] = 1;
                    break;
                case 'clear_blocked':
                    $data['is_blocked_provider'] = 0;
                    $data['is_blocked_government'] = 0;
                    break;
            }

            $updated = $wpdb->update(
                $wpdb->prefix . 'sdm_domains',
                $data,
                ['id' => $domain_id],
                array_fill(0, count($data), '%s'),
                ['%d']
            );

            if (false !== $updated) {
                $success++;
            } else {
                $failed++;
            }
        }

        return [
            'success' => $success,
            'failed' => $failed,
            'message' => sprintf(__('%d domains updated, %d failed.', 'spintax-domain-manager'), $success, $failed)
        ];
    }

        public function mass_add_domains( $project_id, $domain_list ) {
        global $wpdb;
        $project_id = absint( $project_id );
        if ( $project_id <= 0 ) {
            return array( 'error' => __( 'Invalid project ID.', 'spintax-domain-manager' ) );
        }

        // Получаем ID сервиса CloudFlare
        $cf_service_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}sdm_service_types 
             WHERE service_name IN (%s, %s) 
             LIMIT 1",
            'CloudFlare (API Key)', 'CloudFlare (OAuth)'
        ));
        if ( empty( $cf_service_id ) ) {
            return array( 'error' => __( 'Cloudflare service is not configured.', 'spintax-domain-manager' ) );
        }

        // Получаем CloudFlare аккаунт для проекта
        $account = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sdm_accounts 
             WHERE project_id = %d 
               AND service_id = %d 
             LIMIT 1",
            $project_id,
            $cf_service_id
        ));
        if ( ! $account ) {
            return array( 'error' => __( 'No CloudFlare account found for this project.', 'spintax-domain-manager' ) );
        }
        if ( empty( $account->email ) || empty( $account->api_key_enc ) ) {
            return array( 'error' => __( 'Incomplete CloudFlare credentials in account.', 'spintax-domain-manager' ) );
        }
        $api_key = sdm_decrypt( $account->api_key_enc );
        $credentials = array(
            'email'   => $account->email,
            'api_key' => $api_key,
        );

        // Подключаем CloudFlare API
        require_once SDM_PLUGIN_DIR . 'includes/api/class-sdm-cloudflare-api.php';
        $cf_api = new SDM_Cloudflare_API( $credentials );

        $inserted = 0;
        $errors   = array();

        foreach ( $domain_list as $domain ) {
            $domain = trim( $domain );
            if ( empty( $domain ) ) {
                continue;
            }

            // Добавляем зону через CloudFlare API
            $result = $cf_api->add_zone( $domain );
            if ( is_wp_error( $result ) ) {
                $errors[] = sprintf( __( 'Error adding %s: %s', 'spintax-domain-manager' ), $domain, $result->get_error_message() );
                continue;
            }

            // Получаем cf_zone_id из ответа (предполагается, что ответ содержит ключ result)
            $zone_id = isset( $result['result']['id'] ) ? $result['result']['id'] : '';
            if ( empty( $zone_id ) ) {
                $errors[] = sprintf( __( 'Error adding %s: no zone ID returned.', 'spintax-domain-manager' ), $domain );
                continue;
            }

            // Проверяем, существует ли уже запись для этого домена
            $existing_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}sdm_domains
                 WHERE project_id = %d AND domain = %s
                 LIMIT 1",
                $project_id, $domain
            ) );

            $data = array(
                'project_id' => $project_id,
                'domain'     => $domain,
                'cf_zone_id' => $zone_id,
                'status'     => 'active',
                'updated_at' => current_time( 'mysql' ),
            );

            if ( $existing_id ) {
                $wpdb->update(
                    $wpdb->prefix . 'sdm_domains',
                    $data,
                    array( 'id' => $existing_id )
                );
            } else {
                $data['created_at'] = current_time( 'mysql' );
                $wpdb->insert(
                    $wpdb->prefix . 'sdm_domains',
                    $data
                );
            }
            $inserted++;
        }

        return array(
            'inserted' => $inserted,
            'errors'   => $errors,
        );
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

/**
 * AJAX Handler: Fetch Domains List with Sorting and Search.
 */
function sdm_ajax_fetch_domains_list() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'spintax-domain-manager'));
    }
    sdm_check_main_nonce();
    $project_id = isset($_POST['project_id']) ? absint($_POST['project_id']) : 0;
    $sort_column = isset($_POST['sort_column']) ? sanitize_text_field($_POST['sort_column']) : 'created_at';
    $sort_direction = isset($_POST['sort_direction']) ? sanitize_text_field($_POST['sort_direction']) : 'desc';
    $search_term = isset($_POST['search_term']) ? sanitize_text_field($_POST['search_term']) : '';
    $is_blocked_sort = isset($_POST['is_blocked_sort']) && $_POST['is_blocked_sort'] === '1';

    if ($project_id <= 0) {
        wp_send_json_error(__('Invalid project ID.', 'spintax-domain-manager'));
    }

    global $wpdb;
    $prefix = $wpdb->prefix;
    ob_start();

    // Build WHERE clause for search
    $where = "WHERE d.project_id = %d";
    $params = [$project_id];
    if (!empty($search_term)) {
        $where .= " AND d.domain LIKE %s";
        $params[] = '%' . $search_term . '%';
    }

    // Get main domains for the project
    $main_domains = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT main_domain FROM {$prefix}sdm_sites WHERE project_id = %d",
            $project_id
        )
    );

    // Fetch domains with sorting
    $order_by = "ORDER BY ";
    if ($is_blocked_sort && $sort_column === 'blocked') {
        $order_by .= "CASE WHEN (d.is_blocked_provider OR d.is_blocked_government) THEN 1 ELSE 0 END " . esc_sql($sort_direction);
    } else {
        $order_by .= esc_sql($sort_column) . " " . esc_sql($sort_direction);
    }
    $domains = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT d.*, s.site_name, s.main_domain
             FROM {$prefix}sdm_domains d
             LEFT JOIN {$prefix}sdm_sites s ON d.site_id = s.id
             $where
             $order_by",
            ...$params
        )
    );

    ?>
    <table class="wp-list-table widefat fixed striped sdm-table" id="sdm-domains-table">
        <thead>
            <tr>
                <th class="sdm-sortable" data-column="domain"><?php esc_html_e('Domain', 'spintax-domain-manager'); ?></th>
                <th class="sdm-sortable" data-column="site_name"><?php esc_html_e('Site', 'spintax-domain-manager'); ?></th>
                <th class="sdm-sortable" data-column="abuse_status"><?php esc_html_e('Abuse Status', 'spintax-domain-manager'); ?></th>
                <th class="sdm-sortable" data-column="blocked"><?php esc_html_e('Blocked', 'spintax-domain-manager'); ?></th>
                <th class="sdm-sortable" data-column="status"><?php esc_html_e('Status', 'spintax-domain-manager'); ?></th>
                <th class="sdm-sortable" data-column="last_checked"><?php esc_html_e('Last Checked', 'spintax-domain-manager'); ?></th>
                <th class="sdm-sortable" data-column="created_at"><?php esc_html_e('Created At', 'spintax-domain-manager'); ?></th>
                <th><?php esc_html_e('Actions', 'spintax-domain-manager'); ?>
                    <input type="checkbox" id="sdm-select-all-domains" style="margin-left: 5px; vertical-align: middle;">
                </th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($domains)) : ?>
                <?php foreach ($domains as $domain) :
                    $is_active = ($domain->status === 'active');
                    $is_blocked = ($domain->is_blocked_provider || $domain->is_blocked_government);
                    $is_assigned = !empty($domain->site_id);
                    $is_main_domain = in_array($domain->domain, $main_domains);
                    ?>
                    <tr id="domain-row-<?php echo esc_attr($domain->id); ?>"
                        data-domain-id="<?php echo esc_attr($domain->id); ?>"
                        data-update-nonce="<?php echo esc_attr(sdm_create_main_nonce()); ?>"
                        data-site-id="<?php echo esc_attr($domain->site_id); ?>"
                        data-domain="<?php echo esc_attr($domain->domain); ?>"
                        data-site-name="<?php echo esc_attr($domain->site_name ?: ''); ?>"
                        data-abuse-status="<?php echo esc_attr($domain->abuse_status); ?>"
                        data-blocked="<?php echo esc_attr($is_blocked ? 'Yes' : 'No'); ?>"
                        data-status="<?php echo esc_attr($domain->status); ?>"
                        data-last-checked="<?php echo esc_attr($domain->last_checked); ?>"
                        data-created-at="<?php echo esc_attr($domain->created_at); ?>">
                        <td class="sdm-domain <?php echo $is_blocked ? 'sdm-blocked-domain' : ''; ?>">
                            <?php echo esc_html($domain->domain); ?>
                        </td>
                        <td>
                            <?php if ($is_assigned) : ?>
                                <a href="?page=sdm-sites&project_id=<?php echo esc_attr($project_id); ?>"
                                   class="sdm-site-link">
                                    <?php echo esc_html($domain->site_name); ?>
                                </a>
                                <?php if ($is_main_domain) : ?>
                                    <span class="sdm-main-domain-icon" style="display: inline-flex; align-items: center; margin-left: 5px;">
                                        <?php
                                        $domain_svg = file_get_contents(SDM_PLUGIN_DIR . 'assets/icons/domain.svg');
                                        if ($domain_svg) {
                                            echo wp_kses($domain_svg, array('svg' => array('width' => true, 'height' => true, 'viewBox' => true), 'path' => array('d' => true, 'fill' => true)));
                                        } else {
                                            echo '<img src="' . esc_url(SDM_PLUGIN_URL . 'assets/icons/domain.svg') . '" alt="' . esc_attr__('Main Domain', 'spintax-domain-manager') . '" width="16" height="16" />';
                                        }
                                        ?>
                                    </span>
                                <?php endif; ?>
                            <?php else : ?>
                                (Unassigned)
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($domain->abuse_status); ?></td>
                        <td><?php echo $is_blocked ? esc_html__('Yes', 'spintax-domain-manager') : esc_html__('No', 'spintax-domain-manager'); ?></td>
                        <td><?php echo esc_html($domain->status); ?></td>
                        <td><?php echo esc_html($domain->last_checked); ?></td>
                        <td><?php echo esc_html($domain->created_at); ?></td>
                        <td>
                            <?php if ($is_active) : ?>
                                <?php if ($is_assigned && !$is_main_domain) : ?>
                                    <input type="checkbox" class="sdm-domain-checkbox" value="<?php echo esc_attr($domain->id); ?>">
                                    <button type="button" class="sdm-action-button sdm-unassign sdm-mini-icon" data-domain-id="<?php echo esc_attr($domain->id); ?>" title="<?php esc_attr_e('Unassign', 'spintax-domain-manager'); ?>">
                                        <img src="<?php echo esc_url(SDM_PLUGIN_URL . 'assets/icons/clear.svg'); ?>" alt="<?php esc_attr_e('Unassign', 'spintax-domain-manager'); ?>" />
                                    </button>
                                <?php elseif ($is_assigned && $is_main_domain) : ?>
                                    <!-- No checkbox or actions for main domains -->
                                <?php else : ?>
                                    <input type="checkbox" class="sdm-domain-checkbox" value="<?php echo esc_attr($domain->id); ?>">
                                <?php endif; ?>
                            <?php else : ?>
                                <button type="button" class="sdm-action-button sdm-delete-domain sdm-delete sdm-mini-icon" data-domain-id="<?php echo esc_attr($domain->id); ?>" title="<?php esc_attr_e('Delete', 'spintax-domain-manager'); ?>">
                                    <img src="<?php echo esc_url(SDM_PLUGIN_URL . 'assets/icons/clear.svg'); ?>" alt="<?php esc_attr_e('Delete', 'spintax-domain-manager'); ?>" />
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr id="no-domains">
                    <td colspan="8"><?php esc_html_e('No domains found for this project.', 'spintax-domain-manager'); ?></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Mass Actions Panel -->
    <div class="sdm-mass-actions" style="margin: 20px 0;">
        <select id="sdm-mass-action-select" class="sdm-select">
            <option value=""><?php esc_html_e('Select Mass Action', 'spintax-domain-manager'); ?></option>
            <option value="sync_ns"><?php esc_html_e('Sync NS-Servers', 'spintax-domain-manager'); ?></option>
            <option value="assign_site"><?php esc_html_e('Assign to Site', 'spintax-domain-manager'); ?></option>
            <option value="sync_status"><?php esc_html_e('Sync Statuses', 'spintax-domain-manager'); ?></option>
            <option value="mass_add"><?php esc_html_e('Add Domains', 'spintax-domain-manager'); ?></option>
            <option value="set_abuse_status"><?php esc_html_e('Set Abuse Status', 'spintax-domain-manager'); ?></option>
            <option value="set_blocked_provider"><?php esc_html_e('Block by Provider', 'spintax-domain-manager'); ?></option>
            <option value="set_blocked_government"><?php esc_html_e('Block by Government', 'spintax-domain-manager'); ?></option>
            <option value="clear_blocked"><?php esc_html_e('Clear Blocked Status', 'spintax-domain-manager'); ?></option>
        </select>
        <button id="sdm-mass-action-apply" class="button button-primary sdm-action-button"><?php esc_html_e('Apply', 'spintax-domain-manager'); ?></button>
    </div>
    <?php

    $html = ob_get_clean();
    wp_send_json_success(['html' => $html]);
}
add_action('wp_ajax_sdm_fetch_domains_list', 'sdm_ajax_fetch_domains_list');

function sdm_ajax_validate_domain() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'spintax-domain-manager'));
    }
    sdm_check_main_nonce();

    $domain = isset($_POST['domain']) ? sanitize_text_field($_POST['domain']) : '';
    $site_id = isset($_POST['site_id']) ? absint($_POST['site_id']) : 0;
    if (empty($domain)) {
        wp_send_json_error(__('Domain is required.', 'spintax-domain-manager'));
    }

    global $wpdb;
    $prefix = $wpdb->prefix;

    // Проверяем, существует ли домен в sdm_domains и соответствует требованиям
    $domain_exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}sdm_domains 
         WHERE domain = %s 
           AND status = 'active' 
           AND is_blocked_provider = 0 
           AND is_blocked_government = 0",
        $domain
    ));

    if ($domain_exists <= 0) {
        wp_send_json_error(__('Domain is not active, blocked, or does not exist.', 'spintax-domain-manager'));
    }

    // Проверяем, не используется ли домен другим сайтом в sdm_sites, исключая текущий сайт
    $domain_in_use = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}sdm_sites 
         WHERE main_domain = %s" . ($site_id > 0 ? " AND id != %d" : ""),
        $domain,
        $site_id
    ));

    if ($domain_in_use > 0) {
        wp_send_json_error(__('This domain is already assigned to another site.', 'spintax-domain-manager'));
    }

    wp_send_json_success(__('Domain is valid and available.', 'spintax-domain-manager'));
}
add_action('wp_ajax_sdm_validate_domain', 'sdm_ajax_validate_domain');

function sdm_ajax_mass_action() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'spintax-domain-manager'));
    }
    sdm_check_main_nonce();

    $action = isset($_POST['mass_action']) ? sanitize_text_field($_POST['mass_action']) : '';
    $domain_ids = isset($_POST['domain_ids']) ? json_decode(stripslashes($_POST['domain_ids']), true) : [];
    $options = [];

    if (empty($domain_ids) || empty($action)) {
        wp_send_json_error(__('Invalid data provided.', 'spintax-domain-manager'));
    }

    if ($action === 'assign_site') {
        $site_id = isset($_POST['site_id']) ? absint($_POST['site_id']) : 0;
        $manager = new SDM_Domains_Manager();
        $result = $manager->assign_domains_to_site($domain_ids, $site_id);
    } else {
        if ($action === 'set_abuse_status') {
            $options['abuse_status'] = isset($_POST['abuse_status']) ? sanitize_text_field($_POST['abuse_status']) : 'clean';
        }
        $manager = new SDM_Domains_Manager();
        $result = $manager->mass_update_domains($domain_ids, $action, $options);
    }

    if ($result['success'] > 0) {
        wp_send_json_success(['message' => $result['message']]);
    } else {
        wp_send_json_error($result['message']);
    }
}
add_action('wp_ajax_sdm_mass_action', 'sdm_ajax_mass_action');

function sdm_ajax_mass_add_domains() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();

    $project_id = isset( $_POST['project_id'] ) ? absint( $_POST['project_id'] ) : 0;
    if ( $project_id <= 0 ) {
        wp_send_json_error( __( 'Invalid project ID.', 'spintax-domain-manager' ) );
    }

    $domain_list = isset( $_POST['domain_list'] ) ? json_decode( stripslashes( $_POST['domain_list'] ), true ) : array();
    if ( empty( $domain_list ) ) {
        wp_send_json_error( __( 'No domains provided.', 'spintax-domain-manager' ) );
    }

    $manager = new SDM_Domains_Manager();
    $result = $manager->mass_add_domains( $project_id, $domain_list );

    if ( isset( $result['error'] ) ) {
        wp_send_json_error( $result['error'] );
    }

    $message = sprintf( __( 'Added %d domains to CloudFlare.', 'spintax-domain-manager' ), $result['inserted'] );
    if ( ! empty( $result['errors'] ) ) {
        $message .= ' ' . implode( ' | ', $result['errors'] );
    }
    wp_send_json_success( array( 'message' => $message ) );
}
add_action( 'wp_ajax_sdm_mass_add_domains', 'sdm_ajax_mass_add_domains' );
