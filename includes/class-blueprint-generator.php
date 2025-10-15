<?php

require_once plugin_dir_path(__FILE__) . 'class-asset-detector.php';

class Playground_Blueprint_Generator {
    
    private $post_id;
    private $detector;
    private $analysis;
    private $temp_plugin_zips = array();
    
    public function __construct($post_id) {
        // Safety check - prevent execution during plugin deletion
        if (defined('WP_UNINSTALL_PLUGIN')) {
            return;
        }
        
        $this->post_id = absint($post_id);
        $this->detector = new Playground_Asset_Detector($post_id);
    }
    
    public function create_bundle() {
        // Check ZipArchive availability
        if (!class_exists('ZipArchive')) {
            return new WP_Error('no_zip', __('ZipArchive extension not available.', 'playground-bundler'));
        }
        
        // Analyze post content
        $this->analysis = $this->detector->analyze();
        
        if (is_wp_error($this->analysis)) {
            return $this->analysis;
        }
        
        // No need to create WordPress files ZIP - we'll handle plugins and media individually
        
        // Generate blueprint JSON
        $blueprint = $this->generate_blueprint_json();
        
        if (is_wp_error($blueprint)) {
            return $blueprint;
        }
        
        // Create final bundle with blueprint.json
        $bundle_path = $this->create_final_bundle($blueprint);
        
        // Cleanup temporary plugin ZIPs
        $this->cleanup_temp_files();
        
        return $bundle_path;
    }
    
    private function generate_blueprint_json() {
        $post = get_post($this->post_id);
        
        if (!$post) {
            return new WP_Error('invalid_post', __('Post not found.', 'playground-bundler'));
        }
        
        $blueprint = array(
            '$schema' => 'https://playground.wordpress.net/blueprint-schema.json',
            'landingPage' => $this->get_landing_page($post),
            'preferredVersions' => array(
                'php' => '8.3',
                'wp' => 'latest'
            ),
            'features' => array(
                'networking' => true
            ),
            'steps' => array()
        );
        
        // Add login step
        $blueprint['steps'][] = array(
            'step' => 'login',
            'username' => 'admin',
            'password' => 'password'
        );
        
        // Upload media files using runPHP
        if (!empty($this->analysis['media_assets'])) {
            $blueprint['steps'][] = array(
                'step' => 'runPHP',
                'code' => $this->generate_media_upload_php()
            );
        }
        
        // Install and activate custom block plugins
        $installed_plugins = array();
        foreach ($this->analysis['custom_blocks'] as $block_name) {
            $plugin_info = $this->get_plugin_for_block($block_name);
            
            if ($plugin_info && !in_array($plugin_info['plugin_file'], $installed_plugins)) {
                // Create plugin ZIP and get URL
                $plugin_zip_path = $this->create_plugin_zip($plugin_info);
                if ($plugin_zip_path && !is_wp_error($plugin_zip_path)) {
                    $upload_dir = wp_upload_dir();
                    $plugin_zip_filename = basename($plugin_zip_path);
                    $plugin_zip_url = $upload_dir['baseurl'] . '/playground-bundles/' . $plugin_zip_filename;
                    
                    $blueprint['steps'][] = array(
                        'step' => 'installPlugin',
                        'pluginZipFile' => array(
                            'resource' => 'url',
                            'url' => $plugin_zip_url
                        ),
                        'options' => array(
                            'activate' => true
                        )
                    );
                    
                    $installed_plugins[] = $plugin_info['plugin_file'];
                }
            }
        }
        
        // Note: Theme switching removed - WordPress Playground will use the default theme
        // This avoids potential issues with custom themes that might not be available
        
        // Clear any caches to ensure everything is fresh
        $blueprint['steps'][] = array(
            'step' => 'runPHP',
            'code' => "<?php\nrequire_once '/wordpress/wp-load.php';\nwp_cache_flush();\nif (function_exists('rocket_clean_domain')) { rocket_clean_domain(); }\nif (function_exists('w3tc_flush_all')) { w3tc_flush_all(); }"
        );
        
        // Create the post with content after import
        $blueprint['steps'][] = array(
            'step' => 'runPHP',
            'code' => $this->generate_post_creation_php($post)
        );
        
        return $blueprint;
    }
    
    private function get_landing_page($post) {
        // Use predictable post ID (5) for Playground
        $playground_post_id = 5;
        
        // Always open in backend editor for better user experience
        return '/wp-admin/post.php?post=' . $playground_post_id . '&action=edit';
    }
    
