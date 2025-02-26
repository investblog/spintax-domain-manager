<?php
/**
 * Normalize language code for Flag Icons.
 */
function sdm_normalize_language_code( $language_code ) {
    if ( empty( $language_code ) ) {
        return 'us';
    }
    $normalized = strtolower( substr( $language_code, 0, 2 ) );
    $mappings = array(
        'ru' => 'ru',
        'en' => 'us',
        'es' => 'es',
        'fr' => 'fr',
        'pl' => 'pl',
    );
    return isset( $mappings[$normalized] ) ? $mappings[$normalized] : $normalized;
}
?>