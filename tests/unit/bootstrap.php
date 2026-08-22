<?php
/**
 * Bootstrap for the plain PHPUnit suite.
 *
 * Loads the development autoloader, the runtime autoloader, and the plugin's
 * WordPress-free code. No WordPress bootstrap and no database: everything
 * reachable from here must be pure PHP.
 */

$ozhmd_dev_autoload = __DIR__ . '/../tools/vendor/autoload.php';

if (!file_exists($ozhmd_dev_autoload)) {
	fwrite(STDERR, "Dev tooling missing. Run: ./tools/dev.sh composer install -d tests/tools\n");
	exit(1);
}

require_once $ozhmd_dev_autoload;
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/ozhmd-functions.php';
