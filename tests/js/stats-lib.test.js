/**
 * Tests for the stats line's counting and wording.
 *
 * The lib is handed prose that marked has already rendered, so nothing here
 * concerns Markdown: what is pinned is where one word ends and the next begins,
 * where one sentence does, and how a set of numbers becomes a line of text.
 *
 * The two halves are tested apart because they are apart. count() is
 * arithmetic and never rounds; format() rounds, picks the singular, and decides
 * what a document with almost nothing in it should say. Keeping the rounding
 * out of the counting is what lets "half a minute" and "no minutes" stay
 * distinguishable, and that distinction is asserted below.
 */

const test = require('node:test');
const assert = require('node:assert');

const lib = require('../../assets/js/lib/ozhmd-stats-lib.js');

test('count reports the words and the sentences of ordinary prose', () => {
	const stats = lib.count('The cat sat. The dog barked.');

	assert.strictEqual(stats.words, 6);
	assert.strictEqual(stats.sentences, 2);
});

test('count does not take punctuation standing alone for a word', () => {
	// The separator of this very stats line, and a dash used as one, both end up
	// in the prose of a post and neither is a word.
	assert.strictEqual(lib.count('one · two — three').words, 3);
});

test('count reads a hyphenated word and a contraction as one word each', () => {
	assert.strictEqual(lib.count("it's well-known").words, 2);
});

test('count separates words on any run of whitespace', () => {
	assert.strictEqual(lib.count('one  two\tthree\nfour \n\n five').words, 5);
});

test('count reports nothing for an empty or blank document', () => {
	['', '   ', '\n\t\n'].forEach((text) => {
		const stats = lib.count(text);

		assert.strictEqual(stats.words, 0, 'words for "' + text + '"');
		assert.strictEqual(stats.sentences, 0, 'sentences for "' + text + '"');
	});
});

test('count answers with numbers, not NaN, for an empty document', () => {
	// Both derive from a division whose divisor is zero here, and NaN would
	// reach the formatter and be printed.
	const stats = lib.count('');

	assert.strictEqual(stats.wordsPerSentence, 0);
	assert.strictEqual(stats.minutes, 0);
});

test('count does not end a sentence on a full stop inside a number', () => {
	// A terminator only counts when whitespace or the end of the text follows
	// it, which is what keeps 3.5 from being two sentences.
	assert.strictEqual(lib.count('It ran 3.5 miles today.').sentences, 1);
});

test('count reads a run of terminators as the one sentence it ends', () => {
	assert.strictEqual(lib.count('Hi!! Bye.').sentences, 2);
	assert.strictEqual(lib.count('Really?! Yes.').sentences, 2);
});

test('count ends a sentence on an ellipsis', () => {
	assert.strictEqual(lib.count('Wait… Go.').sentences, 2);
});

test('count reads text with no terminator at all as one sentence', () => {
	// A heading, or a paragraph still being typed. Zero sentences would make the
	// average infinite.
	assert.strictEqual(lib.count('An unfinished thought').sentences, 1);
});

test('count leaves the average unrounded, for the formatter to round', () => {
	const stats = lib.count('The cat sat. The dog.');

	assert.strictEqual(stats.words, 5);
	assert.strictEqual(stats.sentences, 2);
	assert.strictEqual(stats.wordsPerSentence, 2.5);
});

test('count times the reading at the rate the lib publishes', () => {
	const words = new Array(lib.WORDS_PER_MINUTE).fill('word').join(' ');

	assert.strictEqual(lib.count(words).words, lib.WORDS_PER_MINUTE);
	assert.strictEqual(lib.count(words).minutes, 1);
});

test('count leaves the reading time unrounded, so half a minute survives', () => {
	// Rounding here would make this zero, and the formatter could no longer tell
	// "nearly a minute" from "nothing at all".
	const words = new Array(lib.WORDS_PER_MINUTE / 2).fill('word').join(' ');

	assert.strictEqual(lib.count(words).minutes, 0.5);
});

test('count reports the longest sentence, which the average hides', () => {
	// The whole point of carrying this beside the average: a post can average
	// something reasonable and still contain the one sentence worth rewriting.
	const stats = lib.count('The cat sat. The dog barked loudly at the tired postman.');

	// Three words, then eight. The average splits the difference and names
	// neither of them.
	assert.strictEqual(stats.words, 11);
	assert.strictEqual(stats.wordsPerSentence, 5.5);
	assert.strictEqual(stats.longestSentence, 8);
});

test('count makes the only sentence the longest one', () => {
	assert.strictEqual(lib.count('An unfinished thought').longestSentence, 3);
});

test('count reports no longest sentence for an empty document', () => {
	assert.strictEqual(lib.count('').longestSentence, 0);
});

test('count measures the longest sentence wherever it falls', () => {
	// Last, so an implementation that stopped at the first cannot pass.
	assert.strictEqual(lib.count('One. Two two. Three three three.').longestSentence, 3);
	assert.strictEqual(lib.count('Three three three. Two two. One.').longestSentence, 3);
});

