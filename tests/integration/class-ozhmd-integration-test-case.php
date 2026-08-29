<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Base class for integration tests that run against a real, booted
 * WordPress.
 *
 * Each test runs inside a database transaction that is rolled back
 * afterwards, and the WordPress registries a test is likely to mutate are
 * snapshotted before the test and restored afterwards, so tests cannot leak
 * into one another.
 */
abstract class Ozhmd_Integration_Test_Case extends TestCase {

	/**
	 * Registries captured before the test ran.
	 *
	 * @var array<string, mixed>
	 */
	private array $ozhmd_snapshot = [];

	/**
	 * Open a database transaction and snapshot the mutable WordPress
	 * registries.
	 *
	 * The criterion for what belongs here is "registries WordPress mutates
	 * that tests touch": hooks (`$wp_filter`), post types
	 * (`$wp_post_types`), taxonomies (`$wp_taxonomies`), the post type
	 * feature index that `get_post_types_by_support()` reads
	 * (`$_wp_post_type_features`, a separate global from `$wp_post_types`
	 * and easy to miss), registered meta boxes (`$wp_meta_boxes`), the
	 * current admin screen (`$current_screen`, set by `set_current_screen()`
	 * and otherwise never reset for the rest of the process, cloned rather
	 * than referenced so a test mutating it in place cannot defeat the
	 * restore), enqueued scripts and styles (`$wp_scripts`, `$wp_styles`,
	 * also cloned), registered settings sections, fields and options
	 * (`$wp_settings_sections`, `$wp_settings_fields`,
	 * `$wp_registered_settings`), registered post meta (`$wp_meta_keys`),
	 * and the logged-in user (`get_current_user_id()`, which
	 * `wp_set_current_user()` otherwise leaks for the rest of the process
	 * because it caches `allcaps` on a `WP_User` in `$GLOBALS['current_user']`
	 * and nothing else restores it). Extend this list, and the restore
	 * below, when a later test touches another registry.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		global $wpdb, $wp_filter, $wp_post_types, $wp_taxonomies, $_wp_post_type_features, $wp_meta_boxes, $current_screen, $wp_scripts, $wp_styles, $wp_settings_sections, $wp_settings_fields, $wp_registered_settings, $wp_meta_keys;

		$this->ozhmd_snapshot = [
			'filter'              => array_map(static fn ($hook) => clone $hook, $wp_filter),
			'post_types'          => $wp_post_types,
			'taxonomies'          => $wp_taxonomies,
			'post_type_features'  => $_wp_post_type_features,
			'meta_boxes'          => $wp_meta_boxes,
			'current_screen'      => null === $current_screen ? null : clone $current_screen,
			'scripts'             => null === $wp_scripts ? null : clone $wp_scripts,
			'styles'              => null === $wp_styles ? null : clone $wp_styles,
			'settings_sections'   => $wp_settings_sections,
			'settings_fields'     => $wp_settings_fields,
			'registered_settings' => $wp_registered_settings,
			'meta_keys'           => $wp_meta_keys,
			'current_user_id'     => get_current_user_id(),
		];

		// $wp_filter is snapshotted above and restored below, so this filter
		// is removed automatically in tearDown() without an explicit
		// remove_filter() call.
		add_filter('upload_dir', [$this, 'ozhmd_redirect_uploads_to_scratch_dir']);

		$wpdb->query('SET autocommit = 0');
		$wpdb->query('START TRANSACTION');
	}

	/**
	 * Roll the transaction back, restore the registries, restore the current
	 * user, and flush the object cache.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		global $wpdb, $wp_filter, $wp_post_types, $wp_taxonomies, $_wp_post_type_features, $wp_meta_boxes, $current_screen, $wp_scripts, $wp_styles, $wp_settings_sections, $wp_settings_fields, $wp_registered_settings, $wp_meta_keys;

		$wpdb->query('ROLLBACK');
		$wpdb->query('SET autocommit = 1');

		$wp_filter              = $this->ozhmd_snapshot['filter'];
		$wp_post_types          = $this->ozhmd_snapshot['post_types'];
		$wp_taxonomies          = $this->ozhmd_snapshot['taxonomies'];
		$_wp_post_type_features = $this->ozhmd_snapshot['post_type_features'];
		$wp_meta_boxes          = $this->ozhmd_snapshot['meta_boxes'];
		$current_screen         = $this->ozhmd_snapshot['current_screen'];
		$wp_scripts             = $this->ozhmd_snapshot['scripts'];
		$wp_styles              = $this->ozhmd_snapshot['styles'];
		$wp_settings_sections   = $this->ozhmd_snapshot['settings_sections'];
		$wp_settings_fields     = $this->ozhmd_snapshot['settings_fields'];
		$wp_registered_settings = $this->ozhmd_snapshot['registered_settings'];
		$wp_meta_keys           = $this->ozhmd_snapshot['meta_keys'];

		wp_set_current_user($this->ozhmd_snapshot['current_user_id']);

		wp_cache_flush();

		parent::tearDown();
	}

	/**
	 * Point wp_upload_dir() at a scratch directory under the system temp
	 * dir instead of the live blog's wp-content/uploads.
	 *
	 * config.php deliberately leaves WP_CONTENT_DIR pointed at the live tree
	 * so theme and plugin resolution works. Anything that writes to disk,
	 * such as a future media_handle_upload() call in an integration test,
	 * must be redirected away from it here, because attachment rows created
	 * in a test roll back with the transaction but files written to disk do
	 * not.
	 *
	 * @param array<string, mixed> $uploads Upload directory info as documented on wp_upload_dir().
	 * @return array<string, mixed> Upload directory info pointed at the scratch directory.
	 */
	public function ozhmd_redirect_uploads_to_scratch_dir(array $uploads): array {
		$basedir = sys_get_temp_dir() . '/ozhmd-test-uploads';
		$baseurl = 'http://example.org/ozhmd-test-uploads';

		$uploads['basedir'] = $basedir;
		$uploads['baseurl'] = $baseurl;
		$uploads['path']    = $basedir . $uploads['subdir'];
		$uploads['url']     = $baseurl . $uploads['subdir'];

		return $uploads;
	}

	/**
	 * Load the wp-admin/includes/template.php functions a front-end
	 * WordPress boot does not load.
	 *
	 * wp-settings.php only loads wp-admin includes when WP_ADMIN is set, and
	 * the integration bootstrap boots as a front end, so tests that reach
	 * admin-only functions such as add_settings_section() or submit_button()
	 * must load them explicitly first.
	 *
	 * @return void
	 */
	protected function require_admin_includes(): void {
		require_once ABSPATH . 'wp-admin/includes/template.php';
	}
}
