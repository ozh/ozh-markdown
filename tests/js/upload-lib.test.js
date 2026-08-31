/**
 * Test suite for pure upload helpers.
 *
 * Covers buildPlaceholder(), buildMarkdownImage(), replacePlaceholder(), and
 * isImageFile(). Includes security-relevant cases: regex metacharacters in the
 * needle, a literal $& in the replacement, and the backslash-bracket payload.
 */
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

test('textFileLanguage maps an extension to a bundled Prism language', () => {
	assert.strictEqual(lib.textFileLanguage('wp-config.php'), 'php');
	assert.strictEqual(lib.textFileLanguage('app.mjs'), 'javascript');
	assert.strictEqual(lib.textFileLanguage('index.html'), 'markup');
	assert.strictEqual(lib.textFileLanguage('feed.xml'), 'markup');
	assert.strictEqual(lib.textFileLanguage('deploy.sh'), 'bash');
	assert.strictEqual(lib.textFileLanguage('Module.pm'), 'perl');
});

test('textFileLanguage returns an empty string for a language less text file', () => {
	// Empty string means "recognised, open a bare fence". It is a successful
	// match and must not be confused with the null of an unknown extension.
	assert.strictEqual(lib.textFileLanguage('notes.txt'), '');
	assert.strictEqual(lib.textFileLanguage('README.md'), '');
	assert.strictEqual(lib.textFileLanguage('error.log'), '');
});

test('textFileLanguage returns null for an unknown extension', () => {
	assert.strictEqual(lib.textFileLanguage('archive.zip'), null);
	// The browser types a dropped .svg as image/svg+xml, so it never reaches
	// this map: it goes down the upload path like any other image.
	assert.strictEqual(lib.textFileLanguage('logo.svg'), null);
	assert.strictEqual(lib.textFileLanguage('cat.png'), null);
	assert.strictEqual(lib.textFileLanguage('paper.pdf'), null);
});

test('textFileLanguage ignores the case of the extension', () => {
	assert.strictEqual(lib.textFileLanguage('INDEX.PHP'), 'php');
	assert.strictEqual(lib.textFileLanguage('Notes.TXT'), '');
});

test('textFileLanguage reads the extension after the last dot', () => {
	assert.strictEqual(lib.textFileLanguage('jquery.min.js'), 'javascript');
	assert.strictEqual(lib.textFileLanguage('report.2026.csv'), '');
});

test('textFileLanguage matches a leading dot name as a whole', () => {
	// ".htaccess" has no extension in the usual sense: everything after the
	// last dot is the whole name. Splitting naively yields "htaccess" here,
	// but would also yield "gitignore" for ".gitignore", so the whole name is
	// what gets looked up.
	assert.strictEqual(lib.textFileLanguage('.htaccess'), 'apacheconf');
	assert.strictEqual(lib.textFileLanguage('.unknownrc'), null);
});

test('textFileLanguage returns null for a name with no extension', () => {
	assert.strictEqual(lib.textFileLanguage('Makefile'), null);
	assert.strictEqual(lib.textFileLanguage(''), null);
});

test('textFileLanguage returns null for a missing or non string name', () => {
	assert.strictEqual(lib.textFileLanguage(undefined), null);
	assert.strictEqual(lib.textFileLanguage(null), null);
});

test('buildCodeFence wraps content with a language hint', () => {
	assert.strictEqual(lib.buildCodeFence('echo 1;', 'php'), '```php\necho 1;\n```');
});

test('buildCodeFence opens a bare fence with no language', () => {
	assert.strictEqual(lib.buildCodeFence('plain', ''), '```\nplain\n```');
});

test('buildCodeFence lengthens the delimiter past an inner fence', () => {
	// A dropped Markdown file containing its own fence would otherwise close
	// the block early and spill the rest of the file into the post.
	assert.strictEqual(
		lib.buildCodeFence('```\ninner\n```', ''),
		'````\n```\ninner\n```\n````'
	);
});

test('buildCodeFence counts the longest backtick run', () => {
	assert.strictEqual(
		lib.buildCodeFence('````\ninner\n````', ''),
		'`````\n````\ninner\n````\n`````'
	);
});

test('buildCodeFence ignores backtick runs that are not at line start', () => {
	assert.strictEqual(lib.buildCodeFence('a ``` b', ''), '```\na ``` b\n```');
});

test('buildCodeFence normalises CRLF and lone CR to LF', () => {
	assert.strictEqual(lib.buildCodeFence('a\r\nb\rc', ''), '```\na\nb\nc\n```');
});

test('buildCodeFence strips a leading byte order mark', () => {
	assert.strictEqual(lib.buildCodeFence('﻿a', ''), '```\na\n```');
});

test('buildCodeFence trims trailing blank lines', () => {
	assert.strictEqual(lib.buildCodeFence('a\n\n  \n', ''), '```\na\n```');
});

test('buildCodeFence keeps leading indentation', () => {
	assert.strictEqual(lib.buildCodeFence('\tif (x) {\n\t\ty();\n\t}\n', 'php'), '```php\n\tif (x) {\n\t\ty();\n\t}\n```');
});

test('buildCodeFence handles empty content', () => {
	assert.strictEqual(lib.buildCodeFence('', ''), '```\n\n```');
});


