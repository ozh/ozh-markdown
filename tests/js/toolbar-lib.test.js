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
const l = lib.lineTransforms;

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

test('heading turns a plain line into an h1', () => {
	const out = l.heading('Title');

	assert.strictEqual(out.text, '# Title');
	assert.strictEqual(out.select, null);
	assert.strictEqual(out.shift, 2);
});

test('heading deepens a line that is already a heading', () => {
	assert.strictEqual(l.heading('# Title').text, '## Title');
	assert.strictEqual(l.heading('## Title').text, '### Title');
	assert.strictEqual(l.heading('##### Title').text, '###### Title');
});

test('heading drops the heading past the deepest level', () => {
	const out = l.heading('###### Title');

	assert.strictEqual(out.text, 'Title');
	assert.strictEqual(out.shift, -7);
});

test('heading ignores hashes that are not a heading', () => {
	assert.strictEqual(l.heading('#nothashtag').text, '# #nothashtag');
	assert.strictEqual(l.heading('####### Seven').text, '# ####### Seven');
});

test('heading follows the first line across a block', () => {
	assert.strictEqual(l.heading('## one\ntwo').text, '### one\n### two');
});

test('heading on an empty line selects a placeholder', () => {
	const out = l.heading('');

	assert.strictEqual(out.text, '# Heading');
	assert.strictEqual(out.text.slice(out.select[0], out.select[1]), 'Heading');
});

test('heading keeps deepening the placeholder line', () => {
	assert.strictEqual(l.heading(l.heading('').text).text, '## Heading');
});

test('heading handles a heading with no text after it', () => {
	assert.strictEqual(l.heading('#').text, '## ');
	assert.strictEqual(l.heading('## ').text, '### ');
});

test('strikethrough wraps a selection in double tildes', () => {
	assert.strictEqual(t.strikethrough('word').text, '~~word~~');
	assert.strictEqual(t.strikethrough('word').select, null);
});

test('strikethrough emits a selected placeholder when nothing is selected', () => {
	const out = t.strikethrough('');

	assert.strictEqual(out.text, '~~strikethrough text~~');
	assert.strictEqual(out.text.slice(out.select[0], out.select[1]), 'strikethrough text');
});

test('a rule on an empty line is the rule alone', () => {
	const out = l.hr('');

	assert.strictEqual(out.text, '***');
	assert.deepStrictEqual(out.select, [3, 3]);
});

test('a rule below text keeps the text and a blank line above itself', () => {
	const out = l.hr('foo');

	assert.strictEqual(out.text, 'foo\n\n***');
});

test('a rule leaves the cursor below itself', () => {
	const out = l.hr('foo');

	assert.deepStrictEqual(out.select, [out.text.length, out.text.length]);
});

test('quote prefixes every selected line', () => {
	assert.strictEqual(t.quote('one\ntwo').text, '> one\n> two');
});

