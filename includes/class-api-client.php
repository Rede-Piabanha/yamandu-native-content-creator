<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'YAMANDU_API_Client' ) ) {

    final class YAMANDU_API_Client {

        private $core;
        private $utils;

        public function __construct( $core ) {
            $this->core  = $core;
            $this->utils = is_object( $core ) && method_exists( $core, 'utils' ) ? $core->utils() : null;
        }

        public function core() {
            return $this->core;
        }

        public function utils() {
            return $this->utils;
        }

        private function get_option_value( $key, $default = null ) {
            if ( is_object( $this->core ) && method_exists( $this->core, 'options' ) ) {
                $opts = $this->core->options();
                return is_array( $opts ) && array_key_exists( $key, $opts ) ? $opts[ $key ] : $default;
            }
            $opt_name = is_object( $this->core ) && method_exists( $this->core, 'option_name' ) ? $this->core->option_name() : 'yamandu_options';
            $opts = get_option( $opt_name, array() );
            $opts = is_array( $opts ) ? $opts : array();
            return array_key_exists( $key, $opts ) ? $opts[ $key ] : $default;
        }

        private function get_api_key( $override = '' ) {
            $override = is_string( $override ) ? trim( $override ) : '';
            if ( $override !== '' ) {
                return $override;
            }

            $candidates = array(
                'api_key',
                'google_api_key',
                'vision_api_key',
                'gemini_api_key',
                'google_cloud_api_key',
            );

            foreach ( $candidates as $k ) {
                $v = $this->get_option_value( $k, '' );
                $v = is_string( $v ) ? trim( $v ) : '';
                if ( $v !== '' ) {
                    return $v;
                }
            }

            return '';
        }

        private function get_gemini_model() {
            $model = $this->get_option_value( 'model', '' );
            $model = is_string( $model ) ? trim( $model ) : '';
            if ( $model === '' ) {
                $model = 'gemini-2.5-flash';
            }
            if ( is_object( $this->utils ) && method_exists( $this->utils, 'sanitize_model' ) ) {
                $model = $this->utils->sanitize_model( $model );
            }
            return $model;
        }

        private function request_json( $method, $url, $body = null, $headers = array(), $timeout = 60 ) {
            $method = strtoupper( (string) $method );

            $consent = $this->assert_third_party_requests_enabled();
            if ( is_wp_error( $consent ) ) {
                return $consent;
            }

            $args = array(
                'timeout'     => (int) $timeout,
                'redirection' => 2,
                'sslverify'   => true,
                'headers'     => array_merge(
                    array(
                        'Accept'       => 'application/json',
                        'Content-Type' => 'application/json; charset=utf-8',
                    ),
                    is_array( $headers ) ? $headers : array()
                ),
            );

            if ( $body !== null ) {
                $args['body'] = wp_json_encode( $body );
            }

            if ( $method === 'GET' ) {
                $res = wp_safe_remote_get( $url, $args );
            } else {
                $args['method'] = $method;
                $res = wp_safe_remote_request( $url, $args );
            }

            if ( is_wp_error( $res ) ) {
                return $res;
            }

            $code = (int) wp_remote_retrieve_response_code( $res );
            $raw  = wp_remote_retrieve_body( $res );
            $raw  = is_string( $raw ) ? $raw : '';

            $decoded = null;
            if ( $raw !== '' ) {
                $decoded = json_decode( $raw, true );
            }

            if ( $code < 200 || $code >= 300 ) {
                $msg = is_array( $decoded ) ? wp_json_encode( $decoded ) : $raw;
                $msg = is_string( $msg ) ? $msg : '';
                if ( $msg === '' ) {
                    $msg = __( 'Remote request failed.', 'yamandu-native-ai-content-creator' );
                }
                $safe_url = $this->redact_url( $url );
                return new WP_Error(
                    'yamandu_remote_http_error',
                    $msg,
                    array(
                        'status' => $code,
                        'url'    => $safe_url,
                        'body'   => $raw,
                    )
                );
            }

            if ( $raw !== '' && ! is_array( $decoded ) ) {
                $safe_url = $this->redact_url( $url );
                return new WP_Error(
                    'yamandu_remote_invalid_json',
                    __( 'Remote service returned an invalid JSON response.', 'yamandu-native-ai-content-creator' ),
                    array(
                        'status' => $code,
                        'url'    => $safe_url,
                        'body'   => $raw,
                    )
                );
            }

            return is_array( $decoded ) ? $decoded : array();
        }
        
        private function redact_url( $url ) {
            $url = (string) $url;
            if ( $url === '' ) {
                return $url;
            }
        
            $url = preg_replace( '#([?&](?:key|api_key)=)[^&]+#i', '$1REDACTED', $url );
        
            return $url;
        }


        private function third_party_requests_enabled() {
            return ! empty( $this->get_option_value( 'enable_third_party_requests', 0 ) );
        }

        private function third_party_requests_disabled_message() {
            return __( 'Third-party requests are disabled. Enable consent in the plugin settings to continue.', 'yamandu-native-ai-content-creator' );
        }

        private function assert_third_party_requests_enabled() {
            if ( $this->third_party_requests_enabled() ) {
                return true;
            }

            return new WP_Error(
                'yamandu_third_party_requests_disabled',
                $this->third_party_requests_disabled_message()
            );
        }

        public function gemini_api_request( $path, $payload = array(), $args = array() ) {
            $path = is_string( $path ) ? trim( $path ) : '';
            $path = ltrim( $path, '/' );

            $api_key = '';
            if ( is_array( $args ) && array_key_exists( 'api_key', $args ) ) {
                $api_key = is_string( $args['api_key'] ) ? trim( $args['api_key'] ) : '';
            }
            $api_key = $this->get_api_key( $api_key );

            if ( $api_key === '' ) {
                return new WP_Error( 'yamandu_missing_api_key', __( 'API key is missing.', 'yamandu-native-ai-content-creator' ) );
            }

            $base = 'https://generativelanguage.googleapis.com/';
            $ver  = 'v1beta';

            if ( is_array( $args ) && array_key_exists( 'version', $args ) ) {
                $v = is_string( $args['version'] ) ? trim( $args['version'] ) : '';
                if ( $v !== '' ) {
                    $ver = $v;
                }
            }

            $base = apply_filters( 'yamandu_gemini_api_base', $base );
            $ver  = apply_filters( 'yamandu_gemini_api_version', $ver );

            $timeout = 60;
            if ( is_array( $args ) && array_key_exists( 'timeout', $args ) ) {
                $timeout = (int) $args['timeout'];
                if ( $timeout < 10 ) {
                    $timeout = 10;
                }
            }

            $method = 'POST';
            if ( is_array( $args ) && array_key_exists( 'method', $args ) ) {
                $m = strtoupper( (string) $args['method'] );
                if ( in_array( $m, array( 'GET', 'POST' ), true ) ) {
                    $method = $m;
                }
            }

            $url = rtrim( $base, '/' ) . '/' . $ver . '/' . $path;

            $body = $method === 'GET' ? null : $payload;
            
            return $this->request_json(
                $method,
                $url,
                $body,
                array(
                    'x-goog-api-key' => $api_key,
                ),
                $timeout
            );
        }

        public function call_gemini_api( $context_or_payload, $model = '', $args = array() ) {
            $model = is_string( $model ) ? trim( $model ) : '';
            if ( $model === '' ) {
                $model = $this->get_gemini_model();
            }

            $payload = array();

            if ( is_array( $context_or_payload ) ) {
                $payload = $context_or_payload;
            } else {
                $prompt = is_string( $context_or_payload ) ? $context_or_payload : '';
                $payload = array(
                    'contents' => array(
                        array(
                            'role'  => 'user',
                            'parts' => array(
                                array(
                                    'text' => $prompt,
                                ),
                            ),
                        ),
                    ),
                );
            }

            if ( is_array( $args ) && array_key_exists( 'generationConfig', $args ) && is_array( $args['generationConfig'] ) ) {
                $payload['generationConfig'] = $args['generationConfig'];
            }

            if ( is_array( $args ) && array_key_exists( 'safetySettings', $args ) && is_array( $args['safetySettings'] ) ) {
                $payload['safetySettings'] = $args['safetySettings'];
            }

            if ( is_array( $args ) && array_key_exists( 'tools', $args ) && is_array( $args['tools'] ) ) {
                $payload['tools'] = $args['tools'];
            }

            if ( is_array( $args ) && array_key_exists( 'systemInstruction', $args ) && is_array( $args['systemInstruction'] ) ) {
                $payload['systemInstruction'] = $args['systemInstruction'];
            }

            $path = 'models/' . rawurlencode( $model ) . ':generateContent';

            return $this->gemini_api_request(
                $path,
                $payload,
                array(
                    'api_key'  => is_array( $args ) && array_key_exists( 'api_key', $args ) ? $args['api_key'] : '',
                    'timeout'  => is_array( $args ) && array_key_exists( 'timeout', $args ) ? $args['timeout'] : 60,
                    'version'  => is_array( $args ) && array_key_exists( 'version', $args ) ? $args['version'] : 'v1beta',
                    'method'   => 'POST',
                )
            );
        }


        public function call_image_generation_api( $prompt, $args = array() ) {
            $prompt = is_string( $prompt ) ? trim( wp_strip_all_tags( $prompt ) ) : '';

            if ( $prompt === '' ) {
                return new WP_Error( 'yamandu_missing_image_prompt', __( 'Image prompt is missing.', 'yamandu-native-ai-content-creator' ) );
            }

            $model = 'gemini-2.5-flash-image';
            if ( is_array( $args ) && array_key_exists( 'model', $args ) ) {
                $m = is_string( $args['model'] ) ? trim( $args['model'] ) : '';
                if ( $m !== '' ) {
                    $model = $m;
                }
            }

            $model = apply_filters( 'yamandu_image_generation_model', $model, $prompt, $args, $this );
            $model = $this->normalize_image_generation_model( $model );

            if ( $this->is_imagen_generation_model( $model ) ) {
                return $this->call_imagen_generation_api( $prompt, $model, $args );
            }

            $image_config = array(
                'aspectRatio' => '16:9',
            );

            if ( $model !== 'gemini-2.5-flash-image' ) {
                $image_config['imageSize'] = '2K';
            }

            $payload = array(
                'contents'         => array(
                    array(
                        'role'  => 'user',
                        'parts' => array(
                            array(
                                'text' => $prompt,
                            ),
                        ),
                    ),
                ),
                'generationConfig' => array(
                    'responseModalities' => array( 'IMAGE' ),
                    'candidateCount'     => 1,
                    'imageConfig'        => $image_config,
                ),
            );

            $res = $this->gemini_api_request(
                'models/' . rawurlencode( $model ) . ':generateContent',
                $payload,
                array(
                    'api_key' => is_array( $args ) && array_key_exists( 'api_key', $args ) ? $args['api_key'] : '',
                    'timeout' => is_array( $args ) && array_key_exists( 'timeout', $args ) ? $args['timeout'] : 120,
                    'version' => is_array( $args ) && array_key_exists( 'version', $args ) ? $args['version'] : 'v1beta',
                    'method'  => 'POST',
                )
            );

            if ( is_wp_error( $res ) ) {
                return $res;
            }

            return $this->extract_generated_image_from_response( $res );
        }

        private function call_imagen_generation_api( $prompt, $model, $args = array() ) {
            $payload = array(
                'instances'  => array(
                    array(
                        'prompt' => $prompt,
                    ),
                ),
                'parameters' => array(
                    'sampleCount' => 1,
                    'aspectRatio' => '16:9',
                    'imageSize'   => '2K',
                ),
            );

            $res = $this->gemini_api_request(
                'models/' . rawurlencode( $model ) . ':predict',
                $payload,
                array(
                    'api_key' => is_array( $args ) && array_key_exists( 'api_key', $args ) ? $args['api_key'] : '',
                    'timeout' => is_array( $args ) && array_key_exists( 'timeout', $args ) ? $args['timeout'] : 120,
                    'version' => is_array( $args ) && array_key_exists( 'version', $args ) ? $args['version'] : 'v1beta',
                    'method'  => 'POST',
                )
            );

            if ( is_wp_error( $res ) ) {
                return $res;
            }

            return $this->extract_generated_image_from_response( $res );
        }

        private function normalize_image_generation_model( $model ) {
            $model = is_string( $model ) ? trim( $model ) : '';
            if ( is_object( $this->utils ) && method_exists( $this->utils, 'sanitize_model' ) ) {
                $model = $this->utils->sanitize_model( $model );
            } else {
                $model = preg_replace( '/[^a-zA-Z0-9._:-]/', '', $model );
            }

            $allowed = array(
                'gemini-2.5-flash-image',
                'gemini-3.1-flash-image-preview',
                'imagen-4.0-generate-001',
            );

            return in_array( $model, $allowed, true ) ? $model : 'gemini-2.5-flash-image';
        }

        private function is_imagen_generation_model( $model ) {
            return is_string( $model ) && strpos( $model, 'imagen-' ) === 0;
        }
        private function extract_generated_image_from_response( $res ) {
            if ( isset( $res['predictions'] ) && is_array( $res['predictions'] ) ) {
                foreach ( $res['predictions'] as $prediction ) {
                    if ( ! is_array( $prediction ) ) {
                        continue;
                    }

                    $data = isset( $prediction['bytesBase64Encoded'] ) ? (string) $prediction['bytesBase64Encoded'] : '';
                    if ( $data === '' && isset( $prediction['bytes_base64_encoded'] ) ) {
                        $data = (string) $prediction['bytes_base64_encoded'];
                    }
                    if ( $data === '' && isset( $prediction['image']['bytesBase64Encoded'] ) ) {
                        $data = (string) $prediction['image']['bytesBase64Encoded'];
                    }
                    if ( $data === '' && isset( $prediction['image']['imageBytes'] ) ) {
                        $data = (string) $prediction['image']['imageBytes'];
                    }
                    if ( $data === '' && isset( $prediction['imageBytes'] ) ) {
                        $data = (string) $prediction['imageBytes'];
                    }

                    if ( $data !== '' ) {
                        $mime = isset( $prediction['mimeType'] ) ? (string) $prediction['mimeType'] : '';
                        if ( $mime === '' && isset( $prediction['mime_type'] ) ) {
                            $mime = (string) $prediction['mime_type'];
                        }
                        if ( $mime === '' && isset( $prediction['image']['mimeType'] ) ) {
                            $mime = (string) $prediction['image']['mimeType'];
                        }

                        return array(
                            'data'      => $data,
                            'mime_type' => $mime !== '' ? $mime : 'image/png',
                        );
                    }
                }
            }

            if ( isset( $res['generatedImages'] ) && is_array( $res['generatedImages'] ) ) {
                foreach ( $res['generatedImages'] as $generated_image ) {
                    if ( ! is_array( $generated_image ) ) {
                        continue;
                    }

                    $data = isset( $generated_image['image']['imageBytes'] ) ? (string) $generated_image['image']['imageBytes'] : '';
                    if ( $data === '' && isset( $generated_image['imageBytes'] ) ) {
                        $data = (string) $generated_image['imageBytes'];
                    }

                    if ( $data !== '' ) {
                        $mime = isset( $generated_image['image']['mimeType'] ) ? (string) $generated_image['image']['mimeType'] : '';
                        return array(
                            'data'      => $data,
                            'mime_type' => $mime !== '' ? $mime : 'image/png',
                        );
                    }
                }
            }

            $candidates = isset( $res['candidates'] ) && is_array( $res['candidates'] ) ? $res['candidates'] : array();

            foreach ( $candidates as $candidate ) {
                if ( ! is_array( $candidate ) ) {
                    continue;
                }

                $parts = isset( $candidate['content']['parts'] ) && is_array( $candidate['content']['parts'] ) ? $candidate['content']['parts'] : array();

                foreach ( $parts as $part ) {
                    if ( ! is_array( $part ) ) {
                        continue;
                    }

                    $inline = array();
                    if ( isset( $part['inlineData'] ) && is_array( $part['inlineData'] ) ) {
                        $inline = $part['inlineData'];
                    } elseif ( isset( $part['inline_data'] ) && is_array( $part['inline_data'] ) ) {
                        $inline = $part['inline_data'];
                    }

                    if ( empty( $inline ) ) {
                        continue;
                    }

                    $data = isset( $inline['data'] ) ? (string) $inline['data'] : '';

                    if ( $data === '' ) {
                        continue;
                    }

                    $mime = isset( $inline['mimeType'] ) ? (string) $inline['mimeType'] : '';
                    if ( $mime === '' && isset( $inline['mime_type'] ) ) {
                        $mime = (string) $inline['mime_type'];
                    }

                    return array(
                        'data'      => $data,
                        'mime_type' => $mime !== '' ? $mime : 'image/png',
                    );
                }
            }

            return new WP_Error( 'yamandu_no_generated_image', __( 'The remote service did not return an image.', 'yamandu-native-ai-content-creator' ) );
        }

        public function call_vision_api( $image_content_base64, $features = array(), $image_context = array(), $args = array() ) {
            $api_key = '';
            if ( is_array( $args ) && array_key_exists( 'api_key', $args ) ) {
                $api_key = is_string( $args['api_key'] ) ? trim( $args['api_key'] ) : '';
            }
            $api_key = $this->get_api_key( $api_key );

            if ( $api_key === '' ) {
                return new WP_Error( 'yamandu_missing_api_key', __( 'API key is missing.', 'yamandu-native-ai-content-creator' ) );
            }

            $base = 'https://vision.googleapis.com/v1/images:annotate';
            $base = apply_filters( 'yamandu_vision_api_base', $base );

            $timeout = 60;
            if ( is_array( $args ) && array_key_exists( 'timeout', $args ) ) {
                $timeout = (int) $args['timeout'];
                if ( $timeout < 10 ) {
                    $timeout = 10;
                }
            }

            $img = is_string( $image_content_base64 ) ? trim( $image_content_base64 ) : '';
            if ( $img === '' ) {
                return new WP_Error( 'yamandu_missing_image', __( 'Image content is missing.', 'yamandu-native-ai-content-creator' ) );
            }

            $features = is_array( $features ) ? $features : array();

            if ( empty( $features ) ) {
                $features = array(
                    array( 'type' => 'LABEL_DETECTION', 'maxResults' => 10 ),
                    array( 'type' => 'WEB_DETECTION', 'maxResults' => 10 ),
                );
            }

            $req = array(
                'requests' => array(
                    array(
                        'image'    => array( 'content' => $img ),
                        'features' => $features,
                    ),
                ),
            );

            if ( is_array( $image_context ) && ! empty( $image_context ) ) {
                $req['requests'][0]['imageContext'] = $image_context;
            }

            $url = $base;

            return $this->request_json(
                'POST',
                $url,
                $req,
                array(
                    'x-goog-api-key' => $api_key,
                ),
                $timeout
            );
        }

        public function validate_api_key_remote( $api_key = '' ) {
            if ( ! $this->third_party_requests_enabled() ) {
                return array(
                    'valid'   => false,
                    'message' => $this->third_party_requests_disabled_message(),
                );
            }

            $api_key = $this->get_api_key( $api_key );

            if ( $api_key === '' ) {
                return array(
                    'valid'   => false,
                    'message' => __( 'API key is missing.', 'yamandu-native-ai-content-creator' ),
                );
            }

            $models = $this->get_available_gemini_models( true, $api_key );

            if ( ! is_wp_error( $models ) ) {
                return array(
                    'valid'   => true,
                    'message' => __( 'API key validated successfully.', 'yamandu-native-ai-content-creator' ),
                    'models'  => $models,
                );
            }

            $payload = array(
                'contents' => array(
                    array(
                        'role'  => 'user',
                        'parts' => array(
                            array(
                                'text' => 'ping',
                            ),
                        ),
                    ),
                ),
            );

            $model_try = $this->get_gemini_model();

            $res = $this->call_gemini_api(
                $payload,
                $model_try,
                array(
                    'api_key' => $api_key,
                    'timeout' => 15,
                    'version' => 'v1beta',
                )
            );

            if ( is_wp_error( $res ) ) {
                $fallback_model = 'gemini-2.5-flash';

                if ( $model_try !== $fallback_model ) {
                    $res2 = $this->call_gemini_api(
                        $payload,
                        $fallback_model,
                        array(
                            'api_key' => $api_key,
                            'timeout' => 15,
                            'version' => 'v1beta',
                        )
                    );

                    if ( ! is_wp_error( $res2 ) ) {
                        return array(
                            'valid'   => true,
                            'message' => __( 'API key validated successfully.', 'yamandu-native-ai-content-creator' ),
                            'models'  => array( $fallback_model ),
                        );
                    }
                }

                $msg = $res->get_error_message();
                if ( ! is_string( $msg ) || trim( $msg ) === '' ) {
                    $msg = $models->get_error_message();
                }
                if ( ! is_string( $msg ) || trim( $msg ) === '' ) {
                    $msg = __( 'API key validation failed.', 'yamandu-native-ai-content-creator' );
                }

                return array(
                    'valid'   => false,
                    'message' => $msg,
                );
            }

            return array(
                'valid'   => true,
                'message' => __( 'API key validated successfully.', 'yamandu-native-ai-content-creator' ),
                'models'  => array( $model_try ),
            );
        }

        public function get_available_gemini_models( $force_refresh = false, $api_key = '' ) {
            $api_key   = is_string( $api_key ) ? trim( $api_key ) : '';
            $hash      = $api_key !== '' ? substr( hash( 'sha256', $api_key ), 0, 12 ) : 'no_key';
            $cache_key = 'yamandu_gemini_models_' . $hash;
            $cached    = get_transient( $cache_key );

            if ( ! $force_refresh && is_array( $cached ) && ! empty( $cached ) ) {
                return $cached;
            }

            $res = $this->gemini_api_request(
                'models',
                array(),
                array(
                    'api_key' => $api_key,
                    'timeout' => 30,
                    'version' => 'v1beta',
                    'method'  => 'GET',
                )
            );

            if ( is_wp_error( $res ) ) {
                $res2 = $this->gemini_api_request(
                    'models',
                    array(),
                    array(
                        'api_key' => $api_key,
                        'timeout' => 30,
                        'version' => 'v1',
                        'method'  => 'GET',
                    )
                );
                if ( ! is_wp_error( $res2 ) ) {
                    $res = $res2;
                }
            }

            if ( is_wp_error( $res ) ) {
                $fallback = array(
                    'gemini-2.5-flash',
                    'gemini-2.5-flash-lite',
                    'gemini-2.5-pro',
                );
                if ( $force_refresh ) {
                    return $res;
                }
                return $fallback;
            }

            $models = array();

            if ( isset( $res['models'] ) && is_array( $res['models'] ) ) {
                foreach ( $res['models'] as $m ) {
                    if ( ! is_array( $m ) ) {
                        continue;
                    }

                    $name = isset( $m['name'] ) && is_string( $m['name'] ) ? trim( $m['name'] ) : '';
                    if ( $name === '' ) {
                        continue;
                    }

                    $name = preg_replace( '#^models/#', '', $name );

                    $methods = isset( $m['supportedGenerationMethods'] ) && is_array( $m['supportedGenerationMethods'] ) ? $m['supportedGenerationMethods'] : array();
                    if ( ! empty( $methods ) && ! in_array( 'generateContent', $methods, true ) ) {
                        continue;
                    }

                    $models[] = $name;
                }
            }

            $models = array_values( array_unique( array_filter( $models ) ) );

            $allowed_models = array( 'gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-2.5-pro' );
            $models         = array_values( array_intersect( $allowed_models, $models ) );

            if ( empty( $models ) ) {
                $models = array(
                    'gemini-2.5-flash',
                    'gemini-2.5-flash-lite',
                    'gemini-2.5-pro',
                );
            }

            set_transient( $cache_key, $models, 12 * HOUR_IN_SECONDS );

            return $models;
        }
    }
}
