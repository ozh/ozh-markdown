/**
 * Tests for the toolbar's pure text transforms.
 *
 * Covers each toolbar action against both an empty selection, where it should
 * emit a placeholder and select it, and a real selection, where it should wrap
 * or prefix what is there. Also covers the conversion of the HTML that the
 * WordPress media modal hands back.
 */

const test = require('node:test');
const assert = require('node:assert');
const lib = require('../../assets/js/lib/ozhmd-toolbar-lib.js');

const t = lib.transforms;

test('bold wraps a selection', () => {
	assert.strictEqual(t.bold('word').text, '**word**');
	assert.strictEqual(t.bold('word').select, null);
});

test('bold emits a selected placeholder when nothing is selected', () => {
	const out = t.bold('');

	assert.strictEqual(out.text, '**bold text**');
	assert.deepStrictEqual(out.select, [2, 11]);
	assert.strictEqual(out.text.slice(out.select[0], out.select[1]), 'bold text');
});

test('italic uses a single underscore', () => {
	assert.strictEqual(t.italic('word').text, '_word_');
});

test('heading prefixes the line', () => {
	assert.strictEqual(t.heading('Title').text, '## Title');
});

test('quote prefixes every selected line', () => {
	assert.strictEqual(t.quote('one\ntwo').text, '> one\n> two');
});

test('bulleted list prefixes every selected line', () => {
	assert.strictEqual(t.ul('one\ntwo').text, '- one\n- two');
});

test('numbered list increments per line', () => {
	assert.strictEqual(t.ol('one\ntwo\nthree').text, '1. one\n2. two\n3. three');
});

test('task list prefixes every selected line', () => {
	assert.strictEqual(t.task('one\ntwo').text, '- [ ] one\n- [ ] two');
});

test('code wraps an inline selection in backticks', () => {
	assert.strictEqual(t.code('echo 1;').text, '`echo 1;`');
});

test('code fences a multi line selection', () => {
	assert.strictEqual(t.code('a\nb').text, '```\na\nb\n```');
});

test('code emits an empty fence with the cursor inside it', () => {
	const out = t.code('');

	assert.strictEqual(out.text, '```\n\n```');
	assert.deepStrictEqual(out.select, [4, 4]);
});

test('link treats a selected URL as the destination', () => {
	const out = t.link('https://example.com/x');

	assert.strictEqual(out.text, '[](https://example.com/x)');
	assert.deepStrictEqual(out.select, [1, 1]);
});

test('link treats selected text as the label', () => {
	const out = t.link('click here');

	assert.strictEqual(out.text, '[click here](url)');
	assert.strictEqual(out.text.slice(out.select[0], out.select[1]), 'url');
});

test('link with no selection selects the empty label', () => {
	const out = t.link('');

	assert.strictEqual(out.text, '[](url)');
	assert.deepStrictEqual(out.select, [1, 1]);
});

test('table keeps a selection as the first column heading', () => {
	assert.match(t.table('Name').text, /^\| Name \| Column \|/);
});

test('table with no selection selects the placeholder heading', () => {
	const out = t.table('');

	assert.strictEqual(out.text.slice(out.select[0], out.select[1]), 'Column');
});

test('media HTML for an image becomes a Markdown image', () => {
	assert.strictEqual(
		lib.htmlToMarkdown('<img src="https://example.com/cat.png" alt="A cat" />'),
		'![A cat](https://example.com/cat.png)'
	);
});

test('media HTML for an image without alt still converts', () => {
	assert.strictEqual(
		lib.htmlToMarkdown('<img src="https://example.com/cat.png" />'),
		'![](https://example.com/cat.png)'
	);
});

test('a linked image prefers the image over the surrounding anchor', () => {
	const html = '<a href="https://example.com/full.png"><img src="https://example.com/thumb.png" alt="Cat" /></a>';

	assert.strictEqual(lib.htmlToMarkdown(html), '![Cat](https://example.com/thumb.png)');
});

test('media HTML for a plain link becomes a Markdown link', () => {
	assert.strictEqual(
		lib.htmlToMarkdown('<a href="https://example.com/doc.pdf">The doc</a>'),
		'[The doc](https://example.com/doc.pdf)'
	);
});

test('unrecognised HTML is passed through unchanged', () => {
	assert.strictEqual(lib.htmlToMarkdown('<video controls></video>'), '<video controls></video>');
});

test('a non string input yields an empty string', () => {
	assert.strictEqual(lib.htmlToMarkdown(undefined), '');
	assert.strictEqual(lib.htmlToMarkdown(null), '');
});

test('every toolbar action has a transform', () => {
	for (const action of ['heading', 'bold', 'italic', 'quote', 'code', 'link', 'ol', 'ul', 'task', 'table']) {
		assert.strictEqual(typeof t[action], 'function', 'missing transform: ' + action);
	}
});