test('bulleted list prefixes every selected line', () => {
	assert.strictEqual(t.ul('one\ntwo').text, '* one\n* two');
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

test('image with no selection offers a full template', () => {
	const out = t.image('');

	assert.strictEqual(out.text, '![alt](https:// "title")');
	assert.strictEqual(out.text.slice(out.select[0], out.select[1]), 'alt');
});

test('image treats a selection as the alt text', () => {
	const out = t.image('A cat');

	assert.strictEqual(out.text, '![A cat](https:// "")');
});

test('image with a selection leaves the cursor after the scheme', () => {
	const out = t.image('A cat');

	assert.deepStrictEqual(out.select, [out.text.indexOf('https://') + 8, out.text.indexOf('https://') + 8]);
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

test('indent pushes a list item one level in', () => {
	const out = l.indent('* two');

	assert.strictEqual(out.text, '   * two');
	assert.strictEqual(out.shift, 3);
});

test('indent moves every selected item', () => {
	assert.strictEqual(l.indent('* one\n1. two\n- [ ] three').text, '   * one\n   1. two\n   - [ ] three');
});

test('indent stacks up on an item that is already nested', () => {
	assert.strictEqual(l.indent('   * two').text, '      * two');
});

test('indent leaves anything that is not a list item alone', () => {
	assert.strictEqual(l.indent('plain text').text, 'plain text');
	assert.strictEqual(l.indent('***').text, '***');
	assert.strictEqual(l.indent('plain text').shift, 0);
});

test('indent keeps the lines it may not touch inside a selection', () => {
	assert.strictEqual(l.indent('* one\n\nplain\n* two').text, '   * one\n\nplain\n   * two');
});

test('outdent pulls a list item one level back', () => {
	const out = l.outdent('   * two');

	assert.strictEqual(out.text, '* two');
	assert.strictEqual(out.shift, -3);
});

test('outdent removes a single level and no more', () => {
	assert.strictEqual(l.outdent('      * three').text, '   * three');
});

test('outdent removes a tab as one level', () => {
	assert.strictEqual(l.outdent('\t* two').text, '* two');
});

test('outdent takes what is there when it is less than a level', () => {
	const out = l.outdent('  * two');

	assert.strictEqual(out.text, '* two');
	assert.strictEqual(out.shift, -2);
});

test('outdent on an item already at the margin does nothing', () => {
	const out = l.outdent('* two');

	assert.strictEqual(out.text, '* two');
	assert.strictEqual(out.shift, 0);
});

test('outdent leaves anything that is not a list item alone', () => {
	assert.strictEqual(l.outdent('   plain text').text, '   plain text');
});

test('a bullet item continues with the same marker', () => {
	for (const marker of ['-', '*', '+']) {
		assert.strictEqual(lib.listContinuation(marker + ' foo').prefix, marker + ' ');
	}
});

test('a nested bullet item keeps its indentation', () => {
	assert.strictEqual(lib.listContinuation('  * nested').prefix, '  * ');
	assert.strictEqual(lib.listContinuation('\t\t- deep').prefix, '\t\t- ');
});

test('a numbered item continues with the next number', () => {
	assert.strictEqual(lib.listContinuation('1. foo').prefix, '2. ');
	assert.strictEqual(lib.listContinuation('9. foo').prefix, '10. ');
	assert.strictEqual(lib.listContinuation('   12. foo').prefix, '   13. ');
});

test('a numbered item keeps the delimiter it was written with', () => {
	assert.strictEqual(lib.listContinuation('3) foo').prefix, '4) ');
});

test('a task item continues with an unticked box', () => {
	assert.strictEqual(lib.listContinuation('- [ ] todo').prefix, '- [ ] ');
	assert.strictEqual(lib.listContinuation('- [x] done').prefix, '- [ ] ');
});

test('an item with nothing in it ends the list instead', () => {
	assert.strictEqual(lib.listContinuation('- ').empty, true);
	assert.strictEqual(lib.listContinuation('1. ').empty, true);
	assert.strictEqual(lib.listContinuation('  * ').empty, true);
	assert.strictEqual(lib.listContinuation('- [ ] ').empty, true);
});

test('an item with text in it is not empty', () => {
	assert.strictEqual(lib.listContinuation('- foo').empty, false);
});

test('a line that is not a list item is left alone', () => {
	for (const line of ['', 'plain text', '# Heading', '> quote', '*not a list', '1.no space']) {
		assert.strictEqual(lib.listContinuation(line), null, line);
	}
});

test('a horizontal rule is not mistaken for a bullet', () => {
	assert.strictEqual(lib.listContinuation('***'), null);
	assert.strictEqual(lib.listContinuation('---'), null);
});

test('the marker length covers everything before the item text', () => {
	assert.strictEqual(lib.listContinuation('- foo').markerLength, 2);
	assert.strictEqual(lib.listContinuation('  * nested').markerLength, 4);
	assert.strictEqual(lib.listContinuation('- [ ] todo').markerLength, 6);
});

test('the view cycles from both panes to source, preview and back', () => {
	assert.strictEqual(lib.nextView('both'), 'source');
	assert.strictEqual(lib.nextView('source'), 'preview');
	assert.strictEqual(lib.nextView('preview'), 'both');
});

test('an unknown view is treated as the default one', () => {
	assert.strictEqual(lib.nextView(undefined), 'source');
	assert.strictEqual(lib.nextView('sideways'), 'source');
});

test('every toolbar action has a transform', () => {
	for (const action of ['heading', 'bold', 'italic', 'strikethrough', 'quote', 'hr', 'ol', 'ul', 'indent', 'outdent', 'task', 'code', 'link', 'image', 'table']) {
		assert.strictEqual(
			typeof (t[action] || l[action]),
			'function',
			'missing transform: ' + action
		);
	}
});
