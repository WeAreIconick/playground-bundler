import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { PanelBody, Button, Notice, Spinner } from '@wordpress/components';
import { useState, useEffect, useCallback } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

// Debug: Log that the script is loading
console.log('Playground Bundler: JavaScript loaded successfully');
console.log('Playground Bundler: playgroundBundler object:', window.playgroundBundler);

// Check if WordPress dependencies are available
console.log('Playground Bundler: wp.plugins available:', !!window.wp?.plugins);
console.log('Playground Bundler: wp.editor available:', !!window.wp?.editor);
console.log('Playground Bundler: wp.components available:', !!window.wp?.components);
console.log('Playground Bundler: wp.element available:', !!window.wp?.element);
console.log('Playground Bundler: wp.data available:', !!window.wp?.data);
console.log('Playground Bundler: wp.blockEditor available:', !!window.wp?.blockEditor);
console.log('Playground Bundler: wp.i18n available:', !!window.wp?.i18n);
console.log('Playground Bundler: wp.apiFetch available:', !!window.wp?.apiFetch);

// Debug: Check if we can register the plugin
console.log('Playground Bundler: Attempting to register plugin...');

const PlaygroundBundlerSidebar = () => {
	const [ isGenerating, setIsGenerating ] = useState( false );
	const [ isAnalyzing, setIsAnalyzing ] = useState( false );
	const [ detectedBlocks, setDetectedBlocks ] = useState( [] );
	const [ detectedAssets, setDetectedAssets ] = useState( [] );
	const [ analysisData, setAnalysisData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ bundleGenerated, setBundleGenerated ] = useState( false );
	const [ bundleData, setBundleData ] = useState( null );

	const { postId, blocks } = useSelect( ( select ) => {
		const editor = select( 'core/editor' );
		return {
			postId: editor.getCurrentPostId(),
			blocks: select( blockEditorStore ).getBlocks(),
		};
	}, [] );

	// Analyze blocks whenever they change
	const analyzeBlocks = useCallback( async () => {
		if ( ! postId ) {
			return;
		}

		setIsAnalyzing( true );
		setError( null );

		try {
			console.log('Playground Bundler: Making analyze request...');
			console.log('Playground Bundler: REST URL:', playgroundBundler.restUrl + 'analyze/' + postId);
			console.log('Playground Bundler: Using nonce:', playgroundBundler.nonce);
			
			// Force REST API usage by using full URL
			const response = await apiFetch( {
				url: playgroundBundler.restUrl + 'analyze/' + postId,
				method: 'GET',
				headers: {
					'X-WP-Nonce': playgroundBundler.nonce,
				},
			} );

			if ( response.success ) {
				setAnalysisData( response.data );
				setDetectedBlocks( response.data.blocks || [] );
				setDetectedAssets( response.data.media_types || [] );
			}
		} catch ( err ) {
			setError(
				err.message ||
					__( 'Failed to analyze content.', 'playground-bundler' )
			);
		} finally {
			setIsAnalyzing( false );
		}
	}, [ postId ] );

	// Analyze blocks whenever they change
	useEffect( () => {
		if ( blocks && blocks.length > 0 && postId ) {
			analyzeBlocks();
		}
	}, [ blocks, postId, analyzeBlocks ] );

	const handleGenerateBundle = async () => {
		if ( ! postId ) {
			return;
		}

		setIsGenerating( true );
		setError( null );
		setBundleGenerated( false );
		setBundleData( null );

		try {
			console.log('Playground Bundler: Making bundle request...');
			console.log('Playground Bundler: REST URL:', playgroundBundler.restUrl + 'bundle/' + postId);
			
			// Force REST API usage by using full URL
			const response = await apiFetch( {
				url: playgroundBundler.restUrl + 'bundle/' + postId,
				method: 'POST',
				headers: {
					'X-WP-Nonce': playgroundBundler.nonce,
				},
			} );

			if ( response.success ) {
				setBundleData( response.data );
				setBundleGenerated( true );
				setError( null );
			} else {
				throw new Error(
					response.message ||
						__( 'Failed to generate bundle.', 'playground-bundler' )
				);
			}
		} catch ( err ) {
			// Handle specific error types
			let errorMessage = __(
				'Failed to generate bundle.',
				'playground-bundler'
			);

			if ( err.message ) {
				if ( err.message.includes( 'rate_limit' ) ) {
					errorMessage = __(
						'Too many requests. Please wait before trying again.',
						'playground-bundler'
					);
				} else if ( err.message.includes( 'file_too_large' ) ) {
					errorMessage = __(
						'Bundle too large. Please reduce content or media files.',
						'playground-bundler'
					);
				} else if ( err.message.includes( 'permission' ) ) {
					errorMessage = __(
						'You do not have permission to generate bundles.',
						'playground-bundler'
					);
				} else {
					errorMessage = err.message;
				}
			}

			setError( errorMessage );
			setBundleGenerated( false );
		} finally {
			setIsGenerating( false );
		}
	};

	const handleDownloadBundle = async () => {
		if ( ! bundleData ) {
			return;
		}

		try {
			console.log('Playground Bundler: Starting download...');
			console.log('Playground Bundler: Download URL:', bundleData.blueprint_url);
			console.log('Playground Bundler: Nonce:', playgroundBundler.nonce);
			
			// Use fetch to download with proper authentication
			const response = await fetch(bundleData.blueprint_url, {
				method: 'GET',
				headers: {
					'X-WP-Nonce': playgroundBundler.nonce,
				},
			});
			
			if (!response.ok) {
				throw new Error(`HTTP ${response.status}: ${response.statusText}`);
			}
			
			console.log('Playground Bundler: Download response received');
			
			// Get the blob from the response
			const blob = await response.blob();
			const url = window.URL.createObjectURL(blob);
			
			const blueprintLink = document.createElement('a');
			blueprintLink.href = url;
			blueprintLink.download = bundleData.bundle_name + '-blueprint.json';
			blueprintLink.style.display = 'none';
			document.body.appendChild(blueprintLink);
			blueprintLink.click();
			document.body.removeChild(blueprintLink);
			
			// Clean up the blob URL
			window.URL.revokeObjectURL(url);
			
		} catch ( downloadError ) {
			console.error('Playground Bundler: Download error:', downloadError);
			console.error('Playground Bundler: Error details:', {
				message: downloadError.message,
				status: downloadError.status,
				code: downloadError.code,
				data: downloadError.data
			});
			setError(
				__( 'Download failed: ' + (downloadError.message || 'Unknown error'), 'playground-bundler' )
			);
		}
	};

	const handleOpenInPlayground = () => {
		if ( ! bundleData ) {
			return;
		}

		window.open(
			bundleData.playground_url,
			'_blank',
			'noopener,noreferrer'
		);
	};

	const handleRegenerateBundle = () => {
		setBundleGenerated( false );
		setBundleData( null );
		setError( null );
	};

	const customBlocks = Array.isArray( detectedBlocks )
		? detectedBlocks.filter( ( name ) => ! name.startsWith( 'core/' ) )
		: [];
	const hasCustomBlocks = customBlocks.length > 0;
	const hasMediaAssets = Array.isArray( detectedAssets )
		? detectedAssets.length > 0
		: false;

	return (
		<>
			<PluginSidebarMoreMenuItem target="playground-bundler-sidebar">
				{ __( 'Playground Bundler', 'playground-bundler' ) }
			</PluginSidebarMoreMenuItem>

			<PluginSidebar
				name="playground-bundler-sidebar"
				title={ __(
					'WordPress Playground Bundler',
					'playground-bundler'
				) }
				icon="download"
			>
				<PanelBody
					title={ __( 'Detected Content', 'playground-bundler' ) }
					initialOpen={ true }
				>
					{ isAnalyzing && (
						<div
							style={ {
								display: 'flex',
								alignItems: 'center',
								marginBottom: '16px',
							} }
						>
							<Spinner />
							<span style={ { marginLeft: '8px' } }>
								{ __(
									'Analyzing content…',
									'playground-bundler'
								) }
							</span>
						</div>
					) }

					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }

					<div style={ { marginBottom: '16px' } }>
						<strong>
							{ __( 'Blocks:', 'playground-bundler' ) }
						</strong>
						<p style={ { fontSize: '13px', color: '#757575' } }>
							{ detectedBlocks.length > 0
								? `${ detectedBlocks.length } block type(s) detected`
								: __(
										'No blocks detected',
										'playground-bundler'
								  ) }
						</p>

						{ hasCustomBlocks && (
							<Notice status="info" isDismissible={ false }>
								<strong>
									{ __(
										'Custom blocks found:',
										'playground-bundler'
									) }
								</strong>
								<ul
									style={ {
										marginTop: '8px',
										paddingLeft: '20px',
									} }
								>
									{ customBlocks.map( ( block ) => (
										<li
											key={ block }
											style={ { fontSize: '12px' } }
										>
											{ block }
										</li>
									) ) }
								</ul>
							</Notice>
						) }
					</div>

					<div style={ { marginBottom: '16px' } }>
						<strong>
							{ __( 'Media Assets:', 'playground-bundler' ) }
						</strong>
						<p style={ { fontSize: '13px', color: '#757575' } }>
							{ analysisData?.media_count > 0
								? `${ analysisData.media_count } asset(s) detected`
								: __(
										'No media assets detected',
										'playground-bundler'
								  ) }
						</p>

						{ hasMediaAssets && Array.isArray( detectedAssets ) && (
							<ul
								style={ {
									paddingLeft: '20px',
									fontSize: '12px',
								} }
							>
								{ detectedAssets.map( ( type, index ) => (
									<li key={ index }>
										{ type && typeof type === 'string'
											? type.charAt( 0 ).toUpperCase() +
											  type.slice( 1 )
											: type }{ ' ' }
										files
									</li>
								) ) }
							</ul>
						) }
					</div>
				</PanelBody>

				<PanelBody
					title={ __( 'Generate Blueprint', 'playground-bundler' ) }
					initialOpen={ true }
				>
					<p style={ { fontSize: '13px', marginBottom: '16px' } }>
						{ __(
							'Create a WordPress Playground blueprint bundle containing all blocks, plugins, and media assets from this page.',
							'playground-bundler'
						) }
					</p>

					{ ! bundleGenerated ? (
						<Button
							variant="primary"
							onClick={ handleGenerateBundle }
							isBusy={ isGenerating }
							disabled={ isGenerating || ! postId }
							style={ { width: '100%', marginBottom: '12px' } }
						>
							{ isGenerating
								? __(
										'Generating Bundle…',
										'playground-bundler'
								  )
								: __(
										'Generate Bundle',
										'playground-bundler'
								  ) }
						</Button>
					) : (
						<div
							style={ {
								display: 'flex',
								flexDirection: 'column',
								gap: '8px',
							} }
						>
							<Notice status="success" isDismissible={ false }>
								{ __(
									'Bundle generated successfully!',
									'playground-bundler'
								) }
							</Notice>

							<Button
								variant="primary"
								onClick={ handleDownloadBundle }
								style={ { width: '100%' } }
							>
								{ __(
									'Download Bundle',
									'playground-bundler'
								) }
							</Button>

							<Button
								variant="secondary"
								onClick={ handleOpenInPlayground }
								style={ { width: '100%' } }
							>
								{ __(
									'Open in Playground',
									'playground-bundler'
								) }
							</Button>

							<Button
								variant="tertiary"
								onClick={ handleRegenerateBundle }
								style={ { width: '100%', fontSize: '12px' } }
							>
								{ __(
									'Generate New Bundle',
									'playground-bundler'
								) }
							</Button>
						</div>
					) }

					{ ! postId && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'Please save the post before generating a bundle.',
								'playground-bundler'
							) }
						</Notice>
					) }
				</PanelBody>
			</PluginSidebar>
		</>
	);
};

console.log('Playground Bundler: About to register plugin...');
registerPlugin( 'playground-bundler', {
	render: PlaygroundBundlerSidebar,
	icon: 'download',
} );
console.log('Playground Bundler: Plugin registered successfully!');