test('textFileLanguage looks past a meta suffix', () => {
	// The trailing extension carries no information about the contents, so
	// the one in front of it decides.
	assert.strictEqual(lib.textFileLanguage('phpunit.xml.dist'), 'markup');
	assert.strictEqual(lib.textFileLanguage('wp-config.php.bak'), 'php');
	assert.strictEqual(lib.textFileLanguage('settings.ini.sample'), 'ini');
	assert.strictEqual(lib.textFileLanguage('style.css.orig'), 'css');
});

test('textFileLanguage strips several meta suffixes in a row', () => {
	assert.strictEqual(lib.textFileLanguage('wp-config.php.dist.bak'), 'php');
});

test('textFileLanguage returns null when only a meta suffix is left', () => {
	// "notes.dist" reduces to "notes", which has no extension at all, so the
	// contents have to decide instead.
	assert.strictEqual(lib.textFileLanguage('notes.dist'), null);
	assert.strictEqual(lib.textFileLanguage('updatewp'), null);
});

test('sniffTextLanguage reads a shebang', () => {
	assert.strictEqual(lib.sniffTextLanguage('#!/bin/bash\ncd /var/www\n'), 'bash');
	assert.strictEqual(lib.sniffTextLanguage('#!/bin/sh\n'), 'bash');
	assert.strictEqual(lib.sniffTextLanguage('#!/usr/bin/perl\n'), 'perl');
});

test('sniffTextLanguage reads a shebang through env', () => {
	assert.strictEqual(lib.sniffTextLanguage('#!/usr/bin/env python3\n'), 'python');
	assert.strictEqual(lib.sniffTextLanguage('#!/usr/bin/env php\n<?php\n'), 'php');
	assert.strictEqual(lib.sniffTextLanguage('#!/usr/bin/env node\n'), 'javascript');
});

test('sniffTextLanguage skips env options and assignments', () => {
	assert.strictEqual(lib.sniffTextLanguage('#!/usr/bin/env -S FOO=1 python\n'), 'python');
});

test('sniffTextLanguage falls back to a bare fence for an unknown interpreter', () => {
	assert.strictEqual(lib.sniffTextLanguage('#!/usr/bin/env ruby\n'), '');
});

test('sniffTextLanguage recognises a leading php tag', () => {
	assert.strictEqual(lib.sniffTextLanguage('<?php\n\nnamespace X;\n'), 'php');
});

test('sniffTextLanguage accepts plain text with a bare fence', () => {
	assert.strictEqual(lib.sniffTextLanguage('just some notes\nwith lines\n'), '');
	assert.strictEqual(lib.sniffTextLanguage(''), '');
});

test('sniffTextLanguage refuses content holding a null byte', () => {
	assert.strictEqual(lib.sniffTextLanguage('PK\u0003\u0004\u0000stuff'), null);
});

test('sniffTextLanguage refuses content dense in undecodable bytes', () => {
	// What file.text() hands back for a binary file: run after run of
	// replacement characters, because the bytes are not valid UTF-8.
	assert.strictEqual(lib.sniffTextLanguage('\uFFFD\uFFFD\uFFFDPNG\uFFFD\uFFFD\uFFFD\uFFFD'), null);
});

test('sniffTextLanguage refuses content dense in control characters', () => {
	assert.strictEqual(lib.sniffTextLanguage('abcde'), null);
});

test('sniffTextLanguage tolerates a truncated multibyte character', () => {
	// Sniffing reads a slice, so the last character can be cut in half and
	// decode to a replacement character. One of those in a screenful of text
	// is not a binary file.
	assert.strictEqual(lib.sniffTextLanguage('a plain sentence of prose\uFFFD'), '');
});

test('sniffTextLanguage keeps tabs and newlines innocent', () => {
	assert.strictEqual(lib.sniffTextLanguage('a\tb\r\nc\n\fd'), '');
});

test('sniffTextLanguage refuses a missing or non string sample', () => {
	assert.strictEqual(lib.sniffTextLanguage(undefined), null);
	assert.strictEqual(lib.sniffTextLanguage(null), null);
});

test('resolveMaxTextBytes accepts the configured limit', () => {
	assert.strictEqual(lib.resolveMaxTextBytes(50000), 50000);
});

test('resolveMaxTextBytes accepts the stringified limit wp_localize_script sends', () => {
	// WordPress casts every scalar in the payload to a string, so the limit
	// arrives as "102400" rather than as a number.
	assert.strictEqual(lib.resolveMaxTextBytes('102400'), 102400);
});

test('resolveMaxTextBytes falls back rather than failing open', () => {
	// A comparison against any of these is false for every file size, which
	// would silently remove the cap.
	assert.strictEqual(lib.resolveMaxTextBytes(undefined), lib.DEFAULT_MAX_TEXT_BYTES);
	assert.strictEqual(lib.resolveMaxTextBytes(null), lib.DEFAULT_MAX_TEXT_BYTES);
	assert.strictEqual(lib.resolveMaxTextBytes(''), lib.DEFAULT_MAX_TEXT_BYTES);
	assert.strictEqual(lib.resolveMaxTextBytes('nonsense'), lib.DEFAULT_MAX_TEXT_BYTES);
	assert.strictEqual(lib.resolveMaxTextBytes(0), lib.DEFAULT_MAX_TEXT_BYTES);
	assert.strictEqual(lib.resolveMaxTextBytes(-1), lib.DEFAULT_MAX_TEXT_BYTES);
	assert.strictEqual(lib.resolveMaxTextBytes(Infinity), lib.DEFAULT_MAX_TEXT_BYTES);
});
