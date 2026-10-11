<?php
/**
 * Plugin Name: Ozh' Markdown Plugin
 * Plugin URI:  https://planetozh.com/
 * Description: Split-pane Markdown editor. Markdown in, plain HTML out.
 * Version:     0.1.0
 * Author:      Ozh
 * License:     WTFPL
 * License URI: http://www.wtfpl.net/
 * Text Domain: ozh-markdown
 * Requires PHP: 8.5
 *
 * @package Ozh_Markdown
 */

if (!defined('ABSPATH')) {
	exit;
}

define('OZHMD_VERSION', '0.1.0');
define('OZHMD_FILE', __FILE__);
define('OZHMD_DIR', plugin_dir_path(__FILE__));
define('OZHMD_URL', plugin_dir_url(__FILE__));
define('OZHMD_POST_META_KEY', '_ozhmd_markdown');
define('OZHMD_ORIGINAL_META_KEY', '_ozhmd_original_content');
define('OZHMD_COMMENT_META_KEY', '_ozhmd_markdown');

require_once OZHMD_DIR . 'vendor/autoload.php';
require_once OZHMD_DIR . 'includes/ozhmd-functions.php';
require_once OZHMD_DIR . 'includes/class-ozhmd-converter-factory.php';
require_once OZHMD_DIR . 'includes/class-ozhmd-converter.php';
require_once OZHMD_DIR . 'includes/class-ozhmd-post-types.php';
require_once OZHMD_DIR . 'includes/class-ozhmd-post-storage.php';
require_once OZHMD_DIR . 'includes/class-ozhmd-editor-ui.php';
require_once OZHMD_DIR . 'includes/class-ozhmd-frontend.php';
require_once OZHMD_DIR . 'includes/class-ozhmd-uploader.php';

/**
 * Construct the plugin services and register their hooks.
 *
 * Services are built here and injected into one another. No class registers a
 * hook from its constructor, so every one of them can be instantiated in a
 * test without touching the hook system.
 *
 * @return void
 */
function ozhmd_bootstrap(): void {
	$factory   = new Ozhmd_Converter_Factory();
	$converter = new Ozhmd_Converter($factory->create());

	$post_types = new Ozhmd_Post_Types();
	$post_types->register_hooks();

	$storage = new Ozhmd_Post_Storage($converter, $post_types);
	$storage->register_hooks();

	$editor_ui = new Ozhmd_Editor_UI($post_types, $storage);
	$editor_ui->register_hooks();

	$frontend = new Ozhmd_Frontend();
	$frontend->register_hooks();

	$uploader = new Ozhmd_Uploader();
	$uploader->register_hooks();

	/**
	 * Expose the converter to code loaded later in the build.
	 *
	 * @param Ozhmd_Converter $converter Configured converter pair.
	 */
	do_action('ozhmd_loaded', $converter);
}

add_action('plugins_loaded', 'ozhmd_bootstrap');
