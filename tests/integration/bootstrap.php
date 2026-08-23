<?php
/**
 * Bootstrap for the integration suite.
 *
 * Ensures the test database is installed, then boots the real WordPress
 * installed in this container against it, loading the plugin the way
 * WordPress would load it. Depends on PHPUnit only, not on the WordPress
 * test library, so the suite can follow PHPUnit and PHP forward.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/class-ozhmd-integration-test-case.php';

/**
 * Run the idempotent installer in its own process.
 *
 * A separate process is used because install.php requires wp-settings.php
 * with WP_INSTALLING defined, and this process is about to require it again
 * without that constant. Requiring wp-settings.php twice in one process is
 * not supported.
 *
 * @return void
 */
function ozhmd_ensure_test_database_installed(): void {
	$output    = [];
	$exit_code = 0;

	exec(
		escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/install.php') . ' 2>&1',
		$output,
		$exit_code
	);

	if (0 !== $exit_code) {
		fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);
		fwrite(STDERR, 'Integration test database setup failed.' . PHP_EOL);
		exit(1);
	}
}

/**
 * Register a hook callback before WordPress has loaded.
 *
 * WordPress converts these raw arrays into WP_Hook objects during
 * wp-settings.php, so the plugin can be hooked in before the hook system
 * exists. This is what makes the plugin's own plugins_loaded bootstrap fire
 * for real, instead of requiring the plugin file directly after the fact.
 *
 * @param string   $hook     Hook name.
 * @param callable $callback Callback to register.
 * @param int      $priority Priority.
 * @return void
 */
function ozhmd_preload_filter(string $hook, callable $callback, int $priority = 10): void {
	global $wp_filter;

	$wp_filter[$hook][$priority][] = [
		'function'      => $callback,
		'accepted_args' => 1,
	];
}

ozhmd_ensure_test_database_installed();

ozhmd_preload_filter('muplugins_loaded', static function (): void {
	require dirname(__DIR__, 2) . '/ozh-markdown.php';
});

require ABSPATH . 'wp-settings.php';
