/**
 * Regression tests for the vendored Prism bundle.
 *
 * The bundle is assembled by concatenating Prism's core with a fixed set of
 * language components, so it is easy to ship one that loads without error but
 * throws the moment it highlights something. That is exactly what happened:
 * prism-php requires markup-templating, the bundle omitted it, and the
 * resulting TypeError aborted highlighting for every language, not just PHP.
 *
 * These tests load the real committed bundle and highlight a sample of each
 * language, so a missing dependency fails here rather than in the browser.
 */

const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const BUNDLE = path.join(__dirname, '..', '..', 'assets', 'js', 'vendor', 'prism.js');

/**
 * Load the vendored bundle in an isolated context and return its Prism global.
 *
 * Prism looks for `window` and falls back to a bare object, so it loads
 * happily without a DOM. The string based highlight() path still exercises
 * every grammar and every hook, which is where the dependency bug lived.
 *
 * @return {object} The Prism global.
 */
function loadPrism() {
	const sandbox = { console, setTimeout, clearTimeout };

	sandbox.self = sandbox;
	vm.createContext(sandbox);
	vm.runInContext(fs.readFileSync(BUNDLE, 'utf8'), sandbox);

	return sandbox.Prism;
}

const SAMPLES = {
	markup: '<b>x</b>',
	css: 'a{color:red}',
	clike: 'int a = 1;',
	javascript: 'const a = 1;',
	php: '<?php echo 1;',
	bash: 'ls -la',
	sql: 'SELECT 1;',
	json: '{"a":1}',
	yaml: 'a: 1',
	diff: '+added',
	markdown: '# heading',
	ini: '[section]\na=1'
};

test('the bundle exposes Prism and its highlight entry points', () => {
	const Prism = loadPrism();

	assert.strictEqual(typeof Prism, 'object');
	assert.strictEqual(typeof Prism.highlight, 'function');
	assert.strictEqual(typeof Prism.highlightAllUnder, 'function');
});

test('every language named in the spec has a grammar', () => {
	const Prism = loadPrism();

	for (const language of Object.keys(SAMPLES)) {
		assert.ok(Prism.languages[language], 'missing grammar: ' + language);
	}
});

test('markup-templating is bundled, because prism-php requires it', () => {
	const Prism = loadPrism();

	// Without it, highlighting PHP throws on buildPlaceholders and takes every
	// other language down with it.
	assert.ok(Prism.languages['markup-templating']);
});

test('every language highlights without throwing and produces tokens', () => {
	const Prism = loadPrism();

	for (const [language, code] of Object.entries(SAMPLES)) {
		const html = Prism.highlight(code, Prism.languages[language], language);

		assert.match(html, /<span class="token /, 'no tokens produced for ' + language);
	}
});

test('highlighting PHP specifically does not throw', () => {
	const Prism = loadPrism();

	assert.doesNotThrow(function () {
		Prism.highlight('<?php echo 1; ?>', Prism.languages.php, 'php');
	});
});
