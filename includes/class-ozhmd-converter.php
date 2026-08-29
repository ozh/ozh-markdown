<?php
/**
 * Holds the Markdown converter used for post content.
 */

use League\CommonMark\ConverterInterface;

/**
 * Markdown conversion entry point.
 *
 * Carries no configuration of its own: the converter is injected, so tests can
 * substitute a fake and no caller can reconfigure it at runtime.
 *
 * There is deliberately only one converter. An earlier design had a second,
 * hardened instance for untrusted comment input, but comments are out of scope,
 * so shipping a second configuration that nothing calls would be dead code
 * standing in for a security boundary that no longer exists.
 */
class Ozhmd_Converter {

	/**
	 * Converter permitting raw HTML, used for post content.
	 *
	 * @var ConverterInterface
	 */
	private ConverterInterface $converter;

	/**
	 * Store the injected converter.
	 *
	 * @param ConverterInterface $converter Converter for post content.
	 */
	public function __construct(ConverterInterface $converter) {
		$this->converter = $converter;
	}

	/**
	 * Convert post Markdown into HTML.
	 *
	 * Post content is authored by logged in users, so raw HTML passes through.
	 * WordPress KSES still decides who may store it: the save path writes
	 * through wp_update_post(), which runs content_save_pre for any user
	 * lacking the unfiltered_html capability.
	 *
	 * @param string $markdown Raw Markdown.
	 * @return string Rendered HTML.
	 */
	public function to_html(string $markdown): string {
		return (string) $this->converter->convert($markdown);
	}
}
