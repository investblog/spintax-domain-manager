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
     * CloudFlare API endpoint.
     */
    private $endpoint = 'https://api.cloudflare.com/client/v4/';

    /**
     * API credentials.
     *
     * @var array
     */
    private $credentials;

    /**
     * Constructor.
     *
     * @param array $credentials Array with keys:
     *                           - email (optional)
     *                           - api_key (optional)
     *                           - token (optional)
     */
    public function __construct( $credentials = array() ) {
        $this->credentials = $credentials;
    }

    /**
     * Checks the CloudFlare account by attempting to fetch zones.
     *
     * @return WP_Error|int Returns number of zones on success or WP_Error on failure.
     */
    public function check_account() {
        $result = $this->get_zones();
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return count( $result );
    }

    /**
     * Retrieves all zones (domains) from CloudFlare with pagination.
     *
     * @param int $per_page Number of zones per request. Default 50.
     * @return array|WP_Error Array of zones on success or WP_Error on failure.
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
                return new WP_Error( 'invalid_response', __( 'Invalid response from CloudFlare API.', 'spintax-domain-manager' ) );
            }

            $zones = $response['result'];
            $all_zones = array_merge( $all_zones, $zones );
            $total_pages = isset( $response['result_info']['total_pages'] ) ? intval( $response['result_info']['total_pages'] ) : 1;
            $page++;

        } while ( $page <= $total_pages );

        return $all_zones;
    }

    /**
     * Makes a request to CloudFlare API.
     *
     * @param string $endpoint Endpoint (relative to base URL).
     * @param array  $params   GET parameters.
     * @return array|WP_Error Response array on success or WP_Error on failure.
     */
    private function api_request( $endpoint, $params = array() ) {
        $url = trailingslashit( $this->endpoint ) . $endpoint;

        if ( ! empty( $params ) ) {
            $url = add_query_arg( $params, $url );
        }

        // Prepare headers for authentication.
        $headers = array();

        if ( ! empty( $this->credentials['email'] ) && ! empty( $this->credentials['api_key'] ) ) {
            // Используем email + API ключ
            $headers['X-Auth-Email'] = $this->credentials['email'];
            $headers['X-Auth-Key']   = $this->credentials['api_key'];
        } elseif ( ! empty( $this->credentials['token'] ) ) {
            // Используем API Token
            $headers['Authorization'] = 'Bearer ' . $this->credentials['token'];
        } else {
            return new WP_Error( 'invalid_credentials', __( 'No valid CloudFlare credentials provided.', 'spintax-domain-manager' ) );
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
            return new WP_Error( 'api_error', sprintf( __( 'CloudFlare API responded with error code %d', 'spintax-domain-manager' ), $code ) );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        if ( null === $data ) {
            return new WP_Error( 'json_decode_error', __( 'Error decoding CloudFlare API response.', 'spintax-domain-manager' ) );
        }

        return $data;
    }
}
