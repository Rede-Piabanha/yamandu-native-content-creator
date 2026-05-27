<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'YAMANDU_Ajax' ) ) {

    final class YAMANDU_Ajax {

        private $core;
        private $utils;
        private $api_client;
        private $generator;

        public function __construct( $core ) {
            $this->core       = $core;
            $this->utils      = is_object( $core ) && method_exists( $core, 'utils' ) ? $core->utils() : null;
            $this->api_client = is_object( $core ) && method_exists( $core, 'api_client' ) ? $core->api_client() : null;
            $this->generator  = is_object( $core ) && method_exists( $core, 'generator' ) ? $core->generator() : null;

            add_action( 'wp_ajax_yamandu_generate', array( $this, 'ajax_generate' ) );
            add_action( 'wp_ajax_yamandu_generate_image', array( $this, 'ajax_generate_image' ) );
            add_action( 'wp_ajax_yamandu_generate_text', array( $this, 'ajax_generate_text' ) );
            add_action( 'wp_ajax_yamandu_validate_key', array( $this, 'ajax_validate_key' ) );
            add_action( 'wp_ajax_yamandu_remove_key', array( $this, 'ajax_remove_key' ) );

            add_action( 'admin_post_yamandu_single', array( $this, 'handle_single_admin_post' ) );
        }

        public function ajax_generate() {
            if ( ! current_user_can( 'upload_files' ) ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'You do not have permission to perform this action.', 'yamandu-native-ai-content-creator' ),
                    ),
                    403
                );
            }

            check_ajax_referer( 'yamandu_generate', 'nonce' );

            $attachment_id = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;
            if ( $attachment_id <= 0 ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'Invalid attachment ID.', 'yamandu-native-ai-content-creator' ),
                    ),
                    400
                );
            }

            if ( ! wp_attachment_is_image( $attachment_id ) ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'The selected attachment is not an image.', 'yamandu-native-ai-content-creator' ),
                    ),
                    400
                );
            }

            if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'You do not have permission to edit this attachment.', 'yamandu-native-ai-content-creator' ),
                    ),
                    403
                );
            }

            $overwrite_raw = isset( $_POST['overwrite'] ) ? sanitize_text_field( wp_unslash( $_POST['overwrite'] ) ) : '0';
            $overwrite     = $overwrite_raw === '1';

            $fields_limit = array( 'title', 'alt' );

            $fields_request = filter_input( INPUT_POST, 'fields', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );
            if ( is_array( $fields_request ) ) {
                $tmp = array();
                foreach ( $fields_request as $field_value ) {
                    $field_value = sanitize_key( (string) $field_value );
                    if ( in_array( $field_value, array( 'title', 'alt' ), true ) ) {
                        $tmp[] = $field_value;
                    }
                }
                if ( ! empty( $tmp ) ) {
                    $fields_limit = array_values( array_unique( $tmp ) );
                }
            } else {
                $fields_scalar = filter_input( INPUT_POST, 'fields', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
                if ( is_string( $fields_scalar ) && $fields_scalar !== '' ) {
                    $parts = preg_split( '/\s*,\s*/', $fields_scalar );
                    $tmp   = array();
                    foreach ( (array) $parts as $field_value ) {
                        $field_value = sanitize_key( (string) $field_value );
                        if ( in_array( $field_value, array( 'title', 'alt' ), true ) ) {
                            $tmp[] = $field_value;
                        }
                    }
                    if ( ! empty( $tmp ) ) {
                        $fields_limit = array_values( array_unique( $tmp ) );
                    }
                }
            }

            $options = $this->options();
            if ( ! $this->third_party_requests_enabled( $options ) ) {
                wp_send_json_error(
                    array(
                        'message' => $this->third_party_requests_disabled_message(),
                    ),
                    400
                );
            }

            $api_key = isset( $options['api_key'] ) ? trim( (string) $options['api_key'] ) : '';
            if ( $api_key === '' ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'API key is not configured.', 'yamandu-native-ai-content-creator' ),
                    ),
                    400
                );
            }

            if ( ! is_object( $this->generator ) || ! method_exists( $this->generator, 'analyze_and_update_attachment' ) ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'Generator is not available.', 'yamandu-native-ai-content-creator' ),
                    ),
                    500
                );
            }

            $result = $this->generator->analyze_and_update_attachment( $attachment_id, $options, $overwrite, $fields_limit );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error(
                    array(
                        'message' => $result->get_error_message(),
                    ),
                    500
                );
            }

            $updated = isset( $result['updated'] ) ? (int) $result['updated'] : 0;

            $post = get_post( $attachment_id );
            $payload = array(
                'attachment_id' => $attachment_id,
                'updated'       => $updated,
                'fields'        => array(
                    'title' => $post ? (string) $post->post_title : '',
                    'alt'   => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
                ),
            );

            wp_send_json_success( $payload );
        }


        public function ajax_generate_image() {
            if ( ! current_user_can( 'upload_files' ) ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'You do not have permission to perform this action.', 'yamandu-native-ai-content-creator' ),
                    ),
                    403
                );
            }

            check_ajax_referer( 'yamandu_generate', 'nonce' );

            $prompt = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
            $prompt = trim( preg_replace( '/\s+/', ' ', $prompt ) );

            if ( $prompt === '' ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'Image prompt is missing.', 'yamandu-native-ai-content-creator' ),
                    ),
                    400
                );
            }

            $attachment_id = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;
            if ( $attachment_id > 0 && ( ! wp_attachment_is_image( $attachment_id ) || ! current_user_can( 'edit_post', $attachment_id ) ) ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'The selected attachment is not an editable image.', 'yamandu-native-ai-content-creator' ),
                    ),
                    403
                );
            }

            $options = $this->options();
            if ( ! $this->third_party_requests_enabled( $options ) ) {
                wp_send_json_error(
                    array(
                        'message' => $this->third_party_requests_disabled_message(),
                    ),
                    400
                );
            }

            $api_key = isset( $options['api_key'] ) ? trim( (string) $options['api_key'] ) : '';
            if ( $api_key === '' ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'API key is not configured.', 'yamandu-native-ai-content-creator' ),
                    ),
                    400
                );
            }

            if ( ! is_object( $this->generator ) || ! method_exists( $this->generator, 'generate_image_attachment' ) ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'Image generator is not available.', 'yamandu-native-ai-content-creator' ),
                    ),
                    500
                );
            }

            $result = $this->generator->generate_image_attachment( $prompt, $options, $attachment_id );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error(
                    array(
                        'message' => $result->get_error_message(),
                    ),
                    500
                );
            }

            wp_send_json_success( $result );
        }

        public function ajax_generate_text() {
            if ( ! current_user_can( 'edit_posts' ) ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'You do not have permission to perform this action.', 'yamandu-native-ai-content-creator' ),
                    ),
                    403
                );
            }

            check_ajax_referer( 'yamandu_generate', 'nonce' );

            $post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
            if ( $post_id > 0 ) {
                $post = get_post( $post_id );
                if ( ! $post || (string) $post->post_type !== 'post' || ! current_user_can( 'edit_post', $post_id ) ) {
                    wp_send_json_error(
                        array(
                            'message' => __( 'You do not have permission to edit this post.', 'yamandu-native-ai-content-creator' ),
                        ),
                        403
                    );
                }
            }

            $prompt = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
            $prompt = trim( preg_replace( '/\s+/', ' ', $prompt ) );

            if ( $prompt === '' ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'Text prompt is missing.', 'yamandu-native-ai-content-creator' ),
                    ),
                    400
                );
            }

            $selection = isset( $_POST['selection'] ) ? sanitize_textarea_field( wp_unslash( $_POST['selection'] ) ) : '';
            $selection = trim( preg_replace( '/\s+/', ' ', $selection ) );

            $options = $this->options();
            if ( ! $this->third_party_requests_enabled( $options ) ) {
                wp_send_json_error(
                    array(
                        'message' => $this->third_party_requests_disabled_message(),
                    ),
                    400
                );
            }

            $api_key = isset( $options['api_key'] ) ? trim( (string) $options['api_key'] ) : '';
            if ( $api_key === '' ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'API key is not configured.', 'yamandu-native-ai-content-creator' ),
                    ),
                    400
                );
            }

            if ( ! is_object( $this->generator ) || ! method_exists( $this->generator, 'generate_post_text' ) ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'Text generator is not available.', 'yamandu-native-ai-content-creator' ),
                    ),
                    500
                );
            }

            $result = $this->generator->generate_post_text( $prompt, $options, $post_id, $selection );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error(
                    array(
                        'message' => $result->get_error_message(),
                    ),
                    500
                );
            }

            wp_send_json_success(
                array(
                    'post_id' => $post_id,
                    'text'    => (string) $result,
                )
            );
        }

        public function ajax_validate_key() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'You do not have permission to perform this action.', 'yamandu-native-ai-content-creator' ),
                    ),
                    403
                );
            }

            if ( ! check_ajax_referer( 'yamandu_validate_key', 'nonce', false ) ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'Invalid nonce.', 'yamandu-native-ai-content-creator' ),
                    ),
                    403
                );
            }

            $api_key = '';
            if ( isset( $_POST['api_key'] ) ) {
                $api_key = sanitize_text_field( wp_unslash( $_POST['api_key'] ) );
                $api_key = trim( $api_key );
            }

            if ( $api_key === '' ) {
                $opts = $this->options();
                $api_key = isset( $opts['api_key'] ) ? trim( (string) $opts['api_key'] ) : '';
            }

            if ( $api_key === '' ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'Enter an API key to validate it.', 'yamandu-native-ai-content-creator' ),
                    ),
                    400
                );
            }

            $opts = $this->options();
            if ( ! $this->third_party_requests_enabled( $opts ) ) {
                wp_send_json_error(
                    array(
                        'message' => $this->third_party_requests_disabled_message(),
                    ),
                    400
                );
            }

            if ( ! is_object( $this->api_client ) || ! method_exists( $this->api_client, 'validate_api_key_remote' ) ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'API client is not available.', 'yamandu-native-ai-content-creator' ),
                    ),
                    500
                );
            }

            $validated = $this->api_client->validate_api_key_remote( $api_key );

            $is_valid = is_array( $validated ) && ! empty( $validated['valid'] );
            $message  = is_array( $validated ) && isset( $validated['message'] ) ? (string) $validated['message'] : '';

            $models = array();
            if ( is_array( $validated ) && ! empty( $validated['models'] ) && is_array( $validated['models'] ) ) {
                $models = array_values( array_unique( array_filter( array_map( 'strval', $validated['models'] ) ) ) );
                sort( $models, SORT_STRING );
            }

            $persisted = 0;
            $saved_key = isset( $opts['api_key'] ) ? trim( (string) $opts['api_key'] ) : '';

            if ( $is_valid ) {
                $opts['api_key']       = $api_key;
                $opts['api_validated'] = 1;
                $opts['api_key_hash']  = $this->hash_api_key( $api_key );
                update_option( $this->option_name(), $opts, false );
                $this->clear_gemini_models_cache( $api_key );
                $persisted = 1;
            } elseif ( $saved_key !== '' && hash_equals( $saved_key, $api_key ) ) {
                $opts['api_validated'] = 0;
                update_option( $this->option_name(), $opts, false );
                $persisted = 1;
            }

            if ( $is_valid ) {
                wp_send_json_success(
                    array(
                        'valid'     => 1,
                        'message'   => $message !== '' ? $message : __( 'API key validated successfully.', 'yamandu-native-ai-content-creator' ),
                        'models'    => $models,
                        'persisted' => $persisted,
                    )
                );
            }

            if ( $saved_key !== '' && hash_equals( $saved_key, $api_key ) ) {
                $opts['api_key_hash']  = '';
                $opts['api_validated'] = 0;
                update_option( $this->option_name(), $opts, false );
                $this->clear_gemini_models_cache( $api_key );
                $persisted = 1;
            }

            wp_send_json_success(
                array(
                    'valid'     => 0,
                    'message'   => $message !== '' ? $message : __( 'API key validation failed.', 'yamandu-native-ai-content-creator' ),
                    'models'    => $models,
                    'persisted' => $persisted,
                )
            );
        }

        public function ajax_remove_key() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'You do not have permission to perform this action.', 'yamandu-native-ai-content-creator' ),
                    ),
                    403
                );
            }

            if ( ! check_ajax_referer( 'yamandu_validate_key', 'nonce', false ) ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'Invalid nonce.', 'yamandu-native-ai-content-creator' ),
                    ),
                    403
                );
            }
            $opts      = $this->options();
            $saved_key = isset( $opts['api_key'] ) ? trim( (string) $opts['api_key'] ) : '';

            $opts['api_key']       = '';
            $opts['api_key_hash']  = '';
            $opts['api_validated'] = 0;

            update_option( $this->option_name(), $opts, false );

            $this->clear_gemini_models_cache( $saved_key );

            wp_send_json_success(
                array(
                    'message' => __( 'API key removed.', 'yamandu-native-ai-content-creator' ),
                )
            );
        }

        public function handle_single_admin_post() {
            if ( ! current_user_can( 'upload_files' ) ) {
                wp_die( esc_html__( 'You do not have permission to perform this action.', 'yamandu-native-ai-content-creator' ) );
            }

            check_admin_referer( 'yamandu_single' );

            $attachment_id = isset( $_GET['attachment_id'] ) ? absint( wp_unslash( $_GET['attachment_id'] ) ) : 0;
            $mode          = isset( $_GET['mode'] ) ? sanitize_key( (string) wp_unslash( $_GET['mode'] ) ) : 'generate';
            $redirect_to   = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';

            $fallback = admin_url( 'upload.php' );
            $redirect_to = $redirect_to !== '' ? wp_validate_redirect( $redirect_to, $fallback ) : $fallback;

            if ( $attachment_id <= 0 || ! wp_attachment_is_image( $attachment_id ) ) {
                $redirect_to = add_query_arg(
                    $this->add_notice_query_args( array(
                        'yamandu_notice' => 'single_error',
                        'yamandu_error'  => rawurlencode( __( 'Invalid image attachment.', 'yamandu-native-ai-content-creator' ) ),
                    ) ),
                    $redirect_to
                );
                wp_safe_redirect( $redirect_to );
                exit;
            }

            if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
                $redirect_to = add_query_arg(
                    $this->add_notice_query_args( array(
                        'yamandu_notice' => 'single_error',
                        'yamandu_error'  => rawurlencode( __( 'You do not have permission to edit this attachment.', 'yamandu-native-ai-content-creator' ) ),
                    ) ),
                    $redirect_to
                );
                wp_safe_redirect( $redirect_to );
                exit;
            }

            $options   = $this->options();
            $overwrite = $mode === 'regenerate';

            if ( ! $this->third_party_requests_enabled( $options ) ) {
                $redirect_to = add_query_arg(
                    $this->add_notice_query_args( array(
                        'yamandu_notice' => 'single_error',
                        'yamandu_error'  => rawurlencode( $this->third_party_requests_disabled_message() ),
                    ) ),
                    $redirect_to
                );
                wp_safe_redirect( $redirect_to );
                exit;
            }

            $api_key = isset( $options['api_key'] ) ? trim( (string) $options['api_key'] ) : '';
            if ( $api_key === '' ) {
                $redirect_to = add_query_arg(
                    $this->add_notice_query_args( array(
                        'yamandu_notice' => 'single_error',
                        'yamandu_error'  => rawurlencode( __( 'API key is not configured.', 'yamandu-native-ai-content-creator' ) ),
                    ) ),
                    $redirect_to
                );
                wp_safe_redirect( $redirect_to );
                exit;
            }

            if ( ! is_object( $this->generator ) || ! method_exists( $this->generator, 'analyze_and_update_attachment' ) ) {
                $redirect_to = add_query_arg(
                    $this->add_notice_query_args( array(
                        'yamandu_notice' => 'single_error',
                        'yamandu_error'  => rawurlencode( __( 'Generator is not available.', 'yamandu-native-ai-content-creator' ) ),
                    ) ),
                    $redirect_to
                );
                wp_safe_redirect( $redirect_to );
                exit;
            }

            $fields_all = array( 'title', 'alt' );
            $result = $this->generator->analyze_and_update_attachment( $attachment_id, $options, $overwrite, $fields_all );

            if ( is_wp_error( $result ) ) {
                $redirect_to = add_query_arg(
                    $this->add_notice_query_args( array(
                        'yamandu_notice' => 'single_error',
                        'yamandu_error'  => rawurlencode( $result->get_error_message() ),
                    ) ),
                    $redirect_to
                );
                wp_safe_redirect( $redirect_to );
                exit;
            }

            $updated = isset( $result['updated'] ) ? (int) $result['updated'] : 0;

            $redirect_to = add_query_arg(
                $this->add_notice_query_args( array(
                    'yamandu_notice' => $updated > 0 ? 'single_ok' : 'single_noop',
                ) ),
                $redirect_to
            );

            wp_safe_redirect( $redirect_to );
            exit;
        }
        private function option_name() {
            if ( is_object( $this->core ) && method_exists( $this->core, 'option_name' ) ) {
                return (string) $this->core->option_name();
            }
            return 'yamandu_options';
        }

        private function options() {
            if ( is_object( $this->core ) && method_exists( $this->core, 'options' ) ) {
                $o = $this->core->options();
                return is_array( $o ) ? $o : array();
            }
            $o = get_option( $this->option_name(), array() );
            return is_array( $o ) ? $o : array();
        }

        private function third_party_requests_enabled( $options = null ) {
            if ( ! is_array( $options ) ) {
                $options = $this->options();
            }

            return ! empty( $options['enable_third_party_requests'] );
        }

        private function third_party_requests_disabled_message() {
            return __( 'Third-party requests are disabled. Enable consent in the plugin settings to continue.', 'yamandu-native-ai-content-creator' );
        }

        private function hash_api_key( $api_key ) {
            $api_key = trim( (string) $api_key );
            if ( $api_key === '' ) {
                return '';
            }
            $salt = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : ( defined( 'AUTH_SALT' ) ? (string) AUTH_SALT : '' );
            return hash_hmac( 'sha256', $api_key, $salt );
        }

        private function add_notice_query_args( $args ) {
            $args = is_array( $args ) ? $args : array();
            $args['yamandu_notice_nonce'] = wp_create_nonce( 'yamandu_admin_notice' );
            return $args;
        }

        private function clear_gemini_models_cache( $api_key ) {
            $api_key = is_string( $api_key ) ? trim( $api_key ) : '';
            $hash    = $api_key !== '' ? substr( hash( 'sha256', $api_key ), 0, 12 ) : 'no_key';
            delete_transient( 'yamandu_gemini_models' );
            delete_transient( 'yamandu_gemini_models_' . $hash );
        }
    }
}
