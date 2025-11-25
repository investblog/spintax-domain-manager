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
     * Retrieve CloudFlare account for a project. Tries API Key then OAuth.
     *
     * @param int $project_id
     * @return object|false
     */
    private function get_cloudflare_account($project_id) {
        $account_manager = new SDM_Accounts_Manager();
        $account = $account_manager->get_account_by_project_and_service($project_id, 'CloudFlare (API Key)');
        if (!$account) {
            $account = $account_manager->get_account_by_project_and_service($project_id, 'CloudFlare (OAuth)');
        }
        return $account;
    }

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
        $account = $this->get_cloudflare_account( $project_id );
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

        $cf_domain_names = array();
        $seen_domains    = array();
        foreach ( $zones as $zone ) {
            // Пример структуры $zone:
            // [
            //   'id' => 'cf_zone_id',
            //   'name' => 'example.com',
            //   'status' => 'active', // или 'pending', 'paused' и т.д.
            //   ...
            // ]

            // Нормализуем домен и исключаем повторную обработку одинаковых имён в одном запросе
            $domain_name = isset( $zone['name'] ) ? strtolower( trim( $zone['name'] ) ) : '';
            if ( empty( $domain_name ) ) {
                continue;
            }

            if ( isset( $seen_domains[ $domain_name ] ) ) {
                continue;
            }
            $seen_domains[ $domain_name ] = true;

            // Проверяем, есть ли уже такая запись в sdm_domains
            $existing_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, project_id FROM {$wpdb->prefix}sdm_domains
                 WHERE domain = %s
                 LIMIT 1",
                $domain_name
            ) );
            $existing_id = $existing_row ? intval( $existing_row->id ) : 0;

            // Формируем данные для вставки/обновления
            $data = array(
                'project_id'  => $project_id,
                'domain'      => $domain_name,
                'cf_zone_id'  => $zone['id'],
                'status'      => isset($zone['status']) ? $zone['status'] : 'active',
                'updated_at'  => current_time('mysql'),
            );

            // Вставляем/обновляем
            if ( $existing_id ) {
                // Если домен принадлежит другому проекту, обновляем его проект
                if ( intval( $existing_row->project_id ) !== $project_id ) {
                    $data['project_id'] = $project_id;
                }
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
                $insert_result = $wpdb->insert(
                    $wpdb->prefix . 'sdm_domains',
                    $data,
                    array( '%d','%s','%s','%s','%s','%s' )
                );
                if ( false !== $insert_result ) {
                    $inserted++;
                } elseif ( strpos( strtolower( $wpdb->last_error ), 'duplicate' ) !== false ) {
                    // Защитимся от гонок при наличии уникального индекса
                    $existing_row = $wpdb->get_row( $wpdb->prepare(
                        "SELECT id, project_id FROM {$wpdb->prefix}sdm_domains WHERE domain = %s LIMIT 1",
                        $domain_name
                    ) );
                    if ( $existing_row ) {
                        if ( intval( $existing_row->project_id ) !== $project_id ) {
                            $data['project_id'] = $project_id;
                        }
                        $wpdb->update(
                            $wpdb->prefix . 'sdm_domains',
                            $data,
                            array( 'id' => intval( $existing_row->id ) ),
                            array( '%d','%s','%s','%s','%s' ),
                            array( '%d' )
                        );
                        $updated++;
                    }
                } else {
                    error_log( 'Failed to insert domain: ' . $wpdb->last_error );
                }
            }
            // Keep track of domains we found on Cloudflare
            if ( isset( $domain_name ) ) {
                $cf_domain_names[] = $domain_name;
            }
        }

        // Mark domains missing from Cloudflare as expired
        $placeholders = array_fill( 0, count( $cf_domain_names ), '%s' );
        $not_in_clause = '';
        $params       = array( current_time( 'mysql' ), $project_id );
        if ( ! empty( $cf_domain_names ) ) {
            $not_in_clause = 'AND domain NOT IN (' . implode( ',', $placeholders ) . ')';
            $params        = array_merge( $params, $cf_domain_names );
        }

        $query = "UPDATE {$wpdb->prefix}sdm_domains
                 SET status = 'expired', updated_at = %s
                 WHERE project_id = %d {$not_in_clause}";
        array_unshift( $params, $query );
        $prepared = call_user_func_array( array( $wpdb, 'prepare' ), $params );
        $wpdb->query( $prepared );

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

        $site_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, project_id FROM {$wpdb->prefix}sdm_sites WHERE id = %d",
            $site_id
        ) );

        if ( ! $site_row ) {
            return array(
                'success' => 0,
                'failed'  => count( $domain_ids ),
                'message' => __( 'Site not found.', 'spintax-domain-manager' ),
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

            // Check if the domain exists and whether it is currently assigned
            $domain_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT site_id, domain, project_id FROM {$wpdb->prefix}sdm_domains WHERE id = %d",
                $domain_id
            ) );

            if ( ! $domain_row ) {
                $failed++;
                $messages[] = sprintf( __( 'Domain ID %d was not found.', 'spintax-domain-manager' ), $domain_id );
                continue;
            }

            if ( (int) $domain_row->project_id !== (int) $site_row->project_id ) {
                $failed++;
                $messages[] = sprintf(
                    __( 'Domain %1$s belongs to another project and cannot be assigned to site ID %2$d.', 'spintax-domain-manager' ),
                    $domain_row->domain,
                    $site_id
                );
                continue;
            }

            $needs_release = false;

            if ( $domain_row->site_id && (int) $domain_row->site_id !== $site_id ) {
                $needs_release = true;
            }

            $conflict_params = array( $domain_row->domain );
            $conflict_query  = "SELECT id FROM {$wpdb->prefix}sdm_sites WHERE main_domain = %s";
            if ( $site_id > 0 ) {
                $conflict_query  .= ' AND id != %d';
                $conflict_params[] = $site_id;
            }

            $conflicting_site_id = $wpdb->get_var( $wpdb->prepare( $conflict_query, ...$conflict_params ) );
            if ( $conflicting_site_id ) {
                $needs_release = true;
            }

            if ( $needs_release ) {
                $release_result = sdm_release_domain_conflicts( $domain_row->domain, $site_id );
                if ( is_wp_error( $release_result ) ) {
                    $failed++;
                    $messages[] = sprintf(
                        __( 'Could not reassign domain %1$s: %2$s', 'spintax-domain-manager' ),
                        $domain_row->domain,
                        $release_result->get_error_message()
                    );
                    continue;
                }
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

        // Получаем CloudFlare аккаунт для проекта
        $account = $this->get_cloudflare_account( $project_id );
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

        // Получаем кэш зон для поиска подходящей зоны под сабдомен
        $zones_cache = $cf_api->get_zones();
        if ( is_wp_error( $zones_cache ) ) {
            return array( 'error' => $zones_cache->get_error_message() );
        }

        $inserted = 0;
        $errors   = array();

        foreach ( $domain_list as $domain ) {
            $domain = trim( $domain );
            if ( empty( $domain ) ) {
                continue;
            }

            // Normalize input so that users can paste full URLs or hostnames.
            $parsed = wp_parse_url( $domain );
            if ( empty( $parsed['host'] ) ) {
                $fallback = wp_parse_url( 'https://' . ltrim( $domain, '/' ) );
                $domain   = ! empty( $fallback['host'] ) ? $fallback['host'] : $domain;
            } else {
                $domain = $parsed['host'];
            }

            $domain = strtolower( trim( $domain, " \t\n\r\0\x0B." ) );
            if ( empty( $domain ) ) {
                continue;
            }

            $zone_id      = '';
            $zone_name    = '';
            $is_subdomain = 0;

            // Пытаемся определить существующую зону для домена/сабдомена
            $matched_zone = $cf_api->find_zone_for_hostname( $domain, $zones_cache );
            if ( ! is_wp_error( $matched_zone ) && ! empty( $matched_zone['id'] ) ) {
                $zone_id   = $matched_zone['id'];
                $zone_name = strtolower( $matched_zone['name'] );

                if ( $zone_name === $domain ) {
                    $is_subdomain = 0;
                } elseif ( ! empty( $zone_name ) && ( ( function_exists( 'str_ends_with' ) && str_ends_with( $domain, '.' . $zone_name ) ) || substr( $domain, - ( strlen( $zone_name ) + 1 ) ) === '.' . $zone_name ) ) {
                    $is_subdomain = 1;
                    $record_resp  = $cf_api->create_dns_record( $zone_id, 'A', $domain, '192.0.2.1', 1, true );
                    if ( is_wp_error( $record_resp ) ) {
                        $record_error = $record_resp->get_error_message();
                        $is_duplicate = ( false !== stripos( $record_error, 'exist' ) ) || ( false !== strpos( $record_error, '81057' ) );

                        if ( ! $is_duplicate ) {
                            $errors[] = sprintf( __( 'Error adding %s: %s', 'spintax-domain-manager' ), $domain, $record_error );
                            continue;
                        }
                    }
                } else {
                    $errors[] = sprintf(
                        __( 'Zone mismatch for %1$s: matched %2$s which is not a suffix.', 'spintax-domain-manager' ),
                        esc_html( $domain ),
                        esc_html( $zone_name )
                    );
                    $is_subdomain = 0;
                }
            }

            // Если зона не найдена — создаём новую, включая случаи с сабдоменами
            if ( empty( $zone_id ) ) {
                $result = $cf_api->add_zone( $domain );
                if ( is_wp_error( $result ) ) {
                    $errors[] = sprintf( __( 'Error adding %s: %s', 'spintax-domain-manager' ), $domain, $result->get_error_message() );
                    continue;
                }

                $zone_id   = isset( $result['result']['id'] ) ? $result['result']['id'] : '';
                $zone_name = isset( $result['result']['name'] ) ? strtolower( $result['result']['name'] ) : '';
                if ( empty( $zone_id ) ) {
                    $errors[] = sprintf( __( 'Error adding %s: no zone ID returned.', 'spintax-domain-manager' ), $domain );
                    continue;
                }

                $is_subdomain = ( ! empty( $zone_name ) && $domain !== $zone_name ) ? 1 : 0;

                // Сохраняем новую зону в кэше, чтобы её могли использовать следующие домены
                $zones_cache[] = array( 'id' => $zone_id, 'name' => $zone_name ? $zone_name : $domain );
            }

            // Проверяем, существует ли уже запись для этого домена
            $existing_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, project_id FROM {$wpdb->prefix}sdm_domains
                 WHERE domain = %s
                 LIMIT 1",
                $domain
            ) );
            $existing_id = $existing_row ? intval( $existing_row->id ) : 0;

            $data = array(
                'project_id' => $project_id,
                'domain'     => $domain,
                'cf_zone_id' => $zone_id,
                'status'     => 'active',
                'updated_at' => current_time( 'mysql' ),
            );

            if ( $existing_id ) {
                if ( intval( $existing_row->project_id ) !== $project_id ) {
                    $data['project_id'] = $project_id;
                }
                $wpdb->update(
                    $wpdb->prefix . 'sdm_domains',
                    $data,
                    array( 'id' => $existing_id )
                );
                $inserted++;
            } else {
                $data['created_at'] = current_time( 'mysql' );
                $insert_result = $wpdb->insert(
                    $wpdb->prefix . 'sdm_domains',
                    $data
                );
                if ( false !== $insert_result ) {
                    $inserted++;
                } else {
                    error_log( 'Failed to insert domain: ' . $wpdb->last_error );
                }
            }
        }

        return array(
            'inserted' => $inserted,
            'errors'   => $errors,
        );
    }

}/*<-- Last! */

