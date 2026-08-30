<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Marker classes added to <pre> for the Prism plugins.
 */
final class PreClassesTest extends TestCase {

	/**
	 * A bare pre wrapping code gains the classes.
	 *
	 * @return void
	 */
	public function test_a_bare_pre_gains_the_classes(): void {
		$this->assertSame(
			'<pre class="match-braces"><code class="language-php">x</code></pre>',
			ozhmd_add_pre_classes('<pre><code class="language-php">x</code></pre>', ['match-braces'])
		);
	}

	/**
	 * Existing classes on the pre are preserved.
	 *
	 * @return void
	 */
	public function test_existing_classes_are_preserved(): void {
		$out = ozhmd_add_pre_classes('<pre class="keep"><code>x</code></pre>', ['match-braces']);

		$this->assertStringContainsString('keep', $out);
		$this->assertStringContainsString('match-braces', $out);
	}

	/**
	 * A class already present is not repeated.
	 *
	 * @return void
	 */
	public function test_a_class_is_not_duplicated(): void {
		$out = ozhmd_add_pre_classes('<pre class="match-braces"><code>x</code></pre>', ['match-braces']);

		$this->assertSame(1, substr_count($out, 'match-braces'));
	}

	/**
	 * Preformatted text that is not code is left alone.
	 *
	 * @return void
	 */
	public function test_a_pre_without_code_is_untouched(): void {
		$html = '<pre>just preformatted</pre>';

		$this->assertSame($html, ozhmd_add_pre_classes($html, ['match-braces']));
	}

	/**
	 * Several blocks in one document are all decorated.
	 *
	 * @return void
	 */
	public function test_every_code_block_is_decorated(): void {
		$html = '<pre><code>a</code></pre><p>x</p><pre><code>b</code></pre>';

		$this->assertSame(2, substr_count(ozhmd_add_pre_classes($html, ['match-braces']), 'match-braces'));
	}

	/**
	 * An empty class list leaves the HTML untouched.
	 *
	 * @return void
	 */
	public function test_an_empty_class_list_is_a_no_op(): void {
		$html = '<pre><code>x</code></pre>';

		$this->assertSame($html, ozhmd_add_pre_classes($html, []));
	}

	/**
	 * The inner code element keeps its language class.
	 *
	 * @return void
	 */
	public function test_the_language_class_on_code_survives(): void {
		$out = ozhmd_add_pre_classes('<pre><code class="language-php">x</code></pre>', ['line-numbers']);

		$this->assertStringContainsString('<code class="language-php">', $out);
	}

	/**
	 * Line numbers are only requested when the setting is on.
	 *
	 * @return void
	 */
	public function test_line_numbers_are_opt_in(): void {
		$this->assertSame(['match-braces'], ozhmd_prism_pre_classes(false));
		$this->assertSame(['match-braces', 'line-numbers'], ozhmd_prism_pre_classes(true));
	}
}
