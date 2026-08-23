<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Post type eligibility helpers.
 */
final class PostTypesTest extends TestCase {

	/**
	 * The built-in exclusion list covers every post type named in the spec.
	 *
	 * @return void
	 */
	public function test_default_exclusions_cover_every_post_type_named_in_the_spec(): void {
		$excluded = ozhmd_default_excluded_post_types();

		foreach ([
			'attachment',
			'revision',
			'nav_menu_item',
			'wp_block',
			'wp_template',
			'wp_template_part',
			'wp_navigation',
			'wp_global_styles',
			'customize_changeset',
			'oembed_cache',
			'user_request',
		] as $post_type) {
			$this->assertContains($post_type, $excluded);
		}
	}

	/**
	 * A post type present in the exclusion list is reported as excluded.
	 *
	 * @return void
	 */
	public function test_is_excluded_post_type_finds_a_listed_type(): void {
		$this->assertTrue(ozhmd_is_excluded_post_type('revision', ['post', 'revision']));
	}

	/**
	 * A post type absent from the exclusion list is reported as not excluded.
	 *
	 * @return void
	 */
	public function test_is_excluded_post_type_rejects_an_unlisted_type(): void {
		$this->assertFalse(ozhmd_is_excluded_post_type('post', ['revision']));
	}

	/**
	 * Matching against the exclusion list is case sensitive, so a differently
	 * cased post type name is not treated as excluded.
	 *
	 * @return void
	 */
	public function test_is_excluded_post_type_is_case_sensitive(): void {
		$this->assertFalse(ozhmd_is_excluded_post_type('Revision', ['revision']));
	}

	/**
	 * The comparison is strict, so a post type of '0' is not loosely coerced
	 * into matching an unrelated exclusion list.
	 *
	 * @return void
	 */
	public function test_is_excluded_post_type_does_not_coerce_types(): void {
		$this->assertFalse(ozhmd_is_excluded_post_type('0', ['revision']));
	}

	/**
	 * Excluded post types are removed from the list of eligible post types.
	 *
	 * @return void
	 */
	public function test_eligible_post_types_removes_exclusions(): void {
		$this->assertSame(
			['post', 'page'],
			ozhmd_eligible_post_types(['post', 'revision', 'page', 'wp_block'], ['revision', 'wp_block'])
		);
	}

	/**
	 * The eligible list keeps the original ordering and reindexes array keys
	 * sequentially from zero.
	 *
	 * @return void
	 */
	public function test_eligible_post_types_preserves_order_and_reindexes_keys(): void {
		$eligible = ozhmd_eligible_post_types(['page', 'revision', 'post'], ['revision']);

		$this->assertSame([0, 1], array_keys($eligible));
		$this->assertSame(['page', 'post'], $eligible);
	}

	/**
	 * With an empty exclusion list, every post type passed in remains eligible.
	 *
	 * @return void
	 */
	public function test_eligible_post_types_with_nothing_excluded_returns_everything(): void {
		$this->assertSame(['post', 'page'], ozhmd_eligible_post_types(['post', 'page'], []));
	}

	/**
	 * When every candidate post type is excluded, the eligible list is empty.
	 *
	 * @return void
	 */
	public function test_eligible_post_types_with_everything_excluded_returns_empty(): void {
		$this->assertSame([], ozhmd_eligible_post_types(['post'], ['post']));
	}
}