/**
 * Build (and cache per-request) a lookup of Cloudflare zone IDs to zone names for a project.
 *
 * @param int $project_id
 * @return array
 */
function sdm_get_cf_zone_lookup( $project_id ) {
    static $cache = array();

    if ( isset( $cache[ $project_id ] ) ) {
        return $cache[ $project_id ];
    }

    $account_manager = new SDM_Accounts_Manager();
    $account = $account_manager->get_account_by_project_and_service( $project_id, 'CloudFlare (API Key)' );
    if ( ! $account ) {
        $account = $account_manager->get_account_by_project_and_service( $project_id, 'CloudFlare (OAuth)' );
    }

    if ( ! $account || empty( $account->email ) || empty( $account->api_key_enc ) ) {
        $cache[ $project_id ] = array();
        return $cache[ $project_id ];
    }

    require_once SDM_PLUGIN_DIR . 'includes/api/class-sdm-cloudflare-api.php';
    $api_key = sdm_decrypt( $account->api_key_enc );
    $cf_api  = new SDM_Cloudflare_API(
        array(
            'email'   => $account->email,
            'api_key' => $api_key,
        )
    );

    $zones = $cf_api->get_zones();
    if ( is_wp_error( $zones ) ) {
        $cache[ $project_id ] = array();
        return $cache[ $project_id ];
    }

    $lookup = array();
    foreach ( (array) $zones as $zone ) {
        if ( empty( $zone['id'] ) || empty( $zone['name'] ) ) {
            continue;
        }
        $lookup[ $zone['id'] ] = strtolower( $zone['name'] );
    }

    $cache[ $project_id ] = $lookup;
    return $lookup;
}

/**
 * Determine if a domain is a subdomain by comparing it to known Cloudflare zones for the project.
 *
 * @param string     $domain
 * @param int        $project_id
 * @param string     $cf_zone_id
 * @param array|null $zones_lookup Optional pre-fetched lookup from sdm_get_cf_zone_lookup().
 *
 * @return bool
 */
