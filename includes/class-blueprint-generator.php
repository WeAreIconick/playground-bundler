<?php

require_once plugin_dir_path(__FILE__) . 'class-asset-detector.php';

class Playground_Blueprint_Generator {
    
    private $post_id;
    private $detector;
    private $analysis;
    private $temp_plugin_zips = array();
    
    public function __construct($post_id) {
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
        
        // Generate blueprint JSON
        $blueprint = $this->generate_blueprint_json();
        
        if (is_wp_error($blueprint)) {
            return $blueprint;
        }
        
        // Create ZIP bundle
        $zip_path = $this->create_zip_bundle($blueprint);
        
        // Cleanup temporary plugin ZIPs
        $this->cleanup_temp_files();
        
        return $zip_path;
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
        
        // Install custom block plugins
        $installed_plugins = array();
        
        foreach ($this->analysis['custom_blocks'] as $block_name) {
            $plugin_info = $this->get_plugin_for_block($block_name);
            
            if ($plugin_info && !in_array($plugin_info['plugin_file'], $installed_plugins)) {
                // Create a temporary ZIP of this plugin
                $plugin_zip = $this->create_plugin_zip($plugin_info);
                
                if ($plugin_zip && !is_wp_error($plugin_zip)) {
                    $this->temp_plugin_zips[] = $plugin_zip;
                    
                    $blueprint['steps'][] = array(
                        'step' => 'installPlugin',
                        'pluginData' => array(
                            'resource' => 'bundled',
                            'path' => '/plugins/' . basename($plugin_zip)
                        ),
                        'options' => array(
                            'activate' => true
                        )
                    );
                    
                    $installed_plugins[] = $plugin_info['plugin_file'];
                }
            }
        }
        
        // Upload media assets
        foreach ($this->analysis['media_assets'] as $asset) {
            $upload_dir = wp_upload_dir();
            $relative_path = str_replace($upload_dir['basedir'], '', $asset['path']);
            
            $blueprint['steps'][] = array(
                'step' => 'writeFile',
                'path' => '/wordpress/wp-content/uploads' . $relative_path,
                'data' => array(
                    'resource' => 'bundled',
                    'path' => '/assets' . $relative_path
                )
            );
        }
        
        // Create the post with content
        $blueprint['steps'][] = array(
            'step' => 'runPHP',
            'code' => $this->generate_post_creation_php($post)
        );
        
        return $blueprint;
    }
    
    private function get_landing_page($post) {
        // Use predictable post ID (5) for Playground
        $playground_post_id = 5;
        
        if ($post->post_type === 'page') {
            return '/?page_id=' . $playground_post_id;
        } elseif ($post->post_type === 'post') {
            return '/?p=' . $playground_post_id;
        } else {
            return '/wp-admin/post.php?post=' . $playground_post_id . '&action=edit';
        }
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
        
        // Add all files from plugin directory
        $this->add_directory_to_zip(
            $zip, 
            $plugin_info['plugin_path'],
            $plugin_info['plugin_dir']
        );
        
        $zip->close();
        
        return $zip_path;
    }
    
    private function generate_post_creation_php($post) {
        $title = addslashes($post->post_title);
        $content = addslashes($post->post_content);
        $post_type = $post->post_type;
        $post_status = $post->post_status;
        
        $php = "<?php\n";
        $php .= "require_once '/wordpress/wp-load.php';\n";
        $php .= "\$post_data = array(\n";
        $php .= "    'post_title' => '{$title}',\n";
        $php .= "    'post_content' => '{$content}',\n";
        $php .= "    'post_type' => '{$post_type}',\n";
        $php .= "    'post_status' => '{$post_status}',\n";
        $php .= "    'post_author' => 1\n";
        $php .= ");\n";
        $php .= "\$post_id = wp_insert_post(\$post_data);\n";
        
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
    
    private function create_zip_bundle($blueprint) {
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/playground-bundles/';
        wp_mkdir_p($temp_dir);
        
        $zip_filename = 'playground-bundle-' . $this->post_id . '-' . time() . '.zip';
        $zip_path = $temp_dir . $zip_filename;
        
        $zip = new ZipArchive();
        
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return new WP_Error('zip_error', __('Failed to create ZIP archive.', 'playground-bundler'));
        }
        
        // Add blueprint.json
        $zip->addFromString(
            'blueprint.json',
            wp_json_encode($blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        
        // Add plugin ZIPs
        foreach ($this->temp_plugin_zips as $plugin_zip_path) {
            if (file_exists($plugin_zip_path)) {
                $zip->addFile(
                    $plugin_zip_path,
                    'plugins/' . basename($plugin_zip_path)
                );
            }
        }
        
        // Add media assets
        $upload_base = wp_upload_dir()['basedir'];
        
        foreach ($this->analysis['media_assets'] as $asset) {
            if (file_exists($asset['path'])) {
                $relative_path = str_replace($upload_base, '', $asset['path']);
                $zip->addFile($asset['path'], 'assets' . $relative_path);
            }
        }
        
        $zip->close();
        
        return $zip_path;
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
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir_path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($files as $file) {
            if (!$file->isDir()) {
                $file_path = $file->getRealPath();
                $relative_path = substr($file_path, strlen($dir_path) + 1);
                
                // Add file to ZIP with proper path structure
                $zip->addFile($file_path, $zip_path . '/' . $relative_path);
            }
        }
    }
}
