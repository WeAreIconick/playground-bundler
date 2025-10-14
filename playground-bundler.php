<?php
/**
 * Plugin Name: WordPress Playground Blueprint Bundler
 * Description: Exports page content and blocks into a WordPress Playground blueprint
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: playground-bundler
 */

if (!defined('ABSPATH')) {
    exit;
}

class Playground_Bundler_Plugin {
    
    public function __construct() {
        add_action('enqueue_block_editor_assets', array($this, 'enqueue_editor_assets'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        
        require_once plugin_dir_path(__FILE__) . 'includes/class-asset-detector.php';
        require_once plugin_dir_path(__FILE__) . 'includes/class-blueprint-generator.php';
    }
    
    public function enqueue_editor_assets() {
        wp_enqueue_script(
            'playground-bundler-sidebar',
            plugins_url('build/index.js', __FILE__),
            array(
                'wp-plugins',
                'wp-editor',
                'wp-element',
                'wp-components',
                'wp-data',
                'wp-i18n',
                'wp-blocks',
                'wp-block-editor',
                'wp-api-fetch'
            ),
            filemtime(plugin_dir_path(__FILE__) . 'build/index.js')
        );
        
        wp_localize_script(
            'playground-bundler-sidebar',
            'playgroundBundler',
            array(
                'restUrl' => rest_url('playground-bundler/v1/'),
                'nonce' => wp_create_nonce('wp_rest')
            )
        );
    }
    
    public function register_rest_routes() {
        register_rest_route('playground-bundler/v1', '/bundle/(?P<post_id>\d+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_bundle_generation'),
            'permission_callback' => array($this, 'permissions_check'),
            'args' => array(
                'post_id' => array(
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                )
            )
        ));
        
        register_rest_route('playground-bundler/v1', '/analyze/(?P<post_id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_content_analysis'),
            'permission_callback' => array($this, 'permissions_check'),
            'args' => array(
                'post_id' => array(
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                )
            )
        ));
    }
    
    public function permissions_check($request) {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', __('You must be logged in to access this endpoint.', 'playground-bundler'), array('status' => 401));
        }
        
        if (!current_user_can('edit_posts')) {
            return new WP_Error('insufficient_permissions', __('You do not have permission to access this endpoint.', 'playground-bundler'), array('status' => 403));
        }
        
        return true;
    }
    
    public function handle_content_analysis($request) {
        $post_id = absint($request['post_id']);
        
        if (!$post_id || !get_post($post_id)) {
            return new WP_Error('invalid_post', __('Post not found.', 'playground-bundler'), array('status' => 404));
        }
        
        try {
            $detector = new Playground_Asset_Detector($post_id);
            $analysis = $detector->analyze();
            
            if (is_wp_error($analysis)) {
                return $analysis;
            }
            
            return new WP_REST_Response(array(
                'success' => true,
                'data' => array(
                    'blocks' => $analysis['block_types'],
                    'custom_blocks' => $analysis['custom_blocks'],
                    'media_count' => count($analysis['media_assets']),
                    'media_types' => array_unique(array_column($analysis['media_assets'], 'type'))
                )
            ), 200);
            
        } catch (Exception $e) {
            error_log('Playground bundler analysis error: ' . $e->getMessage());
            return new WP_Error('analysis_failed', __('Failed to analyze content.', 'playground-bundler'), array('status' => 500));
        }
    }
    
    public function handle_bundle_generation($request) {
        $post_id = absint($request['post_id']);
        
        if (!$post_id || !get_post($post_id)) {
            return new WP_Error('invalid_post', __('Post not found.', 'playground-bundler'), array('status' => 404));
        }
        
        // Rate limiting: max 5 bundles per user per hour
        $user_id = get_current_user_id();
        $rate_limit_key = 'playground_bundler_rate_' . $user_id;
        $rate_limit_count = get_transient($rate_limit_key);
        
        if ($rate_limit_count && $rate_limit_count >= 5) {
            return new WP_Error('rate_limit_exceeded', __('Too many bundle generation requests. Please try again later.', 'playground-bundler'), array('status' => 429));
        }
        
        // Increment rate limit counter
        if (!$rate_limit_count) {
            set_transient($rate_limit_key, 1, HOUR_IN_SECONDS);
        } else {
            set_transient($rate_limit_key, $rate_limit_count + 1, HOUR_IN_SECONDS);
        }
        
        try {
            $generator = new Playground_Blueprint_Generator($post_id);
            $zip_path = $generator->create_bundle();
            
            if (is_wp_error($zip_path)) {
                return $zip_path;
            }
            
            // Stream the file download
            $this->stream_file_download($zip_path);
            
            // Cleanup
            @unlink($zip_path);
            
        } catch (Exception $e) {
            error_log('Playground bundle error: ' . $e->getMessage());
            return new WP_Error('bundle_failed', __('Failed to generate bundle.', 'playground-bundler'), array('status' => 500));
        }
        
        wp_die();
    }
    
    private function stream_file_download($file_path) {
        if (!file_exists($file_path)) {
            wp_die(__('File not found.', 'playground-bundler'));
        }
        
        $filename = basename($file_path);
        $filesize = filesize($file_path);
        
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        header('Content-Description: File Transfer');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . esc_attr($filename) . '"');
        header('Content-Transfer-Encoding: binary');
        header('Content-Length: ' . $filesize);
        header('Cache-Control: must-revalidate');
        header('Expires: 0');
        header('Pragma: public');
        
        flush();
        readfile($file_path);
    }
}

new Playground_Bundler_Plugin();