function sdm_domain_is_subdomain_by_zone( $domain, $project_id, $cf_zone_id = '', $zones_lookup = null ) {
    $domain = strtolower( trim( $domain, " ." ) );

    if ( null === $zones_lookup ) {
        $zones_lookup = sdm_get_cf_zone_lookup( $project_id );
    }

    $zone_name = '';
    if ( ! empty( $cf_zone_id ) && isset( $zones_lookup[ $cf_zone_id ] ) ) {
        $zone_name = $zones_lookup[ $cf_zone_id ];
    }

    if ( ! empty( $zone_name ) ) {
        return $domain !== $zone_name;
    }

    foreach ( $zones_lookup as $name ) {
        if ( $domain === $name ) {
            return false;
        }

        $suffix = '.' . $name;
        if ( substr( $domain, -strlen( $suffix ) ) === $suffix ) {
            return true;
        }
    }

    return false;
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

    // Проверка наличия сервиса Mail-in-a-Box для проекта и получение server_url
    $account_manager = new SDM_Accounts_Manager();
    $mail_in_a_box_data = $account_manager->get_account_by_project_and_service($project_id, 'Mail-in-a-Box');

    $mail_in_a_box_enabled = $mail_in_a_box_data && !empty($mail_in_a_box_data->additional_data_enc);
    $server_url = '';
    if ($mail_in_a_box_enabled) {
        $additional_data = json_decode($mail_in_a_box_data->additional_data_enc, true);
        if (is_array($additional_data) && isset($additional_data['server_url'])) {
            $server_url = esc_attr($additional_data['server_url']);
            // Удаляем http/https префикс
            $server_url = str_replace(['https://', 'http://'], '', $server_url);
        } else {
            $server_url = 'box.mailrouting.site'; // fallback
        }
    }

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
            "SELECT main_domain 
             FROM {$prefix}sdm_sites 
             WHERE project_id = %d",
            $project_id
        )
    );

    // Сортировка
    $order_by = "ORDER BY ";
    if ($is_blocked_sort && $sort_column === 'blocked') {
        $order_by .= "CASE WHEN (d.is_blocked_provider OR d.is_blocked_government) THEN 1 ELSE 0 END " . esc_sql($sort_direction);
    } else {
        $order_by .= esc_sql($sort_column) . " " . esc_sql($sort_direction);
    }

    // Получаем список доменов
    $domains = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT d.*, s.site_name, s.main_domain, s.language
             FROM {$prefix}sdm_domains d
             LEFT JOIN {$prefix}sdm_sites s
               ON d.site_id = s.id
             $where
             $order_by",
            ...$params
        )
    );

    $zones_lookup = sdm_get_cf_zone_lookup( $project_id );

    // Группируем домены по Cloudflare зоне и вычисляем тип домена
    $grouped_domains = array();

    foreach ( $domains as $domain ) {
        $zone_key = $domain->cf_zone_id ? $domain->cf_zone_id : 'no-zone';
        $domain->_is_subdomain = sdm_domain_is_subdomain_by_zone( $domain->domain, $project_id, $domain->cf_zone_id, $zones_lookup ) ? 1 : 0;

        if ( ! isset( $grouped_domains[ $zone_key ] ) ) {
            $grouped_domains[ $zone_key ] = array(
                'zone_id' => $domain->cf_zone_id,
                'domains' => array(),
            );
        }

        $grouped_domains[ $zone_key ]['domains'][] = $domain;
    }

    foreach ( $grouped_domains as $group_key => $group_data ) {
        usort( $grouped_domains[ $group_key ]['domains'], function( $a, $b ) {
            if ( $a->_is_subdomain !== $b->_is_subdomain ) {
                return $a->_is_subdomain - $b->_is_subdomain;
            }

            return strcmp( $a->domain, $b->domain );
        } );
    }
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
                <th>
                    <?php esc_html_e('Actions', 'spintax-domain-manager'); ?>
                    <input type="checkbox" id="sdm-select-all-domains" style="margin-left: 5px; vertical-align: middle;">
                </th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($grouped_domains)) : ?>
                <?php foreach ( $grouped_domains as $group_key => $group ) :
                    $zone_label = '';
                    if ( ! empty( $group['zone_id'] ) && isset( $zones_lookup[ $group['zone_id'] ] ) ) {
                        $zone_label = sprintf( '%s (%s)', $zones_lookup[ $group['zone_id'] ], $group['zone_id'] );
                    } elseif ( ! empty( $group['zone_id'] ) ) {
                        $zone_label = sprintf( __( 'Zone ID: %s', 'spintax-domain-manager' ), $group['zone_id'] );
                    } else {
                        $zone_label = __( 'No Cloudflare zone', 'spintax-domain-manager' );
                    }
                    ?>
                    <tr class="sdm-zone-row">
                        <td colspan="8">
                            <span class="sdm-zone-title">🌐 <?php echo esc_html( $zone_label ); ?></span>
                        </td>
                    </tr>

                    <?php foreach ( $group['domains'] as $domain ) :
                        $is_active      = ($domain->status === 'active');
                        $is_blocked     = ($domain->is_blocked_provider || $domain->is_blocked_government);
                        $is_assigned    = !empty($domain->site_id);
                        $is_main_domain = in_array($domain->domain, $main_domains);
                        $is_subdomain   = ! empty( $domain->_is_subdomain );

                        // Проверяем, есть ли запись в wp_sdm_email_forwarding
                        $has_forwarding = (bool) $wpdb->get_var(
                            $wpdb->prepare(
                                "SELECT 1
                                 FROM {$prefix}sdm_email_forwarding
                                 WHERE domain_id = %d
                                 LIMIT 1",
                                $domain->id
                            )
                        );
                        $forwarding_class = $has_forwarding ? 'sdm-email-active' : '';

                        $status_slug = sanitize_title( $domain->status );
                        ?>
                        <tr id="domain-row-<?php echo esc_attr($domain->id); ?>"
                            data-domain-id="<?php echo esc_attr($domain->id); ?>"
                            data-update-nonce="<?php echo esc_attr(sdm_create_main_nonce()); ?>"
                            data-is-subdomain="<?php echo $is_subdomain ? '1' : '0'; ?>">

                            <!-- Domain ------------------------------------------------------------- -->
                            <td class="sdm-domain <?php echo $is_blocked ? 'sdm-blocked-domain' : ''; ?>">
                                <?php if ( $is_subdomain ) : ?>
                                    <span class="sdm-subdomain-indent">↳</span>
                                <?php else : ?>
                                    <span class="sdm-root-icon" aria-hidden="true">🌐</span>
                                <?php endif; ?>

                                <span class="<?php echo $is_subdomain ? 'sdm-subdomain-label' : 'sdm-root-domain'; ?>">
                                    <?php echo esc_html($domain->domain); ?>
                                </span>
                                <?php if ( $is_subdomain ) : ?>
                                    <span class="sdm-badge sdm-badge-sub"><?php esc_html_e('sub', 'spintax-domain-manager'); ?></span>
                                <?php endif; ?>
                            </td>

                        <!-- Site link / main-icon -------------------------------------------- -->
                        <td>
                            <?php if ($is_assigned) : ?>
                                <?php if ($is_main_domain) : ?>
                                    <span class="sdm-main-domain-icon" style="display:inline-flex;align-items:center;margin-right:5px;width:auto;height:auto;font-size:16px;">
                                        <span class="fi fi-<?php echo esc_attr( sdm_normalize_language_code( $domain->language ?: 'en' ) ); ?>" style="vertical-align:middle;"></span>
                                    </span>
                                <?php endif; ?>
                                <a href="?page=sdm-sites&project_id=<?php echo esc_attr($project_id); ?>"
                                   class="sdm-site-link">
                                    <?php echo esc_html($domain->site_name); ?>
                                </a>
                            <?php else : ?>
                                (Unassigned)
                            <?php endif; ?>
                        </td>

                        <!-- Other columns ------------------------------------------------------ -->
                        <td class="<?php echo $domain->abuse_status !== 'clean' ? 'sdm-abuse-warning' : ''; ?>">
                            <?php echo esc_html($domain->abuse_status); ?>
                        </td>
                        <td><?php echo $is_blocked ? esc_html__('Yes', 'spintax-domain-manager') : esc_html__('No', 'spintax-domain-manager'); ?></td>
                        <td>
                            <span class="sdm-status-badge sdm-status-<?php echo esc_attr( $status_slug ?: 'unknown' ); ?>">
                                <?php echo esc_html($domain->status); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html($domain->last_checked); ?></td>
                        <td><?php echo esc_html($domain->created_at); ?></td>

                        <!-- ACTIONS ------------------------------------------------------------ -->
                        <td>
                            <?php if ( $is_active && $is_subdomain ) : ?>
                                <?php if ($is_assigned && !$is_main_domain) : ?>
                                    <input type="checkbox" class="sdm-domain-checkbox" value="<?php echo esc_attr($domain->id); ?>">
                                    <button type="button"
                                            class="sdm-action-button sdm-unassign sdm-mini-icon"
                                            data-domain-id="<?php echo esc_attr($domain->id); ?>"
                                            title="<?php esc_attr_e('Unassign', 'spintax-domain-manager'); ?>">
                                        <img src="<?php echo esc_url(SDM_PLUGIN_URL . 'assets/icons/clear.svg'); ?>" alt="" />
                                    </button>
                                <?php elseif (!$is_main_domain) : ?>
                                    <input type="checkbox" class="sdm-domain-checkbox" value="<?php echo esc_attr($domain->id); ?>">
                                <?php endif; ?>

                            <?php elseif ($is_active && $mail_in_a_box_enabled) : ?>
                                <!-- чекбокс / Unassign -->
                                <?php if ($is_assigned && !$is_main_domain) : ?>
                                    <input type="checkbox" class="sdm-domain-checkbox" value="<?php echo esc_attr($domain->id); ?>">
                                    <button type="button"
                                            class="sdm-action-button sdm-unassign sdm-mini-icon"
                                            data-domain-id="<?php echo esc_attr($domain->id); ?>"
                                            title="<?php esc_attr_e('Unassign', 'spintax-domain-manager'); ?>">
                                        <img src="<?php echo esc_url(SDM_PLUGIN_URL . 'assets/icons/clear.svg'); ?>" alt="" />
                                    </button>
                                <?php elseif (!$is_main_domain) : ?>
                                    <input type="checkbox" class="sdm-domain-checkbox" value="<?php echo esc_attr($domain->id); ?>">
                                <?php endif; ?>

                                <!-- NEW Sync NS  ↓↓ -->
                                <button type="button"
                                        class="sdm-action-button sdm-mini-icon sdm-sync-ns"
                                        data-domain-id="<?php echo esc_attr($domain->id); ?>"
                                        title="<?php esc_attr_e('Sync NS to Namecheap', 'spintax-domain-manager'); ?>">
                                    <?php
                                    $dns_svg = file_get_contents(SDM_PLUGIN_DIR . 'assets/icons/dns.svg');
                                    if ($dns_svg) {
                                        echo wp_kses($dns_svg, [
                                            'svg'  => ['width'=>true,'height'=>true,'viewBox'=>true,'fill'=>true,'xmlns'=>true],
                                            'path' => ['d'=>true,'fill'=>true,'fill-rule'=>true,'clip-rule'=>true],
                                        ]);
                                    } else {
                                        echo '<img src="' . esc_url(SDM_PLUGIN_URL . 'assets/icons/dns.svg') . '" alt="NS" width="16" height="16" />';
                                    }
                                    ?>
                                </button>
                                <!-- ↑↑ NEW Sync NS -->

                                <!-- Email-forwarding -->
                                <button type="button"
                                        class="sdm-action-button sdm-mini-icon sdm-email-forwarding <?php echo $forwarding_class; ?>"
                                        data-domain-id="<?php echo esc_attr($domain->id); ?>"
                                        data-domain="<?php echo esc_attr($domain->domain); ?>"
                                        data-server-url="<?php echo $server_url; ?>"
                                        title="<?php esc_attr_e('Set Email Forwarding', 'spintax-domain-manager'); ?>">
                                    <?php
                                    $email_svg = file_get_contents(SDM_PLUGIN_DIR . 'assets/icons/email.svg');
                                    echo $email_svg
                                        ? wp_kses($email_svg, [
                                              'svg'=>['width'=>true,'height'=>true,'viewBox'=>true],
                                              'path'=>['d'=>true,'fill'=>true]
                                          ])
                                        : '<img src="'.esc_url(SDM_PLUGIN_URL.'assets/icons/email.svg').'" alt="@" width="16" height="16" />';
                                    ?>
                                </button>

                            <?php elseif ($is_active && !$mail_in_a_box_enabled) : ?>
                                <!-- чекбокс / Unassign (тот же блок, но без email-кнопки) -->
                                <?php if ($is_assigned && !$is_main_domain) : ?>
                                    <input type="checkbox" class="sdm-domain-checkbox" value="<?php echo esc_attr($domain->id); ?>">
                                    <button type="button"
                                            class="sdm-action-button sdm-unassign sdm-mini-icon"
                                            data-domain-id="<?php echo esc_attr($domain->id); ?>"
                                            title="<?php esc_attr_e('Unassign', 'spintax-domain-manager'); ?>">
                                        <img src="<?php echo esc_url(SDM_PLUGIN_URL . 'assets/icons/clear.svg'); ?>" alt="" />
                                    </button>
                                <?php elseif (!$is_main_domain) : ?>
                                    <input type="checkbox" class="sdm-domain-checkbox" value="<?php echo esc_attr($domain->id); ?>">
                                <?php endif; ?>

                                <!-- NEW Sync NS  ↓↓ -->
                                <button type="button"
                                        class="sdm-action-button sdm-mini-icon sdm-sync-ns"
                                        data-domain-id="<?php echo esc_attr($domain->id); ?>"
                                        title="<?php esc_attr_e('Sync NS to Namecheap', 'spintax-domain-manager'); ?>">
                                    <?php
                                    $dns_svg = file_get_contents(SDM_PLUGIN_DIR . 'assets/icons/dns.svg');
                                    echo $dns_svg
                                        ? wp_kses($dns_svg, [
                                              'svg'=>['width'=>true,'height'=>true,'viewBox'=>true,'fill'=>true,'xmlns'=>true],
                                              'path'=>['d'=>true,'fill'=>true,'fill-rule'=>true,'clip-rule'=>true],
                                          ])
                                        : '<img src="'.esc_url(SDM_PLUGIN_URL.'assets/icons/dns.svg').'" alt="NS" width="16" height="16" />';
                                    ?>
                                </button>
                                <!-- ↑↑ NEW Sync NS -->

                            <?php else : ?>
                                <!-- Sync NS + Delete for inactive/blocked -->
                                <?php if ( ! $is_subdomain ) : ?>
                                    <button type="button"
                                            class="sdm-action-button sdm-mini-icon sdm-sync-ns sdm-sync-ns-inactive"
                                            data-domain-id="<?php echo esc_attr($domain->id); ?>"
                                            title="<?php esc_attr_e('Sync NS to Namecheap', 'spintax-domain-manager'); ?>">
                                        <?php
                                        $dns_svg = file_get_contents(SDM_PLUGIN_DIR . 'assets/icons/dns.svg');
                                        echo $dns_svg
                                            ? wp_kses($dns_svg, [
                                                  'svg'=>['width'=>true,'height'=>true,'viewBox'=>true,'fill'=>true,'xmlns'=>true],
                                                  'path'=>['d'=>true,'fill'=>true,'fill-rule'=>true,'clip-rule'=>true],
                                              ])
                                            : '<img src="'.esc_url(SDM_PLUGIN_URL.'assets/icons/dns.svg').'" alt="NS" width="16" height="16" />';
                                        ?>
                                    </button>
                                <?php endif; ?>

                                <button type="button"
                                        class="sdm-action-button sdm-delete-domain sdm-delete sdm-mini-icon"
                                        data-domain-id="<?php echo esc_attr($domain->id); ?>"
                                        title="<?php esc_attr_e('Delete', 'spintax-domain-manager'); ?>">
                                    <img src="<?php echo esc_url(SDM_PLUGIN_URL . 'assets/icons/clear.svg'); ?>" alt="" />
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
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
            <option value="mass_add"><?php esc_html_e('Add Domains/Subdomains', 'spintax-domain-manager'); ?></option>
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

    $conflict_params = array( $domain );
    $conflict_query  = "SELECT id FROM {$prefix}sdm_sites WHERE main_domain = %s";
    if ( $site_id > 0 ) {
        $conflict_query  .= ' AND id != %d';
        $conflict_params[] = $site_id;
    }

    $conflicting_site_id = $wpdb->get_var( $wpdb->prepare( $conflict_query, ...$conflict_params ) );

    $domain_row = $wpdb->get_row( $wpdb->prepare(
        "SELECT site_id FROM {$prefix}sdm_domains WHERE domain = %s",
        $domain
    ) );

    $needs_release = false;
    if ( $conflicting_site_id ) {
        $needs_release = true;
    }

    if (
        $domain_row &&
        $domain_row->site_id &&
        ( $site_id <= 0 || (int) $domain_row->site_id !== $site_id )
    ) {
        $needs_release = true;
    }

    $message_suffix = '';
    if ( $needs_release ) {
        $release_result = sdm_release_domain_conflicts( $domain, $site_id );
        if ( is_wp_error( $release_result ) ) {
            wp_send_json_error( $release_result->get_error_message() );
        }

        $message_suffix = ' ' . __( 'A stale assignment was cleared automatically.', 'spintax-domain-manager' );
    }

    wp_send_json_success( __( 'Domain is valid and available.', 'spintax-domain-manager' ) . $message_suffix );
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

    $message = sprintf( __( 'Added %d domains/subdomains to CloudFlare.', 'spintax-domain-manager' ), $result['inserted'] );
    if ( ! empty( $result['errors'] ) ) {
        $message .= ' ' . implode( ' | ', $result['errors'] );
    }
    wp_send_json_success( array( 'message' => $message ) );
}
add_action( 'wp_ajax_sdm_mass_add_domains', 'sdm_ajax_mass_add_domains' );


