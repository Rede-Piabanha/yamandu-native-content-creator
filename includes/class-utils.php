<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'YAMANDU_Utils' ) ) {

    final class YAMANDU_Utils {

        private $core;

        public function __construct( $core ) {
            $this->core = $core;
        }

        public function core() {
            return $this->core;
        }

        public function option_name() {
            if ( is_object( $this->core ) && method_exists( $this->core, 'option_name' ) ) {
                return $this->core->option_name();
            }
            return 'yamandu_options';
        }

        public function options() {
            if ( is_object( $this->core ) && method_exists( $this->core, 'options' ) ) {
                $opts = $this->core->options();
                return is_array( $opts ) ? $opts : array();
            }
            $opt = get_option( $this->option_name(), array() );
            return is_array( $opt ) ? $opt : array();
        }

        public function get_option_value( $key, $default = null ) {
            $opts = $this->options();
            return array_key_exists( $key, $opts ) ? $opts[ $key ] : $default;
        }

        public function to_bool( $value ) {
            if ( is_bool( $value ) ) {
                return $value;
            }
            if ( is_numeric( $value ) ) {
                return (int) $value === 1;
            }
            if ( is_string( $value ) ) {
                $v = strtolower( trim( $value ) );
                return in_array( $v, array( '1', 'true', 'yes', 'y', 'on' ), true );
            }
            return (bool) $value;
        }

        public function to_int( $value, $default = 0 ) {
            if ( is_numeric( $value ) ) {
                return (int) $value;
            }
            return (int) $default;
        }

        public function clamp_int( $value, $min, $max, $default = 0 ) {
            $v = $this->to_int( $value, $default );
            if ( $v < (int) $min ) {
                return (int) $min;
            }
            if ( $v > (int) $max ) {
                return (int) $max;
            }
            return $v;
        }

        public function sanitize_text( $value, $max_len = 0 ) {
            if ( is_array( $value ) || is_object( $value ) ) {
                $value = wp_json_encode( $value );
            }
            $value = is_string( $value ) ? $value : (string) $value;
            $value = wp_strip_all_tags( $value );
            $value = $this->strip_control_chars( $value );
            $value = $this->normalize_whitespace( $value );
            $value = trim( $value );
            if ( $max_len > 0 ) {
                $value = $this->mb_substr_safe( $value, 0, (int) $max_len );
            }
            return $value;
        }

        public function sanitize_multiline_text( $value, $max_len = 0 ) {
            if ( is_array( $value ) || is_object( $value ) ) {
                $value = wp_json_encode( $value );
            }
            $value = is_string( $value ) ? $value : (string) $value;
            $value = wp_strip_all_tags( $value );
            $value = $this->strip_control_chars( $value );
            $value = preg_replace( "/[ \t]+/u", ' ', $value );
            $value = preg_replace( "/\n{3,}/u", "\n\n", $value );
            $value = trim( $value );
            if ( $max_len > 0 ) {
                $value = $this->mb_substr_safe( $value, 0, (int) $max_len );
            }
            return $value;
        }

        public function sanitize_deep( $value ) {
            if ( is_array( $value ) ) {
                $out = array();
                foreach ( $value as $k => $v ) {
                    $key = is_string( $k ) ? sanitize_key( $k ) : $k;
                    $out[ $key ] = $this->sanitize_deep( $v );
                }
                return $out;
            }
            if ( is_object( $value ) ) {
                return $this->sanitize_text( wp_json_encode( $value ) );
            }
            if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
                return $value;
            }
            return $this->sanitize_text( $value );
        }

        public function sanitize_model( $model ) {
            $model = is_string( $model ) ? $model : '';
            $model = trim( $model );
            $model = preg_replace( '/[^a-zA-Z0-9._:-]/', '', $model );
            return $model;
        }

        public function normalize_whitespace( $text ) {
            $text = is_string( $text ) ? $text : (string) $text;
            $text = preg_replace( "/\r\n|\r/u", "\n", $text );
            $text = preg_replace( "/[ \t]+/u", ' ', $text );
            $text = preg_replace( "/\n[ \t]+/u", "\n", $text );
            $text = preg_replace( "/[ \t]+\n/u", "\n", $text );
            return $text;
        }

        public function strip_control_chars( $text ) {
            $text = is_string( $text ) ? $text : (string) $text;
            $text = preg_replace( "/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u", '', $text );
            return $text;
        }

        public function mb_substr_safe( $text, $start, $length = null ) {
            $text = is_string( $text ) ? $text : (string) $text;
            if ( function_exists( 'mb_substr' ) ) {
                if ( $length === null ) {
                    return mb_substr( $text, (int) $start, null, 'UTF-8' );
                }
                return mb_substr( $text, (int) $start, (int) $length, 'UTF-8' );
            }
            if ( $length === null ) {
                return substr( $text, (int) $start );
            }
            return substr( $text, (int) $start, (int) $length );
        }

        public function is_valid_json( $text ) {
            if ( ! is_string( $text ) || $text === '' ) {
                return false;
            }
            json_decode( $text, true );
            return json_last_error() === JSON_ERROR_NONE;
        }

        public function extract_json_candidate( $text ) {
            if ( ! is_string( $text ) || $text === '' ) {
                return '';
            }

            $t = trim( $text );

            if ( preg_match( '/```(?:json)?\s*(.+?)\s*```/is', $t, $m ) ) {
                $t = trim( $m[1] );
            }

            if ( $this->is_valid_json( $t ) ) {
                return $t;
            }

            $first_obj = strpos( $t, '{' );
            $last_obj  = strrpos( $t, '}' );
            if ( $first_obj !== false && $last_obj !== false && $last_obj > $first_obj ) {
                $cand = substr( $t, $first_obj, $last_obj - $first_obj + 1 );
                $cand = trim( $cand );
                if ( $this->is_valid_json( $cand ) ) {
                    return $cand;
                }
            }

            $first_arr = strpos( $t, '[' );
            $last_arr  = strrpos( $t, ']' );
            if ( $first_arr !== false && $last_arr !== false && $last_arr > $first_arr ) {
                $cand = substr( $t, $first_arr, $last_arr - $first_arr + 1 );
                $cand = trim( $cand );
                if ( $this->is_valid_json( $cand ) ) {
                    return $cand;
                }
            }

            return '';
        }

        public function json_decode_safe( $text, $fallback = null ) {
            if ( ! is_string( $text ) ) {
                return $fallback;
            }

            $cand = $this->extract_json_candidate( $text );
            if ( $cand === '' ) {
                return $fallback;
            }

            $decoded = json_decode( $cand, true );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                return $fallback;
            }

            return $decoded;
        }

        public function json_encode_safe( $value ) {
            $json = wp_json_encode( $value );
            if ( is_string( $json ) && $json !== '' ) {
                return $json;
            }
            $json = json_encode( $value );
            return is_string( $json ) ? $json : '';
        }

        public function is_image_attachment( $attachment_id ) {
            $attachment_id = absint( $attachment_id );
            if ( $attachment_id <= 0 ) {
                return false;
            }
            $post = get_post( $attachment_id );
            if ( ! $post || $post->post_type !== 'attachment' ) {
                return false;
            }
            return wp_attachment_is_image( $attachment_id );
        }

        public function can_edit_attachment( $attachment_id ) {
            $attachment_id = absint( $attachment_id );
            if ( $attachment_id <= 0 ) {
                return false;
            }
            return current_user_can( 'edit_post', $attachment_id );
        }

        public function is_allowed_admin() {
            return current_user_can( 'manage_options' );
        }

        public function is_allowed_media_user() {
            return current_user_can( 'upload_files' );
        }

        public function abs_url( $url ) {
            $url = is_string( $url ) ? trim( $url ) : '';
            if ( $url === '' ) {
                return '';
            }
            return esc_url_raw( $url, array( 'http', 'https' ) );
        }

        public function plugin_file() {
            if ( defined( 'YAMANDU_FILE' ) ) {
                return YAMANDU_FILE;
            }
            return '';
        }

        public function plugin_path() {
            if ( defined( 'YAMANDU_PATH' ) ) {
                return YAMANDU_PATH;
            }
            $file = $this->plugin_file();
            return $file !== '' ? plugin_dir_path( $file ) : '';
        }

        public function plugin_url() {
            if ( defined( 'YAMANDU_URL' ) ) {
                return YAMANDU_URL;
            }
            $file = $this->plugin_file();
            return $file !== '' ? plugin_dir_url( $file ) : '';
        }

        public function build_admin_asset_url( $relative ) {
            $base = $this->plugin_url();
            if ( $base === '' ) {
                return '';
            }
            $relative = ltrim( (string) $relative, '/' );
            return $base . 'assets/' . $relative;
        }

        public function build_admin_asset_path( $relative ) {
            $base = $this->plugin_path();
            if ( $base === '' ) {
                return '';
            }
            $relative = ltrim( (string) $relative, '/' );
            return $base . 'assets/' . $relative;
        }

        public function now_gmt_mysql() {
            return gmdate( 'Y-m-d H:i:s' );
        }
    }
}
