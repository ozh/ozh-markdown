/**
 * Live word count, sentence length and reading time under the editor.
 *
 * The numbers describe the post a reader will get rather than the Markdown
 * being typed, so they are taken from the rendered preview: syntax, link
 * targets and code blocks are already gone from it. The counting and the
 * wording are pure and live in ozhmd-stats-lib.js; this file is the DOM.
 *
 * It listens for the editor's ozhmd:preview event rather than for changes to
 * the document, so it runs on the render's own debounce and can never describe
 * a preview that has not been rendered yet. That also means insertions made by
 * the toolbar and the uploader are counted without either of them knowing this
 * file exists.
 */
(function () {
	'use strict';

	var lib = window.ozhmdStatsLib;

	/**
	 * The prose of the preview, with the code taken out.
	 *
	 * The clone is what keeps the visible preview untouched while its <pre>
	 * blocks are removed. Fenced and indented code is not prose and would
	 * inflate all three numbers; inline code stays, because it reads as part of
	 * the sentence around it. Images bring nothing either way, having no text.
	 *
	 * @param {HTMLElement} preview Preview container.
	 * @return {string} Text to count.
	 */
	function proseOf(preview) {
		var clone = preview.cloneNode(true);

		Array.prototype.forEach.call(clone.querySelectorAll('pre'), function (block) {
			block.parentNode.removeChild(block);
		});

		// marked writes a newline between block elements, so those come apart
		// on their own. A hard line break does not: "One<br>Two" reads back as
		// "OneTwo", which is one word where the author wrote two, and one
		// sentence where they may have written two.
		Array.prototype.forEach.call(clone.querySelectorAll('br'), function (br) {
			br.parentNode.replaceChild(clone.ownerDocument.createTextNode('\n'), br);
		});

		return clone.textContent || '';
	}

	/**
	 * Start counting once the DOM is ready.
	 *
	 * @return {void}
	 */
	function init() {
		var container = document.querySelector('[data-ozhmd-editor]');
		var preview = document.querySelector('[data-ozhmd-preview]');
		var output = document.querySelector('[data-ozhmd-stats]');

		// Without the lib, or without anywhere to write, the editor is whole
		// and only the stats line is missing. It stays empty, and the
		// stylesheet takes an empty one out of the flow.
		if (!lib || !container || !preview || !output) {
			return;
		}

		var config = window.ozhmdStats || {};

		/**
		 * Count what the preview currently holds and write the line.
		 *
		 * The links and the code blocks are counted off the live preview rather
		 * than the clone, since neither survives into the prose: the blocks are
		 * removed on the way, and an anchor keeps its text but loses its href.
		 *
		 * @return {void}
		 */
		function update() {
			var stats = lib.count(proseOf(preview));

			stats.links = preview.querySelectorAll('a').length;
			stats.codeBlocks = preview.querySelectorAll('pre').length;

			output.textContent = lib.format(stats, config.i18n);
		}

		container.addEventListener('ozhmd:preview', update);

		// The editor renders once from its own DOMContentLoaded handler, which
		// was registered before this one and so has already run: that first
		// event was dispatched before there was anything listening for it.
		// Without this the line would sit empty until the first keystroke.
		update();
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
