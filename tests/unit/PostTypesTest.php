<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PostTypesTest extends TestCase {

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

	public function test_is_excluded_post_type_finds_a_listed_type(): void {
		$this->assertTrue(ozhmd_is_excluded_post_type('revision', ['post', 'revision']));
	}

	public function test_is_excluded_post_type_rejects_an_unlisted_type(): void {
		$this->assertFalse(ozhmd_is_excluded_post_type('post', ['revision']));
	}

	public function test_is_excluded_post_type_is_case_sensitive(): void {
		$this->assertFalse(ozhmd_is_excluded_post_type('Revision', ['revision']));
	}

	public function test_is_excluded_post_type_does_not_coerce_types(): void {
		$this->assertFalse(ozhmd_is_excluded_post_type('0', ['revision']));
	}

	public function test_eligible_post_types_removes_exclusions(): void {
		$this->assertSame(
			['post', 'page'],
			ozhmd_eligible_post_types(['post', 'revision', 'page', 'wp_block'], ['revision', 'wp_block'])
		);
	}

	public function test_eligible_post_types_preserves_order_and_reindexes_keys(): void {
		$eligible = ozhmd_eligible_post_types(['page', 'revision', 'post'], ['revision']);

		$this->assertSame([0, 1], array_keys($eligible));
		$this->assertSame(['page', 'post'], $eligible);
	}

	public function test_eligible_post_types_with_nothing_excluded_returns_everything(): void {
		$this->assertSame(['post', 'page'], ozhmd_eligible_post_types(['post', 'page'], []));
	}

	public function test_eligible_post_types_with_everything_excluded_returns_empty(): void {
		$this->assertSame([], ozhmd_eligible_post_types(['post'], ['post']));
	}
}
