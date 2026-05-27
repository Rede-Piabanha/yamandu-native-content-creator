<?php
/*
Plugin Name: Yamandu Native AI Content Creator
Description: Generate images, title and alt text with AI to improve media metadata, SEO, and accessibility.
Version: 1.0.0
Author: Rede Piabanha
Author URI: https://piabanha.net/
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: yamandu-native-ai-content-creator
Domain Path: /languages
Requires at least: 5.8
Requires PHP: 7.4
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'yamandu_freemius' ) ) {
    function yamandu_freemius() {
        global $yamandu_freemius;

        if ( ! isset( $yamandu_freemius ) ) {
            require_once dirname( __FILE__ ) . '/vendor/freemius/start.php';

            $yamandu_freemius = fs_dynamic_init( array(
                'id'                  => '28108',
                'slug'                => 'yamandu-native-ai-content-creator',
                'type'                => 'plugin',
                'public_key'          => 'pk_f978b2e5cd168719eafec6c02f4f0',
                'is_premium'          => false,
                'has_premium_version' => false,
                'has_addons'          => true,
                'has_paid_plans'      => false,
                'is_org_compliant'    => true,
                'menu'                => array(
                    'slug'           => 'yamandu-settings',
                    'override_exact' => true,
                    'first-path'     => 'options-general.php?page=yamandu-settings',
                    'contact'        => false,
                    'support'        => false,
                    'parent'         => array(
                        'slug' => 'options-general.php',
                    ),
                ),
            ) );
        }

        return $yamandu_freemius;
    }

    yamandu_freemius();
    do_action( 'yamandu_freemius_loaded' );

    function yamandu_freemius_settings_url() {
        return admin_url( 'options-general.php?page=yamandu-settings' );
    }

    function yamandu_after_uninstall() {
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
    }

    yamandu_freemius()->add_filter( 'connect_url', 'yamandu_freemius_settings_url' );
    yamandu_freemius()->add_filter( 'after_skip_url', 'yamandu_freemius_settings_url' );
    yamandu_freemius()->add_filter( 'after_connect_url', 'yamandu_freemius_settings_url' );
    yamandu_freemius()->add_filter( 'after_pending_connect_url', 'yamandu_freemius_settings_url' );
    yamandu_freemius()->add_action( 'after_uninstall', 'yamandu_after_uninstall' );
}

if ( ! defined( 'YAMANDU_VERSION' ) ) {
    define( 'YAMANDU_VERSION', '1.0.0' );
}

if ( ! defined( 'YAMANDU_FILE' ) ) {
    define( 'YAMANDU_FILE', __FILE__ );
}

if ( ! defined( 'YAMANDU_BASENAME' ) ) {
    define( 'YAMANDU_BASENAME', plugin_basename( __FILE__ ) );
}

if ( ! defined( 'YAMANDU_PATH' ) ) {
    define( 'YAMANDU_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'YAMANDU_URL' ) ) {
    define( 'YAMANDU_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'YAMANDU_TEXT_DOMAIN' ) ) {
    define( 'YAMANDU_TEXT_DOMAIN', 'yamandu-native-ai-content-creator' );
}



if ( ! function_exists( 'yamandu_core_file' ) ) {
    function yamandu_core_file() {
        return YAMANDU_PATH . 'includes/class-core.php';
    }
}

$yamandu_core_file = yamandu_core_file();

if ( is_readable( $yamandu_core_file ) ) {
    require_once $yamandu_core_file;
}

if ( ! function_exists( 'yamandu' ) ) {
    function yamandu() {
        if ( class_exists( 'YAMANDU_Core' ) && method_exists( 'YAMANDU_Core', 'instance' ) ) {
            return YAMANDU_Core::instance();
        }
        return null;
    }
}

if ( ! function_exists( 'yamandu_boot' ) ) {
    function yamandu_boot() {
        return yamandu();
    }
}

if ( ! function_exists( 'yamandu_is_addon_active' ) ) {
    function yamandu_is_addon_active( $feature = '' ) {
        $core = yamandu();
        if ( $core && method_exists( $core, 'is_feature_available' ) ) {
            return (bool) $core->is_feature_available( $feature );
        }
        return false;
    }
}

if ( ! function_exists( 'yamandu_supported_fields' ) ) {
    function yamandu_supported_fields() {
        $core = yamandu();
        if ( $core && method_exists( $core, 'supported_fields' ) ) {
            return (array) $core->supported_fields();
        }
        return array();
    }
}

if ( ! function_exists( 'yamandu_activate' ) ) {
    function yamandu_activate() {
        $core = yamandu();
        if ( $core && method_exists( $core, 'activate' ) ) {
            $core->activate();
            return;
        }
        flush_rewrite_rules();
    }
}

if ( ! function_exists( 'yamandu_deactivate' ) ) {
    function yamandu_deactivate() {
        $core = yamandu();
        if ( $core && method_exists( $core, 'deactivate' ) ) {
            $core->deactivate();
            return;
        }
        flush_rewrite_rules();
    }
}

register_activation_hook( __FILE__, 'yamandu_activate' );
register_deactivation_hook( __FILE__, 'yamandu_deactivate' );

add_action( 'plugins_loaded', 'yamandu_boot', 20 );
