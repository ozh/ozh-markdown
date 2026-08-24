<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Depends;

require_once __DIR__ . '/class-ozhmd-integration-test-case.php';

/**
 * Smoke tests proving WordPress and the plugin both loaded, plus isolation
 * proofs for the base test case's per-test rollback and registry restore.
 *
 * Each isolation proof is a pair: a setup half that mutates state, and an
 * assertion half that checks the mutation is gone. The assertion half
 * carries a #[Depends] on its setup half, using the PHP attribute rather
 * than the @depends annotation, since annotation parsing is what pushed
 * this harness off wp-phpunit in the first place. PHPUnit resolves
 * dependencies after applying --order-by, so each pair keeps its relative
 * order under any test order, including --order-by=reverse and
 * --order-by=random; without it, a reversed run lets the assertion half run
 * first and pass trivially, before anything has mutated state to detect.
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
	 * ISOLATION PROOF (1 of 5, setup half): create a post that must not
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
	 * ISOLATION PROOF (1 of 5, assertion half): the post created by the
	 * previous test must be gone, proving the transaction rollback works.
	 *
	 * @return void
	 */
	#[Depends('test_isolation_a_post_is_created')]
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
	 * ISOLATION PROOF (2 of 5, setup half): add a filter that must not
	 * survive teardown.
	 *
	 * @return void
	 */
	public function test_isolation_a_filter_is_added(): void {
		add_filter('ozhmd_isolation_proof_filter', static fn ($value) => 'leaked');

		$this->assertSame('leaked', apply_filters('ozhmd_isolation_proof_filter', 'clean'));
	}

	/**
	 * ISOLATION PROOF (2 of 5, assertion half): the filter added by the
	 * previous test must be gone, proving the $wp_filter restore works.
	 *
	 * @return void
	 */
	#[Depends('test_isolation_a_filter_is_added')]
	public function test_isolation_the_filter_does_not_leak_into_the_next_test(): void {
		$this->assertSame('clean', apply_filters('ozhmd_isolation_proof_filter', 'clean'));
	}

	/**
	 * ISOLATION PROOF (3 of 5, setup half): register a post type that must
	 * not survive teardown.
	 *
	 * @return void
	 */
	public function test_isolation_a_post_type_is_registered(): void {
		register_post_type('ozhmd_isolation_cpt', ['supports' => ['title', 'editor']]);

		$this->assertTrue(post_type_exists('ozhmd_isolation_cpt'));
	}

	/**
	 * ISOLATION PROOF (3 of 5, assertion half): the post type registered by
	 * the previous test must be gone from $wp_post_types, proving the
	 * $wp_post_types restore works.
	 *
	 * @return void
	 */
	#[Depends('test_isolation_a_post_type_is_registered')]
	public function test_isolation_the_post_type_does_not_leak_into_the_next_test(): void {
		$this->assertFalse(post_type_exists('ozhmd_isolation_cpt'));
	}

	/**
	 * ISOLATION PROOF (4 of 5, setup half): register a post type that
	 * supports the editor feature, read through the separate feature index
	 * rather than $wp_post_types.
	 *
	 * @return void
	 */
	public function test_isolation_a_post_type_feature_is_registered(): void {
		register_post_type('ozhmd_feature_cpt', ['supports' => ['title', 'editor']]);

		$this->assertContains('ozhmd_feature_cpt', get_post_types_by_support('editor'));
	}

	/**
	 * ISOLATION PROOF (4 of 5, assertion half): the post type registered by
	 * the previous test must be gone from $_wp_post_type_features, proving
	 * that separate registry is restored too, not just $wp_post_types.
	 *
	 * @return void
	 */
	#[Depends('test_isolation_a_post_type_feature_is_registered')]
	public function test_isolation_the_post_type_feature_does_not_leak_into_the_next_test(): void {
		$this->assertNotContains('ozhmd_feature_cpt', get_post_types_by_support('editor'));
	}

	/**
	 * ISOLATION PROOF (5 of 5, setup half): register a meta box that must
	 * not survive teardown. The admin-only functions this needs are not
	 * loaded by a front-end WordPress boot, so this test loads them itself.
	 *
	 * @return void
	 */
	public function test_isolation_a_meta_box_is_registered(): void {
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';

		set_current_screen('post');
		add_meta_box('ozhmd_isolation_meta_box', 'Isolation Proof', '__return_false', 'post', 'normal', 'default');

		global $wp_meta_boxes;

		$this->assertArrayHasKey('ozhmd_isolation_meta_box', $wp_meta_boxes['post']['normal']['default']);
	}

	/**
	 * ISOLATION PROOF (5 of 5, assertion half): the meta box registered by
	 * the previous test must be gone, proving the $wp_meta_boxes restore
	 * works.
	 *
	 * @return void
	 */
	#[Depends('test_isolation_a_meta_box_is_registered')]
	public function test_isolation_the_meta_box_does_not_leak_into_the_next_test(): void {
		global $wp_meta_boxes;

		$this->assertArrayNotHasKey('ozhmd_isolation_meta_box', $wp_meta_boxes['post']['normal']['default'] ?? []);
	}
}
