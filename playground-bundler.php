<?php
/**
 * Plugin Name: Playground Bundler
 * Description: Create portable WordPress environments by bundling your content, blocks, and plugins into shareable Playground blueprints
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: iconick
 * License: GPL v2 or later
 * Text Domain: playground-bundler
 */

if (!defined('ABSPATH')) {
    exit;
}

// Load required class files
require_once plugin_dir_path(__FILE__) . 'includes/class-asset-detector.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-blueprint-generator.php';

class Playground_Bundler_Plugin {
    
    public function __construct() {
        // Safety check - only run if we're not in the middle of plugin deletion
        if (defined('WP_UNINSTALL_PLUGIN')) {
            return;
        }
        
        add_action('enqueue_block_editor_assets', array($this, 'enqueue_editor_assets'));
        add_action('enqueue_block_assets', array($this, 'enqueue_editor_assets'));
        add_action('admin_enqueue_scripts', array($this, 'maybe_enqueue_editor_assets'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }
    
    public function enqueue_editor_assets() {
        // Safety check
        if (defined('WP_UNINSTALL_PLUGIN') || !file_exists(plugin_dir_path(__FILE__) . 'build/index.js')) {
            return;
        }
        
        // Ensure WordPress editor scripts are loaded
        $wp_scripts = array('wp-editor', 'wp-plugins', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n', 'wp-blocks', 'wp-block-editor', 'wp-api-fetch');
        foreach ($wp_scripts as $script) {
            if (wp_script_is($script, 'registered')) {
                wp_enqueue_script($script);
            }
        }
        
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
    
    public function maybe_enqueue_editor_assets($hook) {
        // Only enqueue on post edit pages
        if (!in_array($hook, array('post.php', 'post-new.php'))) {
            return;
        }
        
        // Check if we're using the block editor
        if (function_exists('use_block_editor_for_post')) {
            global $post;
            if (!$post || !use_block_editor_for_post($post)) {
                return;
            }
        }
        
        $this->enqueue_editor_assets();
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
        
        register_rest_route('playground-bundler/v1', '/download/(?P<filename>[a-zA-Z0-9\-_\.]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_file_download'),
            'permission_callback' => array($this, 'download_permissions_check'),
            'args' => array(
                'filename' => array(
                    'required' => true,
                    'validate_callback' => function($param) {
                        return preg_match('/^[a-zA-Z0-9\-_\.]+$/', $param);
                    }
                )
            )
        ));
        
        // Public download endpoint for WordPress Playground (no auth required)
        register_rest_route('playground-bundler/v1', '/public/(?P<filename>[a-zA-Z0-9\-_\.]+)', array(
            'methods' => array('GET', 'OPTIONS'),
            'callback' => array($this, 'handle_public_file_download'),
            'permission_callback' => '__return_true', // No authentication required
            'args' => array(
                'filename' => array(
                    'required' => true,
                    'validate_callback' => function($param) {
                        return preg_match('/^[a-zA-Z0-9\-_\.]+$/', $param);
                    }
                )
            )
        ));
    }
    
    public function permissions_check($request) {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', __('You must be logged in to access this endpoint.', 'playground-bundler'), array('status' => 401));
        }
        
        // Verify nonce for security
        $nonce = $request->get_header('X-WP-Nonce');
        if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error('invalid_nonce', __('Security check failed.', 'playground-bundler'), array('status' => 403));
        }
        
        // Check capabilities based on operation
        $method = $request->get_method();
        if ($method === 'POST') {
            // Bundle generation requires higher privileges
            if (!current_user_can('upload_files')) {
                return new WP_Error('insufficient_permissions', __('You do not have permission to generate bundles.', 'playground-bundler'), array('status' => 403));
            }
        } else {
            // Analysis requires basic edit permissions
            if (!current_user_can('edit_posts')) {
                return new WP_Error('insufficient_permissions', __('You do not have permission to analyze content.', 'playground-bundler'), array('status' => 403));
            }
        }
        
        return true;
    }
    
    public function download_permissions_check($request) {
        // For downloads, we only need basic authentication
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', __('You must be logged in to access this endpoint.', 'playground-bundler'), array('status' => 401));
        }
        