test('count refuses nothing, answering zero for what is not a string', () => {
	// The caller reads textContent off a cloned preview, which is a string on
	// every browser that has one. Null would mean the preview was missing, and
	// an editor is not worth throwing over a stats line.
	[null, undefined, 42].forEach((value) => {
		assert.strictEqual(lib.count(value).words, 0, 'words for ' + String(value));
	});
});

/**
 * The templates as the server sends them, with a marker on each.
 *
 * Deliberately not the real English strings: a test that asserted on those
 * would pass just as well if format() picked the wrong one of a pair, since
 * several of them read alike. The markers make the choice visible.
 *
 * @return {object} An i18n bag with every key format() knows.
 */
function labels() {
	return {
		words: '%s WORDS',
		wordsOne: '%s WORD',
		perSentence: '%s PER-SENTENCE',
		perSentenceOne: '%s PER-SENTENCE-ONE',
		longest: '(LONGEST %s)',
		longestOne: '(LONGEST-ONE %s)',
		links: '%s LINKS',
		linksOne: '%s LINK',
		codeBlocks: '%s BLOCKS',
		codeBlocksOne: '%s BLOCK',
		minutes: '%s MIN',
		minutesUnder: 'UNDER',
		separator: ' | '
	};
}

test('format writes every number it is given as one line', () => {
	// Six words over two sentences of three, far under a minute of reading,
	// with some links and some code in the post.
	const stats = Object.assign(lib.count('The cat sat. The dog barked.'), {
		links: 3,
		codeBlocks: 2
	});

	// The longest rides on the average rather than standing as a segment of its
	// own: it qualifies that number, and reads as an aside from it.
	assert.strictEqual(
		lib.format(stats, labels()),
		'6 WORDS | 3 PER-SENTENCE (LONGEST 3) | 3 LINKS | 2 BLOCKS | UNDER'
	);
});

test('format leaves out the links and the code when there are none', () => {
	// The common case is a post of pure prose, and "0 links · 0 code blocks"
	// would be two thirds of the line saying nothing.
	const line = lib.format(lib.count('The cat sat. The dog barked.'), labels());

	assert.strictEqual(line, '6 WORDS | 3 PER-SENTENCE (LONGEST 3) | UNDER');
});

test('format takes the singular for one link and one code block', () => {
	const stats = Object.assign(lib.count('Hello'), { links: 1, codeBlocks: 1 });

	assert.strictEqual(
		lib.format(stats, labels()),
		'1 WORD | 1 PER-SENTENCE-ONE (LONGEST-ONE 1) | 1 LINK | 1 BLOCK | UNDER'
	);
});

test('format still describes a post that is nothing but a code block', () => {
	// No prose, so no average, no longest and no reading time. The block is
	// still worth reporting: it is the whole post.
	const stats = Object.assign(lib.count(''), { codeBlocks: 1 });

	assert.strictEqual(lib.format(stats, labels()), '0 WORDS | 1 BLOCK');
});

test('format rounds the average to a whole number of words', () => {
	const stats = lib.count('The cat sat on it. The dog.');

	assert.strictEqual(stats.wordsPerSentence, 3.5);
	assert.match(lib.format(stats, labels()), /4 PER-SENTENCE/);
});

test('format takes the singular for one word', () => {
	assert.match(lib.format(lib.count('Hello'), labels()), /^1 WORD /);
});

test('format takes the singular for an average of one word a sentence', () => {
	assert.match(lib.format(lib.count('Go. Stop.'), labels()), /1 PER-SENTENCE-ONE/);
});

test('format says a document is under a minute rather than zero minutes', () => {
	// Rounding alone would report "0 min read" for a paragraph that plainly
	// takes some reading.
	const stats = lib.count(new Array(100).fill('word').join(' '));

	assert.match(lib.format(stats, labels()), /UNDER$/);
});

test('format rounds the reading time to whole minutes', () => {
	const stats = lib.count(new Array(1284).fill('word').join(' '));

	// 1284 words at 220 a minute is 5.8 of them.
	assert.match(lib.format(stats, labels()), /6 MIN$/);
});

test('format says only how many words there are when there are none', () => {
	// The other two would be zero and zero, which is three ways of saying the
	// same nothing.
	assert.strictEqual(lib.format(lib.count(''), labels()), '0 WORDS');
});

test('format groups a large word count the way the reader groups numbers', () => {
	const stats = lib.count(new Array(1284).fill('word').join(' '));

	assert.match(lib.format(stats, labels()), new RegExp('^' + (1284).toLocaleString() + ' WORDS'));
});

test('format writes a readable line when the strings never arrived', () => {
	// wp_localize_script can fail to run. An untranslated line beats a line of
	// "undefined", and beats an exception in the editor.
	const line = lib.format(lib.count('The cat sat. The dog barked.'));

	assert.strictEqual(line, '6 words · 3 words per sentence (longest: 3 words) · < 1 min read');
});
