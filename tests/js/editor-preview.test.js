/**
 * Regression tests for the HTML the preview pane ends up holding.
 *
 * marked closes every fenced block with "\n</code></pre>". Inside a <pre> that
 * newline is content, not formatting, so the block rendered with a trailing
 * empty line under its last line of code. Prism's line numbers plugin counts
 * lines with /\n(?!$)/, so it numbered nothing there and the gap read as a
 * stray blank row.
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
 * Boot editor.js over the given Markdown and return the preview's code blocks.
 *
 * @param {string} markdown Source the fake CodeMirror instance holds.
 * @return {object[]} One fake node per <pre> > <code> of the rendered HTML.
 */
function renderCodeBlocks(markdown) {
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
		classList: { add: () => {} },
		querySelectorAll: () => [],
		innerHTML: ''
	});

	const window = {
		marked: marked.marked || marked,
		CodeMirror: {
			fromTextArea: () => ({ getValue: () => markdown, on: () => {}, save: () => {} })
		},
		setTimeout: () => 0,
		clearTimeout: () => {}
	};

	const document = {
		readyState: 'complete',
		querySelector: (selector) => ('[data-ozhmd-preview]' === selector ? preview : element()),
		getElementById: () => element(),
		addEventListener: () => {}
	};

	window.window = window;
	window.document = document;

	vm.runInNewContext(fs.readFileSync(EDITOR, 'utf8'), window, { filename: EDITOR });

	return blocks;
}

test('a code block does not end on an empty line', () => {
	const blocks = renderCodeBlocks('```js\ndsqds();\nmeh();\n```\n');

	assert.strictEqual(blocks.length, 1, 'one fenced block was rendered');
	assert.strictEqual(blocks[0].textContent, 'dsqds();\nmeh();');
});

test('a code block keeps the empty lines its author wrote inside it', () => {
	const blocks = renderCodeBlocks('```js\ndsqds();\n\n\nmeh();\n```\n');

	assert.strictEqual(blocks[0].textContent, 'dsqds();\n\n\nmeh();');
});
