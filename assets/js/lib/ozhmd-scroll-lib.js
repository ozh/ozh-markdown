/**
 * Pure geometry for keeping the two editor panes scrolled together.
 *
 * Mapping one pane's scroll position onto the other by percentage drifts as
 * soon as the panes disagree on density, and they disagree constantly: an
 * image is one line of source and several hundred pixels of preview, a table
 * is a handful of lines and a tall block, a fenced block is neither. So the
 * panes are tied together at known points instead. Every top level Markdown
 * block is a point whose position is known on both sides, and a position
 * between two of them is interpolated.
 *
 * Neither function here touches the DOM, marked or CodeMirror. tokenLines()
 * takes tokens that have already been lexed, and mapScroll() takes a table of
 * positions that have already been measured, which is what makes both of them
 * directly testable.
 *
 * Loaded as a browser global (window.ozhmdScrollLib) by the editor, and as a
 * CommonJS module by the Node test suite.
 */
(function (root, factory) {
	'use strict';

	var api = factory();

	if (typeof module === 'object' && module.exports) {
		module.exports = api;
	} else {
		root.ozhmdScrollLib = api;
	}
})(typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	/**
	 * Line each top level block starts on.
	 *
	 * marked hands back the source of every token in its raw property, so
	 * counting newlines as the list is walked gives each block its starting
	 * line without the source needing to be scanned separately.
	 *
	 * Space tokens are dropped rather than counted, because they render to
	 * nothing: skipping them leaves one entry per element the preview will
	 * actually contain, which is what lets the caller line the two up by index.
	 *
	 * @param {object[]} tokens Top level tokens from marked's lexer.
	 * @return {number[]} Zero based start line of each rendered block.
	 */
	function tokenLines(tokens) {
		var lines = [];
		var line = 0;

		(tokens || []).forEach(function (token) {
			if ('space' !== token.type) {
				lines.push(line);
			}

			// \r\n contains one \n, so counting newlines rather than splitting
			// keeps CRLF sources on the same numbering as the editor's.
			line += (String(token.raw).match(/\n/g) || []).length;
		});

		return lines;
	}

	/**
	 * Translate a scroll position from one pane to the other.
	 *
	 * @param {object[]} anchors Table of {source, preview} pixel positions,
	 *                           ascending, as measured in each pane.
	 * @param {string} from Pane the position belongs to, 'source' or 'preview'.
	 * @param {number} pos Scroll position in that pane, in pixels.
	 * @return {number} Matching position in the other pane, in pixels.
	 */
	function mapScroll(anchors, from, pos) {
		var to = 'source' === from ? 'preview' : 'source';

		// Nothing to interpolate between. Handing the position back unchanged
		// leaves the pane where it is rather than throwing it to the top.
		if (!anchors || anchors.length < 2) {
			return pos;
		}

		var first = anchors[0];
		var last = anchors[anchors.length - 1];

		// Past either end there is no pair left, so the ends are held. Without
		// this, overscroll on one side would map to a position off the other.
		if (pos <= first[from]) {
			return first[to];
		}

		if (pos >= last[from]) {
			return last[to];
		}

		// Last anchor at or before the position. Stopping one short of the end
		// guarantees there is always a following anchor to pair it with.
		var i = 0;

		while (i < anchors.length - 2 && anchors[i + 1][from] <= pos) {
			i++;
		}

		var start = anchors[i];
		var end = anchors[i + 1];
		var span = end[from] - start[from];

		// Two blocks measuring to the same position in this pane, which happens
		// when one of them collapses to nothing. There is no ratio to take.
		if (0 === span) {
			return end[to];
		}

		return start[to] + ((pos - start[from]) / span) * (end[to] - start[to]);
	}

	return {
		tokenLines: tokenLines,
		mapScroll: mapScroll
	};
});
