<?php
/**
 * Uninstall script for WordPress Playground Blueprint Bundler
 * 
 * This file is executed when the plugin is uninstalled (deleted).
 * It removes all data created by the plugin.
 */

// If uninstall not called from WordPress, then exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove all plugin options
delete_option('playground_bundler_settings');

// Remove all transients created by the plugin
global $wpdb;

// Get all transients with our prefix
$transients = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
        '_transient_playground_bundler_%'
    )
);

foreach ($transients as $transient) {
    $transient_name = str_replace('_transient_', '', $transient->option_name);
    delete_transient($transient_name);
}

// Clean up temporary bundle files
$upload_dir = wp_upload_dir();
$bundle_dir = $upload_dir['basedir'] . '/playground-bundles/';

if (is_dir($bundle_dir)) {
    // Remove all files in the bundle directory
    $files = glob($bundle_dir . '*');
    if ($files) {
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
    
    // Remove subdirectories
    $subdirs = glob($bundle_dir . '*', GLOB_ONLYDIR);
    if ($subdirs) {
        foreach ($subdirs as $subdir) {
            remove_directory($subdir);
        }
    }
    
    // Remove the main bundle directory
    @rmdir($bundle_dir);
}

/**
 * Recursively remove a directory and all its contents
 */
function remove_directory($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    
    $files = array_diff(scandir($dir), array('.', '..'));
    
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            remove_directory($path);
        } else {
            @unlink($path);
        }
    }
    
    return @rmdir($dir);
}

// Remove any custom database tables if they exist
// (This plugin doesn't create custom tables, but this is here for future use)
// $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}playground_bundler_data");

// Clear any cached data
wp_cache_flush();

