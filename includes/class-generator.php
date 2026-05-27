<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'YAMANDU_Generator' ) ) {

    final class YAMANDU_Generator {

        private $core;
        private $utils;
        private $api_client;
        private $req_id = '';

        public function __construct( $core ) {
            $this->core       = $core;
            $this->utils      = is_object( $core ) && method_exists( $core, 'utils' ) ? $core->utils() : null;
            $this->api_client = is_object( $core ) && method_exists( $core, 'api_client' ) ? $core->api_client() : null;
        }

        public function analyze_and_update_attachment( $attachment_id, $options, $overwrite, $fields_limit ) {

            $attachment_id = absint( $attachment_id );
            $post          = get_post( $attachment_id );

            $this->req_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'yamandu_', true );

            $this->ai_log(
                'request_start',
                array(
                    'attachment_id' => (int) $attachment_id,
                    'overwrite'     => $overwrite ? 1 : 0,
                )
            );

            if ( ! $post || $post->post_type !== 'attachment' ) {
                return new WP_Error( 'not_attachment', __( 'The provided ID is not an attachment.', 'yamandu-native-ai-content-creator' ) );
            }

            $mime = get_post_mime_type( $attachment_id );
            if ( ! $mime || strpos( $mime, 'image/' ) !== 0 ) {
                return new WP_Error( 'not_image', __( 'The provided ID is not an image.', 'yamandu-native-ai-content-creator' ) );
            }

            $image_url = wp_get_attachment_image_url( $attachment_id, 'full' );
            if ( ! $image_url ) {
                return new WP_Error( 'no_url', __( 'Could not retrieve image URL.', 'yamandu-native-ai-content-creator' ) );
            }

            $options = is_array( $options ) ? $options : array();
            $overwrite_generate = ! empty( $options['overwrite_generate'] );

            if ( ! $this->third_party_requests_enabled( $options ) ) {
                return $this->third_party_requests_disabled_error();
            }

            $limit_set = ! empty( $fields_limit );
            $allowed   = array(
                'title' => ! empty( $options['generate_title'] ),
                'alt'   => ! empty( $options['generate_alt'] ),
            );

            $post_title = (string) $post->post_title;
            $alt        = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

            $targets = array(
                'title' => false,
                'alt'   => false,
            );

            if ( $allowed['title'] && ( ! $limit_set || in_array( 'title', (array) $fields_limit, true ) ) ) {
                if ( $overwrite || $overwrite_generate || $post_title === '' ) {
                    $targets['title'] = true;
                }
            }

            if ( $allowed['alt'] && ( ! $limit_set || in_array( 'alt', (array) $fields_limit, true ) ) ) {
                if ( $overwrite || $overwrite_generate || $alt === '' ) {
                    $targets['alt'] = true;
                }
            }

            if ( ! $targets['title'] && ! $targets['alt'] ) {
                return array( 'updated' => 0 );
            }

            $analysis = $this->call_vision_for_attachment( $attachment_id, $image_url, $options );
            if ( is_wp_error( $analysis ) ) {
                return $analysis;
            }

            $lang_info = $this->get_language_info();
            $context   = $this->build_gemini_context( $analysis, $attachment_id, $targets, $lang_info );

            $generated = $this->generate_metadata_with_gemini( $context, $lang_info, $options );
            if ( is_wp_error( $generated ) ) {
                return $generated;
            }

            $title = isset( $generated['title'] ) ? $this->clean_generated_text( wp_strip_all_tags( (string) $generated['title'] ) ) : '';
            $altT  = isset( $generated['alt'] ) ? $this->clean_generated_text( wp_strip_all_tags( (string) $generated['alt'] ) ) : '';

            $title = $this->trim_to_word_boundary( $title, 70 );
            $altT  = $this->trim_to_word_boundary( $altT, 160 );

            $new_meta = array(
                'title' => $targets['title'] && $title !== '' ? $title : '',
                'alt'   => $targets['alt'] && $altT !== '' ? $altT : '',
            );

            $did_any      = false;
            $needs_update = false;

            $update_data = array(
                'ID'         => $attachment_id,
                'post_title' => $post_title,
            );

            if ( $new_meta['alt'] !== '' ) {
                update_post_meta( $attachment_id, '_wp_attachment_image_alt', $new_meta['alt'] );
                $did_any = true;
            }

            if ( $new_meta['title'] !== '' ) {
                $update_data['post_title'] = $new_meta['title'];
                $needs_update              = true;
            }

            if ( $needs_update ) {
                $r = wp_update_post( wp_slash( $update_data ), true );
                if ( is_wp_error( $r ) ) {
                    return $r;
                }
                if ( intval( $r ) <= 0 ) {
                    return new WP_Error( 'wp_update_post_failed', __( 'Failed to update attachment post fields.', 'yamandu-native-ai-content-creator' ) );
                }
                $did_any = true;
            }

            if ( ! $did_any ) {
                return array( 'updated' => 0 );
            }

            clean_post_cache( $attachment_id );

            return array( 'updated' => 1 );
        }

        public function build_gemini_context( $analysis, $attachment_id, $targets, $lang_info ) {
            $labels_simple = array();
            if ( ! empty( $analysis['labels'] ) && is_array( $analysis['labels'] ) ) {
                foreach ( $analysis['labels'] as $label ) {
                    if ( ! empty( $label['description'] ) ) {
                        $labels_simple[] = $label['description'];
                    }
                }
            }

            $web_simple = array();
            if ( ! empty( $analysis['web']['webEntities'] ) && is_array( $analysis['web']['webEntities'] ) ) {
                foreach ( $analysis['web']['webEntities'] as $entity ) {
                    if ( ! empty( $entity['description'] ) ) {
                        $web_simple[] = $entity['description'];
                    }
                }
            }

            $logo_names = array();
            if ( ! empty( $analysis['logos'] ) && is_array( $analysis['logos'] ) ) {
                foreach ( $analysis['logos'] as $logo ) {
                    if ( ! empty( $logo['description'] ) ) {
                        $logo_names[] = $logo['description'];
                    }
                }
            }
            $logo_names = array_values( array_unique( $logo_names ) );

            $post        = get_post( $attachment_id );
            $title       = $post ? $post->post_title : '';
            $alt         = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
            $filename_terms = $this->extract_keywords_from_filename( $attachment_id );

            $requested_fields = array();
            if ( is_array( $targets ) ) {
                foreach ( $targets as $field => $flag ) {
                    if ( $flag ) {
                        $requested_fields[] = $field;
                    }
                }
            }

            return array(
                'siteLanguage'    => ! empty( $lang_info['bcp47'] ) ? $lang_info['bcp47'] : 'en-US',
                'labels'          => $labels_simple,
                'webEntities'     => $web_simple,
                'ocrText'         => isset( $analysis['ocr_text'] ) ? (string) $analysis['ocr_text'] : '',
                'logoNames'       => $logo_names,
                'filenameTerms'   => $filename_terms,
                'attachmentId'    => (int) $attachment_id,
                'existing'        => array(
                    'title' => (string) $title,
                    'alt'   => (string) $alt,
                ),
                'requestedFields' => $requested_fields,
            );
        }

        public function build_gemini_inline_image_part( $attachment_id ) {
            $path = $this->get_best_attachment_ai_path( $attachment_id );
            if ( ! $path ) {
                return null;
            }

            $temp_path = '';
            $max_bytes = 3 * 1024 * 1024;
            $size      = $this->safe_filesize( $path );

            if ( $size && $size > $max_bytes ) {
                $editor = wp_get_image_editor( $path );
                if ( ! is_wp_error( $editor ) ) {
                    $editor->resize( 1200, 1200, false );
                    $tmp = wp_tempnam( 'yamandu' );
                    if ( $tmp ) {
                        $tmp_jpg = $tmp . '.jpg';
                        $this->safe_unlink( $tmp );
                        $saved = $editor->save( $tmp_jpg, 'image/jpeg' );
                        if ( ! is_wp_error( $saved ) && ! empty( $saved['path'] ) && file_exists( $saved['path'] ) ) {
                            $temp_path = $saved['path'];
                            $path      = $temp_path;
                        }
                    }
                }
            }

            if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
                if ( $temp_path ) {
                    $this->safe_unlink( $temp_path );
                }
                return null;
            }

            $bin = $this->safe_file_get_contents( $path );
            if ( $bin === false || $bin === '' ) {
                if ( $temp_path ) {
                    $this->safe_unlink( $temp_path );
                }
                return null;
            }

            $ft   = wp_check_filetype( $path );
            $mime = ! empty( $ft['type'] ) ? $ft['type'] : 'image/jpeg';

            $part = array(
                'inlineData' => array(
                    'mimeType' => $mime,
                    'data'     => base64_encode( $bin ),
                ),
            );

            if ( $temp_path ) {
                $this->safe_unlink( $temp_path );
            }

            return $part;
        }

        public function get_best_attachment_ai_path( $attachment_id ) {
            $full = get_attached_file( $attachment_id );
            if ( ! $full || ! file_exists( $full ) || ! is_readable( $full ) ) {
                return '';
            }

            $meta = wp_get_attachment_metadata( $attachment_id );
            $try  = array( 'medium_large', 'large', 'medium', 'thumbnail' );

            if ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
                foreach ( $try as $s ) {
                    if ( ! empty( $meta['sizes'][ $s ]['file'] ) ) {
                        $p = path_join( dirname( $full ), $meta['sizes'][ $s ]['file'] );
                        if ( file_exists( $p ) && is_readable( $p ) ) {
                            return $p;
                        }
                    }
                }
            }

            return $full;
        }

        private function extract_keywords_from_filename( $attachment_id ) {
            $file_meta = get_post_meta( $attachment_id, '_wp_attached_file', true );
            $keywords  = array();
            if ( ! $file_meta ) {
                return $keywords;
            }

            $base = basename( $file_meta );
            $base = preg_replace( '~\.[a-zA-Z0-9]+$~', '', $base );
            $base = str_replace( array( '_', '-' ), ' ', $base );
            $parts = preg_split( '~\s+~', $base );

            foreach ( $parts as $part ) {
                $part = trim( (string) $part );
                if ( $part === '' || is_numeric( $part ) ) {
                    continue;
                }
                $keywords[] = $this->str_lower( $part );
            }

            return array_values( array_unique( $keywords ) );
        }

        private function str_lower( $string ) {
            if ( function_exists( 'mb_strtolower' ) ) {
                return mb_strtolower( $string );
            }
            return strtolower( $string );
        }

        private function clean_generated_text( $text ) {
            $text = (string) $text;

            $patterns = array(
                'this image was analyzed', 'this image was analysed',
                'labels detected', 'detected labels', 'logos detected', 'detected logos',
                'text detected', 'detected text', 'metadata', 'alt text', 'image description',
                'caption:', 'description:', 'title:',
            );

            foreach ( $patterns as $pattern ) {
                if ( stripos( $text, $pattern ) !== false ) {
                    $text = str_ireplace( $pattern, '', $text );
                }
            }

            $text = preg_replace( '~\s+~', ' ', $text );
            $text = trim( $text );
            $text = preg_replace( "/\r\n|\r|\n/u", ' ', $text );
            $text = preg_replace( "/[ \t]+/u", ' ', $text );
            $text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text );

            return trim( $text );
        }

        private function trim_to_word_boundary( $text, $max_chars, $ellipsis = '…' ) {
            $text = trim( (string) $text );
            if ( $text === '' ) {
                return '';
            }

            $len = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
            if ( $len <= $max_chars ) {
                return $text;
            }

            $cut = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max_chars ) : substr( $text, 0, $max_chars );

            $cut2 = preg_replace( '~[\s\pP]+[^\s\pP]*$~u', '', $cut );
            $cut2 = trim( (string) $cut2 );
            if ( $cut2 === '' ) {
                $cut2 = trim( $cut );
            }

            return rtrim( $cut2 ) . $ellipsis;
        }

        private function get_language_info() {
            $locale = get_locale();
            if ( ! $locale ) {
                $locale = 'en_US';
            }
            $bcp47 = str_replace( '_', '-', $locale );
            $parts = preg_split( '/[-_]/', $locale );
            $base  = isset( $parts[0] ) ? strtolower( (string) $parts[0] ) : 'en';

            return array(
                'locale' => $locale,
                'bcp47'  => $bcp47,
                'base'   => $base,
            );
        }

        private function call_vision_for_attachment( $attachment_id, $image_url, $options ) {
            $options = is_array( $options ) ? $options : array();
            if ( ! $this->third_party_requests_enabled( $options ) ) {
                return $this->third_party_requests_disabled_error();
            }

            $api_key = isset( $options['api_key'] ) ? trim( (string) $options['api_key'] ) : '';
            if ( $api_key === '' ) {
                return new WP_Error( 'missing_key', __( 'API key is not configured.', 'yamandu-native-ai-content-creator' ) );
            }

            $lang_info     = $this->get_language_info();
            $features      = array(
                array( 'type' => 'LABEL_DETECTION', 'maxResults' => 8 ),
                array( 'type' => 'WEB_DETECTION', 'maxResults' => 6 ),
                array( 'type' => 'TEXT_DETECTION', 'maxResults' => 3 ),
                array( 'type' => 'LOGO_DETECTION', 'maxResults' => 3 ),
            );
            $image_context = array(
                'languageHints' => array( $lang_info['bcp47'] ),
            );
            $image_payload  = null;
            $content_base64 = '';
            $file_path      = get_attached_file( $attachment_id );

            if ( $file_path && file_exists( $file_path ) && is_readable( $file_path ) ) {
                $max_bytes = 8 * 1024 * 1024;
                $size      = $this->safe_filesize( $file_path );
                if ( $size && $size > 0 && $size <= $max_bytes ) {
                    $bin = $this->safe_file_get_contents( $file_path );
                    if ( $bin !== '' ) {
                        $content_base64 = base64_encode( $bin );
                        $image_payload  = array(
                            'content' => $content_base64,
                        );
                    }
                }
            }

            if ( ! $image_payload ) {
                $image_payload = array(
                    'source' => array(
                        'imageUri' => $image_url,
                    ),
                );
            }

            if ( is_object( $this->api_client ) && method_exists( $this->api_client, 'call_vision_api' ) ) {
                $callable = $this->resolve_method_arity( $this->api_client, 'call_vision_api' );

                if ( $callable >= 4 && $content_base64 !== '' ) {
                    $res = $this->api_client->call_vision_api(
                        $content_base64,
                        $features,
                        $image_context,
                        array(
                            'api_key' => $api_key,
                            'timeout' => 40,
                        )
                    );
                    if ( is_wp_error( $res ) ) {
                        return $res;
                    }
                    $parsed = $this->normalize_vision_result( $res );
                    if ( is_array( $parsed ) ) {
                        return $parsed;
                    }
                } elseif ( $callable === 2 ) {
                    $res = $this->api_client->call_vision_api( $attachment_id, $image_url );
                    if ( is_wp_error( $res ) ) {
                        return $res;
                    }
                    $parsed = $this->normalize_vision_result( $res );
                    if ( is_array( $parsed ) ) {
                        return $parsed;
                    }
                }
            }

            $body = array(
                'requests' => array(
                    array(
                        'image'        => $image_payload,
                        'features'     => $features,
                        'imageContext' => $image_context,
                    ),
                ),
            );

            $response = wp_safe_remote_post(
                'https://vision.googleapis.com/v1/images:annotate',
                array(
                    'headers' => array(
                        'Content-Type'   => 'application/json; charset=utf-8',
                        'x-goog-api-key' => $api_key,
                    ),
                    'body'    => wp_json_encode( $body ),
                    'timeout' => 40,
                )
            );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
            $raw  = (string) wp_remote_retrieve_body( $response );

            if ( $code !== 200 ) {
                $decoded = json_decode( $raw, true );
                $msg     = ( is_array( $decoded ) && ! empty( $decoded['error']['message'] ) ) ? (string) $decoded['error']['message'] : '';
                return new WP_Error( 'vision_http_error', ( $msg ? $msg : 'HTTP ' . $code ) . ' (HTTP ' . $code . ')' );
            }

            $data = json_decode( $raw, true );
            if ( ! $data || empty( $data['responses'][0] ) ) {
                return new WP_Error( 'vision_parse_error', __( 'Invalid or empty Vision API response.', 'yamandu-native-ai-content-creator' ) );
            }

            return $this->normalize_vision_result( $data );
        }

        private function normalize_vision_result( $res ) {
            if ( ! is_array( $res ) ) {
                return null;
            }

            if ( isset( $res['labels'] ) || isset( $res['web'] ) || isset( $res['ocr_text'] ) || isset( $res['logos'] ) ) {
                return $res;
            }

            if ( isset( $res['responses'][0] ) && is_array( $res['responses'][0] ) ) {
                $resp     = $res['responses'][0];
                $labels   = isset( $resp['labelAnnotations'] ) ? $resp['labelAnnotations'] : array();
                $web      = isset( $resp['webDetection'] ) ? $resp['webDetection'] : array();
                $text_ann = isset( $resp['textAnnotations'] ) ? $resp['textAnnotations'] : array();
                $logos    = isset( $resp['logoAnnotations'] ) ? $resp['logoAnnotations'] : array();

                $ocr_text = '';
                $first_text = isset( $text_ann[0]['description'] ) ? $text_ann[0]['description'] : '';
                if ( $first_text ) {
                    $ocr_text = $first_text;
                }

                return array(
                    'labels'   => $labels,
                    'web'      => $web,
                    'ocr_text' => $ocr_text,
                    'logos'    => $logos,
                );
            }
            return null;
        }

        private function generate_metadata_with_gemini( $context, $lang_info, $options ) {
            $options = is_array( $options ) ? $options : array();
            if ( ! $this->third_party_requests_enabled( $options ) ) {
                return $this->third_party_requests_disabled_error();
            }
            $api_key = isset( $options['api_key'] ) ? trim( (string) $options['api_key'] ) : '';
            if ( $api_key === '' ) {
                return new WP_Error( 'no_api_key', __( 'Missing Gemini API key.', 'yamandu-native-ai-content-creator' ) );
            }

            return $this->generate_metadata_with_gemini_attempt( $context, $lang_info, $options, false, 0 );
        }

        private function generate_metadata_with_gemini_attempt( $context, $lang_info, $options, $did_retry_strict, $attempt ) {
            $options     = is_array( $options ) ? $options : array();
            $saved_model = ! empty( $options['model'] ) ? $this->normalize_gemini_model_id( (string) $options['model'] ) : 'gemini-2.5-flash';
            $api_key     = isset( $options['api_key'] ) ? trim( (string) $options['api_key'] ) : '';
            $model       = $this->get_runtime_gemini_model( $api_key, $saved_model );

            $language_tag = ! empty( $lang_info['bcp47'] ) ? (string) $lang_info['bcp47'] : 'en-US';

            $labels         = ! empty( $context['labels'] ) && is_array( $context['labels'] ) ? $context['labels'] : array();
            $web_entities   = ! empty( $context['webEntities'] ) && is_array( $context['webEntities'] ) ? $context['webEntities'] : array();
            $logo_names     = ! empty( $context['logoNames'] ) && is_array( $context['logoNames'] ) ? $context['logoNames'] : array();
            $filename_terms = ! empty( $context['filenameTerms'] ) && is_array( $context['filenameTerms'] ) ? $context['filenameTerms'] : array();
            $ocr_text       = isset( $context['ocrText'] ) ? (string) $context['ocrText'] : '';
            $existing        = ! empty( $context['existing'] ) && is_array( $context['existing'] ) ? $context['existing'] : array();

            $labels_text   = ! empty( $labels ) ? implode( ', ', array_slice( array_map( 'strval', $labels ), 0, 12 ) ) : '—';
            $web_text      = ! empty( $web_entities ) ? implode( ', ', array_slice( array_map( 'strval', $web_entities ), 0, 12 ) ) : '—';
            $logo_text     = ! empty( $logo_names ) ? implode( ', ', array_slice( array_map( 'strval', $logo_names ), 0, 8 ) ) : '—';
            $filename_text = ! empty( $filename_terms ) ? implode( ', ', array_slice( array_map( 'strval', $filename_terms ), 0, 10 ) ) : '—';

            $ocr_text = trim( (string) $ocr_text );
            if ( $ocr_text !== '' && $ocr_text !== '—' ) {
                $ocr_text = $this->trim_to_word_boundary( $ocr_text, 800 );
                $ocr_text = preg_replace( '~\s+~', ' ', $ocr_text );
            }

            $ex_title = isset( $existing['title'] ) ? (string) $existing['title'] : '';
            $ex_alt   = isset( $existing['alt'] ) ? (string) $existing['alt'] : '';
            $ex_title = preg_replace( "/\r\n|\r|\n/u", ' ', $ex_title );
            $ex_alt   = preg_replace( "/\r\n|\r|\n/u", ' ', $ex_alt );

            $requested_fields = array( 'title', 'alt' );

            $requested_fields_text = implode( ', ', $requested_fields );

            if ( $did_retry_strict ) {
                $prompt  = "Return ONLY a single-line JSON object with keys: {$requested_fields_text}. No extra keys.
";
                $prompt .= "Language: {$language_tag}.
";
                $prompt .= "HARD LIMITS: title <= 70 chars; alt <= 160.
";
                $prompt .= "No markdown. No code fences. No line breaks, tabs or control chars in values.
";
                $prompt .= "If you would exceed limits, shorten aggressively.
";
            } else {
                $prompt  = "You are an SEO and accessibility specialist.
";
                $prompt .= "Write natural, human-sounding image metadata.
";
                $prompt .= "Never mention OCR, labels, or analysis.
";
                $prompt .= "Return content strictly in: {$language_tag}.
";
                $prompt .= "Return only these keys: {$requested_fields_text}.

";
                $prompt .= "Hints (do not copy literally):
";
                $prompt .= "- Labels: {$labels_text}
";
                $prompt .= "- Web concepts: {$web_text}
";
                $prompt .= "- Logos/brands: {$logo_text}
";
                $prompt .= "- Filename terms: {$filename_text}
";
                $prompt .= "- Text inside image: {$ocr_text}

";
                $prompt .= "Existing metadata (may refine):
";
                $prompt .= "- title: {$ex_title}
";
                $prompt .= "- alt: {$ex_alt}
";
                $prompt .= "Rules:
";
                if ( in_array( 'title', $requested_fields, true ) ) {
                    $prompt .= "- title: ~60 chars, sentence case
";
                }
                if ( in_array( 'alt', $requested_fields, true ) ) {
                    $prompt .= "- alt: 1 concise sentence (~120 chars)
";
                }
                $prompt .= "- HARD LIMITS: title <= 70 chars; alt <= 160. If longer, shorten.
";
                $prompt .= "- If there is lots of text inside the image, DO NOT transcribe it; summarize in <= 12 words.
";
                $prompt .= "- Return ONLY the JSON object with the requested keys. No markdown, no extra keys.
";
                $prompt .= "- Output must be a SINGLE-LINE JSON object. Do not use line breaks in any value; use spaces.
";
                $prompt .= "- All values must be single-line strings (no \n, \r, or \t).
";
            }

            $generation_config = array(
                'temperature'      => $did_retry_strict ? 0.0 : 0.2,
                'maxOutputTokens'  => $did_retry_strict ? ( $attempt >= 1 ? 2048 : 1536 ) : 900,
                'responseMimeType' => 'application/json',
                'responseSchema'   => $this->get_metadata_response_schema( $requested_fields ),
            );

            $parts = array(
                array( 'text' => $prompt ),
            );

            $attachment_id = ! empty( $context['attachmentId'] ) ? (int) $context['attachmentId'] : 0;
            $img_part      = null;

            if ( $attachment_id ) {
                $img_part = $this->build_gemini_inline_image_part( $attachment_id );
                if ( $img_part ) {
                    $parts[] = $img_part;
                }
            }

            $this->ai_log(
                'gemini_request_ctx',
                array(
                    'model'     => $model,
                    'lang'      => $language_tag,
                    'has_image' => $img_part ? 1 : 0,
                    'labels_n'  => is_array( $labels ) ? count( $labels ) : 0,
                    'web_n'     => is_array( $web_entities ) ? count( $web_entities ) : 0,
                    'logos_n'   => is_array( $logo_names ) ? count( $logo_names ) : 0,
                    'ocr_len'   => is_string( $ocr_text ) ? strlen( $ocr_text ) : 0,
                )
            );

            $body = array(
                'contents' => array(
                    array(
                        'role'  => 'user',
                        'parts' => $parts,
                    ),
                ),
                'generationConfig' => $generation_config,
            );

            $res = $this->gemini_generate_content_request( $model, $body, $api_key, 90 );
            if ( is_wp_error( $res ) ) {
                return $res;
            }

            $code = null;
            $raw  = null;
            $data = null;

            if ( is_array( $res ) && isset( $res['response'] ) && isset( $res['body'] ) ) {
                $code = (int) wp_remote_retrieve_response_code( $res );
                $raw  = (string) wp_remote_retrieve_body( $res );

                if ( $code < 200 || $code >= 300 ) {
                    $decoded_err = json_decode( $raw, true );
                    $msg = ( is_array( $decoded_err ) && ! empty( $decoded_err['error']['message'] ) ) ? (string) $decoded_err['error']['message'] : '';
                    return new WP_Error( 'gemini_http_error', ( $msg ? $msg : 'HTTP ' . $code ) . ' (HTTP ' . $code . ')' );
                }

                $data = json_decode( $raw, true );
                if ( ! is_array( $data ) ) {
                    return new WP_Error( 'gemini_parse_error', __( 'Invalid Gemini API response.', 'yamandu-native-ai-content-creator' ) );
                }
            } elseif ( is_array( $res ) ) {
                $data = $res;
            } else {
                return new WP_Error( 'gemini_parse_error', __( 'Invalid Gemini API response.', 'yamandu-native-ai-content-creator' ) );
            }

            if ( empty( $data['candidates'][0] ) || ! is_array( $data['candidates'][0] ) ) {
                return new WP_Error( 'gemini_empty', __( 'Gemini returned an empty response.', 'yamandu-native-ai-content-creator' ) );
            }

            $cand  = $data['candidates'][0];
            $finish = isset( $cand['finishReason'] ) ? (string) $cand['finishReason'] : '';
            if ( strtoupper( $finish ) === 'MAX_TOKENS' && ! $did_retry_strict ) {
                return $this->generate_metadata_with_gemini_attempt( $context, $lang_info, $options, true, 0 );
            }
            if ( strtoupper( $finish ) === 'MAX_TOKENS' ) {
                if ( ! $did_retry_strict ) {
                    return $this->generate_metadata_with_gemini_attempt( $context, $lang_info, $options, true, 0 );
                }
                if ( $attempt < 1 ) {
                    return $this->generate_metadata_with_gemini_attempt( $context, $lang_info, $options, true, $attempt + 1 );
                }
                return new WP_Error( 'gemini_max_tokens', __( 'Gemini truncated output (MAX_TOKENS).', 'yamandu-native-ai-content-creator' ) );
            }

            $text_out = '';
            if ( ! empty( $cand['content']['parts'] ) && is_array( $cand['content']['parts'] ) ) {
                foreach ( $cand['content']['parts'] as $p ) {
                    if ( is_array( $p ) && isset( $p['text'] ) ) {
                        $text_out .= (string) $p['text'];
                    }
                }
            }

            $text_out = trim( (string) $text_out );
            if ( $text_out === '' ) {
                return new WP_Error( 'gemini_no_text', __( 'Gemini returned no text output.', 'yamandu-native-ai-content-creator' ) );
            }

            $meta = $this->extract_first_json_any_strict( $text_out );
            if ( is_wp_error( $meta ) ) {
                if ( ! $did_retry_strict ) {
                    return $this->generate_metadata_with_gemini_attempt( $context, $lang_info, $options, true, 0 );
                }

                $this->ai_log(
                    'gemini_invalid_json',
                    array(
                        'message'        => $meta->get_error_message(),
                        'candidate_len'  => is_string( $text_out ) ? strlen( $text_out ) : 0,
                        'candidate_hash' => is_string( $text_out ) && $text_out !== '' ? substr( hash( 'sha256', $text_out ), 0, 12 ) : '',
                    )
                );

                return new WP_Error(
                    'gemini_invalid_json',
                    __( 'Gemini did not return valid JSON for metadata.', 'yamandu-native-ai-content-creator' ) . ' ' . __( 'Cause:', 'yamandu-native-ai-content-creator' ) . ' ' . $meta->get_error_message()
                );
            }

            if ( isset( $meta[0] ) && is_array( $meta[0] ) && ! isset( $meta['title'] ) ) {
                $meta = $meta[0];
            }

            foreach ( array( 'title', 'alt' ) as $k ) {
                if ( ! isset( $meta[ $k ] ) ) {
                    $meta[ $k ] = '';
                }
                $meta[ $k ] = $this->clean_generated_text( (string) $meta[ $k ] );
                $meta[ $k ] = preg_replace( "/\r\n|\r|\n/u", ' ', (string) $meta[ $k ] );
                $meta[ $k ] = preg_replace( "/[ \t]+/u", ' ', (string) $meta[ $k ] );
                $meta[ $k ] = trim( (string) $meta[ $k ] );
            }

            $final = array(
                'title' => (string) $meta['title'],
                'alt'   => (string) $meta['alt'],
            );

            if ( $this->is_placeholder_metadata( $final ) ) {
                if ( ! $did_retry_strict ) {
                    return $this->generate_metadata_with_gemini_attempt( $context, $lang_info, $options, true, 0 );
                }
                return new WP_Error( 'gemini_placeholder', __( 'Gemini returned placeholder metadata. Check logs and site language.', 'yamandu-native-ai-content-creator' ) );
            }

            return $final;
        }

        private function gemini_generate_content_request( $model, $body, $api_key, $timeout ) {
            if ( is_object( $this->api_client ) && method_exists( $this->api_client, 'gemini_api_request' ) ) {
                $arity = $this->resolve_method_arity( $this->api_client, 'gemini_api_request' );

                if ( $arity === 3 ) {
                    $path = 'models/' . rawurlencode( (string) $model ) . ':generateContent';
                    return $this->api_client->gemini_api_request(
                        $path,
                        $body,
                        array(
                            'api_key' => $api_key,
                            'timeout' => max( 20, (int) $timeout ),
                            'version' => 'v1beta',
                            'method'  => 'POST',
                        )
                    );
                }

                if ( $arity >= 5 ) {
                    $path = '/v1beta/models/' . rawurlencode( (string) $model ) . ':generateContent';
                    return $this->api_client->gemini_api_request( 'POST', $path, $api_key, $body, max( 20, (int) $timeout ) );
                }
            }

            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( (string) $model ) . ':generateContent';

            $args = array(
                'method'      => 'POST',
                'timeout'     => max( 20, (int) $timeout ),
                'redirection' => 0,
                'httpversion' => '1.1',
                'headers'     => array(
                    'Content-Type'   => 'application/json; charset=utf-8',
                    'Accept'         => 'application/json',
                    'x-goog-api-key' => $api_key,
                    'User-Agent'     => 'YAMANDU/' . ( defined( 'YAMANDU_VERSION' ) ? YAMANDU_VERSION : '1.0.0' ) . ' (WordPress)',
                ),
                'body'        => wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            );

            $res = wp_remote_request( $url, $args );

            return $res;
        }

        private function get_metadata_response_schema( $requested_fields = array() ) {
            return array(
                'type'       => 'OBJECT',
                'properties' => array(
                    'title' => array( 'type' => 'STRING' ),
                    'alt'   => array( 'type' => 'STRING' ),
                ),
                'required'   => array( 'title', 'alt' ),
            );
        }

        private function is_placeholder_metadata( $meta ) {
            if ( ! is_array( $meta ) ) {
                return true;
            }

            $t = strtolower( trim( (string) ( $meta['title'] ?? '' ) ) );
            $a = strtolower( trim( (string) ( $meta['alt'] ?? '' ) ) );
            $joined = $t . '|' . $a;
            if ( $joined === '|' ) {
                return true;
            }

            $bad = array(
                'example title',
                'alternative text for the image',
            );

            foreach ( $bad as $b ) {
                if ( strpos( $joined, $b ) !== false ) {
                    return true;
                }
            }

            return false;
        }

        private function supported_field_keys() {
            if ( is_object( $this->core ) && method_exists( $this->core, 'supported_field_keys' ) ) {
                $fields = $this->core->supported_field_keys();
                return is_array( $fields ) ? array_values( array_unique( array_map( 'strval', $fields ) ) ) : array();
            }
            return array( 'title', 'alt' );
        }

        private function is_known_field( $field ) {
            return in_array( (string) $field, array( 'title', 'alt' ), true );
        }

        private function normalize_gemini_model_id( $model ) {
            $model = trim( (string) $model );
            $model = preg_replace( '#^models/#', '', $model );
            return $model;
        }

        private function hash_api_key( $api_key ) {
            $api_key = trim( (string) $api_key );
            if ( $api_key === '' ) {
                return '';
            }
            $salt = '';
            if ( function_exists( 'wp_salt' ) ) {
                $salt = (string) wp_salt( 'auth' );
            } elseif ( defined( 'AUTH_SALT' ) ) {
                $salt = (string) AUTH_SALT;
            }
            return hash_hmac( 'sha256', $api_key, $salt );
        }

        private function get_runtime_gemini_model( $api_key, $fallback_model ) {
            $selected = $this->normalize_gemini_model_id( $fallback_model );
            $key_hash = $this->hash_api_key( $api_key );
            $t_key    = 'yamandu_best_model_' . substr( $key_hash, 0, 12 );
        
            $models = $this->get_available_gemini_models_for_key( $api_key );
            if ( ! is_wp_error( $models ) && $selected !== '' ) {
                foreach ( $models as $m ) {
                    if ( $this->normalize_gemini_model_id( $m ) === $selected ) {
                        return $selected;
                    }
                }
            }
        
            $cached = get_transient( $t_key );
            if ( is_string( $cached ) && $cached !== '' ) {
                return $this->normalize_gemini_model_id( $cached );
            }
        
            if ( is_wp_error( $models ) ) {
                return $selected ?: 'gemini-2.5-flash';
            }
        
            $best = $this->pick_preferred_gemini_model( $models );
            if ( is_wp_error( $best ) ) {
                return $selected ?: 'gemini-2.5-flash';
            }
        
            set_transient( $t_key, $best, 6 * HOUR_IN_SECONDS );
        
            return $this->normalize_gemini_model_id( $best );
        }

        private function get_available_gemini_models_for_key( $api_key ) {
            if ( is_object( $this->api_client ) && method_exists( $this->api_client, 'get_available_gemini_models' ) ) {
                $arity = $this->resolve_method_arity( $this->api_client, 'get_available_gemini_models' );

                if ( $arity === 1 ) {
                    return $this->api_client->get_available_gemini_models( $api_key );
                }

                if ( $arity >= 2 ) {
                    return $this->api_client->get_available_gemini_models( false, $api_key );
                }
            }

            return array( 'gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-2.5-pro' );
        }

        private function pick_preferred_gemini_model( $models ) {
            if ( ! is_array( $models ) || empty( $models ) ) {
                return new WP_Error( 'no_models', __( 'No Gemini models available.', 'yamandu-native-ai-content-creator' ) );
            }

            $models = array_values( array_filter( array_map( 'strval', $models ) ) );

            $priority = array(
                'gemini-2.5-pro',
                'gemini-2.5-flash',
                'gemini-2.5-flash-lite',
            );

            foreach ( $priority as $p ) {
                foreach ( $models as $m ) {
                    if ( $this->normalize_gemini_model_id( $m ) === $p ) {
                        return $p;
                    }
                }
            }

            foreach ( $models as $m ) {
                if ( stripos( $m, 'flash' ) !== false ) {
                    return $this->normalize_gemini_model_id( $m );
                }
            }

            return $this->normalize_gemini_model_id( $models[0] );
        }

        private function extract_first_json_any_strict( $text ) {
            $text = trim( (string) $text );
            if ( $text === '' ) {
                return new WP_Error( 'json_extract_empty', __( 'Empty model output.', 'yamandu-native-ai-content-creator' ) );
            }

            $text = preg_replace( '~```(?:json)?~i', '', $text );
            $text = str_replace( '```', '', $text );
            $text = trim( $text );

            $text = str_replace(
                array( "“", "”", "„", "‟", "’", "‘", "`" ),
                array( '"', '"', '"', '"', "'", "'", "'" ),
                $text
            );

            $text = str_replace( array( "\xE2\x80\xA8", "\xE2\x80\xA9" ), array( '\\u2028', '\\u2029' ), $text );

            $text = $this->escape_control_chars_inside_json_strings( $text );
            $text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text );

            $try = $this->json_decode_assoc_safe( $text );
            if ( ! is_wp_error( $try ) ) {
                return $try;
            }

            $obj = $this->extract_balanced_braces_object( $text );
            if ( $obj ) {
                $obj = preg_replace( '~,\s*}~', '}', $obj );
                $obj = preg_replace( '~,\s*]~', ']', $obj );
                $try2 = $this->json_decode_assoc_safe( $obj );
                if ( ! is_wp_error( $try2 ) ) {
                    return $try2;
                }
            }

            return $try;
        }

        private function json_decode_assoc_safe( $text ) {
            $decoded = json_decode( (string) $text, true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
                return $decoded;
            }
            $msg = function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : 'JSON decode error';
            return new WP_Error( 'json_decode_error', (string) $msg );
        }

        private function extract_balanced_braces_object( $text ) {
            $text = (string) $text;
            $len  = strlen( $text );

            $start = strpos( $text, '{' );
            if ( $start === false ) {
                return '';
            }

            $depth   = 0;
            $in_str  = false;
            $escape  = false;

            for ( $i = $start; $i < $len; $i++ ) {
                $ch = $text[ $i ];

                if ( $in_str ) {
                    if ( $escape ) {
                        $escape = false;
                        continue;
                    }
                    if ( $ch === '\\' ) {
                        $escape = true;
                        continue;
                    }
                    if ( $ch === '"' ) {
                        $in_str = false;
                        continue;
                    }
                    continue;
                }

                if ( $ch === '"' ) {
                    $in_str = true;
                    continue;
                }

                if ( $ch === '{' ) {
                    $depth++;
                    continue;
                }

                if ( $ch === '}' ) {
                    $depth--;
                    if ( $depth === 0 ) {
                        return substr( $text, $start, $i - $start + 1 );
                    }
                }
            }
            $this->ai_log( 'fs_read_failed', array( 'stage' => 'vision_local' ) );
            return '';
        }

        private function escape_control_chars_inside_json_strings( $text ) {
            $text = (string) $text;
            $out  = '';
            $len  = strlen( $text );

            $in_str = false;
            $escape = false;

            for ( $i = 0; $i < $len; $i++ ) {
                $ch  = $text[ $i ];
                $ord = ord( $ch );

                if ( $in_str ) {
                    if ( $escape ) {
                        $out   .= $ch;
                        $escape = false;
                        continue;
                    }

                    if ( $ch === '\\' ) {
                        $out   .= $ch;
                        $escape = true;
                        continue;
                    }

                    if ( $ch === '"' ) {
                        $out   .= $ch;
                        $in_str = false;
                        continue;
                    }

                    if ( $ord < 32 ) {
                        $out .= sprintf( '\\u%04x', $ord );
                        continue;
                    }

                    $out .= $ch;
                    continue;
                }

                if ( $ch === '"' ) {
                    $out   .= $ch;
                    $in_str = true;
                    continue;
                }

                $out .= $ch;
            }

            return $out;
        }
        
        private function safe_filesize( $path ) {
            $path = (string) $path;
            if ( $path === '' || ! file_exists( $path ) ) {
                return 0;
            }

            $size = filesize( $path );
            return is_int( $size ) && $size > 0 ? $size : 0;
        }
        
        private function safe_file_get_contents( $path ) {
            $path = (string) $path;
            if ( $path === '' || ! is_readable( $path ) ) {
                return '';
            }

            $bin = file_get_contents( $path );
            return is_string( $bin ) ? $bin : '';
        }
        
        private function safe_unlink( $path ) {
            $path = (string) $path;
            if ( $path === '' || ! file_exists( $path ) ) {
                return true;
            }

            if ( function_exists( 'wp_delete_file' ) ) {
                wp_delete_file( $path );
                return ! file_exists( $path );
            }

            return false;
        }



        public function generate_post_text( $prompt, $options = array(), $post_id = 0, $selection = '' ) {
            $prompt = is_string( $prompt ) ? trim( wp_strip_all_tags( $prompt ) ) : '';
            $prompt = preg_replace( '/\s+/', ' ', $prompt );
            $prompt = is_string( $prompt ) ? trim( $prompt ) : '';

            if ( $prompt === '' ) {
                return new WP_Error( 'yamandu_missing_text_prompt', __( 'Text prompt is missing.', 'yamandu-native-ai-content-creator' ) );
            }

            if ( function_exists( 'mb_strlen' ) && mb_strlen( $prompt, 'UTF-8' ) > 4000 ) {
                $prompt = mb_substr( $prompt, 0, 4000, 'UTF-8' );
            } elseif ( strlen( $prompt ) > 4000 ) {
                $prompt = substr( $prompt, 0, 4000 );
            }

            $selection = is_string( $selection ) ? trim( wp_strip_all_tags( $selection ) ) : '';
            $selection = preg_replace( '/\s+/', ' ', $selection );
            $selection = is_string( $selection ) ? trim( $selection ) : '';

            if ( function_exists( 'mb_strlen' ) && mb_strlen( $selection, 'UTF-8' ) > 6000 ) {
                $selection = mb_substr( $selection, 0, 6000, 'UTF-8' );
            } elseif ( strlen( $selection ) > 6000 ) {
                $selection = substr( $selection, 0, 6000 );
            }

            $options = is_array( $options ) ? $options : array();

            if ( ! $this->third_party_requests_enabled( $options ) ) {
                return $this->third_party_requests_disabled_error();
            }

            $api_key = isset( $options['api_key'] ) ? trim( (string) $options['api_key'] ) : '';
            if ( $api_key === '' ) {
                return new WP_Error( 'yamandu_missing_api_key', __( 'API key is missing.', 'yamandu-native-ai-content-creator' ) );
            }

            $lang_info    = $this->get_language_info();
            $language_tag = ! empty( $lang_info['bcp47'] ) ? (string) $lang_info['bcp47'] : 'en-US';
            $saved_model  = ! empty( $options['model'] ) ? $this->normalize_gemini_model_id( (string) $options['model'] ) : 'gemini-2.5-flash';
            $model        = $this->get_runtime_gemini_model( $api_key, $saved_model );

            $post_title = '';
            $post_id    = absint( $post_id );
            if ( $post_id > 0 ) {
                $post = get_post( $post_id );
                if ( $post && isset( $post->post_title ) ) {
                    $post_title = trim( wp_strip_all_tags( (string) $post->post_title ) );
                }
            }

            $context_lines = array(
                'Site language: ' . $language_tag,
            );

            if ( $post_title !== '' ) {
                $context_lines[] = 'Post title: ' . $this->trim_to_word_boundary( $post_title, 220, '' );
            }

            if ( $selection !== '' ) {
                $context_lines[] = 'Selected text context: ' . $this->trim_to_word_boundary( $selection, 6000, '' );
            }

            $instruction = "You are helping write native WordPress post content. Write in {$language_tag}. Follow the user's prompt precisely. Return only the generated text, without prefaces, markdown fences, labels, or explanations. Use natural editorial language and avoid generic AI filler.";
            $full_prompt = $instruction . "\n\n" . implode( "\n", $context_lines ) . "\n\nUser prompt: " . $prompt;

            $generation_config = array(
                'temperature'     => 0.7,
                'maxOutputTokens' => 8192,
            );

            $text_out = '';
            $next_prompt = $full_prompt;

            for ( $attempt = 0; $attempt < 6; $attempt++ ) {
                $body = array(
                    'contents' => array(
                        array(
                            'role'  => 'user',
                            'parts' => array(
                                array( 'text' => $next_prompt ),
                            ),
                        ),
                    ),
                    'generationConfig' => $generation_config,
                );

                $res = $this->gemini_generate_content_request( $model, $body, $api_key, 120 );
                if ( is_wp_error( $res ) ) {
                    return $res;
                }

                $data = $this->normalize_gemini_response_data( $res );
                if ( is_wp_error( $data ) ) {
                    return $data;
                }

                if ( empty( $data['candidates'][0] ) || ! is_array( $data['candidates'][0] ) ) {
                    if ( $text_out !== '' ) {
                        break;
                    }
                    return new WP_Error( 'yamandu_gemini_empty', __( 'Gemini returned an empty response.', 'yamandu-native-ai-content-creator' ) );
                }

                $cand   = $data['candidates'][0];
                $finish = isset( $cand['finishReason'] ) ? strtoupper( (string) $cand['finishReason'] ) : '';
                $piece  = $this->normalize_post_text_output( $this->extract_text_from_gemini_candidate( $cand ) );

                if ( $piece !== '' ) {
                    $text_out = $this->append_post_text_piece( $text_out, $piece );
                }

                if ( $finish !== 'MAX_TOKENS' ) {
                    break;
                }

                if ( $text_out === '' ) {
                    return new WP_Error( 'yamandu_gemini_no_text', __( 'Gemini returned no text output.', 'yamandu-native-ai-content-creator' ) );
                }

                $next_prompt = "Continue the same WordPress post text from the exact point where it stopped. Do not repeat prior text. Finish the draft completely. Return only the continuation, without prefaces, markdown fences, labels, or explanations. Write in {$language_tag}.\n\nOriginal user prompt: " . $prompt . "\n\nLast generated excerpt:\n" . $this->get_post_text_tail( $text_out, 2200 );
            }

            $text_out = $this->normalize_post_text_output( $text_out );

            if ( $text_out === '' ) {
                return new WP_Error( 'yamandu_gemini_no_text', __( 'Gemini returned no text output.', 'yamandu-native-ai-content-creator' ) );
            }

            return $text_out;
        }

        private function normalize_gemini_response_data( $res ) {
            if ( is_array( $res ) && isset( $res['response'] ) && isset( $res['body'] ) ) {
                $code = (int) wp_remote_retrieve_response_code( $res );
                $raw  = (string) wp_remote_retrieve_body( $res );

                if ( $code < 200 || $code >= 300 ) {
                    $decoded_err = json_decode( $raw, true );
                    $msg = ( is_array( $decoded_err ) && ! empty( $decoded_err['error']['message'] ) ) ? (string) $decoded_err['error']['message'] : '';
                    return new WP_Error( 'yamandu_gemini_http_error', ( $msg ? $msg : 'HTTP ' . $code ) . ' (HTTP ' . $code . ')' );
                }

                $data = json_decode( $raw, true );
                if ( ! is_array( $data ) ) {
                    return new WP_Error( 'yamandu_gemini_parse_error', __( 'Invalid Gemini API response.', 'yamandu-native-ai-content-creator' ) );
                }

                return $data;
            }

            if ( is_array( $res ) ) {
                return $res;
            }

            return new WP_Error( 'yamandu_gemini_parse_error', __( 'Invalid Gemini API response.', 'yamandu-native-ai-content-creator' ) );
        }

        private function extract_text_from_gemini_candidate( $candidate ) {
            $text = '';

            if ( ! is_array( $candidate ) || empty( $candidate['content']['parts'] ) || ! is_array( $candidate['content']['parts'] ) ) {
                return '';
            }

            foreach ( $candidate['content']['parts'] as $part ) {
                if ( is_array( $part ) && isset( $part['text'] ) ) {
                    $text .= (string) $part['text'];
                }
            }

            return $text;
        }

        private function normalize_post_text_output( $text ) {
            $text = trim( preg_replace( "/[ \t]+/u", ' ', (string) $text ) );
            $text = preg_replace( "/\n{3,}/u", "\n\n", $text );
            return trim( (string) $text );
        }

        private function append_post_text_piece( $text, $piece ) {
            $text  = (string) $text;
            $piece = (string) $piece;

            if ( $text === '' ) {
                return $piece;
            }

            if ( $piece === '' ) {
                return $text;
            }

            $last  = function_exists( 'mb_substr' ) ? mb_substr( $text, -1, 1, 'UTF-8' ) : substr( $text, -1 );
            $first = function_exists( 'mb_substr' ) ? mb_substr( $piece, 0, 1, 'UTF-8' ) : substr( $piece, 0, 1 );

            if ( preg_match( '/\s/u', $last ) || preg_match( '/^[.,;:!?)]/u', $first ) ) {
                return $text . $piece;
            }

            return $text . ' ' . $piece;
        }

        private function get_post_text_tail( $text, $max_chars ) {
            $text = (string) $text;
            $max_chars = max( 1, (int) $max_chars );

            if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
                return mb_strlen( $text, 'UTF-8' ) > $max_chars ? mb_substr( $text, -1 * $max_chars, null, 'UTF-8' ) : $text;
            }

            return strlen( $text ) > $max_chars ? substr( $text, -1 * $max_chars ) : $text;
        }

        public function generate_image_attachment( $prompt, $options = array(), $source_attachment_id = 0 ) {
            $prompt = is_string( $prompt ) ? trim( wp_strip_all_tags( $prompt ) ) : '';
            $prompt = preg_replace( '/\s+/', ' ', $prompt );
            $prompt = is_string( $prompt ) ? trim( $prompt ) : '';

            if ( $prompt === '' ) {
                return new WP_Error( 'yamandu_missing_image_prompt', __( 'Image prompt is missing.', 'yamandu-native-ai-content-creator' ) );
            }

            if ( function_exists( 'mb_strlen' ) && mb_strlen( $prompt, 'UTF-8' ) > 2000 ) {
                $prompt = mb_substr( $prompt, 0, 2000, 'UTF-8' );
            } elseif ( strlen( $prompt ) > 2000 ) {
                $prompt = substr( $prompt, 0, 2000 );
            }

            $options = is_array( $options ) ? $options : array();

            if ( ! $this->third_party_requests_enabled( $options ) ) {
                return $this->third_party_requests_disabled_error();
            }

            $api_key = isset( $options['api_key'] ) ? trim( (string) $options['api_key'] ) : '';
            if ( $api_key === '' ) {
                return new WP_Error( 'yamandu_missing_api_key', __( 'API key is missing.', 'yamandu-native-ai-content-creator' ) );
            }

            if ( ! is_object( $this->api_client ) || ! method_exists( $this->api_client, 'call_image_generation_api' ) ) {
                return new WP_Error( 'yamandu_image_generator_unavailable', __( 'Image generator is not available.', 'yamandu-native-ai-content-creator' ) );
            }

            $generated = $this->api_client->call_image_generation_api(
                $prompt,
                array(
                    'api_key' => $api_key,
                    'model'   => isset( $options['image_generation_model'] ) ? (string) $options['image_generation_model'] : '',
                    'timeout' => 120,
                )
            );

            if ( is_wp_error( $generated ) ) {
                return $generated;
            }

            $data = isset( $generated['data'] ) ? (string) $generated['data'] : '';
            $mime = isset( $generated['mime_type'] ) ? (string) $generated['mime_type'] : 'image/png';
            $mime = $this->normalize_generated_image_mime( $mime );
            $data = preg_replace( '#^data:image/[a-z0-9.+-]+;base64,#i', '', $data );
            $binary = base64_decode( $data, true );

            if ( ! is_string( $binary ) || $binary === '' ) {
                return new WP_Error( 'yamandu_invalid_generated_image', __( 'The generated image data is invalid.', 'yamandu-native-ai-content-creator' ) );
            }

            $extension = $this->generated_image_extension( $mime );
            $filename  = sanitize_file_name( 'yamandu-generated-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false ) . '.' . $extension );
            $upload    = wp_upload_bits( $filename, null, $binary );

            if ( ! empty( $upload['error'] ) ) {
                return new WP_Error( 'yamandu_generated_image_upload_failed', (string) $upload['error'] );
            }

            $file = isset( $upload['file'] ) ? (string) $upload['file'] : '';
            $url  = isset( $upload['url'] ) ? (string) $upload['url'] : '';

            if ( $file === '' || $url === '' ) {
                return new WP_Error( 'yamandu_generated_image_upload_failed', __( 'Could not save the generated image.', 'yamandu-native-ai-content-creator' ) );
            }

            $title = pathinfo( $filename, PATHINFO_FILENAME );
            $title = trim( wp_strip_all_tags( (string) $title ) );
            if ( $title === '' ) {
                $title = 'yamandu-generated-image';
            }

            $attachment_id = wp_insert_attachment(
                array(
                    'post_mime_type' => $mime,
                    'post_title'     => $title,
                    'post_name'      => sanitize_title( $title ),
                    'post_content'   => '',
                    'post_excerpt'   => '',
                    'post_status'    => 'inherit',
                ),
                $file
            );

            if ( is_wp_error( $attachment_id ) ) {
                $this->safe_unlink( $file );
                return $attachment_id;
            }

            $attachment_id = absint( $attachment_id );
            if ( $attachment_id <= 0 ) {
                $this->safe_unlink( $file );
                return new WP_Error( 'yamandu_generated_image_insert_failed', __( 'Could not create the media library item.', 'yamandu-native-ai-content-creator' ) );
            }

            if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
            }

            $metadata = wp_generate_attachment_metadata( $attachment_id, $file );
            if ( is_array( $metadata ) ) {
                wp_update_attachment_metadata( $attachment_id, $metadata );
            }


            $source_attachment_id = absint( $source_attachment_id );
            if ( $source_attachment_id > 0 ) {
                update_post_meta( $attachment_id, '_yamandu_source_attachment_id', $source_attachment_id );
            }

            return array(
                'attachment_id' => $attachment_id,
                'url'           => wp_get_attachment_url( $attachment_id ),
                'edit_url'      => get_edit_post_link( $attachment_id, 'raw' ),
                'title'         => get_the_title( $attachment_id ),
            );
        }

        private function normalize_generated_image_mime( $mime ) {
            $mime = is_string( $mime ) ? strtolower( trim( $mime ) ) : '';
            if ( in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
                return $mime;
            }
            return 'image/png';
        }

        private function generated_image_extension( $mime ) {
            if ( $mime === 'image/jpeg' ) {
                return 'jpg';
            }

            if ( $mime === 'image/webp' ) {
                return 'webp';
            }

            return 'png';
        }

        private function third_party_requests_enabled( $options = null ) {
            if ( is_array( $options ) ) {
                return ! empty( $options['enable_third_party_requests'] );
            }

            if ( is_object( $this->core ) && method_exists( $this->core, 'options' ) ) {
                $opts = $this->core->options();
                return is_array( $opts ) && ! empty( $opts['enable_third_party_requests'] );
            }

            return false;
        }

        private function third_party_requests_disabled_error() {
            return new WP_Error(
                'yamandu_third_party_requests_disabled',
                __( 'Third-party requests are disabled. Enable consent in the plugin settings to continue.', 'yamandu-native-ai-content-creator' )
            );
        }

        private function ai_snippet( $text, $limit = 600 ) {
            $text = (string) $text;
            $text = trim( $text );
            if ( $text === '' ) {
                return '';
            }

            if ( function_exists( 'mb_strlen' ) && mb_strlen( $text, 'UTF-8' ) > $limit ) {
                return mb_substr( $text, 0, (int) $limit, 'UTF-8' ) . '…';
            }

            if ( strlen( $text ) > $limit ) {
                return substr( $text, 0, (int) $limit ) . '…';
            }

            return $text;
        }

        private function ai_log( $event, array $data = array() ) {
            $enabled = ( defined( 'YAMANDU_DEBUG' ) && YAMANDU_DEBUG )
                || ( defined( 'WP_DEBUG' ) && WP_DEBUG );

            if ( ! $enabled ) {
                return;
            }

            $payload = array_merge(
                array(
                    'event' => (string) $event,
                    'ts'    => gmdate( 'c' ),
                    'req'   => (string) $this->req_id,
                ),
                $data
            );

            if ( isset( $payload['candidate_snippet'] ) ) {
                unset( $payload['candidate_snippet'] );
            }

            do_action( 'yamandu_debug_log', $payload, $this );
        }

        private function resolve_method_arity( $obj, $method ) {
            try {
                $ref = new ReflectionMethod( $obj, $method );
                return (int) $ref->getNumberOfParameters();
            } catch ( Exception $e ) {
                return -1;
            }
        }
    }
}
