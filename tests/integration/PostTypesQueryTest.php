<?php

declare(strict_types=1);

require_once __DIR__ . '/class-ozhmd-integration-test-case.php';

/**
 * Eligibility resolved against the real WordPress post type registry.
 */
final class PostTypesQueryTest extends Ozhmd_Integration_Test_Case {

	/**
	 * Resolver under test.
	 *
	 * @var Ozhmd_Post_Types
	 */
	private Ozhmd_Post_Types $post_types;

	/**
	 * Build a fresh resolver for each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->post_types = new Ozhmd_Post_Types();
	}

	/**
	 * Core content types post and page are eligible.
	 *
	 * @return void
	 */
	public function test_core_content_types_are_eligible(): void {
		$eligible = $this->post_types->eligible();

		$this->assertContains('post', $eligible);
		$this->assertContains('page', $eligible);
	}

	/**
	 * Excluded types are not returned even though they support the editor.
	 *
	 * @return void
	 */
	public function test_excluded_types_are_absent_even_though_they_support_the_editor(): void {
		// wp_block supports 'editor', so its absence proves the exclusion list
		// is doing the work rather than the editor-support query.
		$this->assertContains('wp_block', get_post_types_by_support('editor'));
		$this->assertNotContains('wp_block', $this->post_types->eligible());
	}

	/**
	 * Registering a custom post type with editor support is enough to pick it
	 * up, with no further wiring needed.
	 *
	 * @return void
	 */
	public function test_a_custom_post_type_supporting_the_editor_is_eligible(): void {
		// No unregister_post_type() cleanup here on purpose. The harness
		// restores $wp_post_types and $_wp_post_type_features in teardown, so
		// manual cleanup would mask a regression in that restore.
		register_post_type('ozhmd_book', ['supports' => ['title', 'editor']]);

		$this->assertContains('ozhmd_book', $this->post_types->eligible());
	}

	/**
	 * A custom post type that omits editor from its supports array is left out.
	 *
	 * @return void
	 */
	public function test_a_custom_post_type_without_editor_support_is_not_eligible(): void {
		register_post_type('ozhmd_link', ['supports' => ['title']]);

		$this->assertNotContains('ozhmd_link', $this->post_types->eligible());
	}

	/**
	 * Appending to the ozhmd_excluded_post_types filter removes one type
	 * without touching the eligibility of the others.
	 *
	 * @return void
	 */
	public function test_the_filter_can_exclude_a_further_post_type(): void {
		add_filter('ozhmd_excluded_post_types', static function (array $excluded): array {
			$excluded[] = 'page';

			return $excluded;
		});

		$eligible = $this->post_types->eligible();

		$this->assertNotContains('page', $eligible);
		$this->assertContains('post', $eligible);
	}

	/**
	 * eligible() returns a plain, zero-indexed list of strings rather than an
	 * associative or gapped array.
	 *
	 * @return void
	 */
	public function test_the_result_is_a_reindexed_list_of_strings(): void {
		$eligible = $this->post_types->eligible();

		$this->assertSame(range(0, count($eligible) - 1), array_keys($eligible));
		$this->assertContainsOnlyString($eligible);
	}
}