    private function get_plugin_for_block($block_name) {
        // First check if block is registered
        $registry = WP_Block_Type_Registry::get_instance();
        $block_type = $registry->get_registered($block_name);
        
        if (!$block_type) {
            return null;
        }
        
        // Try to determine plugin from editor script
        $plugin_file = null;
        $plugin_dir = null;
        
        if ($block_type->editor_script) {
            $script = wp_scripts()->query($block_type->editor_script, 'registered');
            if ($script && isset($script->src)) {
                // Extract plugin directory from script source
                if (preg_match('#/wp-content/plugins/([^/]+)/#', $script->src, $matches)) {
                    $plugin_dir = $matches[1];
                    
                    // Find the main plugin file
                    $plugin_file = $this->find_plugin_file($plugin_dir);
                }
            }
        }
        
        // Fallback: search for block.json files
        if (!$plugin_file) {
            $plugin_file = $this->find_plugin_by_block_json($block_name);
            if ($plugin_file) {
                $plugin_dir = dirname($plugin_file);
                $plugin_dir = basename($plugin_dir);
            }
        }
        
        if (!$plugin_file) {
            return null;
        }
        
        return array(
            'block_name' => $block_name,
            'plugin_file' => $plugin_file,
            'plugin_dir' => $plugin_dir,
            'plugin_path' => WP_PLUGIN_DIR . '/' . $plugin_dir
        );
    }
    
    private function find_plugin_file($plugin_dir) {
        $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_dir;
        
        if (!is_dir($plugin_path)) {
            return null;
        }
        
        // Look for main plugin file (PHP file with plugin headers)
        $php_files = glob($plugin_path . '/*.php');
        
        foreach ($php_files as $file) {
            $content = file_get_contents($file);
            
            // Check if file has plugin headers
            if (preg_match('/Plugin Name:/i', $content)) {
                return $plugin_dir . '/' . basename($file);
            }
        }
        
        return null;
    }
    
    private function find_plugin_by_block_json($block_name) {
        $all_plugins = get_plugins();
        
        foreach ($all_plugins as $plugin_file => $plugin_data) {
            $plugin_dir = dirname(WP_PLUGIN_DIR . '/' . $plugin_file);
            
            // Search for block.json files
            $block_json_files = $this->glob_recursive($plugin_dir . '/block.json');
            
            foreach ($block_json_files as $block_json_file) {
                $block_data = json_decode(file_get_contents($block_json_file), true);
                
                if (isset($block_data['name']) && $block_data['name'] === $block_name) {
                    return $plugin_file;
                }
            }
        }
        
        return null;
    }
    
    private function glob_recursive($pattern, $flags = 0) {
        $files = glob($pattern, $flags);
        
        foreach (glob(dirname($pattern) . '/*', GLOB_ONLYDIR | GLOB_NOSORT) as $dir) {
            $files = array_merge($files, $this->glob_recursive($dir . '/' . basename($pattern), $flags));
        }
        
        return $files;
    }
    
    private function create_plugin_zip($plugin_info) {
        if (!isset($plugin_info['plugin_path']) || !is_dir($plugin_info['plugin_path'])) {
            return new WP_Error('invalid_plugin', __('Plugin directory not found.', 'playground-bundler'));
        }
        
        // Security: Validate plugin path is within WordPress plugins directory
        $real_plugin_path = realpath($plugin_info['plugin_path']);
        $real_wp_plugins_dir = realpath(WP_PLUGIN_DIR);
        
        if (!$real_plugin_path || strpos($real_plugin_path, $real_wp_plugins_dir) !== 0) {
            return new WP_Error('invalid_plugin_path', __('Invalid plugin path.', 'playground-bundler'));
        }
        
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/playground-bundles/plugins/';
        wp_mkdir_p($temp_dir);
        
        $zip_filename = sanitize_file_name($plugin_info['plugin_dir']) . '.zip';
        $zip_path = $temp_dir . $zip_filename;
        
        // Delete existing ZIP if present
        if (file_exists($zip_path)) {
            @unlink($zip_path);
        }
        
        $zip = new ZipArchive();
        
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return new WP_Error('zip_error', __('Failed to create plugin ZIP.', 'playground-bundler'));
        }
        
        // Add all files from plugin directory with security checks
        $this->add_directory_to_zip(
            $zip, 
            $plugin_info['plugin_path'],
            $plugin_info['plugin_dir']
        );
        
        $zip->close();
        
