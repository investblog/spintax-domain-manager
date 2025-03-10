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
     * Удаляет все Page Rules в указанной зоне.
     *
     * @param string $zone_id Идентификатор зоны.
     * @return true|WP_Error
     */
    public function delete_sdm_page_rules( $zone_id ) {
        $per_page = 50;
        $page = 1;
        $errors = [];

        do {
            $list_resp = $this->api_request_extended(
                "zones/{$zone_id}/pagerules",
                ['per_page' => $per_page, 'page' => $page],
                'GET'
            );

            if ( is_wp_error($list_resp) ) {
                error_log("Failed to list Page Rules for zone {$zone_id}: " . $list_resp->get_error_message());
                return $list_resp;
            }

            if ( empty($list_resp['result']) ) {
                break;
            }

            foreach ( $list_resp['result'] as $pageRule ) {
                $rule_id = $pageRule['id'];
                $target_value = $pageRule['targets'][0]['constraint']['value'] ?? 'unknown';
                $desc = $pageRule['description'] ?? '';
                error_log("Deleting Page Rule {$rule_id} with target '{$target_value}' and description '{$desc}'");
                $delete_resp = $this->api_request_extended(
                    "zones/{$zone_id}/pagerules/{$rule_id}",
                    [],
                    'DELETE'
                );
                if ( is_wp_error($delete_resp) ) {
                    $errors[] = "Failed to delete Page Rule {$rule_id}: " . $delete_resp->get_error_message();
                    continue;
                }
            }

            $total_pages = isset($list_resp['result_info']['total_pages']) ? (int) $list_resp['result_info']['total_pages'] : 1;
            $page++;
        } while ( $page <= $total_pages );

        if ( !empty($errors) ) {
            return new WP_Error( 'partial_delete', implode(' | ', $errors) );
        }
        return true;
    }

    /**
     * Создает Page Rule для редиректа.
     *
     * @param string $zone_id        Идентификатор зоны в CloudFlare.
     * @param string $sourcePattern  Маска источника (например, "https://old-domain.com/*").
     * @param string $targetUrl      Целевой URL, например "https://new-domain.com/$1".
     * @param int    $status_code    HTTP‑статус редиректа (301 или 302).
     * @param string $description    Подпись (description) для Page Rule (например, "SDM domain_id=XX").
     *
     * @return array|WP_Error        Результат запроса к API или WP_Error.
     */
    public function create_page_rule( $zone_id, $sourcePattern, $targetUrl, $status_code = 301, $description = '' ) {
        $payload = array(
            "targets" => array(
                array(
                    "target"     => "url",
                    "constraint" => array(
                        "operator" => "matches",
                        "value"    => $sourcePattern
                    )
                )
            ),
            "actions" => array(
                array(
                    "id"    => "forwarding_url",
                    "value" => array(
                        "url"         => $targetUrl,
                        "status_code" => $status_code
                    )
                )
            ),
            "status" => "active",
        );

        // Добавим description, чтобы отличать наши правила
        if ( ! empty($description) ) {
            $payload['description'] = $description;
        }

        $url = trailingslashit( $this->endpoint ) . "zones/{$zone_id}/pagerules";

        $headers = array();
        if ( ! empty( $this->credentials['email'] ) && ! empty( $this->credentials['api_key'] ) ) {
            $headers['X-Auth-Email'] = $this->credentials['email'];
            $headers['X-Auth-Key']   = $this->credentials['api_key'];
        } elseif ( ! empty( $this->credentials['token'] ) ) {
            $headers['Authorization'] = 'Bearer ' . $this->credentials['token'];
        } else {
            return new WP_Error( 'invalid_credentials', __( 'No valid CloudFlare credentials provided.', 'spintax-domain-manager' ) );
        }
        $headers['Content-Type'] = 'application/json';

        $args = array(
            'method'  => 'POST',
            'headers' => $headers,
            'body'    => wp_json_encode( $payload ),
            'timeout' => 20,
        );

        $response = wp_remote_request( $url, $args );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code( $response );
        $json = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            $message = 'CloudFlare API responded with error code ' . $code;
            if ( isset( $json['errors'][0]['message'] ) ) {
                $message .= ' - ' . $json['errors'][0]['message'];
            }
            return new WP_Error( 'api_error', $message );
        }
        return $json;
    }

    /**
     * Пересоздаёт ruleset(ы) в фазе http_request_dynamic_redirect (Glue‑редиректы).
     * (Осталось без изменений, если у вас уже работало).
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

        // 1) Удаляем существующие rulesets (фаза http_request_dynamic_redirect)
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
                    $delete_resp = $this->api_request_extended(
                        "zones/$zone_id/rulesets/" . $ruleset['id'],
                        array(),
                        'DELETE'
                    );
                    if ( is_wp_error($delete_resp) ) {
                        if ( 'api_error' === $delete_resp->get_error_code() &&
                             false !== strpos( $delete_resp->get_error_message(), '429' ) ) {
                            return new WP_Error('cf_rate_limited', 'CloudFlare 429 (Rate Limit) on delete.');
                        }
                        return $delete_resp;
                    }
                }
            }
        }

        // 2) Собираем glue‑редиректы (redirect_type='glue') (исключая hidden)
        $prefix = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare("
            SELECT r.*
            FROM {$prefix}sdm_redirects r
            JOIN {$prefix}sdm_domains d ON r.domain_id = d.id
            WHERE d.cf_zone_id = %s
              AND r.redirect_type = 'glue'
        ", $zone_id ) );
        if ( empty($rows) ) {
            // Нет glue‑редиректов
            return true;
        }

        // 3) Формируем rules
        $rules = array();
        foreach ( $rows as $row ) {
            $final_target = $row->target_url;
            // etc... (Логика не менялась)
            // Для примера: используем eq "/"
            $expression = '(http.request.uri.path eq "/")';

            $rules[] = array(
                'action'      => 'redirect',
                'description' => "SDM domain_id={$row->domain_id}",
                'expression'  => $expression,
                'action_parameters' => array(
                    'from_value' => array(
                        'target_url' => array('value' => rtrim($final_target, '/')),
                        'status_code'           => (int) $row->type,
                        'preserve_query_string' => (bool) $row->preserve_query_string,
                    ),
                ),
            );
        }
        if ( empty($rules) ) {
            return true;
        }

        // 4) Создаём новый ruleset
        $body = array(
            'name'        => 'SDM Rebuilt Redirects ' . date('Y-m-d H:i:s'),
            'description' => 'All WP sdm_redirects re-created by rebuild_redirect_rules() for GLUE',
            'kind'        => 'zone',
            'phase'       => 'http_request_dynamic_redirect',
            'rules'       => $rules,
        );
        $create_resp = $this->api_request_extended(
            "zones/$zone_id/rulesets",
            array(),
            'POST',
            $body
        );
        if ( is_wp_error($create_resp) ) {
            return $create_resp;
        }
        if ( empty($create_resp['result']) || empty($create_resp['result']['id']) ) {
            return new WP_Error( 'ruleset_create_failed', 'No valid ruleset created in CloudFlare response.' );
        }
        return true;
    }


    /**
     * Статический метод для получения расшифрованных креденшалов CloudFlare.
     *
     * @param int $project_id
     * @param int $service_id  ID сервиса CloudFlare (например, 1 для API Key, другой для OAuth).
     * @return array|WP_Error
     */
    public static function get_project_cf_credentials($project_id, $service_id = 1) {
        global $wpdb;
        $account = $wpdb->get_row($wpdb->prepare("
            SELECT * 
            FROM {$wpdb->prefix}sdm_accounts
            WHERE project_id = %d
              AND service_id = %d
            LIMIT 1
        ", $project_id, $service_id));
        if (!$account) {
            return new WP_Error('no_cf_account', "No CloudFlare account found for project #{$project_id}.");
        }

        $credentials = array();
        if (!empty($account->additional_data_enc)) {
            $decrypted = sdm_decrypt($account->additional_data_enc);
            if ($decrypted !== false) {
                $additional_data = json_decode($decrypted, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($additional_data)) {
                    // Определяем тип сервиса через Service Types Manager для большей точности
                    $service_manager = new SDM_Service_Types_Manager();
                    $service = $service_manager->get_service_by_id($service_id);
                    if ($service && $service->service_name === 'CloudFlare (API Key)') {
                        $credentials['email'] = isset($additional_data['email']) ? $additional_data['email'] : '';
                        $credentials['api_key'] = isset($additional_data['api_key']) ? $additional_data['api_key'] : '';
                    } elseif ($service && $service->service_name === 'CloudFlare (OAuth)') {
                        $credentials['email'] = isset($additional_data['email']) ? $additional_data['email'] : '';
                        $credentials['client_id'] = isset($additional_data['client_id']) ? $additional_data['client_id'] : '';
                        $credentials['client_secret'] = isset($additional_data['client_secret']) ? $additional_data['client_secret'] : '';
                        $credentials['refresh_token'] = isset($additional_data['refresh_token']) ? $additional_data['refresh_token'] : '';
                    } else {
                        return new WP_Error('invalid_service', 'Unknown CloudFlare service type.');
                    }
                }
            }
        }

        // Если данные в additional_data_enc отсутствуют или неполные, используем старые поля
        if (empty($credentials['email']) || ($service && $service->service_name === 'CloudFlare (API Key)' && empty($credentials['api_key']))) {
            if (empty($account->email) || empty($account->api_key_enc)) {
                return new WP_Error('invalid_cf_account', 'CloudFlare account missing email or api_key_enc.');
            }
            $credentials['email'] = $account->email;
            $credentials['api_key'] = sdm_decrypt($account->api_key_enc);
        }

        // Проверяем, заполнены ли необходимые поля для каждого типа сервиса
        if (empty($credentials['email'])) {
            return new WP_Error('invalid_cf_account', 'Missing CloudFlare email.');
        }
        if ($service && $service->service_name === 'CloudFlare (API Key)' && empty($credentials['api_key'])) {
            return new WP_Error('invalid_cf_account', 'Missing CloudFlare API key.');
        }
        if ($service && $service->service_name === 'CloudFlare (OAuth)' && (empty($credentials['client_id']) || empty($credentials['client_secret']) || empty($credentials['refresh_token']))) {
            return new WP_Error('invalid_cf_account', 'Incomplete CloudFlare OAuth credentials (client_id, client_secret, and refresh_token required).');
        }

        return $credentials;
    }

    /**
     * Ищет зону по доменному имени.
     *
     * @param string $domain Доменное имя, по которому ищем зону.
     * @return array|WP_Error Массив с данными зоны или WP_Error, если зона не найдена.
     */
    public function get_zone_by_domain( $domain ) {
        // Получаем все зоны из CloudFlare.
        $zones = $this->get_zones();
        if ( is_wp_error( $zones ) ) {
            return $zones;
        }
        
        // Перебираем зоны и ищем совпадение по имени.
        foreach ( $zones as $zone ) {
            if ( isset( $zone['name'] ) && strtolower( $zone['name'] ) === strtolower( $domain ) ) {
                return $zone;
            }
        }
        
        return new WP_Error( 'zone_not_found', sprintf( __( 'Zone for domain "%s" not found.', 'spintax-domain-manager' ), $domain ) );
    }

        public function add_zone( $domain ) {
        $url = trailingslashit( $this->endpoint ) . 'zones';
        $headers = array();
        if ( ! empty( $this->credentials['email'] ) && ! empty( $this->credentials['api_key'] ) ) {
            $headers['X-Auth-Email'] = $this->credentials['email'];
            $headers['X-Auth-Key']   = $this->credentials['api_key'];
        } elseif ( ! empty( $this->credentials['token'] ) ) {
            $headers['Authorization'] = 'Bearer ' . $this->credentials['token'];
        } else {
            return new WP_Error( 'invalid_credentials', __( 'No valid CloudFlare credentials provided.', 'spintax-domain-manager' ) );
        }
        $headers['Content-Type'] = 'application/json';

        $body = wp_json_encode( array(
            'name'       => $domain,
            'jump_start' => true, // Если хотите сразу начать сканирование DNS-записей
        ) );

        $args = array(
            'method'  => 'POST',
            'headers' => $headers,
            'body'    => $body,
            'timeout' => 20,
        );

        $response = wp_remote_request( $url, $args );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code( $response );
        $json = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code < 200 || $code >= 300 ) {
            $message = sprintf( __( 'CloudFlare API responded with error code %d', 'spintax-domain-manager' ), $code );
            if ( isset( $json['errors'][0]['message'] ) ) {
                $message .= ' - ' . $json['errors'][0]['message'];
            }
            return new WP_Error( 'api_error', $message );
        }
        return $json;
    }


}
