<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$yamandu_options = get_option( 'yamandu_options', array() );
$yamandu_options = is_array( $yamandu_options ) ? $yamandu_options : array();

if ( empty( $yamandu_options['delete_data_on_uninstall'] ) ) {
    return;
}

delete_option( 'yamandu_options' );
delete_transient( 'yamandu_gemini_models' );

$yamandu_api_key = isset( $yamandu_options['api_key'] ) ? trim( (string) $yamandu_options['api_key'] ) : '';
if ( $yamandu_api_key !== '' ) {
    delete_transient( 'yamandu_gemini_models_' . substr( hash( 'sha256', $yamandu_api_key ), 0, 12 ) );
}

$yamandu_api_key_hash = isset( $yamandu_options['api_key_hash'] ) ? trim( (string) $yamandu_options['api_key_hash'] ) : '';
if ( $yamandu_api_key_hash !== '' ) {
    delete_transient( 'yamandu_best_model_' . substr( $yamandu_api_key_hash, 0, 12 ) );
}
