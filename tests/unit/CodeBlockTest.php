<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fenced code block detection.
 */
final class CodeBlockTest extends TestCase {

	/**
	 * A bare pre/code pair is detected.
	 *
	 * @return void
	 */
	public function test_detects_a_plain_code_block(): void {
		$this->assertTrue(ozhmd_content_has_code_block('<pre><code>echo 1;</code></pre>'));
	}

	/**
	 * A code block with a language class is detected.
	 *
	 * @return void
	 */
	public function test_detects_a_code_block_with_a_language_class(): void {
		$this->assertTrue(ozhmd_content_has_code_block('<pre><code class="language-php">echo 1;</code></pre>'));
	}

	/**
	 * A code block with attributes on the pre tag is detected.
	 *
	 * @return void
	 */
	public function test_detects_a_code_block_with_attributes_on_the_pre_tag(): void {
		$this->assertTrue(ozhmd_content_has_code_block('<pre class="line-numbers"><code>x</code></pre>'));
	}

	/**
	 * A code block is detected across whitespace and newlines.
	 *
	 * @return void
	 */
	public function test_detects_a_code_block_across_whitespace_and_newlines(): void {
		$this->assertTrue(ozhmd_content_has_code_block("<pre>\n\t<code>x</code></pre>"));
	}

	/**
	 * Detection is case insensitive.
	 *
	 * @return void
	 */
	public function test_detection_is_case_insensitive(): void {
		$this->assertTrue(ozhmd_content_has_code_block('<PRE><CODE>x</CODE></PRE>'));
	}

	/**
	 * A pre without a code child is ignored.
	 *
	 * @return void
	 */
	public function test_ignores_a_pre_without_a_code_child(): void {
		$this->assertFalse(ozhmd_content_has_code_block('<pre>just preformatted</pre>'));
	}

	/**
	 * Inline code is ignored.
	 *
	 * @return void
	 */
	public function test_ignores_inline_code(): void {
		$this->assertFalse(ozhmd_content_has_code_block('<p>use <code>echo</code> here</p>'));
	}

	/**
	 * A tag merely starting with pre is ignored.
	 *
	 * @return void
	 */
	public function test_ignores_a_tag_merely_starting_with_pre(): void {
		$this->assertFalse(ozhmd_content_has_code_block('<preface><code>x</code></preface>'));
	}

	/**
	 * Empty content has no code block.
	 *
	 * @return void
	 */
	public function test_empty_content_has_no_code_block(): void {
		$this->assertFalse(ozhmd_content_has_code_block(''));
	}
}