        return $zip_path;
    }
    
    private function generate_media_upload_php() {
        $php = "<?php\n";
        $php .= "require_once '/wordpress/wp-load.php';\n";
        $php .= "\n";
        $php .= "// Create uploads directory structure\n";
        $php .= "\$upload_dir = wp_upload_dir();\n";
        $php .= "wp_mkdir_p(\$upload_dir['path']);\n";
        $php .= "\n";
        
        foreach ($this->analysis['media_assets'] as $asset) {
            $filename = basename($asset['path']);
            $url = $asset['url'];
            
            $php .= "// Upload: {$filename}\n";
            $php .= "\$response = wp_remote_get('{$url}');\n";
            $php .= "if (!is_wp_error(\$response)) {\n";
            $php .= "    \$file_content = wp_remote_retrieve_body(\$response);\n";
            $php .= "    \$file_path = \$upload_dir['path'] . '/{$filename}';\n";
            $php .= "    file_put_contents(\$file_path, \$file_content);\n";
            $php .= "    \n";
            $php .= "    // Create attachment\n";
            $php .= "    \$attachment = array(\n";
            $php .= "        'post_mime_type' => wp_check_filetype(\$file_path)['type'],\n";
            $php .= "        'post_title' => sanitize_file_name(pathinfo('{$filename}', PATHINFO_FILENAME)),\n";
            $php .= "        'post_content' => '',\n";
            $php .= "        'post_status' => 'inherit'\n";
            $php .= "    );\n";
            $php .= "    \$attachment_id = wp_insert_attachment(\$attachment, \$file_path);\n";
            $php .= "    \n";
            $php .= "    if (!is_wp_error(\$attachment_id)) {\n";
            $php .= "        require_once(ABSPATH . 'wp-admin/includes/image.php');\n";
            $php .= "        \$attachment_data = wp_generate_attachment_metadata(\$attachment_id, \$file_path);\n";
            $php .= "        wp_update_attachment_metadata(\$attachment_id, \$attachment_data);\n";
            $php .= "    }\n";
            $php .= "}\n";
            $php .= "\n";
        }
        
        return $php;
    }
    
    private function generate_post_creation_php($post) {
        $title = addslashes($post->post_title);
        $content = addslashes($post->post_content);
        $post_type = $post->post_type;
        $post_status = $post->post_status;
        
        $php = "<?php\n";
        $php .= "require_once '/wordpress/wp-load.php';\n";
        $php .= "\n";
        $php .= "// Delete any existing post with ID 5 to avoid conflicts\n";
        $php .= "wp_delete_post(5, true);\n";
        $php .= "\n";
        $php .= "\$post_data = array(\n";
        $php .= "    'ID' => 5,\n";
        $php .= "    'post_title' => '{$title}',\n";
        $php .= "    'post_content' => '{$content}',\n";
        $php .= "    'post_type' => '{$post_type}',\n";
        $php .= "    'post_status' => '{$post_status}',\n";
        $php .= "    'post_author' => 1\n";
        $php .= ");\n";
        $php .= "\$post_id = wp_insert_post(\$post_data);\n";
        $php .= "\n";
        $php .= "// Ensure we got the expected post ID\n";
        $php .= "if (\$post_id !== 5) {\n";
        $php .= "    // If insert failed, try update\n";
        $php .= "    \$post_data['ID'] = 5;\n";
        $php .= "    \$post_id = wp_update_post(\$post_data);\n";
        $php .= "}\n";
        
        // Add post meta if needed
        $meta = get_post_meta($this->post_id);
        if (!empty($meta)) {
            foreach ($meta as $key => $values) {
                // Skip private meta
                if (strpos($key, '_') !== 0) {
                    $value = addslashes(maybe_serialize($values[0]));
                    $php .= "update_post_meta(\$post_id, '{$key}', '{$value}');\n";
                }
            }
        }
        
        $php .= "?>";
        
        return $php;
    }
    
    private function create_wordpress_files_zip() {
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/playground-bundles/';
        wp_mkdir_p($temp_dir);
        
        $zip_filename = 'wordpress-files-' . $this->post_id . '-' . time() . '.zip';
        $zip_path = $temp_dir . $zip_filename;
        
        $zip = new ZipArchive();
        
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return new WP_Error('zip_error', __('Failed to create WordPress files ZIP.', 'playground-bundler'));
        }
        
        // Add wp-content structure
        $this->add_wp_content_to_zip($zip);
        
        $zip->close();
        
        return $zip_path;
    }
    
    private function add_wp_content_to_zip($zip) {
        // Add plugins directory
        $this->add_plugins_to_zip($zip);
        
        // Add uploads directory with media assets
        $this->add_uploads_to_zip($zip);
        
        // Add themes directory (active theme)
        $this->add_active_theme_to_zip($zip);
    }
    
    private function add_plugins_to_zip($zip) {
        // Add custom block plugins
        foreach ($this->analysis['custom_blocks'] as $block_name) {
            $plugin_info = $this->get_plugin_for_block($block_name);
            
            if ($plugin_info) {
                $plugin_zip = $this->create_plugin_zip($plugin_info);
                
                if ($plugin_zip && !is_wp_error($plugin_zip)) {
                    $this->temp_plugin_zips[] = $plugin_zip;
                    
                    // Extract plugin ZIP into wp-content/plugins/
                    $this->extract_zip_to_directory($plugin_zip, 'wp-content/plugins/', $zip);
                }
            }
        }
    }
    
    private function add_uploads_to_zip($zip) {
        $upload_base = wp_upload_dir()['basedir'];
        
        foreach ($this->analysis['media_assets'] as $asset) {
            if (file_exists($asset['path'])) {
                $relative_path = str_replace($upload_base, '', $asset['path']);
                $zip->addFile($asset['path'], 'wp-content/uploads' . $relative_path);
            }
        }
    }
    
    private function add_active_theme_to_zip($zip) {
        $active_theme = get_stylesheet();
        $theme_path = get_theme_root() . '/' . $active_theme;
        
        if (is_dir($theme_path)) {
            $this->add_directory_to_zip($zip, $theme_path, 'wp-content/themes/' . $active_theme);
        }
    }
    
    private function extract_zip_to_directory($zip_path, $target_path, $main_zip) {
        $temp_zip = new ZipArchive();
        
        if ($temp_zip->open($zip_path) === true) {
            for ($i = 0; $i < $temp_zip->numFiles; $i++) {
                $filename = $temp_zip->getNameIndex($i);
                $file_content = $temp_zip->getFromIndex($i);
                
                if ($file_content !== false) {
                    $main_zip->addFromString($target_path . $filename, $file_content);
                }
            }
            $temp_zip->close();
        }
    }
    
    private function create_final_bundle($blueprint) {
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/playground-bundles/';
        wp_mkdir_p($temp_dir);
        
        $bundle_filename = 'playground-bundle-' . $this->post_id . '-' . time() . '.zip';
        $bundle_path = $temp_dir . $bundle_filename;
        
        $bundle_zip = new ZipArchive();
        
        if ($bundle_zip->open($bundle_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return new WP_Error('zip_error', __('Failed to create final bundle.', 'playground-bundler'));
        }
        
        // Add blueprint.json
        $bundle_zip->addFromString(
            'blueprint.json',
            wp_json_encode($blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        
        $bundle_zip->close();
        
        return $bundle_path;
    }
    
    private function cleanup_temp_files() {
        foreach ($this->temp_plugin_zips as $zip_path) {
            if (file_exists($zip_path)) {
                @unlink($zip_path);
            }
        }
        
        // Clean up old plugin temp directory
        $upload_dir = wp_upload_dir();
        $plugin_temp_dir = $upload_dir['basedir'] . '/playground-bundles/plugins/';
        
        if (is_dir($plugin_temp_dir)) {
            $files = glob($plugin_temp_dir . '*.zip');
            $now = time();
            
            foreach ($files as $file) {
                // Delete files older than 1 hour
                if ($now - filemtime($file) >= 3600) {
                    @unlink($file);
                }
            }
        }
    }
    
    private function add_directory_to_zip($zip, $dir_path, $zip_path) {
        // Security: Validate directory path
        $real_dir_path = realpath($dir_path);
        if (!$real_dir_path || !is_dir($real_dir_path)) {
            return;
        }
        
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir_path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($files as $file) {
            if (!$file->isDir()) {
                $file_path = $file->getRealPath();
                $relative_path = substr($file_path, strlen($dir_path) + 1);
                
                // Security: Skip potentially dangerous files
                if ($this->is_dangerous_file($relative_path)) {
                    continue;
                }
                
                // Security: Check file size (max 10MB per file)
                if (filesize($file_path) > 10 * 1024 * 1024) {
                    continue;
                }
                
                // Add file to ZIP with proper path structure
                $zip->addFile($file_path, $zip_path . '/' . $relative_path);
            }
        }
    }
    
    private function is_dangerous_file($filename) {
        $dangerous_extensions = array('php', 'phtml', 'php3', 'php4', 'php5', 'pl', 'py', 'jsp', 'asp', 'sh', 'cgi');
        $dangerous_files = array('.htaccess', 'web.config', '.env', 'composer.json', 'package.json');
        
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $basename = strtolower(basename($filename));
        
        return in_array($extension, $dangerous_extensions) || in_array($basename, $dangerous_files);
    }
}
