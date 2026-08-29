<?php

declare(strict_types=1);

use League\CommonMark\ConverterInterface;
use League\CommonMark\Output\RenderedContentInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the trusted and untrusted converter factory and wrapper.
 */
final class ConverterTest extends TestCase {

	/**
	 * Factory under test.
	 *
	 * @var Ozhmd_Converter_Factory
	 */
	private Ozhmd_Converter_Factory $factory;

	/**
	 * Create a fresh factory before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->factory = new Ozhmd_Converter_Factory();
	}

	/**
	 * The trusted converter passes raw HTML through unchanged.
	 *
	 * @return void
	 */
	public function test_trusted_converter_passes_raw_html_through(): void {
		$html = (string) $this->factory->create_trusted()->convert('<div class="x">hi</div>');

		$this->assertStringContainsString('<div class="x">hi</div>', $html);
	}

	/**
	 * The trusted converter passes an iframe embed through unescaped, since
	 * GithubFlavoredMarkdownExtension would otherwise escape it despite
	 * html_input => 'allow'.
	 *
	 * @return void
	 */
	public function test_trusted_converter_passes_iframe_embeds_through(): void {
		$html = (string) $this->factory->create_trusted()->convert('<iframe src="https://www.youtube.com/embed/x"></iframe>');

		$this->assertStringContainsString('<iframe src="https://www.youtube.com/embed/x"></iframe>', $html);
	}

	/**
	 * The trusted converter passes a script embed through unescaped, since
	 * GithubFlavoredMarkdownExtension would otherwise escape it despite
	 * html_input => 'allow'.
	 *
	 * @return void
	 */
	public function test_trusted_converter_passes_script_embeds_through(): void {
		$html = (string) $this->factory->create_trusted()->convert('<script src="https://gist.github.com/x.js"></script>');

		$this->assertStringContainsString('<script src="https://gist.github.com/x.js"></script>', $html);
	}

	/**
	 * The untrusted converter still strips iframe and script tags, proving the
	 * trusted converter's emptied disallowed list is not shared configuration.
	 *
	 * @return void
	 */
	public function test_untrusted_converter_still_strips_iframe_and_script_tags(): void {
		$html = (string) $this->factory->create_untrusted()->convert(
			'<iframe src="https://www.youtube.com/embed/x"></iframe><script src="https://gist.github.com/x.js"></script>'
		);

		// html_input => strip removes the tags and keeps their text, which is
		// safe. Assert on the tag, not on the absence of any text it contained.
		$this->assertStringNotContainsString('<iframe', $html);
		$this->assertStringNotContainsString('<script', $html);
	}

	/**
	 * The trusted converter renders GFM tables.
	 *
	 * @return void
	 */
	public function test_trusted_converter_renders_gfm_tables(): void {
		$html = (string) $this->factory->create_trusted()->convert("| a | b |\n|---|---|\n| 1 | 2 |");

		$this->assertStringContainsString('<table>', $html);
	}

	/**
	 * The untrusted converter strips script tags but keeps their text.
	 *
	 * @return void
	 */
	public function test_untrusted_converter_strips_script_tags(): void {
		$html = (string) $this->factory->create_untrusted()->convert('hi <script>alert(1)</script> there');

		// html_input => strip removes the tags and keeps their text, which is
		// safe. Assert on the tag, not on the text it contained.
		$this->assertStringNotContainsString('<script', $html);
		$this->assertStringContainsString('hi', $html);
	}

	/**
	 * The untrusted converter strips arbitrary raw HTML.
	 *
	 * @return void
	 */
	public function test_untrusted_converter_strips_arbitrary_raw_html(): void {
		$html = (string) $this->factory->create_untrusted()->convert('<img src=x onerror=alert(1)>');

		$this->assertStringNotContainsString('<img', $html);
		$this->assertStringNotContainsString('onerror', $html);
	}

	/**
	 * The untrusted converter marks generated links nofollow ugc.
	 *
	 * @return void
	 */
	public function test_untrusted_converter_marks_links_nofollow_ugc(): void {
		$html = (string) $this->factory->create_untrusted()->convert('[a](https://e.com/x)');

		$this->assertStringContainsString('rel="nofollow ugc"', $html);
		$this->assertStringContainsString('href="https://e.com/x"', $html);
	}

	/**
	 * The untrusted converter drops unsafe link schemes.
	 *
	 * @return void
	 */
	public function test_untrusted_converter_drops_unsafe_link_schemes(): void {
		$html = (string) $this->factory->create_untrusted()->convert('[a](javascript:alert(1))');

		$this->assertStringNotContainsString('javascript:', $html);
	}

	/**
	 * The trusted converter does not mark links ugc.
	 *
	 * @return void
	 */
	public function test_trusted_converter_does_not_mark_links_ugc(): void {
		$html = (string) $this->factory->create_trusted()->convert('[a](https://e.com/x)');

		$this->assertStringNotContainsString('ugc', $html);
	}

