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
 * Defaults to local container paths and a root login with an empty password.
 * That is intentional for this dev-only stack; the file carries no
 * production secret. CI points the suite at its own WordPress and database
 * through the OZHMD_TEST_* environment variables; locally, none are set.
 *
 * WP_CONTENT_DIR is deliberately left undefined here, which lets it default
 * to the live blog's wp-content tree under ABSPATH, so theme and plugin
 * resolution works exactly as in production. This means wp_upload_dir()
 * would otherwise resolve to the live blog's real wp-content/uploads.
 * Anything a test writes to disk, such as an uploaded attachment, must be
 * redirected away from the live tree; see
 * Ozhmd_Integration_Test_Case::ozhmd_redirect_uploads_to_scratch_dir(),
 * which is wired up in setUp() for every test.
 */

define('ABSPATH', getenv('OZHMD_TEST_ABSPATH') ?: '/var/www/html/planetozh.com/blog/');

define('DB_NAME', getenv('OZHMD_TEST_DB_NAME') ?: 'ozhmd_tests');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_HOST', getenv('OZHMD_TEST_DB_HOST') ?: 'mariadb');
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
