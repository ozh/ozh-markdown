/**
 * Pure text transforms for the editor toolbar.
 *
 * Every transform takes the current selection and returns the replacement text
 * plus an optional selection range, expressed as offsets into that text. No DOM
 * access, no editor knowledge, no state, so each one is directly testable.
 *
 * Loaded as a browser global (window.ozhmdToolbarLib) by the editor, and as a
 * CommonJS module by the Node test suite.
 */
(function (root, factory) {
	'use strict';

	var api = factory();

	if (typeof module === 'object' && module.exports) {
		module.exports = api;
	} else {
		root.ozhmdToolbarLib = api;
	}
})(typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	/**
	 * Build a transform result.
	 *
	 * @param {string} text Replacement text.
	 * @param {number[]|null} select Selection range within the text, or null.
	 * @return {object} Transform result.
	 */
	function result(text, select) {
		return { text: text, select: select || null };
	}

	/**
	 * Wrap a selection in a marker, or emit a placeholder when empty.
	 *
	 * @param {string} marker Marker placed on both sides.
	 * @param {string} placeholder Text used when nothing is selected.
	 * @return {Function} Transform.
	 */
	function wrapper(marker, placeholder) {
		return function (selection) {
			var body = selection || placeholder;
			var start = marker.length;

			return result(marker + body + marker, selection ? null : [start, start + body.length]);
		};
	}

	/**
	 * Prefix every line of a selection.
	 *
	 * @param {string|Function} prefix Literal prefix, or a function of the line index.
	 * @param {string} placeholder Text used when nothing is selected.
	 * @return {Function} Transform.
	 */
	function linePrefixer(prefix, placeholder) {
		return function (selection) {
			var body = selection || placeholder;
			var lines = body.split('\n');

			var text = lines.map(function (line, index) {
				return (typeof prefix === 'function' ? prefix(index) : prefix) + line;
			}).join('\n');

			if (selection) {
				return result(text, null);
			}

			var offset = (typeof prefix === 'function' ? prefix(0) : prefix).length;

			return result(text, [offset, offset + body.length]);
		};
	}

	/**
	 * Wrap a selection in a fenced block when it spans lines, inline otherwise.
	 *
	 * @param {string} selection Current selection.
	 * @return {object} Transform result.
	 */
	function code(selection) {
		if (selection && -1 !== selection.indexOf('\n')) {
			return result('```\n' + selection + '\n```', null);
		}

		if (selection) {
			return result('`' + selection + '`', null);
		}

		return result('```\n\n```', [4, 4]);
	}

	/**
	 * Build a Markdown link, treating a selected URL as the destination.
	 *
	 * @param {string} selection Current selection.
	 * @return {object} Transform result.
	 */
	function link(selection) {
		if (/^https?:\/\/\S+$/i.test(selection)) {
			return result('[](' + selection + ')', [1, 1]);
		}

		if (selection) {
			return result('[' + selection + '](url)', [selection.length + 3, selection.length + 6]);
		}

		return result('[](url)', [1, 1]);
	}

	/**
	 * Insert a small Markdown table, keeping any selection as the first cell.
	 *
	 * @param {string} selection Current selection.
	 * @return {object} Transform result.
	 */
	function table(selection) {
		var first = selection || 'Column';
		var text = '| ' + first + ' | Column |\n| --- | --- |\n|  |  |';

		return result(text, selection ? null : [2, 2 + first.length]);
	}

	/**
	 * Convert the HTML the media modal returns into Markdown.
	 *
	 * Handles the two shapes that actually occur, a linked or bare image and a
	 * plain link. Anything else is returned unchanged, which is still valid
	 * inside a Markdown document.
	 *
	 * @param {string} html HTML from the media modal.
	 * @return {string} Markdown, or the original HTML.
	 */
	function htmlToMarkdown(html) {
		if (typeof html !== 'string') {
			return '';
		}

		var img = html.match(/<img[^>]*\bsrc=["']([^"']+)["'][^>]*>/i);

		if (img) {
			var alt = html.match(/<img[^>]*\balt=["']([^"']*)["'][^>]*>/i);

			return '![' + (alt ? alt[1] : '') + '](' + img[1] + ')';
		}

		var anchor = html.match(/<a[^>]*\bhref=["']([^"']+)["'][^>]*>([\s\S]*?)<\/a>/i);

		if (anchor) {
			return '[' + anchor[2].replace(/<[^>]+>/g, '').trim() + '](' + anchor[1] + ')';
		}

		return html;
	}

	return {
		transforms: {
			heading: linePrefixer('## ', 'Heading'),
			bold: wrapper('**', 'bold text'),
			italic: wrapper('_', 'italic text'),
			quote: linePrefixer('> ', 'Quote'),
			code: code,
			link: link,
			ol: linePrefixer(function (index) {
				return (index + 1) + '. ';
			}, 'List item'),
			ul: linePrefixer('- ', 'List item'),
			task: linePrefixer('- [ ] ', 'Task'),
			table: table
		},
		htmlToMarkdown: htmlToMarkdown
	};
});
