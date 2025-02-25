<?php
/**
 * File: includes/api/class-sdm-cloudflare-api.php
 * Description: Provides functions to interact with CloudFlare API.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SDM_Cloudflare_API {

    /**
     * Основной CloudFlare API endpoint.
     */
    private $endpoint = 'https://api.cloudflare.com/client/v4/';

    /**
     * Хранимые креденшелы (email/api_key или token).
     *
     * @var array
     */
    private $credentials;

    /**
     * Конструктор (задаёт креденшелы).
     *
     * @param array $credentials
     */
    public function __construct( $credentials = array() ) {
        $this->credentials = $credentials;
    }

    /**
     * Проверка аккаунта CloudFlare — возвращает количество зон.
     *
     * @return WP_Error|int
     */
    public function check_account() {
        $result = $this->get_zones();
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return count( $result );
    }

    /**
     * Получаем все зоны из CloudFlare (постранично).
     *
     * @param int $per_page
     * @return array|WP_Error
     */
    public function get_zones( $per_page = 50 ) {
        $page = 1;
        $all_zones = array();

        do {
            $response = $this->api_request( 'zones', array(
                'per_page' => $per_page,
                'page'     => $page,
            ) );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            if ( ! isset( $response['result'] ) || ! is_array( $response['result'] ) ) {
                return new WP_Error( 
                    'invalid_response', 
                    __( 'Invalid response from CloudFlare API.', 'spintax-domain-manager' )
                );
            }

            $zones = $response['result'];
            $all_zones = array_merge( $all_zones, $zones );
            $total_pages = isset( $response['result_info']['total_pages'] )
                ? (int) $response['result_info']['total_pages'] 
                : 1;
            $page++;
        } while ( $page <= $total_pages );

        return $all_zones;
    }

    /**
     * Базовый метод для GET-запросов CloudFlare.
     *
     * @param string $endpoint
     * @param array  $params
     * @return array|WP_Error
     */
    private function api_request( $endpoint, $params = array() ) {
        $url = trailingslashit( $this->endpoint ) . $endpoint;
        if ( ! empty( $params ) ) {
            $url = add_query_arg( $params, $url );
        }

        $headers = array();
        if ( ! empty( $this->credentials['email'] ) && ! empty( $this->credentials['api_key'] ) ) {
            $headers['X-Auth-Email'] = $this->credentials['email'];
            $headers['X-Auth-Key']   = $this->credentials['api_key'];
        } elseif ( ! empty( $this->credentials['token'] ) ) {
            $headers['Authorization'] = 'Bearer ' . $this->credentials['token'];
        } else {
            return new WP_Error(
                'invalid_credentials',
                __( 'No valid CloudFlare credentials provided.', 'spintax-domain-manager' )
            );
        }

        $args = array(
            'headers' => $headers,
            'timeout' => 20,
        );

        $response = wp_remote_get( $url, $args );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return new WP_Error(
                'api_error',
                sprintf( __( 'CloudFlare API responded with error code %d', 'spintax-domain-manager' ), $code )
            );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        if ( null === $data ) {
            return new WP_Error(
                'json_decode_error',
                __( 'Error decoding CloudFlare API response.', 'spintax-domain-manager' )
            );
        }
        return $data;
    }

    /**
     * Универсальный метод для HTTP-запросов (POST, PUT, DELETE и т.д.).
     *
     * @param string $endpoint   e.g. "zones/xxx/rulesets"
     * @param array  $query_args e.g. ['per_page'=>50]
     * @param string $method     e.g. 'POST'|'DELETE'
     * @param array|null $body   Тело запроса (если нужно)
     * @return array|WP_Error
     */
    public function api_request_extended( $endpoint, $query_args = array(), $method = 'GET', $body = null ) {
        $url = trailingslashit( $this->endpoint ) . $endpoint;
        if ( ! empty( $query_args ) ) {
            $url = add_query_arg( $query_args, $url );
        }

        $headers = array();
        if ( ! empty( $this->credentials['email'] ) && ! empty( $this->credentials['api_key'] ) ) {
            $headers['X-Auth-Email'] = $this->credentials['email'];
            $headers['X-Auth-Key']   = $this->credentials['api_key'];
        } elseif ( ! empty( $this->credentials['token'] ) ) {
            $headers['Authorization'] = 'Bearer ' . $this->credentials['token'];
        } else {
            return new WP_Error(
                'invalid_credentials',
                __( 'No valid CloudFlare credentials provided.', 'spintax-domain-manager' )
            );
        }

        $args = array(
            'method'  => $method,
            'headers' => $headers,
            'timeout' => 20,
        );
        if ( $body !== null ) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $json = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code < 200 || $code >= 300 ) {
            $message = 'CloudFlare API responded with error code ' . $code;
            if ( isset($json['errors'][0]['message']) ) {
                $message .= ' - ' . $json['errors'][0]['message'];
            }
            return new WP_Error( 'api_error', $message );
        }
        return $json;
    }

    /**
     * Пересоздаёт ruleset(ы) в фазе http_request_dynamic_redirect для заданного zone_id.
     * 1. Удаляет существующие rulesets с нужной фазой.
     * 2. Собирает редиректы из базы (исключая тип "hidden").
     * 3. Создаёт один ruleset с набором правил.
     * Дополнительное логирование добавлено для диагностики.
     *
     * @param string $zone_id
     * @return true|WP_Error
     */
    public function rebuild_redirect_rules( $zone_id ) {
        global $wpdb;
        if ( empty( $zone_id ) ) {
            error_log("rebuild_redirect_rules: No zone_id specified.");
            return new WP_Error( 'no_zone_id', 'No zone_id specified.' );
        }
        error_log("Starting rebuild_redirect_rules for zone: $zone_id");

        // 1) Удаляем существующие rulesets для фазы http_request_dynamic_redirect
        $existing = $this->api_request_extended(
            "zones/$zone_id/rulesets",
            array( 'per_page' => 50 ),
            'GET'
        );
        error_log("Existing rulesets for zone $zone_id: " . print_r($existing, true));
        if ( is_wp_error($existing) ) {
            error_log("Error fetching existing rulesets: " . $existing->get_error_message());
            if ( 'api_error' === $existing->get_error_code() &&
                 false !== strpos( $existing->get_error_message(), '429' ) ) {
                return new WP_Error('cf_rate_limited', 'CloudFlare API 429 (Rate Limit). Try again later.');
            }
            return $existing;
        }
        if ( ! empty($existing['result']) && is_array($existing['result']) ) {
            foreach ( $existing['result'] as $ruleset ) {
                if ( isset($ruleset['phase']) && $ruleset['phase'] === 'http_request_dynamic_redirect' ) {
                    error_log("Deleting ruleset ID {$ruleset['id']} for zone $zone_id");
                    $delete_resp = $this->api_request_extended(
                        "zones/$zone_id/rulesets/" . $ruleset['id'],
                        array(),
                        'DELETE'
                    );
                    if ( is_wp_error($delete_resp) ) {
                        error_log("Error deleting ruleset ID {$ruleset['id']}: " . $delete_resp->get_error_message());
                        if ( 'api_error' === $delete_resp->get_error_code() &&
                             false !== strpos( $delete_resp->get_error_message(), '429' ) ) {
                            return new WP_Error('cf_rate_limited', 'CloudFlare 429 (Rate Limit) on delete.');
                        }
                        return $delete_resp;
                    }
                    error_log("Deleted ruleset ID {$ruleset['id']} successfully.");
                }
            }
        }

        // 2) Собираем редиректы из базы (исключая hidden)
        $prefix = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare("
            SELECT r.*
            FROM {$prefix}sdm_redirects r
            JOIN {$prefix}sdm_domains d ON r.domain_id = d.id
            WHERE d.cf_zone_id = %s
              AND r.redirect_type != 'hidden'
        ", $zone_id ) );
        error_log("Fetched " . count($rows) . " redirect rows for zone $zone_id.");
        if ( empty($rows) ) {
            error_log("No redirects found for zone $zone_id.");
            return true;
        }

        // 3) Формируем массив правил.
        $rules = array();
        foreach ( $rows as $row ) {
            $final_target = $row->target_url;
            if ( 'glue' === $row->redirect_type ) {
                $final_target = preg_replace('#/\*$#', '/', rtrim($final_target, '/') . '/');
            } else {
                $final_target = preg_replace('#/\*$#', '', rtrim($final_target, '/'));
            }
            // Вместо использования matches, применяем eq – это означает, что правило сработает только если путь запроса равен "/"
            $expression = '(http.request.uri.path eq "/")';
            $rule = array(
                'action'      => 'redirect',
                'description' => "SDM domain_id={$row->domain_id}",
                'expression'  => $expression,
                'action_parameters' => array(
                    'from_value' => array(
                        'target_url' => array(
                            'value' => $final_target,
                        ),
                        'status_code'           => (int) $row->type,
                        'preserve_query_string' => (bool) $row->preserve_query_string,
                    ),
                ),
            );
            $rules[] = $rule;
            error_log("Prepared rule for domain_id {$row->domain_id}: " . print_r($rule, true));
        }
        if ( empty($rules) ) {
            error_log("No valid rules prepared for zone $zone_id.");
            return true;
        }

        // 4) Создаём новый ruleset с собранными правилами
        $body = array(
            'name'        => 'SDM Rebuilt Redirects ' . date('Y-m-d H:i:s'),
            'description' => 'All WP sdm_redirects re-created by rebuild_redirect_rules()',
            'kind'        => 'zone',
            'phase'       => 'http_request_dynamic_redirect',
            'rules'       => $rules,
        );
        error_log("Creating new ruleset for zone $zone_id with payload: " . print_r($body, true));
        $create_resp = $this->api_request_extended(
            "zones/$zone_id/rulesets",
            array(),
            'POST',
            $body
        );
        if ( is_wp_error($create_resp) ) {
            error_log("Error creating ruleset for zone $zone_id: " . $create_resp->get_error_message());
            if ( 'api_error' === $create_resp->get_error_code() &&
                 false !== strpos($create_resp->get_error_message(), '429') ) {
                return new WP_Error('cf_rate_limited', 'CloudFlare 429 (Rate Limit) on create.');
            }
            return $create_resp;
        }
        error_log("Create response for zone $zone_id: " . print_r($create_resp, true));
        if ( empty($create_resp['result']) || empty($create_resp['result']['id']) ) {
            error_log("No valid ruleset ID returned for zone $zone_id.");
            return new WP_Error( 'ruleset_create_failed', 'No valid ruleset created in CloudFlare response.' );
        }
        error_log("Successfully created ruleset ID " . $create_resp['result']['id'] . " for zone $zone_id.");
        return true;
    }


    /**
     * Статический метод для получения расшифрованных креденшелов CloudFlare.
     * Ищет запись в таблице sdm_accounts для заданного проекта и сервиса.
     *
     * @param int $project_id
     * @param int $service_id  ID сервиса CloudFlare (например, 1).
     * @return array|WP_Error Массив вида ['email'=>'...', 'api_key'=>'...'] или WP_Error.
     */
    public static function get_project_cf_credentials( $project_id, $service_id = 1 ) {
        global $wpdb;
        $account = $wpdb->get_row( $wpdb->prepare("
            SELECT * 
            FROM {$wpdb->prefix}sdm_accounts
            WHERE project_id = %d
              AND service_id = %d
            LIMIT 1
        ", $project_id, $service_id ) );
        if ( ! $account ) {
            return new WP_Error( 'no_cf_account', "No CloudFlare account found for project #{$project_id}." );
        }
        if ( empty( $account->email ) || empty( $account->api_key_enc ) ) {
            return new WP_Error( 'invalid_cf_account', 'CloudFlare account missing email or api_key_enc.' );
        }
        $decrypted_key = sdm_decrypt( $account->api_key_enc );
        if ( ! $decrypted_key ) {
            return new WP_Error( 'decrypt_error', 'Failed to decrypt CloudFlare API key.' );
        }
        return array(
            'email'   => $account->email,
            'api_key' => $decrypted_key,
        );
    }
}
