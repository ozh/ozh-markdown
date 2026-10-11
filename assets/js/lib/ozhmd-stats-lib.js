/**
 * Pure parts of the editor's stats line.
 *
 * The caller pulls the prose out of the rendered preview, which is the only
 * part of the feature that needs a browser. What is here is everything that is
 * not: where one word ends and the next begins, where one sentence does, and
 * how a set of numbers becomes a line of text.
 *
 * The two halves are kept apart on purpose. count() is arithmetic and never
 * rounds; format() rounds and picks the words. That is what lets the formatter
 * tell "half a minute" from "no minutes", a distinction it could not make from
 * a number already rounded down to zero.
 *
 * Loaded as a browser global (window.ozhmdStatsLib) by the editor, and as a
 * CommonJS module by the Node test suite.
 */
(function (root, factory) {
	'use strict';

	var api = factory();

	if (typeof module === 'object' && module.exports) {
		module.exports = api;
	} else {
		root.ozhmdStatsLib = api;
	}
})(typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	// The rate the reading time is quoted at. A property of the counting rather
	// than a preference, so it lives here and not in a setting.
	var WORDS_PER_MINUTE = 220;

	// A token is a word when something in it is a letter or a digit. So
	// "well-known" and "it's" are one word each, and a dash or a bullet standing
	// between two words is not a word at all.
	//
	// This counts by whitespace, which is right for every script that separates
	// its words that way and wrong for Chinese and Japanese, which do not.
	var WORD = /[\p{L}\p{N}]/u;

	// A sentence ends on a run of terminators followed by whitespace or by the
	// end of the text. The lookahead is what keeps "3.5" and "example.com" from
	// ending one: a full stop with a character hard up against it is part of
	// what it is written in, not the end of anything.
	var SENTENCE_END = /[.!?…]+(?=\s|$)/;

	/**
	 * How many words a piece of text holds.
	 *
	 * @param {string} text Any run of text.
	 * @return {number} Words in it.
	 */
	function wordsIn(text) {
		return (text.match(/\S+/g) || []).filter(function (token) {
			return WORD.test(token);
		}).length;
	}

	/**
	 * Measure a piece of prose.
	 *
	 * Every sentence is counted on its own and the totals fall out of that,
	 * which is what makes the longest one knowable. Splitting cannot cut a word
	 * in half, because a terminator only ends a sentence when whitespace or the
	 * end of the text follows it, so the totals are the same as counting the
	 * whole text at once.
	 *
	 * Nothing here is rounded, and nothing is ever NaN: an empty document
	 * reports zero throughout rather than dividing by its own zero.
	 *
	 * @param {string} text Prose, with the Markdown and the code already gone.
	 * @return {object} Words, sentences, the average and the longest sentence,
	 *                  and minutes of reading.
	 */
	function count(text) {
		var empty = {
			words: 0,
			sentences: 0,
			wordsPerSentence: 0,
			longestSentence: 0,
			minutes: 0
		};

		if (typeof text !== 'string') {
			return empty;
		}

		var words = 0;
		var sentences = 0;
		var longest = 0;

		// Only the pieces that still say something. A terminator at the very end
		// leaves an empty piece after it, and that is not a sentence.
		text.split(SENTENCE_END).forEach(function (piece) {
			var length = wordsIn(piece);

			if (0 === length) {
				return;
			}

			words += length;
			sentences++;
			longest = Math.max(longest, length);
		});

		if (0 === words) {
			return empty;
		}

		return {
			words: words,
			sentences: sentences,
			wordsPerSentence: words / sentences,
			longestSentence: longest,
			minutes: words / WORDS_PER_MINUTE
		};
	}

	// What the line says when the localised strings never arrived.
	// wp_localize_script can fail to run, and an untranslated line is better
	// than a line of "undefined" and much better than an exception in the
	// editor. Each template holds one %s, except the one with no number in it.
	var DEFAULTS = {
		words: '%s words',
		wordsOne: '%s word',
		perSentence: '%s words per sentence',
		perSentenceOne: '%s word per sentence',
		longest: '(longest: %s words)',
		longestOne: '(longest: %s word)',
		links: '%s links',
		linksOne: '%s link',
		codeBlocks: '%s code blocks',
		codeBlocksOne: '%s code block',
		minutes: '%s min read',
		minutesUnder: '< 1 min read',
		separator: ' · '
	};

	/**
	 * The template for a key, translated where there is a translation.
	 *
	 * @param {object} i18n Templates from the server, or nothing.
	 * @param {string} key Which one is wanted.
	 * @return {string} A template with a %s in it, unless it needs no number.
	 */
	function template(i18n, key) {
		var bag = i18n || {};

		return bag[key] || DEFAULTS[key];
	}

	/**
	 * Put a number into a template.
	 *
	 * Grouped by the reader's own conventions, so a long post reads 1,284 or
	 * 1 284 rather than 1284.
	 *
	 * @param {object} i18n Templates from the server, or nothing.
	 * @param {string} key Which template to use.
	 * @param {number} value Number to write into it.
	 * @return {string} One segment of the line.
	 */
	function segment(i18n, key, value) {
		return template(i18n, key).replace('%s', value.toLocaleString());
	}

	/**
	 * Write the counts out as the line the editor shows.
	 *
	 * All the rounding is here, and so is every choice of word, which is what
	 * keeps count() free of both.
	 *
	 * The prose stats come from count(); the links and the code blocks are
	 * counted off the preview by the caller and merged in, because they are
	 * properties of the rendered post rather than of its text. Both are left out
	 * of the line entirely when there are none, so a post of pure prose is not
	 * two thirds a report of what it does not contain.
	 *
	 * @param {object} stats Counts from count(), optionally carrying links and
	 *                       codeBlocks.
	 * @param {object} [i18n] Templates from the server.
	 * @return {string} The line to display.
	 */
	function format(stats, i18n) {
		var parts = [segment(i18n, 1 === stats.words ? 'wordsOne' : 'words', stats.words)];

		// Everything derived from the prose is meaningless without any, and
		// would read as three more ways of saying the same nothing. What the
		// post contains is still worth reporting: a post can be one code block
		// and no words at all.
		if (stats.words > 0) {
			var average = Math.round(stats.wordsPerSentence);

			// The longest qualifies the average rather than standing beside it,
			// so the two are one segment: an aside in brackets, on the number it
			// is an aside about. The space between them is written here and not
			// into a template, because a template with a leading space is one
			// trimmed translation away from reading "sentence(longest: 98".
			parts.push(
				segment(i18n, 1 === average ? 'perSentenceOne' : 'perSentence', average) +
				' ' +
				segment(
					i18n,
					1 === stats.longestSentence ? 'longestOne' : 'longest',
					stats.longestSentence
				)
			);
		}

		[
			{ value: stats.links, many: 'links', one: 'linksOne' },
			{ value: stats.codeBlocks, many: 'codeBlocks', one: 'codeBlocksOne' }
		].forEach(function (each) {
			var value = each.value || 0;

			if (value > 0) {
				parts.push(segment(i18n, 1 === value ? each.one : each.many, value));
			}
		});

		if (stats.words > 0) {
			var minutes = Math.round(stats.minutes);

			// Rounding alone would report no minutes at all for a paragraph
			// that plainly takes some reading.
			parts.push(
				0 === minutes
					? template(i18n, 'minutesUnder')
					: segment(i18n, 'minutes', minutes)
			);
		}

		return parts.join(template(i18n, 'separator'));
	}

	return {
		WORDS_PER_MINUTE: WORDS_PER_MINUTE,
		count: count,
		format: format
	};
});
