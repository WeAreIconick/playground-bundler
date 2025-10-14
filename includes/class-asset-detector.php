<?php

class Playground_Asset_Detector {
    
    private $post_id;
    private $blocks = array();
    private $media_assets = array();
    private $block_types = array();
    
    public function __construct($post_id) {
        $this->post_id = absint($post_id);
    }
    
    public function analyze() {
        $post = get_post($this->post_id);
        
        if (!$post) {
            return new WP_Error('invalid_post', __('Post not found.', 'playground-bundler'));
        }
        
        // Parse blocks from post content
        $this->blocks = parse_blocks($post->post_content);
        
        // Analyze blocks recursively
        $this->process_blocks($this->blocks);
        
        // Get all attached media
        $this->get_attached_media();
        
        return array(
            'blocks' => $this->blocks,
            'block_types' => array_unique($this->block_types),
            'media_assets' => $this->media_assets,
            'custom_blocks' => $this->get_custom_blocks()
        );
    }
    
    private function process_blocks($blocks, $depth = 0) {
        foreach ($blocks as $block) {
            if (empty($block['blockName'])) {
                continue;
            }
            
            $this->block_types[] = $block['blockName'];
            
            // Extract media from different block types
            $this->extract_media_from_block($block);
            
            // Process nested blocks
            if (!empty($block['innerBlocks'])) {
                $this->process_blocks($block['innerBlocks'], $depth + 1);
            }
        }
    }
    
    private function extract_media_from_block($block) {
        $attrs = $block['attrs'] ?? array();
        
        // Image block
        if ($block['blockName'] === 'core/image' && isset($attrs['id'])) {
            $this->add_media_asset($attrs['id'], 'image');
        }
        
        // Gallery block
        if ($block['blockName'] === 'core/gallery' && isset($attrs['ids'])) {
            foreach ($attrs['ids'] as $id) {
                $this->add_media_asset($id, 'image');
            }
        }
        
        // Audio block
        if ($block['blockName'] === 'core/audio' && isset($attrs['id'])) {
            $this->add_media_asset($attrs['id'], 'audio');
        }
        
        // Video block
        if ($block['blockName'] === 'core/video' && isset($attrs['id'])) {
            $this->add_media_asset($attrs['id'], 'video');
        }
        
        // File block
        if ($block['blockName'] === 'core/file' && isset($attrs['id'])) {
            $this->add_media_asset($attrs['id'], 'file');
        }
        
        // Media & Text block
        if ($block['blockName'] === 'core/media-text' && isset($attrs['mediaId'])) {
            $this->add_media_asset($attrs['mediaId'], 'image');
        }
        
        // Cover block
        if ($block['blockName'] === 'core/cover' && isset($attrs['id'])) {
            $this->add_media_asset($attrs['id'], 'image');
        }
        
        // Group block with background image
        if ($block['blockName'] === 'core/group' && isset($attrs['style']['background']['image']['id'])) {
            $this->add_media_asset($attrs['style']['background']['image']['id'], 'image');
        }
        
        // Columns block with background image
        if ($block['blockName'] === 'core/columns' && isset($attrs['style']['background']['image']['id'])) {
            $this->add_media_asset($attrs['style']['background']['image']['id'], 'image');
        }
    }
    
    private function add_media_asset($attachment_id, $type) {
        if (!isset($this->media_assets[$attachment_id])) {
            $file_path = get_attached_file($attachment_id);
            $file_url = wp_get_attachment_url($attachment_id);
            
            if ($file_path && file_exists($file_path)) {
                $this->media_assets[$attachment_id] = array(
                    'id' => $attachment_id,
                    'type' => $type,
                    'path' => $file_path,
                    'url' => $file_url,
                    'filename' => basename($file_path),
                    'mime_type' => get_post_mime_type($attachment_id),
                    'title' => get_the_title($attachment_id)
                );
            }
        }
    }
    
    private function get_attached_media() {
        // Get all media attached to this post
        $attachments = get_posts(array(
            'post_type' => 'attachment',
            'posts_per_page' => -1,
            'post_parent' => $this->post_id,
            'post_status' => 'inherit'
        ));
        
        foreach ($attachments as $attachment) {
            $mime_type = get_post_mime_type($attachment->ID);
            $type = 'file';
            
            if (strpos($mime_type, 'image/') === 0) {
                $type = 'image';
            } elseif (strpos($mime_type, 'audio/') === 0) {
                $type = 'audio';
            } elseif (strpos($mime_type, 'video/') === 0) {
                $type = 'video';
            }
            
            $this->add_media_asset($attachment->ID, $type);
        }
    }
    
    private function get_custom_blocks() {
        $custom = array();
        
        foreach (array_unique($this->block_types) as $block_name) {
            // Custom blocks don't start with 'core/' or 'core-embed/'
            if (strpos($block_name, 'core/') !== 0 && strpos($block_name, 'core-embed/') !== 0) {
                $custom[] = $block_name;
            }
        }
        
        return $custom;
    }
}