        // Basic capability check
        if (!current_user_can('read')) {
            return new WP_Error('insufficient_permissions', __('You do not have permission to download files.', 'playground-bundler'), array('status' => 403));
        }
        
        return true;
    }
    
    public function handle_content_analysis($request) {
        // Prevent any output before JSON response
        if (ob_get_level()) {
            ob_clean();
        }
        
        $post_id = absint($request['post_id']);
        
        if (!$post_id || !get_post($post_id)) {
            return new WP_Error('invalid_post', __('Post not found.', 'playground-bundler'), array('status' => 404));
        }
        
        // Additional security: Check if user can edit this specific post
        if (!current_user_can('edit_post', $post_id)) {
            return new WP_Error('insufficient_permissions', __('You do not have permission to analyze this post.', 'playground-bundler'), array('status' => 403));
        }
        
        try {
            // Check if class exists before instantiating
            if (!class_exists('Playground_Asset_Detector')) {
                return new WP_Error('class_not_found', __('Asset detector class not found. Please check plugin installation.', 'playground-bundler'), array('status' => 500));
            }
            
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
            return new WP_Error('analysis_failed', __('Failed to analyze content.', 'playground-bundler'), array('status' => 500));
        }
    }
    
    public function handle_bundle_generation($request) {
        // Prevent any output before JSON response
        if (ob_get_level()) {
            ob_clean();
        }
        
        $post_id = absint($request['post_id']);
        
        if (!$post_id || !get_post($post_id)) {
            return new WP_Error('invalid_post', __('Post not found.', 'playground-bundler'), array('status' => 404));
        }
        
        // Additional security: Check if user can edit this specific post
        if (!current_user_can('edit_post', $post_id)) {
            return new WP_Error('insufficient_permissions', __('You do not have permission to generate bundles for this post.', 'playground-bundler'), array('status' => 403));
        }
        
        // Rate limiting: max 20 bundles per user per hour (increased for development)
        $user_id = get_current_user_id();
        $rate_limit_key = 'playground_bundler_rate_' . $user_id;
        $rate_limit_count = get_transient($rate_limit_key);
        
        if ($rate_limit_count && $rate_limit_count >= 20) {
            return new WP_Error('rate_limit_exceeded', __('Too many bundle generation requests. Please try again later.', 'playground-bundler'), array('status' => 429));
        }
        
        // Increment rate limit counter
        if (!$rate_limit_count) {
            set_transient($rate_limit_key, 1, HOUR_IN_SECONDS);
        } else {
            set_transient($rate_limit_key, $rate_limit_count + 1, HOUR_IN_SECONDS);
        }
        
        // Set memory and time limits for large operations
        @ini_set('memory_limit', '512M');
        @set_time_limit(300);
        
        try {
            // Check if class exists before instantiating
            if (!class_exists('Playground_Blueprint_Generator')) {
                return new WP_Error('class_not_found', __('Blueprint generator class not found. Please check plugin installation.', 'playground-bundler'), array('status' => 500));
            }
            
            $generator = new Playground_Blueprint_Generator($post_id);
            $bundle_path = $generator->create_bundle();
            
            if (is_wp_error($bundle_path)) {
                return $bundle_path;
            }
            
            // Check file size before proceeding (max 50MB)
            $max_size = 50 * 1024 * 1024; // 50MB
            if (file_exists($bundle_path) && filesize($bundle_path) > $max_size) {
                @unlink($bundle_path);
                return new WP_Error('file_too_large', __('Bundle exceeds size limit (50MB). Please reduce content or media.', 'playground-bundler'), array('status' => 413));
            }
            
            // Extract blueprint.json from bundle
            $this->extract_bundle_files($bundle_path);
            
            // Return URL for blueprint file using REST API
            $upload_dir = wp_upload_dir();
            $bundle_filename = basename($bundle_path);
            $bundle_name = pathinfo($bundle_filename, PATHINFO_FILENAME);
            
            $blueprint_filename = $bundle_name . '-blueprint.json';
            $upload_dir = wp_upload_dir();
            $blueprint_url = $upload_dir['baseurl'] . '/playground-bundles/' . $blueprint_filename;
            
            return new WP_REST_Response(array(
                'success' => true,
                'data' => array(
                    'blueprint_url' => esc_url($blueprint_url),
                    'playground_url' => 'https://playground.wordpress.net/?blueprint-url=' . urlencode($blueprint_url),
                    'bundle_name' => sanitize_file_name($bundle_name)
                )
            ), 200);
            
        } catch (Exception $e) {
            return new WP_Error('bundle_failed', __('Failed to generate bundle.', 'playground-bundler'), array('status' => 500));
        }
    }
    
    private function extract_bundle_files($bundle_path) {
        $upload_dir = wp_upload_dir();
        $bundle_filename = basename($bundle_path);
        $bundle_name = pathinfo($bundle_filename, PATHINFO_FILENAME);
        
        // Ensure the bundles directory exists
        $bundles_dir = $upload_dir['basedir'] . '/playground-bundles/';
        if (!wp_mkdir_p($bundles_dir)) {
            return;
        }
        
        $zip = new ZipArchive();
        
        if ($zip->open($bundle_path) === true) {
            // Extract blueprint.json
            $blueprint_content = $zip->getFromName('blueprint.json');
            if ($blueprint_content !== false) {
                $blueprint_file_path = $bundles_dir . $bundle_name . '-blueprint.json';
                file_put_contents($blueprint_file_path, $blueprint_content);
            }
            
            $zip->close();
        }
        
        // Clean up the original bundle
        @unlink($bundle_path);
    }
    
    public function handle_public_file_download($request) {
        // Public endpoint - no authentication required
        // This is specifically for WordPress Playground to access files
        
        // Handle CORS preflight requests
        if ($request->get_method() === 'OPTIONS') {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
            header('Access-Control-Max-Age: 86400');
            return new WP_REST_Response(null, 200);
        }
        
        $filename = $request->get_param('filename');
        
        // Security: Only allow specific file types and patterns
        if (!preg_match('/^(playground-bundle-\d+-\d+-(blueprint\.json|.*\.zip)|[a-zA-Z0-9\-_]+\.zip)$/', $filename)) {
            return new WP_Error('invalid_filename', __('Invalid filename format.', 'playground-bundler'), array('status' => 400));
        }
        
        $upload_dir = wp_upload_dir();
        
        // Check both root directory and plugins subdirectory
        $file_path = $upload_dir['basedir'] . '/playground-bundles/' . $filename;
        error_log('Playground Bundler: Checking file at: ' . $file_path);
        if (!file_exists($file_path)) {
            $file_path = $upload_dir['basedir'] . '/playground-bundles/plugins/' . $filename;
            error_log('Playground Bundler: File not found in root, checking: ' . $file_path);
        }
        
        error_log('Playground Bundler: Final file path: ' . $file_path);
        error_log('Playground Bundler: File exists: ' . (file_exists($file_path) ? 'YES' : 'NO'));
        
        if (!file_exists($file_path)) {
            return new WP_Error('file_not_found', __('File not found.', 'playground-bundler'), array('status' => 404));
        }
        
        // Get file content
        $file_content = file_get_contents($file_path);
        if ($file_content === false) {
            return new WP_Error('file_read_error', __('Could not read file.', 'playground-bundler'), array('status' => 500));
        }
        
        // Determine content type
        $content_type = 'application/octet-stream';
        if (strpos($filename, '.json') !== false) {
            $content_type = 'application/json';
        } elseif (strpos($filename, '.zip') !== false) {
            $content_type = 'application/zip';
        }
        
        // Use direct output for JSON files to avoid WordPress escaping
        if (strpos($filename, '.json') !== false) {
            // Clear any existing output
            if (ob_get_level()) {
                ob_end_clean();
            }
            
            // Set headers directly
            header('Content-Type: ' . $content_type);
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($file_content));
            header('Cache-Control: must-revalidate');
            header('Expires: 0');
            
            // Output the file content directly
            echo $file_content;
            
            // Exit to prevent WordPress from adding anything else
            exit;
        } else {
            // Return file content with proper headers for non-JSON files
            $response = new WP_REST_Response($file_content, 200);
            $response->header('Content-Type', $content_type);
            $response->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
            $response->header('Content-Length', strlen($file_content));
            $response->header('Cache-Control', 'must-revalidate');
            $response->header('Expires', '0');
            
            return $response;
        }
    }
    
    public function add_cors_headers($served, $result, $request, $server) {
        // Only add CORS headers for our public endpoint
        if (strpos($request->get_route(), '/playground-bundler/v1/public/') !== false) {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        }
        return $served;
    }
    
    public function handle_file_download($request) {
        $filename = $request['filename'];
        $upload_dir = wp_upload_dir();
        $bundles_dir = $upload_dir['basedir'] . '/playground-bundles/';
        $file_path = $bundles_dir . $filename;
        
        // Force log this even without WP_DEBUG to see what's happening
        error_log('Playground Bundler: Download request for file: ' . $filename);
        error_log('Playground Bundler: Looking for file at: ' . $file_path);
        error_log('Playground Bundler: File exists: ' . (file_exists($file_path) ? 'YES' : 'NO'));
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Playground bundler: Download request for file: ' . $filename);
            error_log('Playground bundler: Looking for file at: ' . $file_path);
        }
        
        // Ensure bundles directory exists
        if (!wp_mkdir_p($bundles_dir)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Playground bundler: Failed to create bundles directory for download');
            }
            return new WP_Error('directory_error', __('Bundles directory not accessible.', 'playground-bundler'), array('status' => 500));
        }
        
        // Security check - ensure file is in our bundle directory
        $real_file_path = realpath($file_path);
        $real_bundle_dir = realpath($bundles_dir);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Playground bundler: Real file path: ' . ($real_file_path ?: 'null'));
            error_log('Playground bundler: Real bundle dir: ' . ($real_bundle_dir ?: 'null'));
        }
        
        if (!$real_file_path || strpos($real_file_path, $real_bundle_dir) !== 0) {
            // Force log this even without WP_DEBUG
            error_log('Playground Bundler: Security check failed - file not in bundle directory');
            error_log('Playground Bundler: Real file path: ' . ($real_file_path ?: 'null'));
            error_log('Playground Bundler: Real bundle dir: ' . ($real_bundle_dir ?: 'null'));
            error_log('Playground Bundler: Security check: ' . ($real_file_path ? strpos($real_file_path, $real_bundle_dir) : 'null'));
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Playground bundler: Security check failed - file not in bundle directory');
            }
            return new WP_Error('file_not_found', __('File not found.', 'playground-bundler'), array('status' => 404));
        }
        
        if (!file_exists($real_file_path)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Playground bundler: File does not exist: ' . $real_file_path);
                // List files in directory for debugging
                $files = glob($bundles_dir . '*');
                error_log('Playground bundler: Files in bundles directory: ' . print_r($files, true));
            }
            return new WP_Error('file_not_found', __('File not found.', 'playground-bundler'), array('status' => 404));
        }
        
        // Read file content
        $file_content = file_get_contents($real_file_path);
        if ($file_content === false) {
            // Force log this even without WP_DEBUG
            error_log('Playground Bundler: Failed to read file content');
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Playground bundler: Failed to read file content');
            }
            return new WP_Error('file_read_error', __('Could not read file.', 'playground-bundler'), array('status' => 500));
        }
        
        // Force log this even without WP_DEBUG
        error_log('Playground Bundler: Successfully read file, size: ' . strlen($file_content) . ' bytes');
        error_log('Playground Bundler: File content preview: ' . substr($file_content, 0, 200) . '...');
        error_log('Playground Bundler: File content end: ...' . substr($file_content, -200));
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Playground bundler: Successfully read file, size: ' . strlen($file_content) . ' bytes');
        }
        
        // Set proper content type based on file extension
        $content_type = 'application/octet-stream';
        if (strpos($filename, '.json') !== false) {
            $content_type = 'application/json';
        } elseif (strpos($filename, '.zip') !== false) {
            $content_type = 'application/zip';
        }
        
        // Force log this even without WP_DEBUG
        error_log('Playground Bundler: About to return response, content length: ' . strlen($file_content));
        
        // Use direct output instead of WP_REST_Response to avoid truncation issues
        // Clear any existing output
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // Set headers directly
        header('Content-Type: ' . $content_type);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($file_content));
        header('Cache-Control: must-revalidate');
        header('Expires: 0');
        
        // Output the file content directly
        echo $file_content;
        
        // Exit to prevent WordPress from adding anything else
        exit;
    }
    
}

new Playground_Bundler_Plugin();
