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
	 * and easy to miss), registered meta boxes (`$wp_meta_boxes`), and the
	 * current admin screen (`$current_screen`, set by `set_current_screen()`
	 * and otherwise never reset for the rest of the process). Extend this
	 * list, and the restore below, when a later test touches another
	 * registry.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		global $wpdb, $wp_filter, $wp_post_types, $wp_taxonomies, $_wp_post_type_features, $wp_meta_boxes, $current_screen;

		$this->ozhmd_snapshot = [
			'filter'             => array_map(static fn ($hook) => clone $hook, $wp_filter),
			'post_types'         => $wp_post_types,
			'taxonomies'         => $wp_taxonomies,
			'post_type_features' => $_wp_post_type_features,
			'meta_boxes'         => $wp_meta_boxes,
			'current_screen'     => $current_screen,
		];

		$wpdb->query('SET autocommit = 0');
		$wpdb->query('START TRANSACTION');
	}

	/**
	 * Roll the transaction back, restore the registries, and flush the
	 * object cache.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		global $wpdb, $wp_filter, $wp_post_types, $wp_taxonomies, $_wp_post_type_features, $wp_meta_boxes, $current_screen;

		$wpdb->query('ROLLBACK');
		$wpdb->query('SET autocommit = 1');

		$wp_filter              = $this->ozhmd_snapshot['filter'];
		$wp_post_types          = $this->ozhmd_snapshot['post_types'];
		$wp_taxonomies          = $this->ozhmd_snapshot['taxonomies'];
		$_wp_post_type_features = $this->ozhmd_snapshot['post_type_features'];
		$wp_meta_boxes          = $this->ozhmd_snapshot['meta_boxes'];
		$current_screen         = $this->ozhmd_snapshot['current_screen'];

		wp_cache_flush();

		parent::tearDown();
	}
}
