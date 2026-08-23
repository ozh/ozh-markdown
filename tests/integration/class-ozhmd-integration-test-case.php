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
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		global $wpdb, $wp_filter, $wp_post_types, $wp_taxonomies;

		$this->ozhmd_snapshot = [
			'filter'     => array_map(static fn ($hook) => clone $hook, $wp_filter),
			'post_types' => $wp_post_types,
			'taxonomies' => $wp_taxonomies,
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
		global $wpdb, $wp_filter, $wp_post_types, $wp_taxonomies;

		$wpdb->query('ROLLBACK');
		$wpdb->query('SET autocommit = 1');

		$wp_filter     = $this->ozhmd_snapshot['filter'];
		$wp_post_types = $this->ozhmd_snapshot['post_types'];
		$wp_taxonomies = $this->ozhmd_snapshot['taxonomies'];

		wp_cache_flush();

		parent::tearDown();
	}
}
