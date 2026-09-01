/**
 * Tests for the scroll sync's pure geometry.
 *
 * Two things are being checked. tokenLines() has to agree with what marked
 * actually emits, so it is fed real lexer output rather than hand written
 * tokens: the line it reports for a block is the line that block starts on in
 * the source. mapScroll() is then pure arithmetic over an anchor table, and is
 * checked against tables built by hand, including the ends of the range where
 * there is no pair left to interpolate between.
 */

const test = require('node:test');
const assert = require('node:assert');
const path = require('node:path');

const lib = require('../../assets/js/lib/ozhmd-scroll-lib.js');
const { lexer } = require(path.join(__dirname, '..', '..', 'assets', 'js', 'vendor', 'marked.min.js'));

test('tokenLines reports the start line of each block', () => {
	const markdown = [
		'# Title',      // 0
		'',
		'Para one.',    // 2
		'',
		'```js',        // 4
		'let a = 1;',
		'```',
		'',
		'- a',          // 8
		'- b',
		'',
		'> quote',      // 11
		'',
		'Last.'         // 13
	].join('\n');

	assert.deepStrictEqual(lib.tokenLines(lexer(markdown)), [0, 2, 4, 8, 11, 13]);
});

test('tokenLines skips the space tokens, which render to nothing', () => {
	const tokens = lexer('a\n\nb');

	assert.ok(tokens.some((token) => 'space' === token.type));
	assert.deepStrictEqual(lib.tokenLines(tokens), [0, 2]);
});

test('tokenLines counts a table and an hr like any other block', () => {
	const markdown = [
		'| a | b |',  // 0
		'|---|---|',
		'| 1 | 2 |',
		'',
		'---',        // 4
		'',
		'End.'        // 6
	].join('\n');

	assert.deepStrictEqual(lib.tokenLines(lexer(markdown)), [0, 4, 6]);
});

test('tokenLines handles CRLF sources without double counting', () => {
	assert.deepStrictEqual(lib.tokenLines(lexer('a\r\n\r\nb')), [0, 2]);
});

test('tokenLines returns one entry per block, so it can be counted against the DOM', () => {
	const markdown = '# H\n\ntext\n\n- x\n';

	assert.strictEqual(lib.tokenLines(lexer(markdown)).length, 3);
});

test('tokenLines is empty for empty input', () => {
	assert.deepStrictEqual(lib.tokenLines(lexer('')), []);
});

// source is the left pane, preview the right. The gap between 100 and 400 on
// the preview side is a block that renders far taller than its source, an
// image being the obvious case.
const anchors = [
	{ source: 0, preview: 0 },
	{ source: 50, preview: 100 },
	{ source: 100, preview: 400 },
	{ source: 200, preview: 500 }
];

test('mapScroll returns the counterpart of an exact anchor hit', () => {
	assert.strictEqual(lib.mapScroll(anchors, 'source', 50), 100);
	assert.strictEqual(lib.mapScroll(anchors, 'source', 100), 400);
});

test('mapScroll interpolates between two anchors', () => {
	// Halfway through a source block that is 300px tall in the preview.
	assert.strictEqual(lib.mapScroll(anchors, 'source', 75), 250);
});

test('mapScroll maps the preview back to the source', () => {
	assert.strictEqual(lib.mapScroll(anchors, 'preview', 100), 50);
	assert.strictEqual(lib.mapScroll(anchors, 'preview', 250), 75);
});

test('mapScroll round trips a position through both directions', () => {
	const there = lib.mapScroll(anchors, 'source', 160);

	assert.strictEqual(lib.mapScroll(anchors, 'preview', there), 160);
});

test('mapScroll clamps below the first anchor and above the last', () => {
	assert.strictEqual(lib.mapScroll(anchors, 'source', -40), 0);
	assert.strictEqual(lib.mapScroll(anchors, 'source', 9999), 500);
});

test('mapScroll survives a table too short to interpolate in', () => {
	assert.strictEqual(lib.mapScroll([], 'source', 42), 42);
	assert.strictEqual(lib.mapScroll([{ source: 0, preview: 0 }], 'source', 42), 42);
});

test('mapScroll does not divide by zero when two anchors share a position', () => {
	const flat = [
		{ source: 0, preview: 0 },
		{ source: 10, preview: 30 },
		{ source: 10, preview: 80 },
		{ source: 20, preview: 100 }
	];

	const out = lib.mapScroll(flat, 'source', 10);

	assert.ok(Number.isFinite(out), 'expected a finite position, got ' + out);
	assert.ok(out >= 30 && out <= 80, 'expected a position within the collapsed span, got ' + out);
});

test('proportional is the shape used when the anchor table is discarded', () => {
	// Two endpoint anchors is exactly what the fallback builds, and it has to
	// behave as a plain percentage map.
	const ends = [
		{ source: 0, preview: 0 },
		{ source: 1000, preview: 2000 }
	];

	assert.strictEqual(lib.mapScroll(ends, 'source', 250), 500);
});
