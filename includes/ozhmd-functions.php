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
