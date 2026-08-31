/**
 * Pure text transforms for the editor toolbar.
 *
 * Every transform takes the current selection and returns the replacement text
 * plus an optional selection range, expressed as offsets into that text. No DOM
 * access, no editor knowledge, no state, so each one is directly testable.
 *
 * The ones under lineTransforms are given the whole lines the selection spans
 * instead, because they need to see what is already at the start of the line.
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
	 * @param {number} [shift] Characters gained by the first line, so that a
	 *                         caret sitting in it can be moved along with it.
	 * @return {object} Transform result.
	 */
	function result(text, select, shift) {
		return { text: text, select: select || null, shift: shift || 0 };
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
	 * ATX heading level of a line, zero when the line is not a heading.
	 *
	 * Hashes only open a heading when a space or the end of the line follows,
	 * and six is as deep as Markdown goes, so "#hashtag" and "####### x" are
	 * both plain text.
	 *
	 * @param {string} line Line to measure.
	 * @return {number} Level from 0 to 6.
	 */
	function headingLevel(line) {
		var match = line.match(/^(#{1,6})(?: |$)/);

		return match ? match[1].length : 0;
	}

	/**
	 * Add one hash to the heading of the lines a selection spans.
	 *
	 * Clicking the button again goes one level deeper, and once past h6 the
	 * heading is dropped so the next click starts over at h1. Every line takes
	 * the level computed from the first one, which keeps a block moving as a
	 * unit under repeated clicks.
	 *
	 * @param {string} block The whole lines the selection spans.
	 * @return {object} Transform result.
	 */
	function heading(block) {
		var lines = block.split('\n');
		var level = (headingLevel(lines[0]) + 1) % 7;
		var prefix = level ? new Array(level + 1).join('#') + ' ' : '';

		if ('' === block) {
			return result(prefix + 'Heading', [prefix.length, prefix.length + 'Heading'.length]);
		}

		var text = lines.map(function (line) {
			return prefix + line.replace(/^#{1,6}(?: |$)/, '');
		}).join('\n');

		return result(text, null, text.split('\n')[0].length - lines[0].length);
	}

	/**
	 * Put a thematic break below the lines the selection spans.
	 *
	 * A rule is a block of its own, so it cannot share a line with anything.
	 * On an empty line it stands there alone; under text it goes on its own
	 * line, with a blank one above it to keep the source readable. Either way
	 * the cursor ends up below the rule, where the writing continues.
	 *
	 * @param {string} block The whole lines the selection spans.
	 * @return {object} Transform result.
	 */
	function rule(block) {
		var text = '' === block ? '***' : block + '\n\n***';

		return result(text, [text.length, text.length]);
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
	 * Insert a Markdown image, keeping any selection as the alt text.
	 *
	 * The destination is always the bare scheme, since the button is for typing
	 * a URL in by hand; the Add Media button is the route for one WordPress
	 * already knows. With an alt text supplied the caret lands right after that
	 * scheme, ready for the rest of the URL, and the empty title is left in
	 * place to be filled or deleted.
	 *
	 * @param {string} selection Current selection.
	 * @return {object} Transform result.
	 */
	function image(selection) {
		var scheme = 'https://';

		if (selection) {
			var caret = ('![' + selection + '](' + scheme).length;

			return result('![' + selection + '](' + scheme + ' "")', [caret, caret]);
		}

		return result('![alt](' + scheme + ' "title")', [2, 5]);
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
	 * A list item: indentation, marker, the space after it, and an optional
	 * task box. The space is required, which is what keeps a rule such as
	 * "***" and an emphasis such as "*word*" out of it.
	 */
	var LIST_ITEM = /^([ \t]*)([*+-]|\d+[.)])([ \t]+)(\[[ xX]\][ \t]+)?(.*)$/;

	/**
	 * One level of nesting, in spaces.
	 *
	 * Three, because it is the only width that works everywhere. A nested item
	 * has to start at or past the column its parent's text starts in, which is
	 * two for "* " but three for "1. ", so two spaces silently leaves a bullet
	 * under a numbered item as a separate list. Four would reach far enough,
	 * but an item indented four with no parent above it is an indented code
	 * block instead of a list.
	 */
	var INDENT = '   ';

	/**
	 * Move the list items among a set of lines in or out one level.
	 *
	 * Lines that are not list items are handed back untouched: indenting a
	 * paragraph changes what it means, and a rule or a heading has no level to
	 * sit at. Outdenting takes a tab as a level of its own, and settles for
	 * whatever indentation is there when it is less than a full level.
	 *
	 * @param {boolean} deeper True to indent, false to outdent.
	 * @return {Function} Line transform.
	 */
	function reindenter(deeper) {
		return function (block) {
			var first = 0;

			var text = block.split('\n').map(function (line, index) {
				if (!LIST_ITEM.test(line)) {
					return line;
				}

				var moved = deeper
					? INDENT + line
					: line.replace(/^(?:\t| {1,3})/, '');

				if (0 === index) {
					first = moved.length - line.length;
				}

				return moved;
			}).join('\n');

			return result(text, null, first);
		};
	}

	/**
	 * How a list item carries on to the next line.
	 *
	 * The marker is repeated as written, indentation and all, so a nested item
	 * stays at its level; a number is bumped by one; a task box comes back
	 * unticked. An item with no text is reported as empty, since the writer
	 * pressing enter on a bare marker is done with the list.
	 *
	 * @param {string} line The line the cursor is on.
	 * @return {object|null} Continuation, or null when the line is not an item.
	 */
	function listContinuation(line) {
		var match = line.match(LIST_ITEM);

		if (!match) {
			return null;
		}

		var number = match[2].match(/^(\d+)([.)])$/);
		var marker = number ? (Number(number[1]) + 1) + number[2] : match[2];
		var task = match[4] ? '[ ] ' : '';

		return {
			prefix: match[1] + marker + match[3] + task,
			markerLength: match[1].length + match[2].length + match[3].length + (match[4] ? match[4].length : 0),
			empty: '' === match[5]
		};
	}

	/**
	 * The three states of the eye button, in the order it cycles through them.
	 *
	 * Both panes is where the editor starts, so it is first.
	 */
	var VIEWS = ['both', 'source', 'preview'];

	/**
	 * Next view in the cycle.
	 *
	 * A state that is not one of the three, which is what an out of date stored
	 * value or a first click on a fresh editor looks like, counts as the
	 * default, so the click still moves somewhere sensible.
	 *
	 * @param {string} current Current view.
	 * @return {string} The one that follows it.
	 */
	function nextView(current) {
		var index = VIEWS.indexOf(current);

		return VIEWS[((-1 === index ? 0 : index) + 1) % VIEWS.length];
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
			bold: wrapper('**', 'bold text'),
			italic: wrapper('_', 'italic text'),
			strikethrough: wrapper('~~', 'strikethrough text'),
			quote: linePrefixer('> ', 'Quote'),
			code: code,
			link: link,
			image: image,
			ol: linePrefixer(function (index) {
				return (index + 1) + '. ';
			}, 'List item'),
			ul: linePrefixer('* ', 'List item'),
			task: linePrefixer('- [ ] ', 'Task'),
			table: table
		},
		lineTransforms: {
			heading: heading,
			hr: rule,
			indent: reindenter(true),
			outdent: reindenter(false)
		},
		views: VIEWS,
		nextView: nextView,
		listContinuation: listContinuation,
		htmlToMarkdown: htmlToMarkdown
	};
});
