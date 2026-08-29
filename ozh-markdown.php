<?php
/**
 * Plugin Name: Ozh' Markdown Plugin
 * Plugin URI:  https://planetozh.com/
 * Description: Minimal split-pane Markdown editor for posts and comments. Markdown in, plain HTML out.
 * Version:     0.1.0
 * Author:      Ozh
 * License:     GPL-2.0-or-later
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
require_once OZHMD_DIR . 'includes/class-ozhmd-settings.php';

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

	$settings = new Ozhmd_Settings($post_types);
	$settings->register_hooks();

	$editor_ui = new Ozhmd_Editor_UI($post_types, $storage, $settings);
	$editor_ui->register_hooks();

	/**
	 * Expose the converter to code loaded later in the build.
	 *
	 * @param Ozhmd_Converter $converter Configured converter pair.
	 */
	do_action('ozhmd_loaded', $converter);
}

add_action('plugins_loaded', 'ozhmd_bootstrap');

/**
 * Seed default options on activation.
 *
 * Existing options are never overwritten, so deactivating and reactivating the
 * plugin does not reset the settings.
 *
 * @return void
 */
function ozhmd_activate(): void {
	if (false === get_option('ozhmd_options')) {
		add_option('ozhmd_options', ozhmd_default_options());
	}
}

register_activation_hook(OZHMD_FILE, 'ozhmd_activate');
