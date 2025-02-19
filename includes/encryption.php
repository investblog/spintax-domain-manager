<?php
/**
 * Handles encryption and decryption for sensitive data.
 *
 * Functions:
 *  - sdm_get_encryption_key(): retrieves the encryption key from the plugin settings.
 *  - sdm_encrypt($data): encrypts data using AES-256-CBC.
 *  - sdm_decrypt($encryptedData): decrypts data.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get the encryption key from the plugin settings.
 *
 * @return string Encryption key.
 */
function sdm_get_encryption_key() {
    return get_option( 'sdm_encryption_key', '' );
}

/**
 * Encrypt the given data.
 *
 * @param string $data Data to encrypt.
 * @return string Encrypted data (base64 encoded).
 */
function sdm_encrypt( $data ) {
    $key = sdm_get_encryption_key();
    if ( empty( $key ) ) {
        return $data; // Return original data if no key is provided.
    }
    $ivlen = openssl_cipher_iv_length( $cipher = "AES-256-CBC" );
    $iv = openssl_random_pseudo_bytes( $ivlen );
    $ciphertext_raw = openssl_encrypt( $data, $cipher, $key, OPENSSL_RAW_DATA, $iv );
    $hmac = hash_hmac( 'sha256', $ciphertext_raw, $key, true );
    return base64_encode( $iv . $hmac . $ciphertext_raw );
}

/**
 * Decrypt the given data.
 *
 * @param string $encryptedData Encrypted data (base64 encoded).
 * @return string|false Decrypted data or false on failure.
 */
function sdm_decrypt( $encryptedData ) {
    $key = sdm_get_encryption_key();
    if ( empty( $key ) ) {
        return $encryptedData;
    }
    $c = base64_decode( $encryptedData );
    $ivlen = openssl_cipher_iv_length( $cipher = "AES-256-CBC" );
    $iv = substr( $c, 0, $ivlen );
    $hmac = substr( $c, $ivlen, $sha2len = 32 );
    $ciphertext_raw = substr( $c, $ivlen + $sha2len );
    $original_data = openssl_decrypt( $ciphertext_raw, $cipher, $key, OPENSSL_RAW_DATA, $iv );
    $calcmac = hash_hmac( 'sha256', $ciphertext_raw, $key, true );
    if ( hash_equals( $hmac, $calcmac ) ) {
        return $original_data;
    }
    return false;
}
