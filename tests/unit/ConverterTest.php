<?php

declare(strict_types=1);

use League\CommonMark\ConverterInterface;
use League\CommonMark\Output\RenderedContentInterface;
use PHPUnit\Framework\TestCase;

/**
 * The Markdown converter factory and its wrapper.
 *
 * Comments are out of scope, so there is a single converter for post content.
 * Post content is authored by logged in users and raw HTML passes through;
 * WordPress KSES, not this converter, decides who may store it. The pipeline
 * test for that lives in tests/integration/PostStorageTest.php.
 */
final class ConverterTest extends TestCase {

	/**
	 * Factory under test.
	 *
	 * @var Ozhmd_Converter_Factory
	 */
	private Ozhmd_Converter_Factory $factory;

	/**
	 * Build a fresh factory for each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->factory = new Ozhmd_Converter_Factory();
	}

	/**
	 * Ordinary block level HTML is emitted untouched.
	 *
	 * @return void
	 */
	public function test_raw_html_passes_through(): void {
		$html = (string) $this->factory->create()->convert('<div class="x">hi</div>');

		$this->assertStringContainsString('<div class="x">hi</div>', $html);
	}

	/**
	 * Embeds survive, which GFM's bundled DisallowedRawHtmlExtension would
	 * otherwise escape and quietly break on legacy posts.
	 *
	 * @return void
	 */
	public function test_iframe_embeds_are_not_escaped(): void {
		$html = (string) $this->factory->create()->convert('<iframe src="https://www.youtube.com/embed/x"></iframe>');

		$this->assertStringContainsString('<iframe src="https://www.youtube.com/embed/x">', $html);
		$this->assertStringNotContainsString('&lt;iframe', $html);
	}

	/**
	 * Script embeds such as gists survive for the same reason.
	 *
	 * @return void
	 */
	public function test_script_embeds_are_not_escaped(): void {
		$html = (string) $this->factory->create()->convert('<script src="https://gist.github.com/x.js"></script>');

		$this->assertStringContainsString('<script src="https://gist.github.com/x.js">', $html);
		$this->assertStringNotContainsString('&lt;script', $html);
	}

	/**
	 * GitHub Flavored Markdown is enabled, so tables render.
	 *
	 * @return void
	 */
	public function test_gfm_tables_render(): void {
		$html = (string) $this->factory->create()->convert("| a | b |\n|---|---|\n| 1 | 2 |");

		$this->assertStringContainsString('<table>', $html);
	}

	/**
	 * GitHub Flavored Markdown is enabled, so strikethrough renders.
	 *
	 * @return void
	 */
	public function test_gfm_strikethrough_renders(): void {
		$html = (string) $this->factory->create()->convert('~~x~~');

		$this->assertStringContainsString('<del>x</del>', $html);
	}

	/**
	 * Fenced blocks carry the language class Prism looks for.
	 *
	 * @return void
	 */
	public function test_fenced_blocks_emit_a_prism_language_class(): void {
		$html = (string) $this->factory->create()->convert("```php\necho 1;\n```");

		$this->assertStringContainsString('<pre><code class="language-php">', $html);
	}

	/**
	 * Deeply nested input is not capped, since post authors are trusted.
	 *
	 * @return void
	 */
	public function test_nesting_depth_is_not_capped(): void {
		$html = (string) $this->factory->create()->convert(str_repeat('> ', 25) . 'boom');

		$this->assertSame(25, substr_count($html, '<blockquote>'));
	}

	/**
	 * Each call builds an independent converter, so no state can be shared.
	 *
	 * @return void
	 */
	public function test_the_factory_returns_a_new_instance_each_time(): void {
		$this->assertNotSame($this->factory->create(), $this->factory->create());
	}

	/**
	 * The wrapper delegates to whatever converter it was given.
	 *
	 * @return void
	 */
	public function test_the_wrapper_delegates_to_the_injected_converter(): void {
		$rendered = $this->createMock(RenderedContentInterface::class);
		$rendered->method('__toString')->willReturn('RENDERED');

		$inner = $this->createMock(ConverterInterface::class);
		$inner->method('convert')->willReturn($rendered);

		$this->assertSame('RENDERED', (new Ozhmd_Converter($inner))->to_html('anything'));
	}

	/**
	 * The wrapper passes the Markdown through without altering it.
	 *
	 * @return void
	 */
	public function test_the_wrapper_passes_the_markdown_through_unchanged(): void {
		$rendered = $this->createMock(RenderedContentInterface::class);
		$rendered->method('__toString')->willReturn('out');

		$inner = $this->createMock(ConverterInterface::class);
		$inner->expects($this->once())
			->method('convert')
			->with('# in')
			->willReturn($rendered);

		$this->assertSame('out', (new Ozhmd_Converter($inner))->to_html('# in'));
	}
}
