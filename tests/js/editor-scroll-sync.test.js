/**
 * Tests for the wiring that ties the two editor panes together.
 *
 * ozhmd-scroll-lib.js is where the arithmetic is checked. What is checked here
 * is that editor.js actually connects it to something: that a scroll in either
 * pane reaches the other, that the anchor table is built from real measurements
 * rather than left empty, and that the move one pane makes to follow the other
 * is not then read back as a scroll of its own and echoed. That last one is
 * what makes a naive version of this feature judder.
 *
 * editor.js runs inside an IIFE, so it is booted over a fake DOM whose panes
 * model the one thing that matters here: the source measures a document in
 * lines of equal height, and the preview measures the same document in blocks
 * of a different, unequal height.
 */

const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const ROOT = path.join(__dirname, '..', '..');
const EDITOR = path.join(ROOT, 'assets', 'js', 'editor.js');
const MARKED = path.join(ROOT, 'assets', 'js', 'vendor', 'marked.min.js');
const SCROLL_LIB = path.join(ROOT, 'assets', 'js', 'lib', 'ozhmd-scroll-lib.js');

// Three blocks, starting on source lines 0, 2 and 4.
const MARKDOWN = '# Title\n\nSome text.\n\n- item\n';

const LINE_HEIGHT = 20;   // Source pixels per line.
const BLOCK_HEIGHT = 100; // Preview pixels per block, deliberately unequal.

/**
 * Boot editor.js over a fake DOM and hand back the pieces worth poking.
 *
 * @return {object} The panes, plus the captured event handlers.
 */
function boot() {
	const marked = require(MARKED);
	const scrollLib = require(SCROLL_LIB);

	const frames = [];
	const editorHandlers = {};
	const previewHandlers = {};

	// The source pane: a document of 20 lines at 20px, seen through a 200px
	// viewport, so it can be scrolled by 200px in total.
	const editor = {
		scrolledTo: [],
		cursor: { line: 0, ch: 0 },
		scrollTop: 0,

		getValue: () => MARKDOWN,
		save: () => {},
		on: (name, fn) => {
			editorHandlers[name] = fn;
		},
		getCursor: function () {
			return this.cursor;
		},
		getScrollInfo: function () {
			return { top: this.scrollTop, height: 400, clientHeight: 200 };
		},
		charCoords: function (pos) {
			return { top: pos.line * LINE_HEIGHT };
		},
		scrollTo: function (x, y) {
			this.scrolledTo.push(y);
			this.scrollTop = y;
		}
	};

	const blocks = [];

	const preview = {
		scrollTop: 0,
		scrollHeight: 900,
		clientHeight: 300,
		children: blocks,

		// A real element's rect is viewport relative, so it moves as its
		// scroller moves. Modelling that is what proves editor.js converts back
		// into the pane's own scroll coordinates rather than trusting the rect.
		getBoundingClientRect: () => ({ top: 0 }),
		querySelectorAll: () => [],
		addEventListener: (name, fn) => {
			previewHandlers[name] = fn;
		},

		set innerHTML(html) {
			blocks.length = 0;

			// One entry per top level element of the rendered HTML, stacked at
			// a fixed height so the preview's geometry is known in advance.
			html.trim()
				.split(/\n(?=<(?:h1|h2|h3|p|ul|ol|pre|blockquote|table|hr)\b)/)
				.filter((chunk) => chunk.trim())
				.forEach((chunk, index) => {
					const top = index * BLOCK_HEIGHT;

					blocks.push({
						classList: { add: () => {} },
						getBoundingClientRect: () => ({ top: top - preview.scrollTop })
					});
				});
		}
	};

	// Both toggles live here: the view as classes, the sync as an attribute.
	const attributes = { 'data-ozhmd-sync': 'on' };
	const observers = [];

	/**
	 * Tell every observer the container changed, as the browser would.
	 *
	 * @return {void}
	 */
	function notify() {
		observers.forEach((fn) => fn());
	}

	const container = {
		classList: {
			add: () => {},
			remove: () => {},
			contains: () => false
		},
		getAttribute: (name) => (name in attributes ? attributes[name] : null),
		setAttribute: (name, value) => {
			attributes[name] = value;
			notify();
		},
		removeAttribute: (name) => {
			delete attributes[name];
			notify();
		}
	};

	const element = () => ({
		classList: { add: () => {}, contains: () => false },
		querySelectorAll: () => [],
		addEventListener: () => {},
		innerHTML: ''
	});

	const window = {
		marked: marked,
		ozhmdScrollLib: scrollLib,
		CodeMirror: { fromTextArea: () => editor },

		// Debounces fire at once, so a render is finished by the time the
		// assertions run.
		setTimeout: (fn) => {
			fn();

			return 0;
		},
		clearTimeout: () => {},

		// Held rather than run, so the test decides when the anti echo guard
		// is released and can observe the window where it is still closed.
		requestAnimationFrame: (fn) => {
			frames.push(fn);

			return frames.length;
		},
		addEventListener: () => {},

		// Enough of the real thing for editor.js to notice the toggles. The
		// records are not inspected, so none are built.
		MutationObserver: function (callback) {
			this.observe = () => {
				observers.push(callback);
			};
		}
	};

	const document = {
		readyState: 'complete',
		querySelector: (selector) => {
			if ('[data-ozhmd-preview]' === selector) {
				return preview;
			}

			if ('[data-ozhmd-editor]' === selector) {
				return container;
			}

			return element();
		},
		getElementById: () => element(),
		addEventListener: () => {}
	};

	window.window = window;
	window.document = document;

	vm.runInNewContext(fs.readFileSync(EDITOR, 'utf8'), window, { filename: EDITOR });

	/**
	 * Run every animation frame callback that is waiting.
	 *
	 * @return {void}
	 */
	function flushFrames() {
		frames.splice(0).forEach((fn) => fn());
	}

	return { editor, preview, container, editorHandlers, previewHandlers, flushFrames };
}

