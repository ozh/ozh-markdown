<?php
/**
 * Resolves which post types receive the Markdown editor.
 */

/**
 * Post type eligibility.
 *
 * Thin wiring over ozhmd_eligible_post_types(): this class reads the WordPress
 * registry and applies the filter, the pure function does the deciding.
 */
class Ozhmd_Post_Types {

	/**
	 * Post types eligible for the Markdown editor.
	 *
	 * Every post type supporting the 'editor' feature, minus the built-in
	 * exclusion list as extended by the ozhmd_excluded_post_types filter.
	 *
	 * @return string[] Eligible post type names, reindexed.
	 */
	public function eligible(): array {
		$supporting = array_values(get_post_types_by_support('editor'));

		/**
		 * Filter the post types that never receive the Markdown editor.
		 *
		 * @param string[] $excluded Excluded post type names.
		 */
		$excluded = apply_filters('ozhmd_excluded_post_types', ozhmd_default_excluded_post_types());

		return ozhmd_eligible_post_types($supporting, (array) $excluded);
	}
}
