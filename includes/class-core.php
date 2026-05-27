<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'YAMANDU_Core' ) ) {

    final class YAMANDU_Core {

        private static $instance = null;

        private $option_name = 'yamandu_options';

        private $utils = null;
        private $api_client = null;
        private $generator = null;
        private $ajax = null;
        private $admin = null;
        private $settings = null;

        public static function instance() {
            if ( self::$instance === null ) {
                self::$instance = new self();
                self::$instance->boot();
            }
            return self::$instance;
        }

        private function __construct() {}

        private function boot() {
            $this->includes();
            $this->hooks();
            $this->init_components();
        }

        private function includes() {
            $files = array(
                YAMANDU_PATH . 'includes/class-utils.php',
                YAMANDU_PATH . 'includes/class-api-client.php',
                YAMANDU_PATH . 'includes/class-generator.php',
                YAMANDU_PATH . 'includes/class-ajax.php',
                YAMANDU_PATH . 'admin/class-admin.php',
                YAMANDU_PATH . 'admin/class-settings.php',
            );

            foreach ( $files as $file ) {
                if ( is_readable( $file ) ) {
                    require_once $file;
                }
            }
        }

        private function hooks() {
            add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
            do_action( 'yamandu_register_core_hooks', $this );
        }



        private function init_components() {
            if ( class_exists( 'YAMANDU_Utils' ) ) {
                $this->utils = new YAMANDU_Utils( $this );
            }

            if ( class_exists( 'YAMANDU_API_Client' ) ) {
                $this->api_client = new YAMANDU_API_Client( $this );
            }

            if ( class_exists( 'YAMANDU_Generator' ) ) {
                $this->generator = new YAMANDU_Generator( $this );
            }

            if ( class_exists( 'YAMANDU_Ajax' ) ) {
                $this->ajax = new YAMANDU_Ajax( $this );
            }

            if ( is_admin() ) {
                if ( class_exists( 'YAMANDU_Admin' ) ) {
                    $this->admin = new YAMANDU_Admin( $this );
                }
                if ( class_exists( 'YAMANDU_Settings' ) ) {
                    $this->settings = new YAMANDU_Settings( $this );
                }
            }

            do_action( 'yamandu_loaded', $this );
        }

        public function add_privacy_policy_content() {
            if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
                return;
            }

            $plugin_name = __( 'Yamandu', 'yamandu-native-ai-content-creator' );

            ob_start();
            ?>
            <p>
                <?php echo esc_html__( 'Yamandu can send data to third-party services in order to generate post text and image metadata such as image titles and alt text for SEO and accessibility. These requests only run after an administrator explicitly enables third-party requests in the plugin settings.', 'yamandu-native-ai-content-creator' ); ?>
            </p>

            <h3><?php echo esc_html__( 'What data is sent', 'yamandu-native-ai-content-creator' ); ?></h3>
            <ul>
                <li><?php echo esc_html__( 'Image file content for attachments you choose to process manually.', 'yamandu-native-ai-content-creator' ); ?></li>
                <li><?php echo esc_html__( 'Existing attachment metadata (such as title and alt text) when you regenerate content for an attachment.', 'yamandu-native-ai-content-creator' ); ?></li>
                <li><?php echo esc_html__( 'Derived signals produced during analysis (for example: labels, detected text/OCR output, web entities, and logo detection results), which may be included in the prompt used to generate metadata.', 'yamandu-native-ai-content-creator' ); ?></li>
                <li><?php echo esc_html__( 'Site language/locale and plugin settings relevant to generation, such as the selected model.', 'yamandu-native-ai-content-creator' ); ?></li>
                <li><?php echo esc_html__( 'Text prompts and optional selected post text when you use the post editor text generator.', 'yamandu-native-ai-content-creator' ); ?></li>
            </ul>

            <h3><?php echo esc_html__( 'Where the data is sent', 'yamandu-native-ai-content-creator' ); ?></h3>
            <p>
                <?php
                echo wp_kses_post( '<a href="' . esc_url( 'https://cloud.google.com/vision' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Cloud Vision API', 'yamandu-native-ai-content-creator' ) . '</a>' );
                echo ' ' . esc_html__( 'and', 'yamandu-native-ai-content-creator' ) . ' ';
                echo wp_kses_post( '<a href="' . esc_url( 'https://ai.google.dev/' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Gemini API (Generative Language API)', 'yamandu-native-ai-content-creator' ) . '</a>' );
                echo ' ' . esc_html__( 'are Google services configured with your API key. Processing is subject to Google’s terms and privacy policy.', 'yamandu-native-ai-content-creator' );
                ?>
            </p>
            <p>
                <?php
                echo esc_html__( 'Learn more:', 'yamandu-native-ai-content-creator' ) . ' ';
                echo wp_kses_post( '<a href="' . esc_url( 'https://policies.google.com/privacy' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Google Privacy Policy', 'yamandu-native-ai-content-creator' ) . '</a>.' );
                ?>
            </p>

            <h3><?php echo esc_html__( 'Why the data is sent', 'yamandu-native-ai-content-creator' ); ?></h3>
            <p>
                <?php echo esc_html__( 'The data is sent to analyze images, generate meaningful metadata to improve accessibility and SEO, and generate requested text for WordPress post editing workflows.', 'yamandu-native-ai-content-creator' ); ?>
            </p>

            <h3><?php echo esc_html__( 'What is stored on your site', 'yamandu-native-ai-content-creator' ); ?></h3>
            <ul>
                <li><?php echo esc_html__( 'Generated metadata is stored in your WordPress database as attachment fields such as the post title and the image alt text meta.', 'yamandu-native-ai-content-creator' ); ?></li>
                <li><?php echo esc_html__( 'Generated post text is inserted only when an editor chooses to insert or replace content in the post editor.', 'yamandu-native-ai-content-creator' ); ?></li>
                <li><?php echo esc_html__( 'The plugin stores your API key and related settings in the WordPress options table to authenticate requests to third-party APIs.', 'yamandu-native-ai-content-creator' ); ?></li>
                <li><?php echo esc_html__( 'The plugin stores whether third-party requests are enabled and whether plugin data should be deleted on uninstall.', 'yamandu-native-ai-content-creator' ); ?></li>
                <li><?php echo esc_html__( 'The plugin may cache the list of available Gemini models using WordPress transients to improve performance.', 'yamandu-native-ai-content-creator' ); ?></li>
            </ul>

            <h3><?php echo esc_html__( 'Privacy considerations and compliance', 'yamandu-native-ai-content-creator' ); ?></h3>
            <p>
                <?php echo esc_html__( 'Images and extracted text may contain personal data. By enabling and using this plugin, you confirm you have the appropriate rights and lawful basis to process and send this data to third parties (for example under GDPR/UK GDPR, CCPA/CPRA, LGPD, and similar laws). You are responsible for updating your site privacy policy and obtaining any required consents.', 'yamandu-native-ai-content-creator' ); ?>
            </p>
            <?php
            $content = ob_get_clean();

            wp_add_privacy_policy_content(
                $plugin_name,
                wp_kses_post( $content )
            );
        }

        public function deactivate() {
            flush_rewrite_rules();
        }


        public function option_name() {
            return $this->option_name;
        }

        public function defaults() {
            $defaults = array(
                'api_key'                     => '',
                'api_key_hash'                => '',
                'api_validated'               => 0,
                'model'                       => 'gemini-2.5-flash',
                'image_generation_model'      => 'gemini-2.5-flash-image',
                'enable_third_party_requests' => 0,
                'delete_data_on_uninstall'    => 0,
                'overwrite_generate'          => 0,
                'generate_title'              => 1,
                'generate_alt'                => 1,
            );

            return (array) apply_filters( 'yamandu_defaults', $defaults, $this );
        }


        public function free_supported_fields() {
            return array(
                'title' => __( 'Title', 'yamandu-native-ai-content-creator' ),
                'alt'   => __( 'Alt text', 'yamandu-native-ai-content-creator' ),
            );
        }

        public function free_supported_field_keys() {
            return array_keys( $this->free_supported_fields() );
        }

        public function supported_fields() {
            $fields = array(
                'title' => __( 'Title', 'yamandu-native-ai-content-creator' ),
                'alt'   => __( 'Alt text', 'yamandu-native-ai-content-creator' ),
            );

            $fields = apply_filters( 'yamandu_supported_fields', $fields, $this );

            if ( ! is_array( $fields ) ) {
                return array();
            }

            $normalized = array();
            foreach ( $fields as $field => $label ) {
                $field = sanitize_key( (string) $field );
                if ( $field === '' ) {
                    continue;
                }
                $normalized[ $field ] = is_string( $label ) && $label !== '' ? $label : ucfirst( $field );
            }

            return $normalized;
        }

        public function supported_field_keys() {
            return array_keys( $this->supported_fields() );
        }

        public function supports_field( $field ) {
            $field = sanitize_key( (string) $field );
            return in_array( $field, $this->supported_field_keys(), true );
        }
        public function is_feature_available( $feature = '' ) {
            $feature   = sanitize_key( (string) $feature );
            $available = false;

            if ( $feature === '' || $feature === 'manual_generation' ) {
                $available = true;
            } elseif ( in_array( $feature, array( 'title', 'alt' ), true ) ) {
                $available = $this->supports_field( $feature );
            } elseif ( $feature === 'bulk_processing' ) {
                $available = true;
            } elseif ( $feature === 'overwrite_rules' ) {
                $available = true;
            } elseif ( in_array( $feature, array( 'caption', 'description', 'auto_generation_on_upload' ), true ) ) {
                $available = false;
            }

            return (bool) apply_filters( 'yamandu_is_feature_available', $available, $feature, $this );
        }

        public function options() {
            $options = get_option( $this->option_name, array() );
            $options = is_array( $options ) ? $options : array();
            $options = wp_parse_args( $options, $this->defaults() );
            return (array) apply_filters( 'yamandu_options', $options, $this );
        }

        public function utils() {
            return $this->utils;
        }

        public function api_client() {
            return $this->api_client;
        }

        public function generator() {
            return $this->generator;
        }

        public function ajax() {
            return $this->ajax;
        }

        public function admin() {
            return $this->admin;
        }

        public function settings() {
            return $this->settings;
        }

        public function version() {
            return defined( 'YAMANDU_VERSION' ) ? YAMANDU_VERSION : '';
        }

        private function third_party_requests_enabled( $options = null ) {
            if ( ! is_array( $options ) ) {
                $options = $this->options();
            }

            return ! empty( $options['enable_third_party_requests'] );
        }
    }
}
