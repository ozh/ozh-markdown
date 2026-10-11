/**
 * Tests for what a render of the preview pane leaves behind.
 *
 * Two things: the HTML the pane ends up holding, and the announcement the
 * editor makes once it holds it.
 *
 * The HTML is a regression. marked closes every fenced block with
 * "\n</code></pre>". Inside a <pre> that newline is content, not formatting, so
 * the block rendered with a trailing empty line under its last line of code.
 * Prism's line numbers plugin counts lines with /\n(?!$)/, so it numbered
 * nothing there and the gap read as a stray blank row.
 *
 * The announcement is the seam the stats line hangs off. It is checked here
 * rather than in stats.js, because what matters is that the editor fires it at
 * the end of a render: a listener that is never called is the failure mode, and
 * only this side can prove it is.
 *
 * renderPreview lives in an IIFE, so the test boots editor.js against a stub
 * DOM whose preview element parses the HTML it is handed into code nodes.
 */

const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const ROOT = path.join(__dirname, '..', '..');
const EDITOR = path.join(ROOT, 'assets', 'js', 'editor.js');
const MARKED = path.join(ROOT, 'assets', 'js', 'vendor', 'marked.min.js');

/**
 * Boot editor.js over the given Markdown and report what the render produced.
 *
 * @param {string} markdown Source the fake CodeMirror instance holds.
 * @return {object} The code blocks of the rendered HTML, and the event types
 *                  dispatched on the editor container.
 */
function render(markdown) {
	const marked = require(MARKED);
	const blocks = [];

	// Only what renderPreview() touches: assigning innerHTML fills the list
	// that querySelectorAll() then returns, one entry per fenced block. The
	// selector is honoured rather than ignored, because editor.js also asks
	// for the images it has to remeasure after, and must not be handed these.
	const preview = {
		querySelectorAll: (selector) => ('pre > code' === selector ? blocks : []),

		set innerHTML(html) {
			const pattern = /<pre><code[^>]*>([\s\S]*?)<\/code><\/pre>/g;
			let match;

			blocks.length = 0;

			while (null !== (match = pattern.exec(html))) {
				blocks.push({
					textContent: match[1],
					parentNode: { classList: { add: () => {} } }
				});
			}
		}
	};

	const element = () => ({
		classList: { add: () => {}, contains: () => false },
		querySelectorAll: () => [],
		addEventListener: () => {},
		dispatchEvent: () => true,
		getAttribute: () => null,
		innerHTML: ''
	});

	// The one element editor.js announces on, kept rather than made fresh on
	// every query, so what was dispatched on it can be read back.
	const events = [];
	const container = Object.assign(element(), {
		dispatchEvent: (event) => {
			events.push(event.type);

			return true;
		}
	});

	const window = {
		marked: marked.marked || marked,
		CodeMirror: {
			fromTextArea: () => ({ getValue: () => markdown, on: () => {}, save: () => {} })
		},
		setTimeout: () => 0,
		clearTimeout: () => {},

		// Enough of the constructor for editor.js to name an event with it.
		CustomEvent: function (type) {
			this.type = type;
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

	return { blocks, events };
}

test('the editor announces a render, which is what the stats line counts on', () => {
	// Booting renders once. Without the announcement nothing downstream of the
	// preview ever runs, and the stats line sits empty forever.
	const { events } = render('# Title\n\nSome text.\n');

	assert.deepStrictEqual(events, ['ozhmd:preview']);
});

test('a code block does not end on an empty line', () => {
	const { blocks } = render('```js\ndsqds();\nmeh();\n```\n');

	assert.strictEqual(blocks.length, 1, 'one fenced block was rendered');
	assert.strictEqual(blocks[0].textContent, 'dsqds();\nmeh();');
});

test('a code block keeps the empty lines its author wrote inside it', () => {
	const { blocks } = render('```js\ndsqds();\n\n\nmeh();\n```\n');

	assert.strictEqual(blocks[0].textContent, 'dsqds();\n\n\nmeh();');
});
