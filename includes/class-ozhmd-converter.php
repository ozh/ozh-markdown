<?php
/**
 * Holds the two converter instances and exposes them by trust level.
 */

use League\CommonMark\ConverterInterface;

/**
 * Markdown conversion entry point.
 *
 * Carries no configuration of its own: both instances are injected, so tests
 * can substitute fakes and no caller can reconfigure a converter at runtime.
 */
class Ozhmd_Converter {

	/**
	 * Converter permitting raw HTML, used for post content.
	 *
	 * @var ConverterInterface
	 */
	private ConverterInterface $trusted;

	/**
	 * Converter stripping raw HTML, used for comment content.
	 *
	 * @var ConverterInterface
	 */
	private ConverterInterface $untrusted;

	/**
	 * Store the injected converters.
	 *
	 * @param ConverterInterface $trusted   Converter for trusted input.
	 * @param ConverterInterface $untrusted Converter for untrusted input.
	 */
	public function __construct(ConverterInterface $trusted, ConverterInterface $untrusted) {
		$this->trusted   = $trusted;
		$this->untrusted = $untrusted;
	}

	/**
	 * Convert Markdown written by a trusted author.
	 *
	 * @param string $markdown Raw Markdown.
	 * @return string Rendered HTML.
	 */
	public function to_html_trusted(string $markdown): string {
		return (string) $this->trusted->convert($markdown);
	}

	/**
	 * Convert Markdown written by an untrusted author.
	 *
	 * @param string $markdown Raw Markdown.
	 * @return string Rendered HTML.
	 */
	public function to_html_untrusted(string $markdown): string {
		return (string) $this->untrusted->convert($markdown);
	}
}
