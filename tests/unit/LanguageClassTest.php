<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Language class validation and filtering.
 */
final class LanguageClassTest extends TestCase {

	/**
	 * A simple language class is accepted.
	 *
	 * @return void
	 */
	public function test_accepts_a_simple_language_class(): void {
		$this->assertTrue(ozhmd_is_valid_language_class('language-php'));
	}

	/**
	 * Digits, plus and hyphen are accepted.
	 *
	 * @return void
	 */
	public function test_accepts_digits_plus_and_hyphen(): void {
		$this->assertTrue(ozhmd_is_valid_language_class('language-c++'));
		$this->assertTrue(ozhmd_is_valid_language_class('language-objective-c'));
		$this->assertTrue(ozhmd_is_valid_language_class('language-h2'));
	}

	/**
	 * An empty language name is rejected.
	 *
	 * @return void
	 */
	public function test_rejects_an_empty_language_name(): void {
		$this->assertFalse(ozhmd_is_valid_language_class('language-'));
	}

	/**
	 * A different prefix is rejected.
	 *
	 * @return void
	 */
	public function test_rejects_a_different_prefix(): void {
		$this->assertFalse(ozhmd_is_valid_language_class('lang-php'));
		$this->assertFalse(ozhmd_is_valid_language_class('Language-php'));
	}

	/**
	 * Uppercase in the language name is rejected.
	 *
	 * @return void
	 */
	public function test_rejects_uppercase_in_the_language_name(): void {
		$this->assertFalse(ozhmd_is_valid_language_class('language-PHP'));
	}

	/**
	 * An injected attribute is rejected.
	 *
	 * @return void
	 */
	public function test_rejects_an_injected_attribute(): void {
		$this->assertFalse(ozhmd_is_valid_language_class('language-php" onmouseover="alert(1)'));
		$this->assertFalse(ozhmd_is_valid_language_class('language-php onmouseover=alert(1)'));
	}

	/**
	 * A trailing newline bypass is rejected.
	 *
	 * @return void
	 */
	public function test_rejects_a_trailing_newline_bypass(): void {
		// PCRE's $ matches before a trailing newline, so the pattern must be
		// anchored with \z or a crafted value slips through.
		$this->assertFalse(ozhmd_is_valid_language_class("language-php\n"));
		$this->assertFalse(ozhmd_is_valid_language_class("language-php\nevil"));
	}

	/**
	 * Leading or trailing whitespace is rejected.
	 *
	 * @return void
	 */
	public function test_rejects_leading_or_trailing_whitespace(): void {
		$this->assertFalse(ozhmd_is_valid_language_class(' language-php'));
		$this->assertFalse(ozhmd_is_valid_language_class('language-php '));
	}

	/**
	 * A valid class is kept untouched.
	 *
	 * @return void
	 */
	public function test_keeps_a_valid_class_untouched(): void {
		$this->assertSame(
			'<code class="language-php">x</code>',
			ozhmd_filter_language_classes('<code class="language-php">x</code>')
		);
	}

	/**
	 * A hostile token is dropped but the language is kept.
	 *
	 * @return void
	 */
	public function test_drops_a_hostile_token_but_keeps_the_language(): void {
		$this->assertSame(
			'<code class="language-php">x</code>',
			ozhmd_filter_language_classes('<code class="language-php evil">x</code>')
		);
	}

	/**
	 * The attribute is removed entirely when nothing survives.
	 *
	 * @return void
	 */
	public function test_removes_the_attribute_entirely_when_nothing_survives(): void {
		$this->assertSame(
			'<code>x</code>',
			ozhmd_filter_language_classes('<code class="evil">x</code>')
		);
	}

	/**
	 * Pre and code tags are filtered independently.
	 *
	 * @return void
	 */
	public function test_filters_pre_and_code_independently(): void {
		$this->assertSame(
			'<pre><code class="language-js">x</code></pre>',
			ozhmd_filter_language_classes('<pre class="line-numbers"><code class="language-js">x</code></pre>')
		);
	}

	/**
	 * Single quoted attributes are handled.
	 *
	 * @return void
	 */
	public function test_handles_single_quoted_attributes(): void {
		$this->assertSame(
			'<code class="language-php">x</code>',
			ozhmd_filter_language_classes("<code class='language-php evil'>x</code>")
		);
	}

	/**
	 * Other tags are left alone.
	 *
	 * @return void
	 */
	public function test_leaves_other_tags_alone(): void {
		$this->assertSame(
			'<div class="anything"><span class="x">y</span></div>',
			ozhmd_filter_language_classes('<div class="anything"><span class="x">y</span></div>')
		);
	}

	/**
	 * Non-class attributes are left alone.
	 *
	 * @return void
	 */
	public function test_leaves_non_class_attributes_alone(): void {
		$this->assertSame(
			'<code id="a" class="language-php">x</code>',
			ozhmd_filter_language_classes('<code id="a" class="language-php">x</code>')
		);
	}
}
