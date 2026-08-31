/**
 * Pure helpers for the drag and drop uploader.
 *
 * Loaded as a browser global (window.ozhmdUploadLib) by the editor, and as a
 * CommonJS module by the Node test suite. No DOM access, no network, no state.
 */
(function (root, factory) {
	'use strict';

	var api = factory();

	if (typeof module === 'object' && module.exports) {
		module.exports = api;
	} else {
		root.ozhmdUploadLib = api;
	}
})(typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	/**
	 * Build the in-flight upload placeholder inserted at the cursor.
	 *
	 * Must stay byte-identical to ozhmd_upload_placeholder() in PHP.
	 *
	 * @param {string} filename Original file name.
	 * @return {string} Placeholder Markdown.
	 */
	function buildPlaceholder(filename) {
		return '![Uploading ' + filename + '...]()';
	}

	/**
	 * Build a Markdown image tag.
	 *
	 * Escapes the characters that would otherwise terminate the alt text or
	 * the URL, so a hostile file name cannot break out of the tag.
	 *
	 * The backslash is escaped first, and the order matters. Escaping only
	 * the brackets turns an existing "\]" into "\\]", which CommonMark reads
	 * as an escaped backslash followed by a live bracket, closing the alt
	 * text early and letting the file name supply its own image URL. This
	 * matters more here than in the PHP twin, because the file name reaches
	 * this function straight from the browser, before WordPress has had any
	 * chance to sanitize it.
	 *
	 * @param {string} alt Alt text.
	 * @param {string} url Image URL.
	 * @return {string} Markdown image tag.
	 */
	function buildMarkdownImage(alt, url) {
		var safeAlt = String(alt)
			.replace(/\\/g, '\\\\')
			.replace(/\[/g, '\\[')
			.replace(/\]/g, '\\]');
		var safeUrl = String(url)
			.replace(/\(/g, '%28')
			.replace(/\)/g, '%29')
			.replace(/ /g, '%20');

		return '![' + safeAlt + '](' + safeUrl + ')';
	}

	/**
	 * Replace the first literal occurrence of a needle.
	 *
	 * Never treats the needle as a pattern and never interprets the
	 * replacement, so file names containing regex metacharacters or $&
	 * cannot corrupt the document.
	 *
	 * @param {string} haystack    Text to search.
	 * @param {string} needle      Exact substring to find.
	 * @param {string} replacement Text to insert in its place.
	 * @return {string} Text with the first occurrence replaced, unchanged when absent.
	 */
	function replacePlaceholder(haystack, needle, replacement) {
		var index = haystack.indexOf(needle);

		if (index === -1) {
			return haystack;
		}

		return haystack.slice(0, index) + replacement + haystack.slice(index + needle.length);
	}

	/**
	 * Tell whether a DataTransfer item type is an image.
	 *
	 * @param {*} type MIME type reported by the browser.
	 * @return {boolean} True when the type is an image.
	 */
	function isImageFile(type) {
		return typeof type === 'string' && type.toLowerCase().indexOf('image/') === 0;
	}

	/**
	 * Extensions accepted as text, mapped to their fence info string.
	 *
	 * The languages are the ones the Prism bundle actually ships, listed in
	 * PRISM_LANGUAGES in tools/fetch-vendor.sh, so no fence can name a
	 * language the highlighter does not have. An empty string is a recognised
	 * file with no language, opening a bare fence.
	 *
	 * No svg here even though markup covers it: browsers report image/svg+xml
	 * for a dropped .svg, so it is taken as an image and uploaded, which is
	 * the behaviour a site that allows SVG uploads expects.
	 */
	var textFileLanguages = {
		php: 'php',
		js: 'javascript',
		mjs: 'javascript',
		css: 'css',
		html: 'markup',
		htm: 'markup',
		xml: 'markup',
		sh: 'bash',
		bash: 'bash',
		zsh: 'bash',
		py: 'python',
		json: 'json',
		sql: 'sql',
		pl: 'perl',
		pm: 'perl',
		ini: 'ini',
		conf: 'apacheconf',
		'.htaccess': 'apacheconf',
		txt: '',
		log: '',
		md: '',
		markdown: '',
		csv: ''
	};

	/**
	 * Trailing extensions that describe the file's role, not its contents.
	 *
	 * "phpunit.xml.dist" is XML and "wp-config.php.bak" is PHP, so the
	 * extension in front of these is the one worth looking up.
	 */
	var metaSuffixes = ['dist', 'sample', 'example', 'default', 'template', 'tmpl', 'bak', 'orig', 'old', 'save', 'local', 'in'];

	/**
	 * Interpreters worth recognising in a shebang line.
	 *
	 * Only the ones matching a bundled Prism language: anything else falls
	 * back to a bare fence, which is still better than refusing the file.
	 */
	var shebangLanguages = {
		sh: 'bash',
		bash: 'bash',
		zsh: 'bash',
		ksh: 'bash',
		dash: 'bash',
		python: 'python',
		python2: 'python',
		python3: 'python',
		php: 'php',
		perl: 'perl',
		node: 'javascript'
	};

	/**
	 * How many bytes to read when sniffing a file with no usable extension.
	 */
	var SNIFF_BYTES = 4096;

	/**
	 * Fallback for the largest inlinable text file, in bytes.
	 *
	 * The server sends the real limit in the ozhmdUpload payload; this is only
	 * used when that value is missing or unusable. Without it the comparison
	 * would be made against undefined, which is false for every size, and the
	 * cap would disappear silently: the failure mode is a browser freezing on
	 * a file it should have refused, which is the worst way to find out.
	 *
	 * Must stay in step with Ozhmd_Editor_UI::MAX_TEXT_BYTES in PHP.
	 */
	var DEFAULT_MAX_TEXT_BYTES = 102400;

	/**
	 * Settle on the size limit to enforce.
	 *
	 * wp_localize_script() stringifies everything it sends, so the configured
	 * value arrives as "102400" rather than a number and has to be converted
	 * before it can be trusted.
	 *
	 * @param {*} configured Limit as it arrived from the server.
	 * @return {number} Limit in bytes, always positive.
	 */
	function resolveMaxTextBytes(configured) {
		var limit = Number(configured);

		return isFinite(limit) && limit > 0 ? limit : DEFAULT_MAX_TEXT_BYTES;
	}

	/**
	 * Tell whether an object owns a key, ignoring anything it inherited.
	 *
	 * File names reach these maps unfiltered, and a file called
	 * "x.constructor" must not resolve to Object's own property.
	 *
	 * @param {object} map Object to look in.
	 * @param {string} key Key to look for.
	 * @return {boolean} True when the key is the object's own.
	 */
	function owns(map, key) {
		return Object.prototype.hasOwnProperty.call(map, key);
	}

	/**
	 * Drop trailing extensions that say nothing about the contents.
	 *
	 * Repeats, so "wp-config.php.dist.bak" reduces to "wp-config.php", and
	 * always leaves at least the part before the first dot.
	 *
	 * @param {string} name Lower cased file name.
	 * @return {string} Name with its meta suffixes removed.
	 */
	function stripMetaSuffixes(name) {
		var stripped = name;
		var dot = stripped.lastIndexOf('.');

		while (dot > 0 && -1 !== metaSuffixes.indexOf(stripped.slice(dot + 1))) {
			stripped = stripped.slice(0, dot);
			dot = stripped.lastIndexOf('.');
		}

		return stripped;
	}

	/**
	 * Resolve a dropped file name to the fence info string to open with.
	 *
	 * The MIME type the browser reports is not usable as the rule: it is
	 * text/plain for .txt and .md, the empty string for .php, .py and .ini,
	 * and application/json for .json. The extension is what decides.
	 *
	 * The three results are distinct and callers must tell them apart with an
	 * explicit null test rather than truthiness: a language name, an empty
	 * string for a recognised file that opens a bare fence, and null for a
	 * name that says nothing useful. Null is not a refusal, only the end of
	 * what the name can tell us: the caller then sniffs the contents.
	 *
	 * @param {*} filename Original file name.
	 * @return {string|null} Fence info string, or null when the name decides nothing.
	 */
	function textFileLanguage(filename) {
		if (typeof filename !== 'string' || '' === filename) {
			return null;
		}

		var name = filename.toLowerCase();
		var key;

		if (0 === name.indexOf('.') && -1 === name.indexOf('.', 1)) {
			// A leading dot name has no extension in the usual sense, so the
			// whole name is the key. Splitting on the last dot would read
			// ".htaccess" as "htaccess", but also ".gitignore" as "gitignore".
			key = name;
		} else {
			var dot = name.lastIndexOf('.');

			if (dot < 1) {
				return null;
			}

			key = name.slice(dot + 1);

			if (!owns(textFileLanguages, key)) {
				name = stripMetaSuffixes(name);
				dot = name.lastIndexOf('.');
				key = dot > 0 ? name.slice(dot + 1) : '';
			}
		}

		return owns(textFileLanguages, key) ? textFileLanguages[key] : null;
	}

	/**
	 * Tell whether a decoded sample looks like a binary file.
	 *
	 * file.text() decodes as UTF-8 whatever it is given, so bytes that are not
	 * valid UTF-8 come back as replacement characters. A binary file is dense
	 * in those and in control characters; text has close to none of either.
	 * A single null byte settles it on its own.
	 *
	 * @param {string} sample Decoded start of the file.
	 * @return {boolean} True when the sample looks binary.
	 */
	function looksBinary(sample) {
		if (-1 !== sample.indexOf('\u0000')) {
			return true;
		}

		if ('' === sample) {
			return false;
		}

		// Tab, newline, carriage return and form feed are ordinary in text and
		// are left out.
		var suspect = sample.match(/[\u0000-\u0008\u000B\u000E-\u001F\u007F\uFFFD]/g);

		// A tenth is far above what a truncated multibyte character at the end
		// of the slice can produce, and far below what any binary format hits.
		return null !== suspect && suspect.length / sample.length > 0.1;
	}

	/**
	 * Read the language out of a shebang line.
	 *
	 * @param {string} line First line of the file, starting with "#!".
	 * @return {string} Language name, empty when the interpreter is unknown.
	 */
	function shebangLanguage(line) {
		var words = line.replace(/^#!/, '').split(/\s+/).filter(function (word) {
			return '' !== word;
		});
		var command = words.length ? words[0].split('/').pop() : '';

		if ('env' === command) {
			// env carries its own options and VAR=value assignments before the
			// command it runs.
			var index = 1;

			while (index < words.length && /^(-|[A-Za-z_][A-Za-z0-9_]*=)/.test(words[index])) {
				index++;
			}

			command = index < words.length ? words[index].split('/').pop() : '';
		}

		return owns(shebangLanguages, command) ? shebangLanguages[command] : '';
	}

	/**
	 * Decide what to do with a file whose name gave nothing away.
	 *
	 * A shell script called "updatewp" is perfectly droppable, so the contents
	 * get the last word: anything that reads as text is accepted, with a
	 * language when a shebang or an opening PHP tag names one, and a bare
	 * fence otherwise.
	 *
	 * @param {*} sample Decoded start of the file.
	 * @return {string|null} Fence info string, or null when the file is binary.
	 */
	function sniffTextLanguage(sample) {
		if (typeof sample !== 'string') {
			return null;
		}

		if (looksBinary(sample)) {
			return null;
		}

		if (0 === sample.indexOf('#!')) {
			return shebangLanguage(sample.split('\n', 1)[0]);
		}

		if (0 === sample.indexOf('<?php')) {
			return 'php';
		}

		return '';
	}

	/**
	 * Wrap file contents in a fenced code block.
	 *
	 * The delimiter grows past the longest backtick run the content opens a
	 * line with, as CommonMark requires. Without that, dropping a Markdown
	 * file that itself contains a fence closes the block early and spills the
	 * rest of the file into the post as live Markdown.
	 *
	 * @param {string} content  File contents.
	 * @param {string} language Fence info string, empty for a bare fence.
	 * @return {string} Fenced block, with no trailing newline.
	 */
	function buildCodeFence(content, language) {
		var text = String(content)
			.replace(/^﻿/, '')
			.replace(/\r\n?/g, '\n')
			.replace(/\s+$/, '');
		var longest = 0;
		var runs = text.match(/^`{3,}/gm) || [];

		runs.forEach(function (run) {
			longest = Math.max(longest, run.length);
		});

		var fence = new Array(Math.max(3, longest + 1) + 1).join('`');

		return fence + String(language) + '\n' + text + '\n' + fence;
	}

	return {
		buildPlaceholder: buildPlaceholder,
		buildMarkdownImage: buildMarkdownImage,
		replacePlaceholder: replacePlaceholder,
		isImageFile: isImageFile,
		textFileLanguage: textFileLanguage,
		sniffTextLanguage: sniffTextLanguage,
		buildCodeFence: buildCodeFence,
		resolveMaxTextBytes: resolveMaxTextBytes,
		SNIFF_BYTES: SNIFF_BYTES,
		DEFAULT_MAX_TEXT_BYTES: DEFAULT_MAX_TEXT_BYTES
	};
});
