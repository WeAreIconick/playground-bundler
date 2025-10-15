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
        // Safety check - only run if we're not in the middle of plugin deletion
        if (defined('WP_UNINSTALL_PLUGIN')) {
            return;
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Playground bundler: Plugin constructor called');
        }
        
        // Force log this even without WP_DEBUG to see if it's running
        error_log('Playground Bundler: Plugin constructor called - this should appear in logs');
        
        add_action('enqueue_block_editor_assets', array($this, 'enqueue_editor_assets'));
        add_action('enqueue_block_assets', array($this, 'enqueue_editor_assets'));
        add_action('admin_enqueue_scripts', array($this, 'maybe_enqueue_editor_assets'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Load required class files with safety checks
        $asset_detector_file = plugin_dir_path(__FILE__) . 'includes/class-asset-detector.php';
        $blueprint_generator_file = plugin_dir_path(__FILE__) . 'includes/class-blueprint-generator.php';
        
        if (file_exists($asset_detector_file)) {
            require_once $asset_detector_file;
        } else {
            error_log('Playground Bundler: Asset detector file not found: ' . $asset_detector_file);
        }
        
        if (file_exists($blueprint_generator_file)) {
            require_once $blueprint_generator_file;
        } else {
            error_log('Playground Bundler: Blueprint generator file not found: ' . $blueprint_generator_file);
        }
    }
    
    public function enqueue_editor_assets() {
        // Safety check
        if (defined('WP_UNINSTALL_PLUGIN') || !file_exists(plugin_dir_path(__FILE__) . 'build/index.js')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Playground bundler: Skipping asset enqueue - file not found or uninstalling');
                error_log('Playground bundler: File path: ' . plugin_dir_path(__FILE__) . 'build/index.js');
                error_log('Playground bundler: File exists: ' . (file_exists(plugin_dir_path(__FILE__) . 'build/index.js') ? 'yes' : 'no'));
            }
            return;
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Playground bundler: Enqueuing editor assets');
            $current_screen = function_exists('get_current_screen') ? get_current_screen() : null;
            error_log('Playground bundler: Current screen: ' . ($current_screen ? $current_screen->id : 'unknown'));
        }
        
        // Ensure WordPress editor scripts are loaded (with error handling)
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
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Playground bundler: Script enqueued successfully');
            error_log('Playground bundler: Script URL: ' . plugins_url('build/index.js', __FILE__));
            error_log('Playground bundler: Nonce created: ' . wp_create_nonce('wp_rest'));
        }
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
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Playground bundler: maybe_enqueue_editor_assets called for hook: ' . $hook);
        }
        
        $this->enqueue_editor_assets();
    }
    
    public function register_rest_routes() {
        // Debug: Log that we're registering routes
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Playground bundler: Registering REST API routes');
        }
        
        // Force log this even without WP_DEBUG to see if it's running
        error_log('Playground Bundler: REST API registration called - this should appear in logs');
        
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
        
        // Test endpoint to verify REST API is working
        register_rest_route('playground-bundler/v1', '/test', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_test_endpoint'),
            'permission_callback' => '__return_true'
        ));
        
        // Simple test endpoint without any authentication
        register_rest_route('playground-bundler/v1', '/ping', array(
            'methods' => 'GET',
            'callback' => function() {
                return new WP_REST_Response(array('status' => 'ok', 'message' => 'REST API working'), 200);
            },
            'permission_callback' => '__return_true'
        ));
    }
    
    public function permissions_check($request) {
        if (!is_user_logged_in()) {
            return new WP_Error('not_logged_in', __('You must be logged in to access this endpoint.', 'playground-bundler'), array('status' => 401));
        }
        
        // Verify nonce for security (with debugging)
        $nonce = $request->get_header('X-WP-Nonce');
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Playground bundler: Nonce received: ' . ($nonce ?: 'none'));
            error_log('Playground bundler: Nonce verification: ' . (wp_verify_nonce($nonce, 'wp_rest') ? 'valid' : 'invalid'));
        }
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
    
    public function handle_test_endpoint($request) {
        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'REST API is working',
            'timestamp' => current_time('mysql')
        ), 200);
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
            error_log('Playground bundler analysis error: ' . $e->getMessage());
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
            
            // Log for debugging (only if WP_DEBUG is enabled)
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Playground bundler: Generated blueprint filename: ' . $blueprint_filename);
                error_log('Playground bundler: Blueprint URL: ' . $blueprint_url);
            }
            
            return new WP_REST_Response(array(
                'success' => true,
                'data' => array(
                    'blueprint_url' => esc_url($blueprint_url),
                    'playground_url' => 'https://playground.wordpress.net/?blueprint-url=' . urlencode($blueprint_url),
                    'bundle_name' => sanitize_file_name($bundle_name)
                )
            ), 200);
            
        } catch (Exception $e) {
            error_log('Playground bundle error: ' . $e->getMessage());
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
            error_log('Playground bundler: Failed to create bundles directory');
            return;
        }
        
        $zip = new ZipArchive();
        
        if ($zip->open($bundle_path) === true) {
            // Extract blueprint.json
            $blueprint_content = $zip->getFromName('blueprint.json');
            if ($blueprint_content !== false) {
                $blueprint_file_path = $bundles_dir . $bundle_name . '-blueprint.json';
                $result = file_put_contents($blueprint_file_path, $blueprint_content);
                
                if ($result === false) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('Playground bundler: Failed to write blueprint file to ' . $blueprint_file_path);
                    }
                } else {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('Playground bundler: Successfully extracted blueprint to ' . $blueprint_file_path);
                    }
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('Playground bundler: Failed to extract blueprint.json from bundle');
                }
            }
            
            $zip->close();
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Playground bundler: Failed to open bundle ZIP file: ' . $bundle_path);
            }
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
    
    public function add_admin_menu() {
        add_management_page(
            'Playground Bundler Debug',
            'Playground Bundler Debug',
            'manage_options',
            'playground-bundler-debug',
            array($this, 'debug_page')
        );
    }
    
    public function debug_page() {
        // Check if plugin is active
        $active_plugins = get_option('active_plugins', array());
        $plugin_file = 'playground-bundler/playground-bundler.php';
        $is_active = in_array($plugin_file, $active_plugins);
        
        echo '<div class="wrap">';
        echo '<h1>WordPress Playground Blueprint Bundler - Debug Information</h1>';
        
        // Plugin Status
        echo '<h2>Plugin Status</h2>';
        echo '<p><strong>Plugin Active:</strong> ' . ($is_active ? '<span style="color: green;">Yes</span>' : '<span style="color: red;">No</span>') . '</p>';
        
        if (!$is_active) {
            echo '<p style="color: red; background: #ffe6e6; padding: 10px; border-left: 4px solid #dc3232;"><strong>ERROR:</strong> Plugin is not active!</p>';
            echo '</div>';
            return;
        }
        
        // REST API Status
        echo '<h2>REST API Status</h2>';
        $rest_url = rest_url('wp/v2/');
        echo '<p><strong>REST API URL:</strong> <a href="' . esc_url($rest_url) . '" target="_blank">' . esc_html($rest_url) . '</a></p>';
        
        // Test our plugin endpoints
        echo '<h2>Plugin REST Endpoints</h2>';
        
        $endpoints = array(
            'ping' => rest_url('playground-bundler/v1/ping'),
            'test' => rest_url('playground-bundler/v1/test'),
        );
        
        foreach ($endpoints as $name => $url) {
            echo '<p><strong>' . esc_html($name) . ':</strong> <a href="' . esc_url($url) . '" target="_blank">' . esc_html($url) . '</a></p>';
            
            // Test the endpoint
            $response = wp_remote_get($url);
            if (is_wp_error($response)) {
                echo '<p style="color: red;">Error: ' . esc_html($response->get_error_message()) . '</p>';
            } else {
                $body = wp_remote_retrieve_body($response);
                $code = wp_remote_retrieve_response_code($response);
                echo '<p>Response Code: <strong>' . esc_html($code) . '</strong></p>';
                echo '<p>Response Body: <pre style="background: #f1f1f1; padding: 10px; border-radius: 3px;">' . esc_html($body) . '</pre></p>';
            }
        }
        
        // JavaScript Assets
        echo '<h2>JavaScript Assets</h2>';
        $js_file = plugin_dir_path(__FILE__) . 'build/index.js';
        echo '<p><strong>JS File Path:</strong> ' . esc_html($js_file) . '</p>';
        echo '<p><strong>JS File Exists:</strong> ' . (file_exists($js_file) ? '<span style="color: green;">Yes</span>' : '<span style="color: red;">No</span>') . '</p>';
        
        if (file_exists($js_file)) {
            echo '<p><strong>JS File Size:</strong> ' . esc_html(number_format(filesize($js_file))) . ' bytes</p>';
            echo '<p><strong>JS File URL:</strong> <a href="' . esc_url(plugins_url('build/index.js', __FILE__)) . '" target="_blank">' . esc_html(plugins_url('build/index.js', __FILE__)) . '</a></p>';
        }
        
        // WordPress Debug Settings
        echo '<h2>WordPress Debug Settings</h2>';
        echo '<p><strong>WP_DEBUG:</strong> ' . (defined('WP_DEBUG') && WP_DEBUG ? '<span style="color: green;">Enabled</span>' : '<span style="color: orange;">Disabled</span>') . '</p>';
        echo '<p><strong>WP_DEBUG_LOG:</strong> ' . (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ? '<span style="color: green;">Enabled</span>' : '<span style="color: orange;">Disabled</span>') . '</p>';
        
        // Check error log location
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $log_file = WP_CONTENT_DIR . '/debug.log';
            echo '<p><strong>Debug Log:</strong> <a href="' . esc_url($log_file) . '" target="_blank">' . esc_html($log_file) . '</a></p>';
            
            // Show recent debug log entries
            if (file_exists($log_file)) {
                $log_content = file_get_contents($log_file);
                $log_lines = explode("\n", $log_content);
                $recent_lines = array_slice($log_lines, -20); // Last 20 lines
                $recent_content = implode("\n", $recent_lines);
                
                echo '<h3>Recent Debug Log Entries</h3>';
                echo '<pre style="background: #f1f1f1; padding: 10px; border-radius: 3px; max-height: 300px; overflow-y: auto;">' . esc_html($recent_content) . '</pre>';
            }
        }
        
        // Current Screen Context
        echo '<h2>Current Context</h2>';
        $current_screen = get_current_screen();
        echo '<p><strong>Current Screen:</strong> ' . esc_html($current_screen ? $current_screen->id : 'Unknown') . '</p>';
        echo '<p><strong>Is Block Editor:</strong> ' . (function_exists('is_block_editor') && is_block_editor() ? '<span style="color: green;">Yes</span>' : '<span style="color: orange;">No</span>') . '</p>';
        
        // Plugin Class Status
        echo '<h2>Plugin Class</h2>';
        echo '<p><strong>Plugin Class Exists:</strong> ' . (class_exists('Playground_Bundler_Plugin') ? '<span style="color: green;">Yes</span>' : '<span style="color: red;">No</span>') . '</p>';
        
        // Test JavaScript Loading
        echo '<h2>JavaScript Test</h2>';
        echo '<p>Open browser console and check for:</p>';
        echo '<ul>';
        echo '<li><code>playgroundBundler</code> object should be available</li>';
        echo '<li>No JavaScript errors</li>';
        echo '<li>Plugin sidebar should appear in block editor</li>';
        echo '</ul>';
        
        echo '<h2>Instructions</h2>';
        echo '<ol>';
        echo '<li>Make sure the plugin is active (✓ Done)</li>';
        echo '<li>Test the REST API endpoints above</li>';
        echo '<li>Check if JavaScript file is accessible</li>';
        echo '<li>Enable WP_DEBUG in wp-config.php to see error logs</li>';
        echo '<li>Try the plugin in the block editor (edit a post/page)</li>';
        echo '<li>Check browser console for JavaScript errors</li>';
        echo '</ol>';
        
        echo '</div>';
    }
}

new Playground_Bundler_Plugin();
