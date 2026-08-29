<?php
/**
 * Builds the Markdown converter used for post content.
 */

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Builds the configured Markdown converter for post content.
 */
class Ozhmd_Converter_Factory {

	/**
	 * Build the converter used for post content.
	 *
	 * Raw HTML is allowed through, because post authors are permitted to write
	 * it and legacy posts are full of it. WordPress KSES remains the gate on
	 * who may actually store it. The 'disallowed_raw_html' list is
	 * deliberately emptied: GithubFlavoredMarkdownExtension bundles
	 * DisallowedRawHtmlExtension, which rewrites script, iframe, style, title,
	 * textarea, xmp, noembed, noframes and plaintext to escaped text
	 * regardless of html_input => 'allow'. Left at its default, that would
	 * silently break legacy embeds such as YouTube iframes and gist scripts
	 * when a legacy post is converted.
	 *
	 * @return MarkdownConverter Converter permitting raw HTML.
	 */
	public function create(): MarkdownConverter {
		$environment = new Environment([
			'html_input'          => 'allow',
			'allow_unsafe_links'  => true,
			'disallowed_raw_html' => [
				'disallowed_tags' => [],
			],
		]);

		$environment->addExtension(new CommonMarkCoreExtension());
		$environment->addExtension(new GithubFlavoredMarkdownExtension());

		return new MarkdownConverter($environment);
	}
}
