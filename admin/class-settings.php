<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'YAMANDU_Settings' ) ) {

    final class YAMANDU_Settings {

        private $core;
        private $utils;
        private $api_client;

        private $menu_slug = 'yamandu-settings';
        private $option_group = 'yamandu_group';

        public function __construct( $core ) {
            $this->core       = $core;
            $this->utils      = is_object( $core ) && method_exists( $core, 'utils' ) ? $core->utils() : null;
            $this->api_client = is_object( $core ) && method_exists( $core, 'api_client' ) ? $core->api_client() : null;

            add_action( 'admin_init', array( $this, 'register_settings' ) );
        }

        public function register_settings() {
            register_setting(
                $this->option_group,
                $this->option_name(),
                array(
                    'type'              => 'array',
                    'sanitize_callback' => array( $this, 'sanitize_options' ),
                    'default'           => $this->defaults(),
                )
            );

            add_settings_section(
                'yamandu_section_api',
                __( 'API', 'yamandu-native-ai-content-creator' ),
                array( $this, 'render_section_api' ),
                $this->menu_slug
            );

            add_settings_field(
                'yamandu_api_key',
                __( 'Google API key', 'yamandu-native-ai-content-creator' ),
                array( $this, 'render_field_api_key' ),
                $this->menu_slug,
                'yamandu_section_api'
            );

            add_settings_field(
                'yamandu_model',
                __( 'Gemini model', 'yamandu-native-ai-content-creator' ),
                array( $this, 'render_field_model' ),
                $this->menu_slug,
                'yamandu_section_api'
            );

            add_settings_field(
                'yamandu_image_generation_model',
                __( 'Image generation model', 'yamandu-native-ai-content-creator' ),
                array( $this, 'render_field_image_generation_model' ),
                $this->menu_slug,
                'yamandu_section_api'
            );

            add_settings_section(
                'yamandu_section_generation',
                __( 'Manual generation', 'yamandu-native-ai-content-creator' ),
                array( $this, 'render_section_generation' ),
                $this->menu_slug
            );

            add_settings_field(
                'yamandu_generate_fields',
                __( 'Fields to generate', 'yamandu-native-ai-content-creator' ),
                array( $this, 'render_field_generate_fields' ),
                $this->menu_slug,
                'yamandu_section_generation'
            );

            add_settings_field(
                'yamandu_overwrite_generate',
                __( 'Overwrite behavior', 'yamandu-native-ai-content-creator' ),
                array( $this, 'render_field_overwrite_generate' ),
                $this->menu_slug,
                'yamandu_section_generation'
            );

            add_settings_section(
                'yamandu_section_privacy',
                __( 'Privacy and data handling', 'yamandu-native-ai-content-creator' ),
                array( $this, 'render_section_privacy' ),
                $this->menu_slug
            );

            add_settings_field(
                'yamandu_enable_third_party_requests',
                __( 'Third-party requests', 'yamandu-native-ai-content-creator' ),
                array( $this, 'render_field_enable_third_party_requests' ),
                $this->menu_slug,
                'yamandu_section_privacy'
            );

            add_settings_field(
                'yamandu_delete_data_on_uninstall',
                __( 'Data removal on uninstall', 'yamandu-native-ai-content-creator' ),
                array( $this, 'render_field_delete_data_on_uninstall' ),
                $this->menu_slug,
                'yamandu_section_privacy'
            );

            do_action( 'yamandu_register_settings', $this );
        }

        public function sanitize_options( $input ) {
            if ( ! current_user_can( 'manage_options' ) ) {
                return $this->options();
            }

            $input = is_array( $input ) ? $input : array();
            $old   = $this->options();
            $new   = $this->defaults();

            $api_key = isset( $input['api_key'] ) ? (string) $input['api_key'] : '';
            $api_key = trim( $api_key );

            if ( $api_key === '' ) {
                $api_key = isset( $old['api_key'] ) ? (string) $old['api_key'] : '';
            }

            $new['api_key'] = $api_key;

            $new['model'] = isset( $input['model'] ) ? (string) $input['model'] : ( isset( $old['model'] ) ? (string) $old['model'] : '' );
            $new['model'] = $this->sanitize_model( $new['model'] );
            if ( $new['model'] === '' ) {
                $new['model'] = 'gemini-2.5-flash';
            }

            $new['image_generation_model'] = isset( $input['image_generation_model'] ) ? (string) $input['image_generation_model'] : ( isset( $old['image_generation_model'] ) ? (string) $old['image_generation_model'] : '' );
            $new['image_generation_model'] = $this->sanitize_image_generation_model( $new['image_generation_model'] );

            $new['enable_third_party_requests'] = $this->to01( isset( $input['enable_third_party_requests'] ) ? $input['enable_third_party_requests'] : 0 );
            $new['delete_data_on_uninstall']    = $this->to01( isset( $input['delete_data_on_uninstall'] ) ? $input['delete_data_on_uninstall'] : 0 );
            $new['overwrite_generate']          = $this->to01( isset( $input['overwrite_generate'] ) ? $input['overwrite_generate'] : 0 );

            foreach ( $this->free_supported_fields() as $field => $label ) {
                $option_key = 'generate_' . $field;
                $new[ $option_key ] = $this->to01( isset( $input[ $option_key ] ) ? $input[ $option_key ] : 0 );
            }

            $old_key = isset( $old['api_key'] ) ? trim( (string) $old['api_key'] ) : '';

            $key_changed = false;
            if ( $old_key !== '' && $api_key !== '' ) {
                $key_changed = ! hash_equals( $old_key, $api_key );
            } elseif ( $old_key === '' && $api_key !== '' ) {
                $key_changed = true;
            } elseif ( $old_key !== '' && $api_key === '' ) {
                $key_changed = true;
            }

            $new['api_key_hash']  = $api_key !== '' ? $this->hash_api_key( $api_key ) : '';
            $new['api_validated'] = isset( $old['api_validated'] ) ? (int) $old['api_validated'] : 0;

            if ( isset( $input['api_validated'] ) && ! $key_changed ) {
                $new['api_validated'] = $this->to01( $input['api_validated'] );
            }

            if ( $key_changed ) {
                $new['api_validated'] = 0;
            }

            if ( $api_key === '' ) {
                $new['api_validated'] = 0;
                $new['api_key_hash']  = '';
            }

            do_action( 'yamandu_after_sanitize_options', $input, $old, $new, $this );

            return $new;
        }

        public function render_settings_page() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'You do not have permission to access this page.', 'yamandu-native-ai-content-creator' ) );
            }

            $opts = $this->options();
            $third_party_enabled = ! empty( $opts['enable_third_party_requests'] );

            echo '<div class="wrap">';
            echo '<h1>' . esc_html__( 'Yamandu', 'yamandu-native-ai-content-creator' ) . '</h1>';

            if ( ! $third_party_enabled ) {
                echo '<div class="notice notice-warning"><p>';
                echo esc_html__( 'Third-party requests are currently disabled. API validation and image metadata generation remain unavailable until you enable consent below and save the settings.', 'yamandu-native-ai-content-creator' );
                echo '</p></div>';
            }

            do_action( 'yamandu_before_settings_form', $this, $opts );

            echo '<form method="post" action="options.php">';
            settings_fields( $this->option_group );
            do_settings_sections( $this->menu_slug );
            submit_button( __( 'Save changes', 'yamandu-native-ai-content-creator' ) );
            echo '</form>';

            do_action( 'yamandu_after_settings_form', $this, $opts );

            echo '</div>';
        }

        public function render_section_api() {
            $console = 'https://console.cloud.google.com/';
            $vision  = 'https://console.cloud.google.com/apis/library/vision.googleapis.com';
            $gemini  = 'https://console.cloud.google.com/apis/library/generativelanguage.googleapis.com';
            $creds   = 'https://console.cloud.google.com/apis/credentials';

            echo '<p>' . esc_html__( 'Follow the steps below to configure the API key and enable the required APIs in Google Cloud.', 'yamandu-native-ai-content-creator' ) . '</p>';

            echo '<ol class="yamandu-steps">';
            echo '<li>' . esc_html__( 'In the', 'yamandu-native-ai-content-creator' ) . ' ';
            echo wp_kses_post( '<a href="' . esc_url( $console ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Google Cloud Console', 'yamandu-native-ai-content-creator' ) . '</a>' );
            echo ' ' . esc_html__( 'select (or create) a project.', 'yamandu-native-ai-content-creator' ) . '</li>';

            echo '<li>' . esc_html__( 'Activate billing for the project and enable the', 'yamandu-native-ai-content-creator' ) . ' ';
            echo wp_kses_post( '<a href="' . esc_url( $vision ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Cloud Vision API', 'yamandu-native-ai-content-creator' ) . '</a>' );
            echo '.</li>';

            echo '<li>' . esc_html__( 'Enable the', 'yamandu-native-ai-content-creator' ) . ' ';
            echo wp_kses_post( '<a href="' . esc_url( $gemini ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Generative Language API', 'yamandu-native-ai-content-creator' ) . '</a>' );
            echo ' ' . esc_html__( '(Gemini API) in the same project.', 'yamandu-native-ai-content-creator' ) . '</li>';

            echo '<li>' . esc_html__( 'Create an', 'yamandu-native-ai-content-creator' ) . ' ';
            echo wp_kses_post( '<a href="' . esc_url( $creds ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'API key', 'yamandu-native-ai-content-creator' ) . '</a>' );
            echo ' ' . esc_html__( 'in APIs & Services > Credentials and restrict it to Cloud Vision API and Generative Language API when possible.', 'yamandu-native-ai-content-creator' ) . '</li>';

            echo '<li>' . esc_html__( 'Paste the key here, enable third-party requests below, click “Validate API Key”, choose the Gemini model, and save changes.', 'yamandu-native-ai-content-creator' ) . '</li>';
            echo '</ol>';
        }

        public function render_section_generation() {
            echo '<p>' . esc_html__( 'Choose whether manual and Media Library bulk runs generate image titles, alt text, or both. Regenerate actions always replace eligible fields.', 'yamandu-native-ai-content-creator' ) . '</p>';
        }

        public function render_section_privacy() {
            echo '<p>' . esc_html__( 'Control whether the plugin may send data to configured third-party services and whether plugin data should be removed when uninstalling.', 'yamandu-native-ai-content-creator' ) . '</p>';
        }

        public function render_field_api_key() {
            $opts                = $this->options();
            $api_key             = isset( $opts['api_key'] ) ? (string) $opts['api_key'] : '';
            $validated           = ! empty( $opts['api_validated'] );
            $third_party_enabled = ! empty( $opts['enable_third_party_requests'] );

            echo '<div class="yamandu-field">';
            echo '<input type="password" class="regular-text" name="' . esc_attr( $this->option_name() . '[api_key]' ) . '" value="' . esc_attr( $api_key ) . '" autocomplete="off" spellcheck="false" />';
            echo '<input type="hidden" id="yamandu-api-validated" name="' . esc_attr( $this->option_name() . '[api_validated]' ) . '" value="' . esc_attr( $validated ? '1' : '0' ) . '" />';
            echo '<p class="description">' . esc_html__( 'A single Google API key can be used for both Vision and Gemini if enabled in your Google Cloud project. External requests stay disabled until you enable consent below.', 'yamandu-native-ai-content-creator' ) . '</p>';

            echo '<div class="yamandu-inline-actions">';
            echo '<button type="button" class="button" id="yamandu-validate-key"';
            if ( ! $third_party_enabled ) {
                echo ' disabled="disabled" data-yamandu-consent-locked="1"';
            }
            echo '>' . esc_html__( 'Validate API Key', 'yamandu-native-ai-content-creator' ) . '</button>';
            echo '<button type="button" class="button button-secondary" id="yamandu-remove-key">' . esc_html__( 'Remove key', 'yamandu-native-ai-content-creator' ) . '</button>';
            echo '</div>';

            $badge = $validated ? esc_html__( 'Validated', 'yamandu-native-ai-content-creator' ) : esc_html__( 'Not validated', 'yamandu-native-ai-content-creator' );
            $cls   = $validated ? 'yamandu-badge-valid' : 'yamandu-badge-warn';

            echo '<p><span class="yamandu-badge ' . esc_attr( $cls ) . '">' . esc_html( $badge ) . '</span></p>';
            echo '<div class="yamandu-status" id="yamandu-key-status" aria-live="polite"></div>';
            echo '</div>';
        }

        public function render_field_model() {
            $opts  = $this->options();
            $model = isset( $opts['model'] ) ? (string) $opts['model'] : 'gemini-2.5-flash';
            $model = $this->sanitize_model( $model );
            if ( $model === '' ) {
                $model = 'gemini-2.5-flash';
            }

            $models = $this->get_models_for_ui( $opts );
            if ( ! is_array( $models ) || empty( $models ) ) {
                $models = array( 'gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-2.5-pro' );
            }

            if ( ! in_array( $model, $models, true ) ) {
                $preferred = array(
                    'gemini-2.5-pro',
                    'gemini-2.5-flash',
                    'gemini-2.5-flash-lite',
                );

                foreach ( $preferred as $p ) {
                    if ( in_array( $p, $models, true ) ) {
                        $model = $p;
                        break;
                    }
                }

                if ( ! in_array( $model, $models, true ) ) {
                    $model = (string) $models[0];
                }
            }

            echo '<select name="' . esc_attr( $this->option_name() . '[model]' ) . '">';
            foreach ( $models as $m ) {
                $m = (string) $m;
                echo '<option value="' . esc_attr( $m ) . '"' . selected( $model, $m, false ) . '>' . esc_html( $m ) . '</option>';
            }
            echo '</select>';
        }

        public function render_field_image_generation_model() {
            $opts   = $this->options();
            $model  = isset( $opts['image_generation_model'] ) ? (string) $opts['image_generation_model'] : 'gemini-2.5-flash-image';
            $model  = $this->sanitize_image_generation_model( $model );
            $models = $this->image_generation_models_for_ui();

            echo '<select name="' . esc_attr( $this->option_name() . '[image_generation_model]' ) . '">';
            foreach ( $models as $value => $label ) {
                echo '<option value="' . esc_attr( $value ) . '"' . selected( $model, $value, false ) . '>' . esc_html( $label ) . '</option>';
            }
            echo '</select>';
            echo '<p class="description">' . esc_html__( 'Used only when creating new images from the Media Library.', 'yamandu-native-ai-content-creator' ) . '</p>';
        }

        public function render_field_generate_fields() {
            $opts   = $this->options();
            $fields = $this->free_supported_fields();

            echo '<fieldset class="yamandu-option-list">';

            foreach ( $fields as $field => $label ) {
                $key     = 'generate_' . $field;
                $checked = ! empty( $opts[ $key ] );
                echo '<label>';
                echo '<input type="checkbox" name="' . esc_attr( $this->option_name() . '[' . $key . ']' ) . '" value="1"' . checked( $checked, true, false ) . ' />';
                echo ' ' . esc_html( $label );
                echo '</label>';
            }

            echo '</fieldset>';
        }

        public function render_field_overwrite_generate() {
            $opts    = $this->options();
            $checked = ! empty( $opts['overwrite_generate'] );

            echo '<label class="yamandu-checkbox-label">';
            echo '<input type="checkbox" name="' . esc_attr( $this->option_name() . '[overwrite_generate]' ) . '" value="1"' . checked( $checked, true, false ) . ' />';
            echo ' ' . esc_html__( 'Allow regular Generate actions to overwrite existing eligible fields. Regenerate actions always overwrite eligible fields.', 'yamandu-native-ai-content-creator' );
            echo '</label>';
        }

        public function render_field_enable_third_party_requests() {
            $opts    = $this->options();
            $checked = ! empty( $opts['enable_third_party_requests'] );

            echo '<label class="yamandu-checkbox-label">';
            echo '<input type="checkbox" name="' . esc_attr( $this->option_name() . '[enable_third_party_requests]' ) . '" value="1"' . checked( $checked, true, false ) . ' />';
            echo ' ' . esc_html__( 'I understand that selected images and related metadata may be sent to configured third-party services and I want to enable these requests.', 'yamandu-native-ai-content-creator' );
            echo '</label>';
            echo '<p class="description">' . esc_html__( 'Required for API validation and metadata generation.', 'yamandu-native-ai-content-creator' ) . '</p>';
        }

        public function render_field_delete_data_on_uninstall() {
            $opts    = $this->options();
            $checked = ! empty( $opts['delete_data_on_uninstall'] );

            echo '<label class="yamandu-checkbox-label">';
            echo '<input type="checkbox" name="' . esc_attr( $this->option_name() . '[delete_data_on_uninstall]' ) . '" value="1"' . checked( $checked, true, false ) . ' />';
            echo ' ' . esc_html__( 'Delete free plugin settings and cached plugin data when the plugin is uninstalled.', 'yamandu-native-ai-content-creator' );
            echo '</label>';
            echo '<p class="description">' . esc_html__( 'Disabled by default. When unchecked, uninstall preserves the free plugin data.', 'yamandu-native-ai-content-creator' ) . '</p>';
        }

        private function free_supported_fields() {
            return array(
                'title' => __( 'Title', 'yamandu-native-ai-content-creator' ),
                'alt'   => __( 'Alt text', 'yamandu-native-ai-content-creator' ),
            );
        }

        private function option_name() {
            if ( is_object( $this->core ) && method_exists( $this->core, 'option_name' ) ) {
                return (string) $this->core->option_name();
            }
            return 'yamandu_options';
        }

        private function defaults() {
            if ( is_object( $this->core ) && method_exists( $this->core, 'defaults' ) ) {
                $d = $this->core->defaults();
                return is_array( $d ) ? $d : array();
            }
            return array();
        }

        private function options() {
            if ( is_object( $this->core ) && method_exists( $this->core, 'options' ) ) {
                $o = $this->core->options();
                return is_array( $o ) ? $o : array();
            }
            $o = get_option( $this->option_name(), array() );
            return is_array( $o ) ? $o : array();
        }

        private function sanitize_model( $model ) {
            $model = is_string( $model ) ? trim( $model ) : '';
            if ( is_object( $this->utils ) && method_exists( $this->utils, 'sanitize_model' ) ) {
                return $this->utils->sanitize_model( $model );
            }
            return preg_replace( '/[^a-zA-Z0-9._:-]/', '', $model );
        }

        private function image_generation_models_for_ui() {
            return array(
                'gemini-2.5-flash-image'         => __( 'Gemini', 'yamandu-native-ai-content-creator' ),
                'gemini-3.1-flash-image-preview' => __( 'Nano Banana', 'yamandu-native-ai-content-creator' ),
                'imagen-4.0-generate-001'        => __( 'Imagen 4', 'yamandu-native-ai-content-creator' ),
            );
        }

        private function sanitize_image_generation_model( $model ) {
            $model  = $this->sanitize_model( $model );
            $models = $this->image_generation_models_for_ui();

            if ( isset( $models[ $model ] ) ) {
                return $model;
            }

            return 'gemini-2.5-flash-image';
        }

        private function to01( $value ) {
            if ( is_object( $this->utils ) && method_exists( $this->utils, 'to_bool' ) ) {
                return $this->utils->to_bool( $value ) ? 1 : 0;
            }
            if ( is_string( $value ) ) {
                $v = strtolower( trim( $value ) );
                return in_array( $v, array( '1', 'true', 'yes', 'on' ), true ) ? 1 : 0;
            }
            return (int) ( (bool) $value );
        }

        private function hash_api_key( $api_key ) {
            $api_key = trim( (string) $api_key );
            if ( $api_key === '' ) {
                return '';
            }
            $salt = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : ( defined( 'AUTH_SALT' ) ? (string) AUTH_SALT : '' );
            return hash_hmac( 'sha256', $api_key, $salt );
        }

        private function get_models_for_ui( $opts ) {
            $api_key  = isset( $opts['api_key'] ) ? trim( (string) $opts['api_key'] ) : '';
            $fallback = array(
                'gemini-2.5-pro',
                'gemini-2.5-flash',
                'gemini-2.5-flash-lite',
            );

            if ( empty( $opts['enable_third_party_requests'] ) ) {
                return $fallback;
            }

            if ( ! is_object( $this->api_client ) || ! method_exists( $this->api_client, 'get_available_gemini_models' ) ) {
                return $fallback;
            }

            $models = $this->api_client->get_available_gemini_models( false, $api_key );

            if ( is_wp_error( $models ) || ! is_array( $models ) || empty( $models ) ) {
                return $fallback;
            }

            $models = array_values( array_unique( array_filter( array_map( 'strval', $models ) ) ) );
            if ( empty( $models ) ) {
                return $fallback;
            }

            $models = array_values( array_unique( array_filter( array_map( array( $this, 'sanitize_model' ), $models ) ) ) );
            if ( empty( $models ) ) {
                return $fallback;
            }

            $priority = array(
                'gemini-2.5-pro',
                'gemini-2.5-flash',
                'gemini-2.5-flash-lite',
            );

            $ordered = array();
            $used    = array();

            foreach ( $priority as $p ) {
                foreach ( $models as $m ) {
                    if ( $m === $p && empty( $used[ $m ] ) ) {
                        $ordered[]  = $m;
                        $used[ $m ] = 1;
                        break;
                    }
                }
            }

            $rest = array();
            foreach ( $models as $m ) {
                if ( empty( $used[ $m ] ) ) {
                    $rest[] = $m;
                }
            }

            sort( $rest, SORT_STRING );

            return array_merge( $ordered, $rest );
        }
    }
}
