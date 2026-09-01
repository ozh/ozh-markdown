/**
 * Pure parts of the editor's resize handle.
 *
 * The handle itself is pointer arithmetic over live geometry, which is only
 * meaningful in a browser. What is here is everything that is not: how tall the
 * editor is allowed to be, and how that height survives between two visits to
 * the edit screen.
 *
 * Neither storage function reaches for window.localStorage. The storage object
 * is passed in, which is what makes them testable, and both hold their nerve
 * when it throws: Safari in private browsing throws on setItem, and an editor
 * is not worth losing over a remembered height.
 *
 * Loaded as a browser global (window.ozhmdResizeLib) by the editor, and as a
 * CommonJS module by the Node test suite.
 */
(function (root, factory) {
	'use strict';

	var api = factory();

	if (typeof module === 'object' && module.exports) {
		module.exports = api;
	} else {
		root.ozhmdResizeLib = api;
	}
})(typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	// Matches the min-height the stylesheet gives the container, so the CSS and
	// the drag agree on the floor rather than one quietly correcting the other.
	var MIN_HEIGHT = 200;

	var STORAGE_KEY = 'ozhmd-editor-height';

	// Share of the row the source pane takes when nothing has moved it, and the
	// least either pane may be squeezed to. A pane narrower than this is a
	// column of two words and a scrollbar, which is not a view of anything.
	var DEFAULT_SPLIT = 50;
	var MIN_SPLIT = 15;

	/**
	 * Hold a height between the floor and the height of the window.
	 *
	 * The ceiling is the viewport because a drag that runs past the bottom of
	 * the window scrolls the page under the pointer, and the editor would
	 * otherwise keep growing for as long as the button was held.
	 *
	 * @param {number} height Height being asked for, in pixels.
	 * @param {number} viewport Height of the window, in pixels.
	 * @return {number} Height that may actually be applied, in pixels.
	 */
	function clamp(height, viewport) {
		var wanted = Number(height);
		var top = Number(viewport);

		// A window shorter than the floor leaves no legal height at all. The
		// floor wins, and the editor scrolls with the page.
		var ceiling = isNaN(top) ? MIN_HEIGHT : Math.max(MIN_HEIGHT, top);

		if (isNaN(wanted)) {
			return MIN_HEIGHT;
		}

		return Math.min(Math.max(wanted, MIN_HEIGHT), ceiling);
	}

	/**
	 * The remembered height, ready to apply.
	 *
	 * What comes back from storage is a string that was a height once, on a
	 * window that may have been a different size, in a browser that may have
	 * been given something else entirely under the same key. So it is parsed,
	 * refused unless it is a positive finite number, and clamped against the
	 * window it is being restored into.
	 *
	 * @param {object} storage Storage object, or null when there is none.
	 * @param {number} viewport Height of the window, in pixels.
	 * @return {number|null} Height to apply, or null when there is none to.
	 */
	function readHeight(storage, viewport) {
		var stored;

		if (!storage) {
			return null;
		}

		try {
			stored = storage.getItem(STORAGE_KEY);
		} catch (e) {
			return null;
		}

		// parseFloat rather than Number, so a value stored with its unit still
		// reads as a height. Number('640px') is NaN.
		var height = parseFloat(stored);

		if (!isFinite(height) || height <= 0) {
			return null;
		}

		return clamp(height, viewport);
	}

	/**
	 * Remember a height for the next visit.
	 *
	 * @param {object} storage Storage object, or null when there is none.
	 * @param {number} height Height to store, in pixels.
	 * @return {void}
	 */
	function writeHeight(storage, height) {
		if (!storage) {
			return;
		}

		try {
			storage.setItem(STORAGE_KEY, String(Math.round(height)));
		} catch (e) {
			// Nothing to do and nothing worth saying: the height simply will
			// not be there next time.
		}
	}

	/**
	 * Hold a share of the row between the two panes' minimums.
	 *
	 * @param {number} percent Share the source pane is being given.
	 * @return {number} Share it may actually take.
	 */
	function clampSplit(percent) {
		var wanted = Number(percent);

		if (isNaN(wanted)) {
			return DEFAULT_SPLIT;
		}

		return Math.min(Math.max(wanted, MIN_SPLIT), 100 - MIN_SPLIT);
	}

	/**
	 * Where a pointer sits in a row, as the share to the left of it.
	 *
	 * The row is passed as measurements rather than as an element, which is
	 * what keeps the arithmetic here and out of the drag.
	 *
	 * @param {number} x Pointer position, in viewport coordinates.
	 * @param {number} left Left edge of the row, in the same coordinates.
	 * @param {number} width Width of the row, in pixels.
	 * @return {number} Share of the row to the left of the pointer, bounded.
	 */
	function splitAt(x, left, width) {
		var span = Number(width);

		// A hidden or not yet laid out row measures nothing, and a share of
		// nothing is not a number. Even panes is the honest answer.
		if (isNaN(span) || span <= 0) {
			return DEFAULT_SPLIT;
		}

		return clampSplit(((Number(x) - Number(left)) / span) * 100);
	}

	return {
		MIN_HEIGHT: MIN_HEIGHT,
		STORAGE_KEY: STORAGE_KEY,
		DEFAULT_SPLIT: DEFAULT_SPLIT,
		MIN_SPLIT: MIN_SPLIT,
		clamp: clamp,
		readHeight: readHeight,
		writeHeight: writeHeight,
		clampSplit: clampSplit,
		splitAt: splitAt
	};
});
