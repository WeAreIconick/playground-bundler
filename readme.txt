=== Playground Bundler ===
Contributors: iconick
Tags: wordpress, playground, blueprint, bundler, blocks
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create portable WordPress environments by bundling your content, blocks, and plugins into shareable Playground blueprints.

== Description ==

The Playground Bundler plugin transforms any WordPress page into a portable, instantly-runnable environment. Simply select your content, and the plugin automatically detects all blocks, media assets, and custom plugins, then bundles everything into a shareable WordPress Playground blueprint.

**Why Use Playground Bundler?**

* **Instant Demos** - Share your WordPress creations with anyone, anywhere, instantly
* **Zero Setup** - Recipients don't need WordPress installed - just click and run
* **Complete Environments** - Includes custom plugins, themes, content, and configurations
* **Developer Friendly** - Perfect for showcasing plugins, themes, or custom solutions
* **Client Presentations** - Demonstrate your work in a live, interactive environment

**Key Features:**

* **Real-time Block Analysis** - Detects all blocks used in your content, including custom blocks
* **Media Asset Detection** - Automatically identifies and includes images, audio, video, and file attachments
* **Plugin Dependency Resolution** - Finds and bundles the plugins that provide custom blocks
* **One-Click Export** - Generate and download a complete WordPress Playground bundle
* **REST API Integration** - Modern API endpoints for seamless integration

**How It Works:**

1. Open any post or page in the block editor
2. Access the Playground Bundler sidebar panel
3. Review detected blocks and media assets
4. Click "Download Blueprint Bundle" to generate a ZIP file
5. Upload the bundle to WordPress Playground to recreate your exact environment

**Perfect For:**

* Sharing WordPress demos and prototypes
* Creating reproducible development environments
* Distributing block-based content templates
* Testing custom block plugins in isolated environments

The generated blueprint includes everything needed to recreate your WordPress environment: custom plugins, media files, and post content with all blocks intact.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/playground-bundler` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Navigate to any post or page in the block editor
4. Look for the "Playground Bundler" option in the editor sidebar (three dots menu)
5. Click to open the sidebar and start creating bundles

== Frequently Asked Questions ==

= How do I use this plugin? =

1. Edit any post or page in the block editor
2. Open the Playground Bundler sidebar from the editor menu
3. Review the detected blocks and media assets
4. Click "Download Blueprint Bundle" to generate a ZIP file
5. Upload the ZIP to WordPress Playground to recreate your environment

= What types of blocks are supported? =

The plugin supports all WordPress core blocks and automatically detects custom blocks from plugins. It extracts media from image, audio, video, gallery, file, media-text, and cover blocks.

= What happens to custom block plugins? =

The plugin automatically detects which plugins provide custom blocks and includes them in the bundle. The plugins are packaged as ZIP files and installed automatically when the blueprint runs.

= Are there any file size limits? =

The plugin respects WordPress upload limits and includes rate limiting to prevent server overload. Very large bundles may take longer to generate.

= Can I customize the generated blueprint? =

The generated blueprint follows the WordPress Playground schema and includes login credentials, plugin installation, media uploads, and post creation steps.

== Screenshots ==

1. The Playground Bundler sidebar in the block editor
2. Block and media asset detection interface
3. Bundle generation progress indicator
4. Example of a generated blueprint ZIP structure

== Changelog ==

= 1.0.0 =
* Initial release
* Block and media asset detection
* Custom plugin bundling
* REST API endpoints
* WordPress Playground blueprint generation

== Upgrade Notice ==

= 1.0.0 =
Initial release of Playground Bundler.

