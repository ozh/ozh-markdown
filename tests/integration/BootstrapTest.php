<?php

declare(strict_types=1);

require_once __DIR__ . '/class-ozhmd-integration-test-case.php';

/**
 * Smoke tests proving WordPress and the plugin both loaded, plus isolation
 * proofs for the base test case's per-test rollback and registry restore.
 */
final class BootstrapTest extends Ozhmd_Integration_Test_Case {

	/**
	 * WordPress core has booted and its core functions are available.
	 *
	 * @return void
	 */
	public function test_wordpress_is_loaded(): void {
		$this->assertTrue(defined('ABSPATH'));
		$this->assertTrue(function_exists('add_action'));
	}

	/**
	 * The plugin's constants are defined after loading through muplugins_loaded.
	 *
	 * @return void
	 */
	public function test_the_plugin_file_was_loaded(): void {
		$this->assertTrue(defined('OZHMD_VERSION'));
		$this->assertSame('_ozhmd_markdown', OZHMD_POST_META_KEY);
		$this->assertSame('_ozhmd_original_content', OZHMD_ORIGINAL_META_KEY);
	}

	/**
	 * The plugin's bootstrap fires the ozhmd_loaded action.
	 *
	 * @return void
	 */
	public function test_the_bootstrap_ran(): void {
		$this->assertGreaterThan(0, did_action('ozhmd_loaded'));
	}

	/**
	 * The plugin's pure helper functions are declared and callable.
	 *
	 * @return void
	 */
	public function test_pure_functions_are_available(): void {
		$this->assertTrue(function_exists('ozhmd_eligible_post_types'));
		$this->assertTrue(function_exists('ozhmd_default_options'));
	}

	/**
	 * Activation seeds the default options when none exist yet.
	 *
	 * @return void
	 */
	public function test_activation_seeds_the_default_options(): void {
		delete_option('ozhmd_options');

		ozhmd_activate();

		$this->assertSame(ozhmd_default_options(), get_option('ozhmd_options'));
	}

	/**
	 * Activation leaves existing options untouched rather than overwriting them.
	 *
	 * @return void
	 */
	public function test_activation_does_not_overwrite_existing_options(): void {
		$existing = ['comments_enabled' => false, 'comment_preview' => true, 'prism_line_numbers' => true];
		update_option('ozhmd_options', $existing);

		ozhmd_activate();

		$this->assertSame($existing, get_option('ozhmd_options'));
	}

	/**
	 * ISOLATION PROOF (1 of 3, setup half): create a post that must not
	 * survive teardown, relying on declaration order to run before its
	 * companion assertion below.
	 *
	 * @return void
	 */
	public function test_isolation_a_post_is_created(): void {
		$post_id = wp_insert_post([
			'post_title'   => 'Isolation Proof Post',
			'post_content' => 'temporary',
			'post_status'  => 'publish',
		]);

		$this->assertIsInt($post_id);
		$this->assertGreaterThan(0, $post_id);
	}

	/**
	 * ISOLATION PROOF (1 of 3, assertion half): the post created by the
	 * previous test must be gone, proving the transaction rollback works.
	 *
	 * @return void
	 */
	public function test_isolation_the_post_does_not_leak_into_the_next_test(): void {
		$posts = get_posts([
			'post_type'   => 'post',
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
		]);

		$this->assertCount(1, $posts, 'Only the stock Hello World post should exist.');
	}

	/**
	 * ISOLATION PROOF (2 of 3, setup half): add a filter that must not
	 * survive teardown.
	 *
	 * @return void
	 */
	public function test_isolation_a_filter_is_added(): void {
		add_filter('ozhmd_isolation_proof_filter', static fn ($value) => 'leaked');

		$this->assertSame('leaked', apply_filters('ozhmd_isolation_proof_filter', 'clean'));
	}

	/**
	 * ISOLATION PROOF (2 of 3, assertion half): the filter added by the
	 * previous test must be gone, proving the $wp_filter restore works.
	 *
	 * @return void
	 */
	public function test_isolation_the_filter_does_not_leak_into_the_next_test(): void {
		$this->assertSame('clean', apply_filters('ozhmd_isolation_proof_filter', 'clean'));
	}

	/**
	 * ISOLATION PROOF (3 of 3, setup half): register a post type that must
	 * not survive teardown.
	 *
	 * @return void
	 */
	public function test_isolation_a_post_type_is_registered(): void {
		register_post_type('ozhmd_isolation_cpt', ['supports' => ['title', 'editor']]);

		$this->assertTrue(post_type_exists('ozhmd_isolation_cpt'));
	}

	/**
	 * ISOLATION PROOF (3 of 3, assertion half): the post type registered by
	 * the previous test must be gone, proving the $wp_post_types restore
	 * works.
	 *
	 * @return void
	 */
	public function test_isolation_the_post_type_does_not_leak_into_the_next_test(): void {
		$this->assertFalse(post_type_exists('ozhmd_isolation_cpt'));
	}
}
