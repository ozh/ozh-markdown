<?php
/**
 * Database and path configuration for the integration suite.
 *
 * Defines everything wp-config.php would define, so WordPress can be booted
 * against a dedicated test database without touching the blog's own
 * configuration. DB_NAME must never be the blog's own database: install.php
 * installs a fresh schema into it and the base test case runs every test
 * inside a transaction that is rolled back.
 *
 * Hardcodes local container paths and a root login with an empty password.
 * That is intentional for this dev-only stack; the file carries no
 * production secret.
 */

define('ABSPATH', '/var/www/html/planetozh.com/blog/');

define('DB_NAME', 'ozhmd_tests');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_HOST', 'mariadb');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', true);
define('WP_DEFAULT_THEME', 'twentytwentyfour');

/*
 * wp_guess_url() falls back to $_SERVER superglobals (HTTP_HOST,
 * REQUEST_URI, SCRIPT_FILENAME) that do not exist under the CLI SAPI PHPUnit
 * runs under. Without these defined, wp_install() persists an empty
 * siteurl/home, which makes is_blog_installed() permanently false and every
 * later boot dies in dead_db(). Defining them short-circuits that guess.
 */
define('WP_SITEURL', 'http://example.org');
define('WP_HOME', 'http://example.org');

$GLOBALS['table_prefix'] = 'wptests_';
$table_prefix            = 'wptests_';
