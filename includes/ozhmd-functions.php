<?php
/**
 * Pure helper functions.
 *
 * Nothing in this file may call a WordPress function, read a superglobal, echo
 * output, or depend on request context. Everything here takes arguments and
 * returns a value, so it can be tested without a WordPress bootstrap.
 *
 * There is deliberately no ABSPATH guard here. The file declares functions and
 * has no side effects, so loading it directly does nothing, and a guard would
 * stop the unit test bootstrap from loading it without WordPress.
 */

/**
 * Post types that must never receive the Markdown editor, even when they
 * support the 'editor' feature.
 *
 * @return string[] Post type names.
 */
function ozhmd_default_excluded_post_types(): array {
	return [
		'attachment',
		'revision',
		'nav_menu_item',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_navigation',
		'wp_global_styles',
		'customize_changeset',
		'oembed_cache',
		'user_request',
	];
}

/**
 * Tell whether a post type appears in an exclusion list.
 *
 * @param string   $post_type Post type name to test.
 * @param string[] $excluded  Excluded post type names.
 * @return bool True when the post type is excluded.
 */
function ozhmd_is_excluded_post_type(string $post_type, array $excluded): bool {
	return in_array($post_type, $excluded, true);
}

/**
 * Remove excluded post types from a list of candidates.
 *
 * @param string[] $editor_post_types Post types supporting the editor feature.
 * @param string[] $excluded          Excluded post type names.
 * @return string[] Eligible post types, original order preserved, keys reindexed.
 */
function ozhmd_eligible_post_types(array $editor_post_types, array $excluded): array {
	$eligible = [];

	foreach ($editor_post_types as $post_type) {
		if (!ozhmd_is_excluded_post_type($post_type, $excluded)) {
			$eligible[] = $post_type;
		}
	}

	return $eligible;
}

/**
 * Tell whether rendered HTML contains a fenced code block.
 *
 * Matches an opening <pre> followed by a <code>, tolerating attributes on
 * either tag and any whitespace between them. Deliberately permissive: a false
 * positive costs one stylesheet, a false negative costs unhighlighted code.
 *
 * @param string $html Rendered HTML to inspect.
 * @return bool True when a code block is present.
 */
function ozhmd_content_has_code_block(string $html): bool {
	return 1 === preg_match('#<pre\b[^>]*>\s*<code\b#i', $html);
}

/**
 * Tell whether a class token is a Prism language class.
 *
 * Anchored with \z rather than $, because $ also matches immediately before a
 * trailing newline, which would let a crafted value through.
 *
 * @param string $class Single class token, already split on whitespace.
 * @return bool True when the token matches language-<slug>.
 */
function ozhmd_is_valid_language_class(string $class): bool {
	return 1 === preg_match('/\A language- [a-z0-9+-]+ \z/x', $class);
}

/**
 * Strip every class token that is not a language class from <pre> and <code>.
 *
 * Belt and braces: the untrusted converter already strips raw HTML, so the only
 * class attributes reaching this function are ones CommonMark generated from
 * fence info strings. It runs anyway, because KSES can allow an attribute but
 * cannot pattern-match its value.
 *
 * The tag matcher is quote aware. A naive [^>]* stops at the first > in the
 * string, including one inside a quoted attribute value, which would leave the
 * rest of the tag unexamined.
 *
 * If the regex engine fails, the original HTML is returned unfiltered. That is
 * deliberate: this pass is the middle of three layers, and KSES runs after it.
 *
 * @param string $html HTML to filter.
 * @return string HTML with only language-* classes left on pre and code tags.
 */
function ozhmd_filter_language_classes(string $html): string {
	$filtered = preg_replace_callback(
		'#<(pre|code)\b((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)>#i',
		static function (array $tag_match): string {
			$attributes = preg_replace_callback(
				'/(?<![\w-])class\s*=\s*(["\'])(.*?)\1/is',
				static function (array $class_match): string {
					$tokens = preg_split('/\s+/', trim($class_match[2]), -1, PREG_SPLIT_NO_EMPTY);
					$kept   = array_filter($tokens ?: [], 'ozhmd_is_valid_language_class');

					return [] === $kept ? '' : 'class="' . implode(' ', $kept) . '"';
				},
				$tag_match[2]
			);

			return '<' . $tag_match[1] . rtrim((string) $attributes) . '>';
		},
		$html
	);

	return null === $filtered ? $html : $filtered;
}