/**
 * AJAX Handler: Create Email Forwarding via Mail-in-a-Box API.
 */
function sdm_ajax_create_email_forwarding() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();

    $domain_id  = isset( $_POST['domain_id'] ) ? absint( $_POST['domain_id'] ) : 0;
    $project_id = isset( $_POST['project_id'] ) ? absint( $_POST['project_id'] ) : 0;
    if ( ! $domain_id || ! $project_id ) {
        wp_send_json_error( __( 'Invalid domain or project ID.', 'spintax-domain-manager' ) );
    }

    global $wpdb;
    // Получаем доменное имя
    $domain_row = $wpdb->get_row( $wpdb->prepare(
        "SELECT domain, cf_zone_id FROM {$wpdb->prefix}sdm_domains WHERE id = %d",
        $domain_id
    ) );
    if ( ! $domain_row || empty( $domain_row->domain ) ) {
        wp_send_json_error( __( 'Domain not found.', 'spintax-domain-manager' ) );
    }

    if ( sdm_domain_is_subdomain_by_zone( $domain_row->domain, $project_id, $domain_row->cf_zone_id ) ) {
        wp_send_json_error( __( 'Email creation is not supported for subdomains.', 'spintax-domain-manager' ) );
    }

    $domain_name = $domain_row->domain;

    // Получаем аккаунт Mail‑in‑a‑Box
    $account_manager = new SDM_Accounts_Manager();
    $mail_account = $account_manager->get_account_by_project_and_service($project_id, 'Mail-in-a-Box');
    if ( ! $mail_account ) {
        wp_send_json_error( __( 'Mail-in-a-Box account not found.', 'spintax-domain-manager' ) );
    }

    // Дешифруем
    $additional_data_dec = sdm_decrypt( $mail_account->additional_data_enc );
    $additional_data = json_decode($additional_data_dec, true);
    if ( ! is_array( $additional_data ) || empty( $additional_data['server_url'] ) ) {
        wp_send_json_error( __( 'Invalid Mail-in-a-Box account data.', 'spintax-domain-manager' ) );
    }

    $server_url     = rtrim( $additional_data['server_url'], '/' ); 
    $admin_email    = isset($additional_data['email']) ? $additional_data['email'] : '';
    $admin_password = isset($additional_data['password']) ? $additional_data['password'] : '';
    if ( ! $admin_email || ! $admin_password ) {
        wp_send_json_error( __( 'Mail-in-a-Box admin credentials are missing.', 'spintax-domain-manager' ) );
    }

    // Извлекаем домен mail-сервера (например box.mailrouting.site)
    $host = parse_url( $server_url, PHP_URL_HOST );
    if ( ! $host ) {
        $host = 'box.mailrouting.site';
    }

    // Формируем email: domain.com@box.mailrouting.site
    $email_address = $domain_name . '@' . $host;

    // Генерируем пароль по умолчанию (может быть заменён при наличии сохранённого значения)
    $mailbox_password = wp_generate_password( 12, false );

    // Подключаем класс
    require_once SDM_PLUGIN_DIR . 'includes/api/class-sdm-mailinabox-api.php';
    $mapi = new SDM_Mailinabox_API( $server_url, $admin_email, $admin_password );

    // Проверяем, есть ли уже запись и пытаемся получить сохранённый (зашифрованный) пароль
    $existing_record = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, password FROM {$wpdb->prefix}sdm_email_forwarding WHERE domain_id = %d LIMIT 1",
        $domain_id
    ) );

    if ( $existing_record ) {
        $decrypted_password = sdm_decrypt( $existing_record->password );

        if ( ! empty( $decrypted_password ) ) {
            $mailbox_password = $decrypted_password;
        } elseif ( ! empty( $existing_record->password ) ) {
            // Backward compatibility for legacy plain passwords
            $mailbox_password = $existing_record->password;
        }
    }

    // Пытаемся создать почтовый ящик
    $add_result = $mapi->add_user( $email_address, $mailbox_password );
    if ( is_wp_error( $add_result ) ) {
        // Если уже существует, можно проверить ошибку "Mail-in-a-Box API responded with status 400"...
        // Но для простоты просто возвращаем ошибку:
        wp_send_json_error( $add_result->get_error_message() );
    }

    $encrypted_password = sdm_encrypt( $mailbox_password );

    if ( $existing_record && $existing_record->id ) {
        // UPDATE
        $wpdb->update(
            $wpdb->prefix . 'sdm_email_forwarding',
            array(
                'email_address'     => $email_address,
                'password'          => $encrypted_password ?: $mailbox_password,
                'catch_all_enabled' => 0,
                'created_at'        => current_time('mysql'), // или updated_at
            ),
            array('id' => $existing_record->id),
            array('%s','%s','%d','%s'),
            array('%d')
        );
    } else {
        // INSERT
        $wpdb->insert(
            $wpdb->prefix . 'sdm_email_forwarding',
            array(
                'domain_id'         => $domain_id,
                'email_address'     => $email_address,
                'password'          => $encrypted_password ?: $mailbox_password,
                'catch_all_enabled' => 0,
                'created_at'        => current_time('mysql'),
            ),
            array('%d','%s','%s','%d','%s')
        );
    }

    // Возвращаем данные для UI
    wp_send_json_success( array(
        'email_address' => $email_address,
        'password'      => $mailbox_password,
        'server_url'    => $host,
        'message'       => __( 'Email created (or updated) successfully.', 'spintax-domain-manager' ),
    ) );
}
add_action( 'wp_ajax_sdm_create_email_forwarding', 'sdm_ajax_create_email_forwarding' );