	/**
	 * Both converters emit Prism-compatible language classes for fenced code.
	 *
	 * @return void
	 */
	public function test_both_converters_emit_prism_language_classes(): void {
		$markdown = "```php\necho 1;\n```";

		$this->assertStringContainsString(
			'<pre><code class="language-php">',
			(string) $this->factory->create_trusted()->convert($markdown)
		);
		$this->assertStringContainsString(
			'<pre><code class="language-php">',
			(string) $this->factory->create_untrusted()->convert($markdown)
		);
	}

	/**
	 * The untrusted converter renders GFM strikethrough.
	 *
	 * @return void
	 */
	public function test_untrusted_converter_renders_gfm_strikethrough(): void {
		$html = (string) $this->factory->create_untrusted()->convert('~~x~~');

		$this->assertStringContainsString('<del>x</del>', $html);
	}

	/**
	 * The untrusted converter caps nesting depth at max_nesting_level.
	 *
	 * @return void
	 */
	public function test_untrusted_converter_caps_nesting_depth(): void {
		// max_nesting_level bounds pathological input. Without it, deeply
		// nested markup is a cheap way to burn server time on a comment form.
		$html = (string) $this->factory->create_untrusted()->convert(str_repeat('> ', 25) . 'boom');

		$this->assertSame(20, substr_count($html, '<blockquote>'));
	}

	/**
	 * The trusted converter does not cap nesting depth.
	 *
	 * @return void
	 */
	public function test_trusted_converter_does_not_cap_nesting_depth(): void {
		$html = (string) $this->factory->create_trusted()->convert(str_repeat('> ', 25) . 'boom');

		$this->assertSame(25, substr_count($html, '<blockquote>'));
	}

	/**
	 * The untrusted converter strips HTML comments.
	 *
	 * @return void
	 */
	public function test_untrusted_converter_strips_html_comments(): void {
		$html = (string) $this->factory->create_untrusted()->convert('<!-- secret --> hi');

		$this->assertStringNotContainsString('secret', $html);
	}

	/**
	 * The untrusted converter neutralises data URI images.
	 *
	 * @return void
	 */
	public function test_untrusted_converter_neutralises_data_uri_images(): void {
		$html = (string) $this->factory->create_untrusted()->convert('![x](data:text/html;base64,PHNjcmlwdD4=)');

		$this->assertStringNotContainsString('data:text/html', $html);
	}

	/**
	 * The factory returns a new converter instance on every call.
	 *
	 * @return void
	 */
	public function test_factory_returns_a_new_instance_each_time(): void {
		$this->assertNotSame($this->factory->create_trusted(), $this->factory->create_trusted());
	}

	/**
	 * The wrapper delegates trusted conversion to the injected trusted converter.
	 *
	 * @return void
	 */
	public function test_wrapper_delegates_to_the_injected_trusted_converter(): void {
		$converter = new Ozhmd_Converter(
			$this->stub_converter('TRUSTED'),
			$this->stub_converter('UNTRUSTED')
		);

		$this->assertSame('TRUSTED', $converter->to_html_trusted('anything'));
	}

	/**
	 * The wrapper delegates untrusted conversion to the injected untrusted converter.
	 *
	 * @return void
	 */
	public function test_wrapper_delegates_to_the_injected_untrusted_converter(): void {
		$converter = new Ozhmd_Converter(
			$this->stub_converter('TRUSTED'),
			$this->stub_converter('UNTRUSTED')
		);

		$this->assertSame('UNTRUSTED', $converter->to_html_untrusted('anything'));
	}

	/**
	 * The wrapper passes the markdown argument through unchanged.
	 *
	 * @return void
	 */
	public function test_wrapper_passes_the_markdown_through_unchanged(): void {
		$rendered = $this->createMock(RenderedContentInterface::class);
		$rendered->method('__toString')->willReturn('out');

		$inner = $this->createMock(ConverterInterface::class);
		$inner->expects($this->once())
			->method('convert')
			->with('# in')
			->willReturn($rendered);

		$converter = new Ozhmd_Converter($inner, $this->stub_converter('other'));

		$this->assertSame('out', $converter->to_html_trusted('# in'));
	}

	/**
	 * Build a converter stub whose rendered output is a fixed string.
	 *
	 * @param string $output String the stub renders for any input.
	 * @return ConverterInterface Stubbed converter.
	 */
	private function stub_converter(string $output): ConverterInterface {
		$rendered = $this->createMock(RenderedContentInterface::class);
		$rendered->method('__toString')->willReturn($output);

		$converter = $this->createMock(ConverterInterface::class);
		$converter->method('convert')->willReturn($rendered);

		return $converter;
	}
}
