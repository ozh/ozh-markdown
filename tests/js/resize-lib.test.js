/**
 * Tests for the resize handle's pure parts.
 *
 * Three things are being checked. clamp() is the single definition of how tall
 * the editor may be, used by the drag, by the keyboard and by the load path, so
 * both of its bounds are pinned here along with the case where the window is
 * shorter than the floor. readHeight() has to survive everything a stored
 * string can be, since a preference is not worth taking an edit screen down
 * for, and has to re-clamp what it reads, because a height saved on a large
 * monitor is read back on whatever screen comes next. And both storage
 * functions have to return rather than throw when localStorage itself throws,
 * which is Safari in private browsing.
 *
 * The storage object is passed in rather than reached for, so the fakes below
 * are the real code path and not a stand in for it.
 */

const test = require('node:test');
const assert = require('node:assert');

const lib = require('../../assets/js/lib/ozhmd-resize-lib.js');

/**
 * A storage object holding one key in memory.
 *
 * @param {object} seed Initial contents.
 * @return {object} Fake with getItem and setItem.
 */
function fakeStorage(seed) {
	const items = Object.assign({}, seed);

	return {
		getItem: (key) => (key in items ? items[key] : null),
		setItem: (key, value) => {
			items[key] = String(value);
		}
	};
}

/**
 * A storage object that throws on every call, as in private browsing.
 *
 * @return {object} Fake with getItem and setItem.
 */
function throwingStorage() {
	return {
		getItem: () => {
			throw new Error('denied');
		},
		setItem: () => {
			throw new Error('denied');
		}
	};
}

test('clamp holds the floor, so the editor cannot be dragged shut', () => {
	assert.strictEqual(lib.clamp(0, 1000), lib.MIN_HEIGHT);
	assert.strictEqual(lib.clamp(50, 1000), lib.MIN_HEIGHT);
	assert.strictEqual(lib.clamp(-400, 1000), lib.MIN_HEIGHT);
});

test('clamp holds the ceiling at the height of the window', () => {
	assert.strictEqual(lib.clamp(5000, 900), 900);
});

test('clamp passes through a height between the two bounds', () => {
	assert.strictEqual(lib.clamp(600, 1000), 600);
});

test('clamp keeps the floor when the window is shorter than it', () => {
	// A ceiling below the floor would leave no legal height at all. The floor
	// wins, and the editor scrolls with the page.
	assert.strictEqual(lib.clamp(300, 150), lib.MIN_HEIGHT);
});

test('clamp answers with the floor for a height that is not a number', () => {
	assert.strictEqual(lib.clamp(NaN, 1000), lib.MIN_HEIGHT);
	assert.strictEqual(lib.clamp(Infinity, 1000), 1000);
	assert.strictEqual(lib.clamp(undefined, 1000), lib.MIN_HEIGHT);
});

test('readHeight returns null when nothing has been stored', () => {
	assert.strictEqual(lib.readHeight(fakeStorage({}), 1000), null);
});

test('readHeight returns null for a value that is not a usable height', () => {
	['', '   ', 'tall', 'NaN', '0', '-200', 'Infinity'].forEach((stored) => {
		const storage = fakeStorage({ [lib.STORAGE_KEY]: stored });

		assert.strictEqual(
			lib.readHeight(storage, 1000),
			null,
			'expected null for a stored value of "' + stored + '"'
		);
	});
});

test('readHeight returns the height that was stored', () => {
	const storage = fakeStorage({ [lib.STORAGE_KEY]: '640' });

	assert.strictEqual(lib.readHeight(storage, 1000), 640);
});

test('readHeight reads a height stored with a unit, as CSS would hold it', () => {
	const storage = fakeStorage({ [lib.STORAGE_KEY]: '640px' });

	assert.strictEqual(lib.readHeight(storage, 1000), 640);
});

test('readHeight re-clamps a height saved on a taller screen', () => {
	const storage = fakeStorage({ [lib.STORAGE_KEY]: '1400' });

	assert.strictEqual(lib.readHeight(storage, 800), 800);
});

test('readHeight returns null rather than throwing when storage is denied', () => {
	assert.strictEqual(lib.readHeight(throwingStorage(), 1000), null);
});

test('readHeight returns null when there is no storage at all', () => {
	assert.strictEqual(lib.readHeight(null, 1000), null);
});

test('writeHeight stores a height readHeight can read back', () => {
	const storage = fakeStorage({});

	lib.writeHeight(storage, 512);

	assert.strictEqual(lib.readHeight(storage, 1000), 512);
});

test('writeHeight returns rather than throwing when storage is denied', () => {
	assert.doesNotThrow(() => lib.writeHeight(throwingStorage(), 512));
	assert.doesNotThrow(() => lib.writeHeight(null, 512));
});

test('splitAt reports where the pointer is, as a share of the row', () => {
	// A row 1000px wide starting at x=100, grabbed at its midpoint.
	assert.strictEqual(lib.splitAt(600, 100, 1000), 50);
	assert.strictEqual(lib.splitAt(350, 100, 1000), 25);
});

test('splitAt leaves both panes wide enough to be worth having', () => {
	assert.strictEqual(lib.splitAt(100, 100, 1000), lib.MIN_SPLIT);
	assert.strictEqual(lib.splitAt(1100, 100, 1000), 100 - lib.MIN_SPLIT);
});

test('splitAt holds the same bounds when the pointer leaves the row entirely', () => {
	assert.strictEqual(lib.splitAt(-5000, 100, 1000), lib.MIN_SPLIT);
	assert.strictEqual(lib.splitAt(5000, 100, 1000), 100 - lib.MIN_SPLIT);
});

test('splitAt falls back to even panes when there is no row to divide', () => {
	// A hidden or unlaid out container measures zero wide, and a share of
	// nothing is not a number.
	assert.strictEqual(lib.splitAt(600, 100, 0), lib.DEFAULT_SPLIT);
	assert.strictEqual(lib.splitAt(600, 100, NaN), lib.DEFAULT_SPLIT);
});

test('the split defaults to even panes', () => {
	assert.strictEqual(lib.DEFAULT_SPLIT, 50);
});

test('clampSplit is the bound splitAt uses, reusable on its own', () => {
	// The keyboard steps an existing share rather than measuring a pointer.
	assert.strictEqual(lib.clampSplit(50), 50);
	assert.strictEqual(lib.clampSplit(0), lib.MIN_SPLIT);
	assert.strictEqual(lib.clampSplit(100), 100 - lib.MIN_SPLIT);
	assert.strictEqual(lib.clampSplit(NaN), lib.DEFAULT_SPLIT);
});
