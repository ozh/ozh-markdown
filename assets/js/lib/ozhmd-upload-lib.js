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

	return {
		buildPlaceholder: buildPlaceholder,
		buildMarkdownImage: buildMarkdownImage,
		replacePlaceholder: replacePlaceholder,
		isImageFile: isImageFile
	};
});
