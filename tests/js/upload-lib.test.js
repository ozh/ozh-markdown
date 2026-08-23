const test = require('node:test');
const assert = require('node:assert');
const lib = require('../../assets/js/lib/ozhmd-upload-lib.js');

test('buildPlaceholder matches the PHP helper byte for byte', () => {
	assert.strictEqual(lib.buildPlaceholder('cat.png'), '![Uploading cat.png...]()');
});

test('buildMarkdownImage builds a plain image', () => {
	assert.strictEqual(
		lib.buildMarkdownImage('cat', 'https://example.com/cat.png'),
		'![cat](https://example.com/cat.png)'
	);
});

test('buildMarkdownImage escapes brackets in the alt text', () => {
	assert.strictEqual(
		lib.buildMarkdownImage('a]b', 'https://example.com/x.png'),
		'![a\\]b](https://example.com/x.png)'
	);
});

test('buildMarkdownImage encodes parentheses and spaces in the url', () => {
	assert.strictEqual(
		lib.buildMarkdownImage('x', 'https://example.com/a (1).png'),
		'![x](https://example.com/a%20%281%29.png)'
	);
});

test('buildMarkdownImage escapes backslashes in the alt text', () => {
	assert.strictEqual(
		lib.buildMarkdownImage('a\\b', 'https://example.com/x.png'),
		'![a\\\\b](https://example.com/x.png)'
	);
});

test('buildMarkdownImage neutralises a backslash bracket payload', () => {
	// Escaping only the brackets would turn the existing "\]" into "\\]",
	// which CommonMark reads as an escaped backslash plus a live bracket,
	// letting the file name supply its own image URL.
	assert.strictEqual(
		lib.buildMarkdownImage('x\\](https://evil.example/p)[y', 'https://example.com/cat.png'),
		'![x\\\\\\](https://evil.example/p)\\[y](https://example.com/cat.png)'
	);
});

test('replacePlaceholder replaces the first occurrence', () => {
	assert.strictEqual(lib.replacePlaceholder('a X b X c', 'X', 'Y'), 'a Y b X c');
});

test('replacePlaceholder leaves the text alone when the needle is absent', () => {
	assert.strictEqual(lib.replacePlaceholder('a b c', 'X', 'Y'), 'a b c');
});

test('replacePlaceholder treats regex metacharacters literally', () => {
	// A file named "a+b(1).png" produces a placeholder full of regex
	// metacharacters. Treating it as a pattern would corrupt the document.
	const placeholder = lib.buildPlaceholder('a+b(1).png');
	const text = 'before ' + placeholder + ' after';

	assert.strictEqual(
		lib.replacePlaceholder(text, placeholder, '![a+b(1)](u)'),
		'before ![a+b(1)](u) after'
	);
});

test('replacePlaceholder does not interpret $& in the replacement', () => {
	assert.strictEqual(lib.replacePlaceholder('a X b', 'X', '$&'), 'a $& b');
});

test('isImageFile accepts image types', () => {
	assert.strictEqual(lib.isImageFile('image/png'), true);
	assert.strictEqual(lib.isImageFile('IMAGE/PNG'), true);
});

test('isImageFile rejects non image types', () => {
	assert.strictEqual(lib.isImageFile('text/plain'), false);
	assert.strictEqual(lib.isImageFile('application/image/png'), false);
});

test('isImageFile rejects missing or non string types', () => {
	assert.strictEqual(lib.isImageFile(undefined), false);
	assert.strictEqual(lib.isImageFile(null), false);
	assert.strictEqual(lib.isImageFile(''), false);
});
