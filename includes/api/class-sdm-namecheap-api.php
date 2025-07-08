<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Spintax Domain Manager — Namecheap API wrapper (fixed).
 *
 * Public methods mirror the response contract of other SDM API classes:
 * they always return an array with at least `success` and `message`; raw
 * decoded XML may be attached under `raw` or `nameservers`.
 */
class SDM_Namecheap_API {

    /** @var string */
    private $endpoint;
    /** @var string */
    private $user;
    /** @var string */
    private $api_key;
    /** @var string */
    private $client_ip;

    /**
     * @param array $creds Expected keys: username, api_key, api_ip (optional)
     */
    public function __construct( array $creds = array() ) {
        $this->endpoint = ( defined( 'SDM_NAMECHEAP_SANDBOX' ) && SDM_NAMECHEAP_SANDBOX )
            ? 'https://api.sandbox.namecheap.com/xml.response'
            : 'https://api.namecheap.com/xml.response';

        $this->user      = isset( $creds['username'] ) ? sanitize_text_field( $creds['username'] ) : '';
        $this->api_key   = isset( $creds['api_key'] ) ? $creds['api_key'] : '';
        $this->client_ip = ! empty( $creds['api_ip'] ) ? $creds['api_ip'] : ( $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1' );
    }

    /* --------------------------------------------------------------------- */
    /*  Public helpers                                                       */
    /* --------------------------------------------------------------------- */

    public static function get_required_fields() {
        return array( 'username', 'api_key' );
    }

    public static function get_optional_fields() {
        return array( 'api_ip' );
    }

    /** Lightweight credential check via Users.getBalances */
    public function check_credentials() {
        $response = $this->api_request( array(
            'Command'  => 'namecheap.users.getBalances',
            'ClientIp' => $this->client_ip,
        ) );

        if ( is_wp_error( $response ) ) {
            return array( 'success' => false, 'message' => $response->get_error_message() );
        }

        $status = $this->is_ok( $response );
        return array(
            'success' => $status,
            'message' => $status
                ? __( 'Credentials are valid.', 'spintax-domain-manager' )
                : $this->extract_errors( $response ),
            'raw' => $response,
        );
    }

    /** Change nameservers */
    public function set_nameservers( $domain, array $nameservers ) {
        $response = $this->api_request( array(
            'Command'     => 'namecheap.domains.dns.setCustom',
            'SLD'         => $this->sld( $domain ),
            'TLD'         => $this->tld( $domain ),
            'Nameservers' => implode( ',', $nameservers ),
            'ClientIp'    => $this->client_ip,
        ) );

        if ( is_wp_error( $response ) ) {
            return array( 'success' => false, 'message' => $response->get_error_message() );
        }

        $status = $this->is_ok( $response );
        return array(
            'success' => $status,
            'message' => $status
                ? __( 'Nameservers updated.', 'spintax-domain-manager' )
                : $this->extract_errors( $response ),
            'raw' => $response,
        );
    }

    /** Get current nameservers */
    public function get_nameservers( $domain ) {
        $response = $this->api_request( array(
            'Command'  => 'namecheap.domains.dns.getList',
            'SLD'      => $this->sld( $domain ),
            'TLD'      => $this->tld( $domain ),
            'ClientIp' => $this->client_ip,
        ) );

        if ( is_wp_error( $response ) ) {
            return array( 'success' => false, 'message' => $response->get_error_message() );
        }

        $status = $this->is_ok( $response );
        $list   = array();
        if ( $status && isset( $response['CommandResponse']['DomainDNSGetListResult']['Nameserver'] ) ) {
            $raw  = $response['CommandResponse']['DomainDNSGetListResult']['Nameserver'];
            $list = is_array( $raw ) ? $raw : array( $raw );
        }

        return array(
            'success'     => $status,
            'nameservers' => $list,
            'message'     => $status
                ? __( 'Nameservers retrieved.', 'spintax-domain-manager' )
                : $this->extract_errors( $response ),
            'raw'         => $response,
        );
    }

    /* --------------------------------------------------------------------- */
    /*  Internal helpers                                                     */
    /* --------------------------------------------------------------------- */

    /** Check APIResponse Status="OK" */
    private function is_ok( array $response ) {
        return isset( $response['@attributes']['Status'] ) && 'OK' === $response['@attributes']['Status'];
    }

    /** Perform API request and convert XML → array */
    private function api_request( array $body ) {
        $defaults = array(
            'ApiUser'  => $this->user,
            'ApiKey'   => $this->api_key,
            'UserName' => $this->user,
            'ClientIp' => $this->client_ip,
        );

        $http = wp_remote_post( $this->endpoint, array(
            'timeout' => 20,
            'body'    => array_merge( $defaults, $body ),
        ) );

        if ( is_wp_error( $http ) ) {
            return $http;
        }

        if ( 200 !== wp_remote_retrieve_response_code( $http ) ) {
            return new WP_Error( 'http', __( 'Unexpected HTTP status.', 'spintax-domain-manager' ) );
        }

        $xml = simplexml_load_string( wp_remote_retrieve_body( $http ), 'SimpleXMLElement', LIBXML_NOCDATA );
        if ( ! $xml ) {
            return new WP_Error( 'xml', __( 'Unable to parse Namecheap XML.', 'spintax-domain-manager' ) );
        }

        return json_decode( wp_json_encode( $xml ), true );
    }

    private function sld( $domain ) {
        $parts = explode( '.', $domain );
        return $parts[0];
    }

    private function tld( $domain ) {
        $parts = explode( '.', $domain );
        array_shift( $parts );
        return implode( '.', $parts );
    }

    /** Extract readable errors */
    private function extract_errors( array $response ) {
        if ( empty( $response['Errors']['Error'] ) ) {
            return __( 'Unknown error.', 'spintax-domain-manager' );
        }

        $errs = $response['Errors']['Error'];
        if ( ! is_array( $errs ) ) {
            $errs = array( $errs );
        }

        $msgs = array();
        foreach ( $errs as $err ) {
            // after json_encode+decode <Error Number="2019166">text</Error>
            if ( isset( $err['@attributes']['Number'] ) ) {
                $msgs[] = sprintf( '#%s: %s', $err['@attributes']['Number'], $err['@attributes']['Text'] ?? ( is_string( $err ) ? $err : '' ) );
            } elseif ( is_string( $err ) ) {
                $msgs[] = $err;
            }
        }
        return implode( '\n', $msgs );
    }
}
