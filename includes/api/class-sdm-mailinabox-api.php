<?php
/**
 * File: includes/api/class-sdm-mailinabox-api.php
 * Description: Provides functions to interact with Mail-in-a-Box (mail user API).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SDM_Mailinabox_API {

    /**
     * Адрес сервера Mail‑in‑a‑Box, например: https://box.mailrouting.site
     * @var string
     */
    private $server_url;

    /**
     * Админский email (указанный при установке Mail-in-a-Box).
     * @var string
     */
    private $admin_email;

    /**
     * Пароль админского email (указанный при установке).
     * @var string
     */
    private $admin_password;

    /**
     * Конструктор.
     *
     * @param string $server_url    Полный URL сервера (https://box.mailrouting.site).
     * @param string $admin_email   Админский email на сервере Mail-in-a-Box.
     * @param string $admin_password Пароль к админскому email.
     */
    public function __construct( $server_url, $admin_email, $admin_password ) {
        // Убедимся, что в server_url есть https://
        if ( ! preg_match('#^https?://#', $server_url) ) {
            $server_url = 'https://' . $server_url;
        }
        // Удаляем завершающий слеш, чтобы аккуратно формировать endpoint
        $this->server_url     = rtrim($server_url, '/');
        $this->admin_email    = $admin_email;
        $this->admin_password = $admin_password;
    }

    /**
     * Создаёт нового пользователя (почтовый ящик).
     *
     * @param string $email    Адрес нового ящика (например, domain.com@box.mailrouting.site).
     * @param string $password Пароль для этого ящика.
     * @return true|WP_Error   true при успехе или WP_Error при ошибке.
     */
    public function add_user( $email, $password ) {
        $endpoint = $this->server_url . '/admin/mail/users/add';

        // Параметры в формате application/x-www-form-urlencoded
        $body = array(
            'email'    => $email,
            'password' => $password,
        );

        $args = array(
            'method'  => 'POST',
            'timeout' => 20,
            'headers' => array(
                // Basic Auth: Authorization: Basic base64_encode("adminEmail:adminPassword")
                'Authorization' => 'Basic ' . base64_encode( $this->admin_email . ':' . $this->admin_password ),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ),
            'body'    => http_build_query( $body ),
        );

        $response = wp_remote_request( $endpoint, $args );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error(
                'mailinabox_api_error',
                sprintf( 'Mail-in-a-Box API responded with status %d', $code )
            );
        }

        // При успехе API обычно возвращает HTML или JSON.
        // Можно проверить тело, если нужно:
        // $body_response = wp_remote_retrieve_body($response);

        return true;
    }

    /**
     * Удаляет пользователя (почтовый ящик).
     *
     * @param string $email Адрес ящика, который нужно удалить.
     * @return true|WP_Error
     */
    public function remove_user( $email ) {
        $endpoint = $this->server_url . '/admin/mail/users/remove';

        $body = array(
            'email' => $email,
        );

        $args = array(
            'method'  => 'POST',
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( $this->admin_email . ':' . $this->admin_password ),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ),
            'body'    => http_build_query( $body ),
        );

        $response = wp_remote_request( $endpoint, $args );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error(
                'mailinabox_api_error',
                sprintf( 'Mail-in-a-Box API responded with status %d', $code )
            );
        }
        return true;
    }

    /**
     * Делает пользователя админом.
     *
     * @param string $email
     * @return true|WP_Error
     */
    public function add_admin_privilege( $email ) {
        $endpoint = $this->server_url . '/admin/mail/users/privileges/add';

        $body = array(
            'email'     => $email,
            'privilege' => 'admin',
        );

        $args = array(
            'method'  => 'POST',
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( $this->admin_email . ':' . $this->admin_password ),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ),
            'body'    => http_build_query( $body ),
        );

        $response = wp_remote_request( $endpoint, $args );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error(
                'mailinabox_api_error',
                sprintf( 'Mail-in-a-Box API responded with status %d', $code )
            );
        }
        return true;
    }

    /**
     * Убирает у пользователя права админа.
     *
     * @param string $email
     * @return true|WP_Error
     */
    public function remove_admin_privilege( $email ) {
        $endpoint = $this->server_url . '/admin/mail/users/privileges/remove';

        $body = array(
            'email' => $email,
        );

        $args = array(
            'method'  => 'POST',
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( $this->admin_email . ':' . $this->admin_password ),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ),
            'body'    => http_build_query( $body ),
        );

        $response = wp_remote_request( $endpoint, $args );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error(
                'mailinabox_api_error',
                sprintf( 'Mail-in-a-Box API responded with status %d', $code )
            );
        }
        return true;
    }
}