/**
 * Шаг 1: Создаём custom address (all@domain.com -> forward -> $external_email),
 *        просим пользователя подтвердить адрес в Cloudflare.
 */
function sdm_ajax_create_cf_custom_address() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied.');
    }
    sdm_check_main_nonce();

    $domain_id = isset($_POST['domain_id']) ? absint($_POST['domain_id']) : 0;
    if (!$domain_id) {
        wp_send_json_error('Invalid domain ID.');
    }

    global $wpdb;
    // Берём project_id, cf_zone_id, domain, email_address (Mail-in-a-Box)
    $row = $wpdb->get_row($wpdb->prepare("
        SELECT d.project_id, d.cf_zone_id, d.domain, f.email_address
        FROM {$wpdb->prefix}sdm_domains d
        JOIN {$wpdb->prefix}sdm_email_forwarding f ON d.id = f.domain_id
        WHERE d.id = %d
        LIMIT 1
    ", $domain_id));
    if (!$row || empty($row->cf_zone_id)) {
        wp_send_json_error('No domain or zone data found.');
    }

    if ( sdm_domain_is_subdomain_by_zone( $row->domain, $row->project_id, $row->cf_zone_id ) ) {
        wp_send_json_error( __( 'Email creation is not supported for subdomains.', 'spintax-domain-manager' ) );
    }
    $project_id     = absint($row->project_id);
    $zone_id        = $row->cf_zone_id;
    $domain_name    = $row->domain;
    $external_email = $row->email_address; // Mail-in-a-Box email

    if (!$project_id) {
        wp_send_json_error('Domain missing project_id.');
    }

    // Получаем Cloudflare учётку
    $account_manager = new SDM_Accounts_Manager();
    $account = $account_manager->get_account_by_project_and_service($project_id, 'CloudFlare (API Key)');
    if (!$account) {
        $account = $account_manager->get_account_by_project_and_service($project_id, 'CloudFlare (OAuth)');
    }
    if (!$account) {
        wp_send_json_error('No CloudFlare account found for this project.');
    }
    if (empty($account->email) || empty($account->api_key_enc)) {
        wp_send_json_error('Incomplete CloudFlare credentials in account.');
    }

    // Расшифровываем API key
    $api_key = sdm_decrypt($account->api_key_enc);
    if (!$api_key) {
        wp_send_json_error('Could not decrypt CloudFlare api_key.');
    }

    // Инициализируем Cloudflare API
    require_once SDM_PLUGIN_DIR . 'includes/api/class-sdm-cloudflare-api.php';
    $cf_api = new SDM_Cloudflare_API([
        'email'   => $account->email,
        'api_key' => $api_key
    ]);

    // 1) Узнаём account_id
    $zone_details = $cf_api->get_zone_details($zone_id);
    if (is_wp_error($zone_details)) {
        wp_send_json_error('Failed to get zone details: '.$zone_details->get_error_message());
    }
    $account_id = $zone_details['result']['account']['id'] ?? '';
    if (!$account_id) {
        wp_send_json_error('Could not retrieve account_id from zone details.');
    }

    // 2) Создаём «destination address» (Mail-in-a-Box)
    $resp_dest = $cf_api->create_destination_address($account_id, $external_email);
    if (is_wp_error($resp_dest)) {
        $err_msg = $resp_dest->get_error_message();
        // Если уже существует, можно игнорировать, иначе ошибка
        if (strpos($err_msg, 'already exists') === false) {
            wp_send_json_error('Creating destination address failed: '.$err_msg);
        }
    } else {
        if (empty($resp_dest['success'])) {
            wp_send_json_error('create_destination_address returned success=false: '.print_r($resp_dest, true));
        }
    }

    // 3) Создаём routing rule для all@domain.com → forward -> external_email
    //    POST /zones/{zone_id}/email/routing/rules
    $matchers = [
        [
            'field' => 'to',
            'type'  => 'literal',
            'value' => "all@{$domain_name}",
        ]
    ];
    $actions = [
        [
            'type'  => 'forward',
            'value' => [$external_email]
        ]
    ];
    $resp_rule = $cf_api->create_routing_rule($zone_id, $matchers, $actions, true, "Custom address all@{$domain_name}");
    if (is_wp_error($resp_rule)) {
        wp_send_json_error('Creating custom address rule failed: '.$resp_rule->get_error_message());
    }
    if (empty($resp_rule['success'])) {
        wp_send_json_error('create_routing_rule returned success=false: '.print_r($resp_rule, true));
    }

    // Сообщаем, что адрес создан, нужно подтвердить
    wp_send_json_success([
        'message' => "Created custom address all@{$domain_name} → forward to {$external_email}. 
                      Please check your mailbox for a verification email from Cloudflare and confirm it."
    ]);
}
add_action('wp_ajax_sdm_create_cf_custom_address', 'sdm_ajax_create_cf_custom_address');


/**
 * AJAX Handler: Set Catch-All Forwarding via Cloudflare Email Routing API.
 *
 * Шаг 2/3: 
 *   1) enable_email_routing_dns (добавляет MX/TXT), 
 *   2) Проверяем, что email подтверждён, 
 *   3) выставляем Catch-All Rule.
 */
function sdm_ajax_set_catchall_forwarding() {
    sdm_check_main_nonce();

    $domain_id = absint($_POST['domain_id'] ?? 0);
    if (!$domain_id) {
        wp_send_json_error('Invalid domain ID.');
    }

    global $wpdb;
    // Берём project_id, cf_zone_id, domain, email_address
    $row = $wpdb->get_row($wpdb->prepare("
        SELECT d.project_id, d.cf_zone_id, d.domain, f.email_address
        FROM {$wpdb->prefix}sdm_domains d
        JOIN {$wpdb->prefix}sdm_email_forwarding f ON d.id = f.domain_id
        WHERE d.id = %d
        LIMIT 1
    ", $domain_id));

    if (!$row || empty($row->cf_zone_id)) {
        wp_send_json_error('No domain or zone data found.');
    }
    $project_id     = absint($row->project_id);
    $zone_id        = $row->cf_zone_id;
    $domain_name    = $row->domain;
    $forwarding_email = $row->email_address;

    if (!$project_id) {
        wp_send_json_error('Domain missing project_id.');
    }

    // Получаем Cloudflare-аккаунт
    $account_manager = new SDM_Accounts_Manager();
    $account = $account_manager->get_account_by_project_and_service($project_id, 'CloudFlare (API Key)');
    if (!$account) {
        $account = $account_manager->get_account_by_project_and_service($project_id, 'CloudFlare (OAuth)');
    }
    if (!$account) {
        wp_send_json_error('No CloudFlare account found for this project.');
    }
    if (empty($account->email) || empty($account->api_key_enc)) {
        wp_send_json_error('Incomplete CloudFlare credentials in account.');
    }

    $api_key = sdm_decrypt($account->api_key_enc);
    if (!$api_key) {
        wp_send_json_error('Could not decrypt CloudFlare api_key.');
    }

    require_once SDM_PLUGIN_DIR . 'includes/api/class-sdm-cloudflare-api.php';
    $cf_api = new SDM_Cloudflare_API([
        'email'   => $account->email,
        'api_key' => $api_key
    ]);

    // --- 1) Включаем Email Routing
    $resp_enable = $cf_api->enable_email_routing($zone_id);
    if ( is_wp_error($resp_enable) ) {
        wp_send_json_error('Enable Email Routing failed: '.$resp_enable->get_error_message());
    }
    if ( empty($resp_enable['success']) ) {
        wp_send_json_error('Enable Email Routing returned success=false: '.print_r($resp_enable, true));
    }
    // Успех
    wp_send_json_success([
        'message' => 'Email Routing enabled for zone: '.$zone_id,
    ]);
    // --- 2) Создаём (или убеждаемся что есть) «destination address» – т.е. Mail-in-a-Box-адрес
    // Узнаём account_id из zone_details
    $zone_details = $cf_api->get_zone_details($zone_id);
    if (is_wp_error($zone_details)) {
        wp_send_json_error('Failed to get zone details: ' . $zone_details->get_error_message());
    }
    $account_id = $zone_details['result']['account']['id'] ?? '';
    if (!$account_id) {
        wp_send_json_error('Could not retrieve account_id from zone details.');
    }

    // Создаём/проверяем external email
    $resp_dest = $cf_api->create_destination_address($account_id, $forwarding_email);
    if (is_wp_error($resp_dest)) {
        // Если "already exists", можно игнорировать, иначе ошибка
        $err_msg = $resp_dest->get_error_message();
        if (strpos($err_msg, 'already exists') === false) {
            wp_send_json_error('Creating destination address failed: ' . $err_msg);
        }
        // Иначе продолжаем
    } else {
        if (empty($resp_dest['success'])) {
            wp_send_json_error('create_destination_address returned success=false: ' . print_r($resp_dest, true));
        }
    }

    // --- 3) Настраиваем catch-all
    $resp_rule = $cf_api->set_catch_all_rule($zone_id, $forwarding_email);
    if (is_wp_error($resp_rule)) {
        wp_send_json_error('Set Catch-All Rule failed: ' . $resp_rule->get_error_message());
    }
    if (empty($resp_rule['success'])) {
        wp_send_json_error('Cloudflare rule creation failed: ' . print_r($resp_rule, true));
    }

    // --- 4) Помечаем локально что catch_all_enabled = 1
    $wpdb->update(
        "{$wpdb->prefix}sdm_email_forwarding",
        ['catch_all_enabled' => 1],
        ['domain_id' => $domain_id],
        ['%d'],
        ['%d']
    );

    wp_send_json_success([
        'message' => "Catch-All forwarding enabled for {$forwarding_email}."
    ]);
}



/**
 * AJAX Handler: Enable Email Routing (DNS)
 */
function sdm_ajax_enable_email_routing() {
    sdm_check_main_nonce();

    $domain_id = isset($_POST['domain_id']) ? absint($_POST['domain_id']) : 0;
    if (!$domain_id) {
        wp_send_json_error('Invalid domain ID.');
    }

    global $wpdb;
    // 1) Узнаём cf_zone_id и домен из таблицы
    $row = $wpdb->get_row($wpdb->prepare("
        SELECT cf_zone_id, domain
        FROM {$wpdb->prefix}sdm_domains
        WHERE id = %d
        LIMIT 1
    ", $domain_id));

    if (!$row || empty($row->cf_zone_id)) {
        wp_send_json_error('No zone data found for domain_id=' . $domain_id);
    }

    $zone_id    = $row->cf_zone_id;
    $trueDomain = $row->domain; // Настоящее имя домена (например "vavadacasino.yachts")

    // 2) Получаем Cloudflare‑аккаунт (как в set_catchall_forwarding)
    $account_manager = new SDM_Accounts_Manager();
    $account = $account_manager->get_account_by_project_and_service($project_id, 'CloudFlare (API Key)');
    if (!$account) {
        $account = $account_manager->get_account_by_project_and_service($project_id, 'CloudFlare (OAuth)');
    }
    if (!$account) {
        wp_send_json_error('No CloudFlare account found for this domain/project.');
    }

    // Расшифровываем ключ
    $api_key = sdm_decrypt($account->api_key_enc);
    if (!$api_key) {
        wp_send_json_error('Could not decrypt CloudFlare api_key.');
    }

    // 3) Обращаемся к Cloudflare API
    require_once SDM_PLUGIN_DIR . 'includes/api/class-sdm-cloudflare-api.php';
    $cf_api = new SDM_Cloudflare_API([
        'email'   => $account->email,
        'api_key' => $api_key,
    ]);

    // Включаем Email Routing (DNS) → /zones/{zone_id}/email/routing/dns
    // Вместо zone_id в поле "name" используем сам домен
    $endpoint = "zones/{$zone_id}/email/routing/dns";
    $payload = [
        'name' => $trueDomain  // Важно: именно домен, а не cf_zone_id
    ];

    $resp = $cf_api->api_request_extended(
        $endpoint,
        [],
        'POST',
        $payload
    );

    if (is_wp_error($resp)) {
        wp_send_json_error('Enable Email Routing (DNS) failed: ' . $resp->get_error_message());
    }
    if (empty($resp['success'])) {
        wp_send_json_error('Enabling email routing DNS returned no success: ' . print_r($resp, true));
    }

    // 4) Возвращаем результат
    wp_send_json_success([
        'message' => 'Email Routing (DNS) enabled for zone_id=' . $zone_id,
    ]);
}
add_action('wp_ajax_sdm_enable_email_routing', 'sdm_ajax_enable_email_routing');

/**
 * AJAX Handler: Получить данные Cloudflare по домену
 * (используем доменное имя вместо domain_id)
 */
function sdm_ajax_get_zone_account_details_by_domain() {
    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error('Permission denied.');
    }
    sdm_check_main_nonce();

    $domain = isset($_POST['domain']) ? sanitize_text_field($_POST['domain']) : '';
    if ( empty($domain) ) {
        wp_send_json_error('Missing domain');
    }

    global $wpdb;
    // Получаем данные по домену из таблицы sdm_domains
    $row = $wpdb->get_row($wpdb->prepare("
         SELECT project_id, cf_zone_id, domain
         FROM {$wpdb->prefix}sdm_domains
         WHERE domain = %s
         LIMIT 1
    ", $domain));

    if ( ! $row ) {
        wp_send_json_error('No domain found for domain ' . $domain);
    }
    if ( empty($row->cf_zone_id) ) {
        wp_send_json_error('This domain has no cf_zone_id stored.');
    }

    // Получаем Cloudflare учётку для проекта
    $account_manager = new SDM_Accounts_Manager();
    $account_row = $account_manager->get_account_by_project_and_service($row->project_id, 'CloudFlare (API Key)');
    if (!$account_row) {
        $account_row = $account_manager->get_account_by_project_and_service($row->project_id, 'CloudFlare (OAuth)');
    }
    if ( ! $account_row ) {
        wp_send_json_error('No Cloudflare account found for this project.');
    }
    $api_key = sdm_decrypt($account_row->api_key_enc);
    if ( ! $api_key ) {
        wp_send_json_error('Could not decrypt CloudFlare api_key.');
    }

    require_once SDM_PLUGIN_DIR . 'includes/api/class-sdm-cloudflare-api.php';
    $cf_api = new SDM_Cloudflare_API([
         'email'   => $account_row->email,
         'api_key' => $api_key,
    ]);

    // Получаем details для зоны
    $zone_details = $cf_api->get_zone_details($row->cf_zone_id);
    if ( is_wp_error($zone_details) ) {
        wp_send_json_error('Failed to get zone details: ' . $zone_details->get_error_message());
    }
    if ( empty($zone_details['result']) ) {
        wp_send_json_error('Empty result from get_zone_details');
    }
    $account_id = $zone_details['result']['account']['id'] ?? '';
    if ( ! $account_id ) {
        wp_send_json_error('No account_id found in zone details.');
    }

    wp_send_json_success([
         'domain'     => $row->domain,
         'zone_id'    => $row->cf_zone_id,
         'account_id' => $account_id,
    ]);
}
add_action('wp_ajax_sdm_get_zone_account_details_by_domain', 'sdm_ajax_get_zone_account_details_by_domain');

/* --------------------------------------------------------------------------
 * AJAX: синхронизация NS-серверов Cloudflare → Namecheap
 * -------------------------------------------------------------------------- */
add_action( 'wp_ajax_sdm_sync_cf_ns_namecheap', function () {

    /* 0. Проверка прав и nonce */
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied.', 'spintax-domain-manager' ) );
    }
    sdm_check_main_nonce();

    /* 1. Получаем домен из БД */
    $domain_id = isset( $_POST['domain_id'] ) ? absint( $_POST['domain_id'] ) : 0;
    if ( ! $domain_id ) {
        wp_send_json_error( __( 'Invalid domain ID.', 'spintax-domain-manager' ) );
    }

    global $wpdb;
    $domain = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}sdm_domains WHERE id = %d LIMIT 1",
        $domain_id
    ) );

    if ( ! $domain ) {
        wp_send_json_error( __( 'Domain not found.', 'spintax-domain-manager' ) );
    }

    if ( sdm_domain_is_subdomain_by_zone( $domain->domain, $domain->project_id, $domain->cf_zone_id ) ) {
        wp_send_json_error( __( 'Nameserver sync is not available for subdomains.', 'spintax-domain-manager' ) );
    }

    /* 2. Cloudflare учётка для проекта */
    $cf_creds = SDM_Cloudflare_API::get_project_cf_credentials( $domain->project_id );
    if ( is_wp_error( $cf_creds ) ) {
        wp_send_json_error( $cf_creds->get_error_message() );
    }
    $cf = new SDM_Cloudflare_API( $cf_creds );

    /* 3. Получаем или создаём zone_id */
    $zone_id = $domain->cf_zone_id;

    if ( empty( $zone_id ) ) {
        // Пытаемся найти зону по имени
        $zone_id = $cf->find_zone_id_by_name( $domain->domain );
        if ( is_wp_error( $zone_id ) && 'cf_no_zone' === $zone_id->get_error_code() ) {
            // Зоны нет — создаём
            $create = $cf->add_zone( $domain->domain );
            if ( is_wp_error( $create ) ) {
                wp_send_json_error( $create->get_error_message() );
            }
            $zone_id = $create['result']['id'] ?? '';
        } elseif ( is_wp_error( $zone_id ) ) {
            wp_send_json_error( $zone_id->get_error_message() );
        }
    }

    if ( empty( $zone_id ) ) {
        wp_send_json_error( __( 'Unable to obtain Cloudflare zone ID.', 'spintax-domain-manager' ) );
    }

    /* 4. Получаем именные NS Cloudflare */
    $ns = $cf->get_zone_nameservers( $zone_id );
    if ( is_wp_error( $ns ) ) {
        wp_send_json_error( $ns->get_error_message() );
    }

    /* 5. Учётка Namecheap */
    $acc_mgr = new SDM_Accounts_Manager();
    $nc_acc  = $acc_mgr->get_account_by_project_and_service( $domain->project_id, 'namecheap' );
    if ( ! $nc_acc ) {
        wp_send_json_error( __( 'No Namecheap account linked to this project.', 'spintax-domain-manager' ) );
    }

    $nc_creds = array_merge(
        [ 'username' => $nc_acc->account_name ],
        json_decode( sdm_decrypt( $nc_acc->additional_data_enc ), true ) ?: []
    );

    require_once SDM_PLUGIN_DIR . 'includes/api/class-sdm-namecheap-api.php';
    $nc   = new SDM_Namecheap_API( $nc_creds );
    $resp = $nc->set_nameservers( $domain->domain, $ns );

    if ( ! $resp['success'] ) {
        wp_send_json_error( $resp['message'] );
    }

    /* 6. Сохраняем zone_id, если нашли/создали впервые */
    if ( empty( $domain->cf_zone_id ) && $zone_id ) {
        $wpdb->update(
            $wpdb->prefix . 'sdm_domains',
            [ 'cf_zone_id' => $zone_id ],
            [ 'id'         => $domain_id ],
            [ '%s' ],
            [ '%d' ]
        );
    }

    wp_send_json_success( __( 'Nameservers synced successfully.', 'spintax-domain-manager' ) );
} );
