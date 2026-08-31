/**
 * Regression tests for the CodeMirror options the editor boots with.
 *
 * CodeMirror 5 defaults to inputStyle "textarea" on desktop: what you see is a
 * stack of plain divs and the keystrokes go into a 1px hidden textarea. No
 * browser spellchecks a non editable div, so Firefox underlined nothing and
 * offered no "Spelling" entry in the context menu. Only "contenteditable" plus
 * spellcheck makes the visible text a real editable region.
 *
 * The options live in an IIFE, so the test runs editor.js against a stub DOM
 * and captures what it hands to CodeMirror.fromTextArea().
 */

const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const EDITOR = path.join(__dirname, '..', '..', 'assets', 'js', 'editor.js');

/**
 * Run editor.js against a minimal fake DOM and return the captured options.
 *
 * @return {object} The options object passed to CodeMirror.fromTextArea().
 */
function bootEditor() {
	const captured = {};

	const element = () => ({
		classList: { add: () => {} },
		querySelectorAll: () => [],
		innerHTML: ''
	});

	const window = {
		marked: { parse: (text) => text },
		CodeMirror: {
			fromTextArea: (textarea, options) => {
				captured.options = options;

				return { getValue: () => '', on: () => {}, save: () => {} };
			}
		},
		setTimeout: () => 0,
		clearTimeout: () => {}
	};

	const document = {
		readyState: 'complete',
		querySelector: () => element(),
		getElementById: () => element(),
		addEventListener: () => {}
	};

	window.window = window;
	window.document = document;

	vm.runInNewContext(fs.readFileSync(EDITOR, 'utf8'), window, { filename: EDITOR });

	return captured.options;
}

test('the editor makes the visible text the input field', () => {
	assert.strictEqual(bootEditor().inputStyle, 'contenteditable');
});

test('the editor turns spellchecking on', () => {
	assert.strictEqual(bootEditor().spellcheck, true);
});