test('editor.js subscribes to both panes', () => {
	const { editorHandlers, previewHandlers } = boot();

	assert.strictEqual(typeof editorHandlers.scroll, 'function', 'source pane scroll is listened to');
	assert.strictEqual(typeof previewHandlers.scroll, 'function', 'preview pane scroll is listened to');
	assert.strictEqual(typeof editorHandlers.cursorActivity, 'function', 'cursor moves are listened to');
});

test('the preview renders three blocks, so the anchors have something to use', () => {
	const { preview } = boot();

	assert.strictEqual(preview.children.length, 3);
});

test('scrolling the source moves the preview to the matching block', () => {
	const { editor, preview, editorHandlers } = boot();

	// Source line 2, the second block, which the preview puts at 100.
	editor.scrollTop = 2 * LINE_HEIGHT;
	editorHandlers.scroll();

	assert.strictEqual(preview.scrollTop, BLOCK_HEIGHT);
});

test('scrolling the source between two blocks interpolates', () => {
	const { editor, preview, editorHandlers } = boot();

	// Halfway between source lines 2 and 4, so halfway between preview 100
	// and 200, which a plain percentage map would not have found.
	editor.scrollTop = 3 * LINE_HEIGHT;
	editorHandlers.scroll();

	assert.strictEqual(preview.scrollTop, 150);
});

test('scrolling the preview moves the source back', () => {
	const { editor, preview, previewHandlers } = boot();

	preview.scrollTop = 2 * BLOCK_HEIGHT;
	previewHandlers.scroll();

	assert.deepStrictEqual(editor.scrolledTo, [4 * LINE_HEIGHT]);
});

test('a pane moved to follow the other does not echo the move back', () => {
	const { editor, preview, editorHandlers, previewHandlers } = boot();

	editor.scrollTop = 2 * LINE_HEIGHT;
	editorHandlers.scroll();

	// The browser would now fire the preview's own scroll event. Acting on it
	// would send the source somewhere it never asked to go.
	previewHandlers.scroll();

	assert.deepStrictEqual(editor.scrolledTo, [], 'the source was not pushed back');
});

test('the guard is released once the frame passes, so real scrolls still work', () => {
	const { editor, preview, editorHandlers, previewHandlers, flushFrames } = boot();

	editor.scrollTop = 2 * LINE_HEIGHT;
	editorHandlers.scroll();
	flushFrames();

	// A genuine scroll of the preview, after the follow has settled.
	preview.scrollTop = 2 * BLOCK_HEIGHT;
	previewHandlers.scroll();

	assert.deepStrictEqual(editor.scrolledTo, [4 * LINE_HEIGHT]);
});

test('the preview is left alone while the cursor block is already in view', () => {
	const { editor, preview, editorHandlers } = boot();

	preview.scrollTop = 0;
	editor.cursor = { line: 2, ch: 0 };
	editorHandlers.cursorActivity();

	// Block two sits at 100, inside a 300px tall viewport showing 0 to 300.
	assert.strictEqual(preview.scrollTop, 0, 'no twitch on a visible block');
});

