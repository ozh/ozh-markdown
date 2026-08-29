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
	 * Deliberately not memoized. Plugins and themes register post types
	 * throughout init, so an answer cached early would miss them.
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

	/**
	 * Register the hooks that take the block editor and TinyMCE out of the way.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter('use_block_editor_for_post_type', [$this, 'disable_block_editor'], 10, 2);
		add_action('add_meta_boxes', [$this, 'remove_default_editor'], 20, 2);
	}

	/**
	 * Turn the block editor off for post types we handle.
	 *
	 * @param bool   $enabled   Whether the block editor is currently enabled.
	 * @param string $post_type Post type being asked about.
	 * @return bool False for eligible post types, the incoming value otherwise.
	 */
	public function disable_block_editor(bool $enabled, string $post_type): bool {
		return in_array($post_type, $this->eligible(), true) ? false : $enabled;
	}

	/**
	 * Take the TinyMCE content box off the edit screen.
	 *
	 * Deliberately removes the meta box rather than calling
	 * remove_post_type_support(). Dropping 'editor' support would mutate a
	 * global registry that eligible() itself reads, and would also strip
	 * post_content from REST responses, which the REST save path needs.
	 * Removing the box is scoped to the screen being rendered.
	 *
	 * @param string       $post_type Post type of the screen being built.
	 * @param WP_Post|null $post      Post being edited, unused.
	 * @return void
	 */
	public function remove_default_editor(string $post_type, $post = null): void {
		if (!in_array($post_type, $this->eligible(), true)) {
			return;
		}

		remove_meta_box('postdivrich', $post_type, 'normal');
	}
}
