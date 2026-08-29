<?php
/**
 * Builds the two distinctly configured Markdown converters.
 */

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Factory for the trusted and untrusted converter instances.
 *
 * Two separate instances with distinct configuration, never one instance with
 * runtime flags, so the permissive configuration cannot be reached from the
 * comment path by accident.
 */
class Ozhmd_Converter_Factory {

	/**
	 * Build the converter used for post content, authored by trusted users.
	 *
	 * Raw HTML is allowed through, because post authors are permitted to write
	 * it and legacy posts are full of it. The 'disallowed_raw_html' list is
	 * deliberately emptied: GithubFlavoredMarkdownExtension bundles
	 * DisallowedRawHtmlExtension, which rewrites script, iframe, style, title,
	 * textarea, xmp, noembed, noframes and plaintext to escaped text
	 * regardless of html_input => 'allow'. Left at its default, that would
	 * silently break legacy embeds such as YouTube iframes and gist scripts
	 * when a legacy post is converted.
	 *
	 * @return MarkdownConverter Converter permitting raw HTML.
	 */
	public function create_trusted(): MarkdownConverter {
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

	/**
	 * Build the converter used for comment content, authored by anyone.
	 *
	 * Raw HTML is stripped, unsafe link schemes are dropped, nesting is capped
	 * to bound pathological input, and every generated link is marked
	 * nofollow ugc.
	 *
	 * @return MarkdownConverter Converter safe for untrusted input.
	 */
	public function create_untrusted(): MarkdownConverter {
		$environment = new Environment([
			'html_input'         => 'strip',
			'allow_unsafe_links' => false,
			'max_nesting_level'  => 20,
		]);

		$environment->addExtension(new CommonMarkCoreExtension());
		$environment->addExtension(new GithubFlavoredMarkdownExtension());
		$environment->addEventListener(DocumentParsedEvent::class, [$this, 'mark_links_as_ugc'], -100);

		return new MarkdownConverter($environment);
	}

	/**
	 * Append rel="nofollow ugc" to every link in a parsed document.
	 *
	 * ExternalLinkExtension is deliberately not used: its config schema offers
	 * only nofollow, noopener and noreferrer, with no ugc option, and it
	 * applies rel by host matching rather than unconditionally.
	 *
	 * @param DocumentParsedEvent $event Event carrying the parsed document.
	 * @return void
	 */
	public function mark_links_as_ugc(DocumentParsedEvent $event): void {
		foreach ($event->getDocument()->iterator() as $node) {
			if ($node instanceof Link) {
				$node->data->append('attributes/rel', 'nofollow');
				$node->data->append('attributes/rel', 'ugc');
			}
		}
	}
}
