<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Post editing state derived from stored Markdown.
 */
final class PostStateTest extends TestCase {

	/**
	 * A post with no stored Markdown is legacy.
	 *
	 * @return void
	 */
	public function test_absent_markdown_with_existing_content_means_legacy(): void {
		$this->assertSame('legacy', ozhmd_post_state(null, '<p>Written before the plugin.</p>'));
	}

	/**
	 * A brand new post has neither meta nor content, so it starts as Markdown.
	 *
	 * Treating it as legacy stored the author's Markdown verbatim as HTML and
	 * made every new post require an opt in.
	 *
	 * @return void
	 */
	public function test_a_brand_new_empty_post_is_markdown(): void {
		$this->assertSame('markdown', ozhmd_post_state(null, ''));
	}

	/**
	 * Whitespace only content still counts as empty.
	 *
	 * @return void
	 */
	public function test_whitespace_only_content_is_treated_as_empty(): void {
		$this->assertSame('markdown', ozhmd_post_state(null, "  \n\t "));
	}

	/**
	 * Stored Markdown wins regardless of what post_content holds.
	 *
	 * @return void
	 */
	public function test_stored_markdown_wins_over_existing_content(): void {
		$this->assertSame('markdown', ozhmd_post_state('# Hi', '<p>anything</p>'));
	}

	/**
	 * A post with stored Markdown uses the Markdown editor.
	 *
	 * @return void
	 */
	public function test_present_markdown_means_markdown(): void {
		$this->assertSame('markdown', ozhmd_post_state('# Hello', ''));
	}

	/**
	 * A post with empty Markdown still uses the Markdown editor.
	 *
	 * @return void
	 */
	public function test_empty_markdown_still_means_markdown(): void {
		// A converted post whose body was emptied is not a legacy post. Only a
		// missing meta key means legacy.
		$this->assertSame('markdown', ozhmd_post_state('', ''));
	}
}
