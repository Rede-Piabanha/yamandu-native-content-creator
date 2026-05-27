<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'YAMANDU_Admin' ) ) {

    final class YAMANDU_Admin {

        private $core;
        private $utils;
        private $generator;
        private $menu_slug = 'yamandu-settings';

        public function __construct( $core ) {
            $this->core      = $core;
            $this->utils     = is_object( $core ) && method_exists( $core, 'utils' ) ? $core->utils() : null;
            $this->generator = is_object( $core ) && method_exists( $core, 'generator' ) ? $core->generator() : null;

            add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
            add_action( 'admin_notices', array( $this, 'admin_notice_missing_consent' ) );
            add_action( 'admin_notices', array( $this, 'admin_notice_missing_key' ) );
            add_action( 'admin_notices', array( $this, 'admin_notice_action_results' ) );
            add_action( 'admin_notices', array( $this, 'render_media_library_image_generator' ) );

            if ( defined( 'YAMANDU_BASENAME' ) ) {
                add_filter( 'plugin_action_links_' . YAMANDU_BASENAME, array( $this, 'plugin_action_links' ) );
            }

            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

            add_filter( 'media_row_actions', array( $this, 'media_row_actions' ), 10, 3 );
            add_filter( 'bulk_actions-upload', array( $this, 'register_bulk_actions' ) );
            add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_actions' ), 10, 3 );
            add_action( 'add_meta_boxes_attachment', array( $this, 'add_attachment_metabox' ) );
            add_action( 'add_meta_boxes_post', array( $this, 'add_post_text_metabox' ) );

            do_action( 'yamandu_admin_loaded', $this );
        }

        public function add_admin_menu() {
            add_options_page(
                __( 'Yamandu', 'yamandu-native-ai-content-creator' ),
                __( 'Yamandu', 'yamandu-native-ai-content-creator' ),
                'manage_options',
                $this->menu_slug,
                array( $this, 'render_settings_page' )
            );
        }

        public function render_settings_page() {
            if ( is_object( $this->core ) && method_exists( $this->core, 'settings' ) ) {
                $settings = $this->core->settings();
                if ( is_object( $settings ) && method_exists( $settings, 'render_settings_page' ) ) {
                    $settings->render_settings_page();
                    return;
                }
            }

            echo '<div class="wrap">';
            echo '<h1>' . esc_html__( 'Yamandu', 'yamandu-native-ai-content-creator' ) . '</h1>';
            echo '<p>' . esc_html__( 'Settings page is not available.', 'yamandu-native-ai-content-creator' ) . '</p>';
            echo '</div>';
        }

        public function plugin_action_links( $links ) {
            $url     = $this->get_settings_page_url();
            $links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'yamandu-native-ai-content-creator' ) . '</a>';
            return $links;
        }

        public function enqueue_admin_assets( $hook ) {
            $allowed_hooks = array(
                'upload.php',
                'post.php',
                'post-new.php',
                'media.php',
                'settings_page_' . $this->menu_slug,
            );

            if ( ! in_array( $hook, $allowed_hooks, true ) ) {
                return;
            }

            $is_post_edit_hook = in_array( $hook, array( 'post.php', 'post-new.php' ), true );
            $is_post_text_context = $this->is_post_text_generator_context();

            if ( $is_post_edit_hook && ! $this->is_attachment_edit_context() && ! $is_post_text_context ) {
                return;
            }

            $ver = defined( 'YAMANDU_VERSION' ) ? YAMANDU_VERSION : '1.2.8';

            $css_ver = ( defined( 'YAMANDU_PATH' ) && file_exists( YAMANDU_PATH . 'assets/css/admin.css' ) ) ? (string) filemtime( YAMANDU_PATH . 'assets/css/admin.css' ) : $ver;
            $js_ver  = ( defined( 'YAMANDU_PATH' ) && file_exists( YAMANDU_PATH . 'assets/js/admin.js' ) ) ? (string) filemtime( YAMANDU_PATH . 'assets/js/admin.js' ) : $ver;

            wp_enqueue_style(
                'yamandu-admin',
                defined( 'YAMANDU_URL' ) ? YAMANDU_URL . 'assets/css/admin.css' : '',
                array(),
                $css_ver
            );

            $script_deps = array( 'jquery' );
            if ( $is_post_text_context ) {
                foreach ( array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-blocks', 'wp-block-editor' ) as $script_dep ) {
                    if ( wp_script_is( $script_dep, 'registered' ) ) {
                        $script_deps[] = $script_dep;
                    }
                }
            }

            wp_enqueue_script(
                'yamandu-admin',
                defined( 'YAMANDU_URL' ) ? YAMANDU_URL . 'assets/js/admin.js' : '',
                $script_deps,
                $js_ver,
                true
            );

            $options = $this->get_options();

            $supported_fields = $this->supported_fields();

            $data = array(
                'ajaxUrl'               => admin_url( 'admin-ajax.php' ),
                'nonce'                 => wp_create_nonce( 'yamandu_generate' ),
                'globalGenerateLabel'   => __( 'Generate metadata with AI', 'yamandu-native-ai-content-creator' ),
                'globalRegenerateLabel' => __( 'Regenerate metadata with AI', 'yamandu-native-ai-content-creator' ),
                'imageGeneratorTitle'   => __( 'Yamandu Image Generator', 'yamandu-native-ai-content-creator' ),
                'imageGenerateLabel'    => __( 'Generate image with AI', 'yamandu-native-ai-content-creator' ),
                'imagePromptLabel'      => __( 'Image prompt', 'yamandu-native-ai-content-creator' ),
                'imagePromptPlaceholder' => __( 'Describe the image you want to create.', 'yamandu-native-ai-content-creator' ),
                'imagePromptRequired'   => __( 'Enter an image prompt first.', 'yamandu-native-ai-content-creator' ),
                'imageGeneratedLabel'   => __( 'Image generated', 'yamandu-native-ai-content-creator' ),
                'imageOpenLabel'        => __( 'Open generated image', 'yamandu-native-ai-content-creator' ),
                'postTextContext'       => $is_post_text_context ? 1 : 0,
                'textGeneratorTitle'    => __( 'Yamandu Text Generator', 'yamandu-native-ai-content-creator' ),
                'textPromptLabel'       => __( 'Text prompt', 'yamandu-native-ai-content-creator' ),
                'textPromptPlaceholder' => __( 'Describe the text you want to create for this post.', 'yamandu-native-ai-content-creator' ),
                'textGenerateLabel'     => __( 'Generate text with AI', 'yamandu-native-ai-content-creator' ),
                'textPromptRequired'    => __( 'Enter a text prompt first.', 'yamandu-native-ai-content-creator' ),
                'textGeneratedLabel'    => __( 'Text generated', 'yamandu-native-ai-content-creator' ),
                'textInsertLabel'       => __( 'Insert into editor', 'yamandu-native-ai-content-creator' ),
                'textReplaceLabel'      => __( 'Replace selection', 'yamandu-native-ai-content-creator' ),
                'textCopyLabel'         => __( 'Copy text', 'yamandu-native-ai-content-creator' ),
                'textCopiedLabel'       => __( 'Copied', 'yamandu-native-ai-content-creator' ),
                'textNoEditorLabel'     => __( 'Could not find the post editor.', 'yamandu-native-ai-content-creator' ),
                'fieldPrefix'           => 'AI',
                'fieldRegenPrefix'      => '↻',
                'processingLabel'       => __( 'Generating...', 'yamandu-native-ai-content-creator' ),
                'doneLabel'             => __( 'Generated', 'yamandu-native-ai-content-creator' ),
                'noopLabel'             => __( 'No changes', 'yamandu-native-ai-content-creator' ),
                'errorLabel'            => __( 'Error', 'yamandu-native-ai-content-creator' ),
                'validateNonce'         => wp_create_nonce( 'yamandu_validate_key' ),
                'fieldGenerateTip'      => __( 'Generate with AI', 'yamandu-native-ai-content-creator' ),
                'fieldRegenerateTip'    => __( 'Regenerate with AI', 'yamandu-native-ai-content-creator' ),
                'fieldTitleLabel'       => __( 'Title', 'yamandu-native-ai-content-creator' ),
                'fieldAltLabel'         => __( 'Alt text', 'yamandu-native-ai-content-creator' ),
                'validateKeyLabel'      => __( 'Validate API Key', 'yamandu-native-ai-content-creator' ),
                'removeKeyLabel'        => __( 'Remove key', 'yamandu-native-ai-content-creator' ),
                'badgeValidated'        => __( 'Validated', 'yamandu-native-ai-content-creator' ),
                'badgeNotValidated'     => __( 'Not validated', 'yamandu-native-ai-content-creator' ),
                'statusValid'           => __( 'API key validated.', 'yamandu-native-ai-content-creator' ),
                'statusRemoved'         => __( 'API key removed.', 'yamandu-native-ai-content-creator' ),
                'statusFailed'          => __( 'API key validation failed.', 'yamandu-native-ai-content-creator' ),
                'statusNoKey'           => __( 'Enter an API key to validate it.', 'yamandu-native-ai-content-creator' ),
                'statusValidating'      => __( 'Validating...', 'yamandu-native-ai-content-creator' ),
                'statusRemoving'        => __( 'Removing...', 'yamandu-native-ai-content-creator' ),
                'consentRequired'       => $this->third_party_requests_disabled_message(),
                'consentEnabled'        => $this->third_party_requests_enabled( $options ) ? 1 : 0,
                'siteLocale'            => $this->get_site_locale(),
                'supportedFields'       => array_values( array_keys( $supported_fields ) ),
                'supportedFieldLabels'  => $supported_fields,
            );

            wp_localize_script( 'yamandu-admin', 'YamanduAdmin', $data );
        }

        public function add_attachment_metabox() {
            add_meta_box(
                'yamandu_box',
                __( 'Yamandu', 'yamandu-native-ai-content-creator' ),
                array( $this, 'render_attachment_metabox' ),
                'attachment',
                'side',
                'default'
            );
        }

        public function add_post_text_metabox() {
            add_meta_box(
                'yamandu_text_generator_box',
                __( 'Yamandu Text Generator', 'yamandu-native-ai-content-creator' ),
                array( $this, 'render_post_text_metabox' ),
                'post',
                'side',
                'default'
            );
        }

        public function render_post_text_metabox( $post ) {
            $post_id = is_object( $post ) && ! empty( $post->ID ) ? absint( $post->ID ) : 0;
            if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
                echo '<p>' . esc_html__( 'You do not have permission to edit this post.', 'yamandu-native-ai-content-creator' ) . '</p>';
                return;
            }

            $options = $this->get_options();

            if ( ! $this->third_party_requests_enabled( $options ) ) {
                echo '<p>' . esc_html( $this->third_party_requests_disabled_message() ) . '</p>';
                echo '<p><a class="button" href="' . esc_url( $this->get_settings_page_url() ) . '">' . esc_html__( 'Open Yamandu settings', 'yamandu-native-ai-content-creator' ) . '</a></p>';
                return;
            }

            if ( ! $this->api_key_configured( $options ) ) {
                echo '<p>' . esc_html( $this->api_key_missing_message() ) . '</p>';
                echo '<p><a class="button" href="' . esc_url( $this->get_settings_page_url() ) . '">' . esc_html__( 'Open Yamandu settings', 'yamandu-native-ai-content-creator' ) . '</a></p>';
                return;
            }

            $field_id = 'yamandu-text-prompt-' . ( $post_id > 0 ? $post_id : 'post' );

            echo '<div class="yamandu-text-generator" data-post-id="' . esc_attr( (string) $post_id ) . '">';
            echo '<label for="' . esc_attr( $field_id ) . '">' . esc_html__( 'Text prompt', 'yamandu-native-ai-content-creator' ) . '</label>';
            echo '<textarea id="' . esc_attr( $field_id ) . '" class="widefat yamandu-text-prompt" rows="5" placeholder="' . esc_attr__( 'Describe the text you want to create for this post.', 'yamandu-native-ai-content-creator' ) . '"></textarea>';
            echo '<p><button type="button" class="button button-primary yamandu-generate-text">' . esc_html__( 'Generate text with AI', 'yamandu-native-ai-content-creator' ) . '</button></p>';
            echo '<div class="yamandu-text-result" aria-live="polite">';
            echo '<textarea class="widefat yamandu-text-result-text" rows="8" readonly="readonly"></textarea>';
            echo '<p class="yamandu-text-result-actions">';
            echo '<button type="button" class="button yamandu-insert-text">' . esc_html__( 'Insert into editor', 'yamandu-native-ai-content-creator' ) . '</button> ';
            echo '<button type="button" class="button yamandu-replace-text">' . esc_html__( 'Replace selection', 'yamandu-native-ai-content-creator' ) . '</button> ';
            echo '<button type="button" class="button yamandu-copy-text">' . esc_html__( 'Copy text', 'yamandu-native-ai-content-creator' ) . '</button>';
            echo '</p>';
            echo '</div>';
            echo '</div>';
        }

        public function render_attachment_metabox( $post ) {
            $mime = get_post_mime_type( $post->ID );
            if ( ! $mime || strpos( $mime, 'image/' ) !== 0 ) {
                echo '<p>' . esc_html__( 'This attachment is not an image.', 'yamandu-native-ai-content-creator' ) . '</p>';
                return;
            }

            $options = $this->get_options();

            if ( ! $this->third_party_requests_enabled( $options ) ) {
                echo '<p>' . esc_html( $this->third_party_requests_disabled_message() ) . '</p>';
                echo '<p><a class="button" href="' . esc_url( $this->get_settings_page_url() ) . '">' . esc_html__( 'Open Yamandu settings', 'yamandu-native-ai-content-creator' ) . '</a></p>';
                return;
            }

            if ( ! $this->api_key_configured( $options ) ) {
                echo '<p>' . esc_html( $this->api_key_missing_message() ) . '</p>';
                echo '<p><a class="button" href="' . esc_url( $this->get_settings_page_url() ) . '">' . esc_html__( 'Open Yamandu settings', 'yamandu-native-ai-content-creator' ) . '</a></p>';
                return;
            }

            echo '<p>' . esc_html__( 'Generate title and alt text for this image using Cloud Vision and Gemini.', 'yamandu-native-ai-content-creator' ) . '</p>';

            echo '<p><button type="button" class="button yamandu-generate" data-attachment-id="' . esc_attr( (string) $post->ID ) . '" data-overwrite="0">' . esc_html__( 'Generate metadata with AI', 'yamandu-native-ai-content-creator' ) . '</button></p>';

            echo '<p><button type="button" class="button yamandu-generate" data-attachment-id="' . esc_attr( (string) $post->ID ) . '" data-overwrite="1">' . esc_html__( 'Regenerate metadata with AI', 'yamandu-native-ai-content-creator' ) . '</button></p>';

            $this->render_image_generator_box( (int) $post->ID, false );
        }


        public function render_media_library_image_generator() {
            $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
            if ( ! is_object( $screen ) || $screen->id !== 'upload' ) {
                return;
            }

            if ( ! current_user_can( 'upload_files' ) ) {
                return;
            }

            echo '<div class="notice notice-info yamandu-media-image-generator">';
            echo '<h2>' . esc_html__( 'Yamandu Image Generator', 'yamandu-native-ai-content-creator' ) . '</h2>';

            $options = $this->get_options();

            if ( ! $this->third_party_requests_enabled( $options ) ) {
                echo '<p>' . esc_html( $this->third_party_requests_disabled_message() ) . '</p>';
                echo '<p><a class="button" href="' . esc_url( $this->get_settings_page_url() ) . '">' . esc_html__( 'Open Yamandu settings', 'yamandu-native-ai-content-creator' ) . '</a></p>';
                echo '</div>';
                return;
            }

            if ( ! $this->api_key_configured( $options ) ) {
                echo '<p>' . esc_html( $this->api_key_missing_message() ) . '</p>';
                echo '<p><a class="button" href="' . esc_url( $this->get_settings_page_url() ) . '">' . esc_html__( 'Open Yamandu settings', 'yamandu-native-ai-content-creator' ) . '</a></p>';
                echo '</div>';
                return;
            }

            echo '<p>' . esc_html__( 'Describe a new image and save the generated file directly to the Media Library.', 'yamandu-native-ai-content-creator' ) . '</p>';
            $this->render_image_generator_box( 0, true );
            echo '</div>';
        }

        private function render_image_generator_box( $attachment_id = 0, $wide = true ) {
            $attachment_id = absint( $attachment_id );
            $classes       = $wide ? 'yamandu-image-generator yamandu-image-generator-wide' : 'yamandu-image-generator';
            $field_id      = 'yamandu-image-prompt-' . ( $attachment_id > 0 ? $attachment_id : 'library' );

            echo '<div class="' . esc_attr( $classes ) . '">';
            echo '<label for="' . esc_attr( $field_id ) . '">' . esc_html__( 'Image prompt', 'yamandu-native-ai-content-creator' ) . '</label>';
            echo '<textarea id="' . esc_attr( $field_id ) . '" class="widefat yamandu-image-prompt" rows="3" placeholder="' . esc_attr__( 'Describe the image you want to create.', 'yamandu-native-ai-content-creator' ) . '"></textarea>';
            echo '<p><button type="button" class="button button-primary yamandu-generate-image" data-attachment-id="' . esc_attr( (string) $attachment_id ) . '">' . esc_html__( 'Generate image with AI', 'yamandu-native-ai-content-creator' ) . '</button></p>';
            echo '<div class="yamandu-image-result" aria-live="polite"></div>';
            echo '</div>';
        }

        public function media_row_actions( $actions, $post, $detached ) {
            if ( ! $post->post_mime_type || strpos( $post->post_mime_type, 'image/' ) !== 0 ) {
                return $actions;
            }

            $options = $this->get_options();
            if ( ! $this->third_party_requests_enabled( $options ) || ! $this->api_key_configured( $options ) ) {
                return $actions;
            }

            $redirect = admin_url( 'upload.php' );

            $gen_url = add_query_arg(
                array(
                    'action'        => 'yamandu_single',
                    'attachment_id' => $post->ID,
                    'mode'          => 'generate',
                    'redirect_to'   => rawurlencode( $redirect ),
                ),
                admin_url( 'admin-post.php' )
            );
            $gen_url = wp_nonce_url( $gen_url, 'yamandu_single' );

            $regen_url = add_query_arg(
                array(
                    'action'        => 'yamandu_single',
                    'attachment_id' => $post->ID,
                    'mode'          => 'regenerate',
                    'redirect_to'   => rawurlencode( $redirect ),
                ),
                admin_url( 'admin-post.php' )
            );
            $regen_url = wp_nonce_url( $regen_url, 'yamandu_single' );

            $actions['yamandu_generate']   = '<a href="' . esc_url( $gen_url ) . '">' . esc_html__( 'Generate metadata with AI', 'yamandu-native-ai-content-creator' ) . '</a>';
            $actions['yamandu_regenerate'] = '<a href="' . esc_url( $regen_url ) . '">' . esc_html__( 'Regenerate metadata with AI', 'yamandu-native-ai-content-creator' ) . '</a>';

            return $actions;
        }

        public function register_bulk_actions( $actions ) {
            if ( ! current_user_can( 'upload_files' ) ) {
                return $actions;
            }

            $options = $this->get_options();
            if ( ! $this->third_party_requests_enabled( $options ) || ! $this->api_key_configured( $options ) ) {
                return $actions;
            }

            $actions['yamandu_generate']   = __( 'Generate metadata with AI', 'yamandu-native-ai-content-creator' );
            $actions['yamandu_regenerate'] = __( 'Regenerate metadata with AI', 'yamandu-native-ai-content-creator' );

            return $actions;
        }

        public function handle_bulk_actions( $redirect_to, $doaction, $post_ids ) {
            $doaction = sanitize_key( (string) $doaction );
            if ( ! in_array( $doaction, array( 'yamandu_generate', 'yamandu_regenerate' ), true ) ) {
                return $redirect_to;
            }

            if ( ! current_user_can( 'upload_files' ) ) {
                return $this->bulk_redirect( $redirect_to, 0, 1, __( 'You do not have permission to perform this action.', 'yamandu-native-ai-content-creator' ) );
            }

            check_admin_referer( 'bulk-media' );

            $options = $this->get_options();
            if ( ! $this->third_party_requests_enabled( $options ) ) {
                return $this->bulk_redirect( $redirect_to, 0, 1, $this->third_party_requests_disabled_message() );
            }

            if ( ! $this->api_key_configured( $options ) ) {
                return $this->bulk_redirect( $redirect_to, 0, 1, $this->api_key_missing_message() );
            }

            if ( ! is_object( $this->generator ) || ! method_exists( $this->generator, 'analyze_and_update_attachment' ) ) {
                return $this->bulk_redirect( $redirect_to, 0, 1, __( 'Generator is not available.', 'yamandu-native-ai-content-creator' ) );
            }

            $post_ids  = is_array( $post_ids ) ? $post_ids : array();
            $overwrite = $doaction === 'yamandu_regenerate';
            $updated   = 0;
            $errors    = 0;
            $last_err  = '';

            foreach ( $post_ids as $post_id ) {
                $attachment_id = absint( $post_id );
                if ( $attachment_id <= 0 || ! wp_attachment_is_image( $attachment_id ) || ! current_user_can( 'edit_post', $attachment_id ) ) {
                    $errors++;
                    $last_err = __( 'One or more selected items were not editable images.', 'yamandu-native-ai-content-creator' );
                    continue;
                }

                $result = $this->generator->analyze_and_update_attachment( $attachment_id, $options, $overwrite, array( 'title', 'alt' ) );
                if ( is_wp_error( $result ) ) {
                    $errors++;
                    $last_err = $result->get_error_message();
                    continue;
                }

                if ( is_array( $result ) && ! empty( $result['updated'] ) ) {
                    $updated++;
                }
            }

            return $this->bulk_redirect( $redirect_to, $updated, $errors, $last_err );
        }



        public function admin_notice_missing_consent() {
            if ( ! current_user_can( 'manage_options' ) || ! $this->is_relevant_admin_screen() ) {
                return;
            }

            $options = $this->get_options();
            if ( $this->third_party_requests_enabled( $options ) ) {
                return;
            }

            echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Yamandu is currently paused for external requests.', 'yamandu-native-ai-content-creator' ) . '</strong> ';
            echo esc_html__( 'Go to', 'yamandu-native-ai-content-creator' ) . ' ';
            echo wp_kses( '<a href="' . esc_url( $this->get_settings_page_url() ) . '">' . esc_html__( 'Settings → Yamandu', 'yamandu-native-ai-content-creator' ) . '</a>', array( 'a' => array( 'href' => array() ) ) );
            echo ' ' . esc_html__( 'and enable third-party requests to use remote image analysis and metadata generation.', 'yamandu-native-ai-content-creator' );
            echo '</p></div>';
        }

        public function admin_notice_missing_key() {
            if ( ! current_user_can( 'manage_options' ) || ! $this->is_relevant_admin_screen() ) {
                return;
            }

            $options = $this->get_options();
            if ( ! $this->third_party_requests_enabled( $options ) ) {
                return;
            }

            $api_key = isset( $options['api_key'] ) ? trim( (string) $options['api_key'] ) : '';
            if ( $api_key !== '' ) {
                return;
            }

            $url = $this->get_settings_page_url();

            echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Yamandu requires a Google API key.', 'yamandu-native-ai-content-creator' ) . '</strong> ';
            echo esc_html__( 'Go to', 'yamandu-native-ai-content-creator' ) . ' ';
            echo wp_kses( '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings → Yamandu', 'yamandu-native-ai-content-creator' ) . '</a>', array( 'a' => array( 'href' => array() ) ) );
            echo ' ' . esc_html__( 'to add your key.', 'yamandu-native-ai-content-creator' );
            echo '</p></div>';
        }

        public function admin_notice_action_results() {
            if ( ! current_user_can( 'upload_files' ) || ! $this->is_relevant_admin_screen() ) {
                return;
            }

            $notice_nonce = filter_input( INPUT_GET, 'yamandu_notice_nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

            if ( ! $this->is_valid_notice_request( $notice_nonce ) ) {
                return;
            }

            $notice = filter_input( INPUT_GET, 'yamandu_notice', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
            if ( ! is_string( $notice ) || $notice === '' ) {
                return;
            }

            $count = filter_input( INPUT_GET, 'yamandu_count', FILTER_VALIDATE_INT );
            $err   = filter_input( INPUT_GET, 'yamandu_error', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
            $errs  = filter_input( INPUT_GET, 'yamandu_errors', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

            $count = is_int( $count ) ? $count : 0;
            $err   = is_string( $err ) ? $err : '';
            $errs  = is_string( $errs ) ? $errs : '';

            if ( $notice === 'single_ok' ) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Image processed successfully.', 'yamandu-native-ai-content-creator' ) . '</p></div>';
                return;
            }

            if ( $notice === 'single_noop' ) {
                echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'No changes were made (fields already filled, overwrite disabled, or nothing eligible).', 'yamandu-native-ai-content-creator' ) . '</p></div>';
                return;
            }

            if ( $notice === 'single_error' ) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Error:', 'yamandu-native-ai-content-creator' ) . ' ' . esc_html( $err ? $err : __( 'Unknown error.', 'yamandu-native-ai-content-creator' ) ) . '</p></div>';
                return;
            }

            if ( $notice === 'bulk_done' ) {
                $errors = absint( $errs );
                if ( $count > 0 && $errors === 0 ) {
                    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( number_format_i18n( $count ) . ' ' . ( $count === 1 ? __( 'image processed successfully.', 'yamandu-native-ai-content-creator' ) : __( 'images processed successfully.', 'yamandu-native-ai-content-creator' ) ) ) . '</p></div>';
                    return;
                }

                if ( $count > 0 ) {
                    echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( number_format_i18n( $count ) . ' ' . ( $count === 1 ? __( 'image processed successfully.', 'yamandu-native-ai-content-creator' ) : __( 'images processed successfully.', 'yamandu-native-ai-content-creator' ) ) ) . ' ' . esc_html( number_format_i18n( $errors ) . ' ' . ( $errors === 1 ? __( 'item could not be processed.', 'yamandu-native-ai-content-creator' ) : __( 'items could not be processed.', 'yamandu-native-ai-content-creator' ) ) ) . '</p></div>';
                    return;
                }

                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'No selected images could be processed.', 'yamandu-native-ai-content-creator' );
                if ( $err !== '' ) {
                    echo ' ' . esc_html__( 'Last error:', 'yamandu-native-ai-content-creator' ) . ' ' . esc_html( $err );
                }
                echo '</p></div>';
                return;
            }

            do_action( 'yamandu_admin_notice_action_results', $notice, $count, $err, $errs, $this );
        }
        private function supported_fields() {
            if ( is_object( $this->core ) ) {
                if ( method_exists( $this->core, 'free_supported_fields' ) ) {
                    $fields = $this->core->free_supported_fields();
                    if ( is_array( $fields ) && ! empty( $fields ) ) {
                        return $fields;
                    }
                }

                if ( method_exists( $this->core, 'supported_fields' ) ) {
                    $fields = $this->core->supported_fields();
                    if ( is_array( $fields ) && ! empty( $fields ) ) {
                        $normalized = array();
                        foreach ( $fields as $field => $label ) {
                            $field = sanitize_key( (string) $field );
                            if ( in_array( $field, array( 'title', 'alt' ), true ) ) {
                                $normalized[ $field ] = is_string( $label ) && $label !== '' ? $label : ucfirst( $field );
                            }
                        }
                        if ( ! empty( $normalized ) ) {
                            return $normalized;
                        }
                    }
                }
            }

            return array(
                'title' => __( 'Title', 'yamandu-native-ai-content-creator' ),
                'alt'   => __( 'Alt text', 'yamandu-native-ai-content-creator' ),
            );
        }

        private function get_options() {
            if ( is_object( $this->core ) && method_exists( $this->core, 'options' ) ) {
                $opts = $this->core->options();
                return is_array( $opts ) ? $opts : array();
            }

            $opt_name = 'yamandu_options';
            if ( is_object( $this->core ) && method_exists( $this->core, 'option_name' ) ) {
                $opt_name = (string) $this->core->option_name();
            }

            $opts = get_option( $opt_name, array() );
            return is_array( $opts ) ? $opts : array();
        }

        private function get_settings_page_url() {
            return add_query_arg( array( 'page' => $this->menu_slug ), admin_url( 'options-general.php' ) );
        }

        private function bulk_redirect( $redirect_to, $updated, $errors, $last_error ) {
            $redirect_to = is_string( $redirect_to ) && $redirect_to !== '' ? $redirect_to : admin_url( 'upload.php' );
            $args = array(
                'yamandu_notice' => 'bulk_done',
                'yamandu_count'  => absint( $updated ),
                'yamandu_errors' => absint( $errors ),
            );

            $last_error = is_string( $last_error ) ? trim( wp_strip_all_tags( $last_error ) ) : '';
            if ( $last_error !== '' ) {
                $args['yamandu_error'] = rawurlencode( $last_error );
            }

            return add_query_arg( $this->add_notice_query_args( $args ), $redirect_to );
        }

        private function add_notice_query_args( $args ) {
            $args = is_array( $args ) ? $args : array();
            $args['yamandu_notice_nonce'] = wp_create_nonce( 'yamandu_admin_notice' );
            return $args;
        }

        private function is_attachment_edit_context() {
            if ( ! function_exists( 'get_current_screen' ) ) {
                return false;
            }

            $screen = get_current_screen();
            if ( ! $screen ) {
                return false;
            }

            return isset( $screen->post_type ) && (string) $screen->post_type === 'attachment';
        }

        private function is_post_text_generator_context() {
            if ( ! function_exists( 'get_current_screen' ) ) {
                return false;
            }

            $screen = get_current_screen();
            if ( ! $screen ) {
                return false;
            }

            return isset( $screen->base, $screen->post_type ) && (string) $screen->base === 'post' && (string) $screen->post_type === 'post';
        }

        private function get_site_locale() {
            $locale = get_locale();
            return $locale ? (string) $locale : 'en_US';
        }

        private function third_party_requests_enabled( $options = null ) {
            if ( ! is_array( $options ) ) {
                $options = $this->get_options();
            }

            return ! empty( $options['enable_third_party_requests'] );
        }

        private function third_party_requests_disabled_message() {
            return __( 'Third-party requests are disabled. Enable consent in the plugin settings to continue.', 'yamandu-native-ai-content-creator' );
        }

        private function api_key_configured( $options = null ) {
            if ( ! is_array( $options ) ) {
                $options = $this->get_options();
            }

            $api_key = isset( $options['api_key'] ) ? trim( (string) $options['api_key'] ) : '';
            return $api_key !== '';
        }

        private function api_key_missing_message() {
            return __( 'API key is not configured.', 'yamandu-native-ai-content-creator' );
        }

        private function is_valid_notice_request( $nonce ) {
            $nonce = is_string( $nonce ) ? $nonce : '';
            if ( $nonce === '' ) {
                return false;
            }

            return (bool) wp_verify_nonce( $nonce, 'yamandu_admin_notice' );
        }

        private function is_relevant_admin_screen() {
            if ( ! function_exists( 'get_current_screen' ) ) {
                return false;
            }

            $screen = get_current_screen();
            if ( ! $screen || empty( $screen->id ) ) {
                return false;
            }

            $screen_id = (string) $screen->id;
            if ( in_array( $screen_id, array( 'upload', 'attachment', 'media', 'settings_page_' . $this->menu_slug ), true ) ) {
                return true;
            }

            if ( $screen_id === 'post' ) {
                return isset( $screen->post_type ) && (string) $screen->post_type === 'attachment';
            }

            return false;
        }
    }
}
