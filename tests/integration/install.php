<?php
/**
 * Idempotent installer for the integration test database.
 *
 * Runs in its own process, because WordPress can only be installed into a
 * schema-less database while WP_INSTALLING is set. Safe to run repeatedly:
 * if the site is already installed, this does nothing and exits cleanly.
 */

require __DIR__ . '/config.php';

define('WP_INSTALLING', true);

require ABSPATH . 'wp-settings.php';
require ABSPATH . 'wp-admin/includes/upgrade.php';

if (is_blog_installed()) {
	echo 'Already installed, skipping.' . PHP_EOL;
	exit(0);
}

$result = wp_install(
	'Ozh Markdown Test Blog',
	'admin',
	'admin@example.org',
	true,
	'',
	'password'
);

echo 'Installed. Admin user ID: ' . $result['user_id'] . PHP_EOL;
