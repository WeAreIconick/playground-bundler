import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { PanelBody, Button, Notice, Spinner } from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as coreStore } from '@wordpress/core-data';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const PlaygroundBundlerSidebar = () => {
    const [isGenerating, setIsGenerating] = useState(false);
    const [isAnalyzing, setIsAnalyzing] = useState(false);
    const [detectedBlocks, setDetectedBlocks] = useState([]);
    const [detectedAssets, setDetectedAssets] = useState([]);
    const [analysisData, setAnalysisData] = useState(null);
    const [error, setError] = useState(null);
    
    const { postId, blocks, postType } = useSelect((select) => {
        const editor = select('core/editor');
        return {
            postId: editor.getCurrentPostId(),
            blocks: select(blockEditorStore).getBlocks(),
            postType: editor.getCurrentPostType()
        };
    }, []);
    
    // Analyze blocks whenever they change
    useEffect(() => {
        if (blocks && blocks.length > 0 && postId) {
            analyzeBlocks(blocks);
        }
    }, [blocks, postId]);
    
    const analyzeBlocks = async (blockList) => {
        if (!postId) return;
        
        setIsAnalyzing(true);
        setError(null);
        
        try {
            const response = await apiFetch({
                path: `/playground-bundler/v1/analyze/${postId}`,
                method: 'GET'
            });
            
            if (response.success) {
                setAnalysisData(response.data);
                setDetectedBlocks(response.data.blocks || []);
                setDetectedAssets(response.data.media_types || []);
            }
        } catch (err) {
            console.error('Analysis error:', err);
            setError(err.message || __('Failed to analyze content.', 'playground-bundler'));
        } finally {
            setIsAnalyzing(false);
        }
    };
    
    const handleGenerateBundle = async () => {
        if (!postId) return;
        
        setIsGenerating(true);
        setError(null);
        
        try {
            const response = await fetch(
                playgroundBundler.restUrl + `bundle/${postId}`,
                {
                    method: 'POST',
                    headers: {
                        'X-WP-Nonce': playgroundBundler.nonce,
                        'Content-Type': 'application/json'
                    }
                }
            );
            
            if (!response.ok) {
                const errorData = await response.json();
                throw new Error(errorData.message || __('Failed to generate bundle.', 'playground-bundler'));
            }
            
            // Handle file download
            const blob = await response.blob();
            const link = document.createElement('a');
            link.href = window.URL.createObjectURL(blob);
            link.download = `playground-bundle-${postId}-${Date.now()}.zip`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            window.URL.revokeObjectURL(link.href);
            
        } catch (err) {
            console.error('Bundle generation error:', err);
            setError(err.message || __('Failed to generate bundle.', 'playground-bundler'));
        } finally {
            setIsGenerating(false);
        }
    };
    
    const customBlocks = detectedBlocks.filter(name => !name.startsWith('core/'));
    const hasCustomBlocks = customBlocks.length > 0;
    const hasMediaAssets = detectedAssets.length > 0;
    
    return (
        <>
            <PluginSidebarMoreMenuItem target="playground-bundler-sidebar">
                {__('Playground Bundler', 'playground-bundler')}
            </PluginSidebarMoreMenuItem>
            
            <PluginSidebar
                name="playground-bundler-sidebar"
                title={__('WordPress Playground Bundler', 'playground-bundler')}
                icon="download"
            >
                <PanelBody title={__('Detected Content', 'playground-bundler')} initialOpen={true}>
                    {isAnalyzing && (
                        <div style={{ display: 'flex', alignItems: 'center', marginBottom: '16px' }}>
                            <Spinner />
                            <span style={{ marginLeft: '8px' }}>{__('Analyzing content...', 'playground-bundler')}</span>
                        </div>
                    )}
                    
                    {error && (
                        <Notice status="error" isDismissible={false}>
                            {error}
                        </Notice>
                    )}
                    
                    <div style={{ marginBottom: '16px' }}>
                        <strong>{__('Blocks:', 'playground-bundler')}</strong>
                        <p style={{ fontSize: '13px', color: '#757575' }}>
                            {detectedBlocks.length > 0 
                                ? `${detectedBlocks.length} block type(s) detected`
                                : __('No blocks detected', 'playground-bundler')
                            }
                        </p>
                        
                        {hasCustomBlocks && (
                            <Notice status="info" isDismissible={false}>
                                <strong>{__('Custom blocks found:', 'playground-bundler')}</strong>
                                <ul style={{ marginTop: '8px', paddingLeft: '20px' }}>
                                    {customBlocks.map(block => (
                                        <li key={block} style={{ fontSize: '12px' }}>{block}</li>
                                    ))}
                                </ul>
                            </Notice>
                        )}
                    </div>
                    
                    <div style={{ marginBottom: '16px' }}>
                        <strong>{__('Media Assets:', 'playground-bundler')}</strong>
                        <p style={{ fontSize: '13px', color: '#757575' }}>
                            {analysisData?.media_count > 0
                                ? `${analysisData.media_count} asset(s) detected`
                                : __('No media assets detected', 'playground-bundler')
                            }
                        </p>
                        
                        {hasMediaAssets && (
                            <ul style={{ paddingLeft: '20px', fontSize: '12px' }}>
                                {detectedAssets.map((type, index) => (
                                    <li key={index}>
                                        {type.charAt(0).toUpperCase() + type.slice(1)} files
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </PanelBody>
                
                <PanelBody title={__('Generate Blueprint', 'playground-bundler')} initialOpen={true}>
                    <p style={{ fontSize: '13px', marginBottom: '16px' }}>
                        {__('Create a WordPress Playground blueprint bundle containing all blocks, plugins, and media assets from this page.', 'playground-bundler')}
                    </p>
                    
                    <Button
                        variant="primary"
                        onClick={handleGenerateBundle}
                        isBusy={isGenerating}
                        disabled={isGenerating || !postId}
                        style={{ width: '100%' }}
                    >
                        {isGenerating 
                            ? __('Generating Bundle...', 'playground-bundler')
                            : __('Download Blueprint Bundle', 'playground-bundler')
                        }
                    </Button>
                    
                    {!postId && (
                        <Notice status="warning" isDismissible={false}>
                            {__('Please save the post before generating a bundle.', 'playground-bundler')}
                        </Notice>
                    )}
                </PanelBody>
            </PluginSidebar>
        </>
    );
};

registerPlugin('playground-bundler', {
    render: PlaygroundBundlerSidebar,
    icon: 'download'
});