// Past the last block the anchors run from (80, 200) to the two bottoms,
// (200, 600), so a cursor down there maps well below the preview's viewport.
const DEEP_LINE = 8;
const DEEP_TARGET = 200 + ((DEEP_LINE * LINE_HEIGHT - 80) / (200 - 80)) * (600 - 200);

test('the preview follows the cursor once its block is off screen', () => {
	const { editor, preview, editorHandlers } = boot();

	preview.scrollTop = 0;

	// Cursor at the very top of the source viewport, so it should land at the
	// very top of the preview's.
	editor.scrollTop = DEEP_LINE * LINE_HEIGHT;
	editor.cursor = { line: DEEP_LINE, ch: 0 };
	editorHandlers.cursorActivity();

	assert.ok(
		Math.abs(preview.scrollTop - DEEP_TARGET) < 0.01,
		'expected about ' + DEEP_TARGET + ', got ' + preview.scrollTop
	);
});

test('the toggle set to off leaves the panes alone', () => {
	const { editor, preview, container, editorHandlers } = boot();

	container.setAttribute('data-ozhmd-sync', 'off');

	editor.scrollTop = 2 * LINE_HEIGHT;
	editorHandlers.scroll();

	assert.strictEqual(preview.scrollTop, 0, 'the preview stayed put');
});

test('the toggle set to off stops the preview following the cursor too', () => {
	const { editor, preview, container, editorHandlers } = boot();

	container.setAttribute('data-ozhmd-sync', 'off');

	editor.scrollTop = DEEP_LINE * LINE_HEIGHT;
	editor.cursor = { line: DEEP_LINE, ch: 0 };
	editorHandlers.cursorActivity();

	assert.strictEqual(preview.scrollTop, 0);
});

test('the toggle set to off stops the source following the preview', () => {
	const { editor, preview, container, previewHandlers } = boot();

	container.setAttribute('data-ozhmd-sync', 'off');

	preview.scrollTop = 2 * BLOCK_HEIGHT;
	previewHandlers.scroll();

	assert.deepStrictEqual(editor.scrolledTo, []);
});

test('turning the toggle back on resumes syncing', () => {
	const { editor, preview, container, editorHandlers } = boot();

	container.setAttribute('data-ozhmd-sync', 'off');
	editor.scrollTop = 2 * LINE_HEIGHT;
	editorHandlers.scroll();

	container.setAttribute('data-ozhmd-sync', 'on');
	editorHandlers.scroll();

	assert.strictEqual(preview.scrollTop, BLOCK_HEIGHT);
});

test('markup with no toggle at all still syncs', () => {
	const { editor, preview, container, editorHandlers } = boot();

	// getAttribute now returns null, as it would on a page rendered before the
	// toggle existed. Absent has to mean on, not off.
	container.removeAttribute('data-ozhmd-sync');

	editor.scrollTop = 2 * LINE_HEIGHT;
	editorHandlers.scroll();

	assert.strictEqual(preview.scrollTop, BLOCK_HEIGHT);
});

test('re-enabling remeasures, so edits made while it was off are accounted for', () => {
	const { editor, preview, container, editorHandlers, flushFrames } = boot();

	container.setAttribute('data-ozhmd-sync', 'off');

	// The preview grows while nothing is watching it, as it would after an
	// edit. Anchors built before this point now describe the wrong document.
	preview.children.forEach((block, index) => {
		block.getBoundingClientRect = () => ({ top: index * 200 - preview.scrollTop });
	});

	container.setAttribute('data-ozhmd-sync', 'on');
	flushFrames();

	editor.scrollTop = 2 * LINE_HEIGHT;
	editorHandlers.scroll();

	assert.strictEqual(preview.scrollTop, 200, 'the anchors were rebuilt on the way back on');
});

test('the preview keeps the cursor at the same height it sits at in the source', () => {
	const { editor, preview, editorHandlers } = boot();

	preview.scrollTop = 0;

	// Same cursor, but now 60px down the source viewport rather than at its
	// top, so its block should land 60px down the preview's.
	editor.scrollTop = DEEP_LINE * LINE_HEIGHT - 60;
	editor.cursor = { line: DEEP_LINE, ch: 0 };
	editorHandlers.cursorActivity();

	assert.ok(
		Math.abs(preview.scrollTop - (DEEP_TARGET - 60)) < 0.01,
		'expected about ' + (DEEP_TARGET - 60) + ', got ' + preview.scrollTop
	);
});
