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
	public function test_absent_markdown_means_legacy(): void {
		$this->assertSame('legacy', ozhmd_post_state(null));
	}

	/**
	 * A post with stored Markdown uses the Markdown editor.
	 *
	 * @return void
	 */
	public function test_present_markdown_means_markdown(): void {
		$this->assertSame('markdown', ozhmd_post_state('# Hello'));
	}

	/**
	 * A post with empty Markdown still uses the Markdown editor.
	 *
	 * @return void
	 */
	public function test_empty_markdown_still_means_markdown(): void {
		// A converted post whose body was emptied is not a legacy post. Only a
		// missing meta key means legacy.
		$this->assertSame('markdown', ozhmd_post_state(''));
	}
}
