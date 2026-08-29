<?php
/**
 * Loads Prism on the front end, but only where there is code to highlight.
 */

/**
 * Conditional Prism enqueue.
 *
 * Assets are registered unconditionally and enqueued only when the content
 * being rendered actually contains a fenced code block, so a page with no code
 * loads neither the script nor the stylesheet.
 *
 * Detection runs at wp_enqueue_scripts over the queried posts, which is early
 * enough for the stylesheet to land in the head ahead of the theme's own CSS
 * and therefore stay overridable. A the_content fallback covers loops the main
 * query does not describe, at the cost of footer printed assets in that case.
 */
class Ozhmd_Frontend {

	/**
	 * Settings, used for the Prism line numbers toggle.
	 *
	 * @var Ozhmd_Settings
	 */
	private Ozhmd_Settings $settings;

	/**
	 * Whether the assets have already been enqueued for this request.
	 *
	 * @var bool
	 */
	private bool $enqueued = false;

	/**
	 * Store the injected settings.
	 *
	 * @param Ozhmd_Settings $settings Plugin settings.
	 */
	public function __construct(Ozhmd_Settings $settings) {
		$this->settings = $settings;
	}

	/**
	 * Register the front end hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action('wp_enqueue_scripts', [$this, 'register_assets'], 5);
		add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue_for_query'], 10);
		add_filter('the_content', [$this, 'maybe_enqueue_for_content'], 20);
	}

	/**
	 * Register the Prism assets without enqueueing them.
	 *
	 * @return void
	 */
	public function register_assets(): void {
		wp_register_style('ozhmd-prism', OZHMD_URL . 'assets/css/vendor/prism.css', [], OZHMD_VERSION);
		wp_register_script('ozhmd-prism', OZHMD_URL . 'assets/js/vendor/prism.js', [], OZHMD_VERSION, true);
	}

	/**
	 * Enqueue Prism when the queried post contains a code block.
	 *
	 * Deliberately limited to singular views. An archive or the front page
	 * usually renders excerpts, so scanning the full post_content of every
	 * queried post there loads Prism for code that is never displayed. Those
	 * views fall through to the the_content filter, which sees what is
	 * actually rendered, at the cost of footer printed assets.
	 *
	 * @return void
	 */
	public function maybe_enqueue_for_query(): void {
		if ($this->enqueued || !is_singular()) {
			return;
		}

		foreach ($this->queried_content() as $content) {
			if (ozhmd_content_has_code_block($content)) {
				$this->enqueue();

				return;
			}
		}
	}

	/**
	 * Enqueue Prism when rendered content contains a code block.
	 *
	 * Covers custom loops the main query does not describe. Runs late enough
	 * that shortcodes and other filters have already expanded.
	 *
	 * Skipped while an excerpt is being generated. wp_trim_excerpt() applies
	 * the_content to the full post body before stripping it back to a
	 * summary, so a code block seen here may never reach the page.
	 *
	 * @param string $content Content being rendered.
	 * @return string The content, unchanged.
	 */
	public function maybe_enqueue_for_content(string $content): string {
		if ($this->enqueued || doing_filter('get_the_excerpt')) {
			return $content;
		}

		if (ozhmd_content_has_code_block($content)) {
			$this->enqueue();
		}

		return $content;
	}

	/**
	 * Post content belonging to the current main query.
	 *
	 * @return string[] Raw post_content strings.
	 */
	private function queried_content(): array {
		$query = $GLOBALS['wp_query'] ?? null;

		if (!$query instanceof WP_Query || !is_array($query->posts)) {
			return [];
		}

		$content = [];

		foreach ($query->posts as $post) {
			if ($post instanceof WP_Post) {
				$content[] = $post->post_content;
			}
		}

		return $content;
	}

	/**
	 * Enqueue the registered assets, once per request.
	 *
	 * @return void
	 */
	private function enqueue(): void {
		if ($this->enqueued) {
			return;
		}

		$this->enqueued = true;

		wp_enqueue_style('ozhmd-prism');
		wp_enqueue_script('ozhmd-prism');

		/**
		 * Filter the Prism languages loaded on the front end.
		 *
		 * The bundle is fixed at build time, so this filter exists to let a
		 * theme or plugin inspect the set rather than change it at runtime.
		 * Regenerate the bundle with tools/fetch-vendor.sh to change it.
		 *
		 * @param string[] $languages Bundled language identifiers.
		 */
		apply_filters('ozhmd_prism_languages', ozhmd_prism_languages());
	}

	/**
	 * Tell whether the assets have been enqueued for this request.
	 *
	 * @return bool True when Prism was enqueued.
	 */
	public function is_enqueued(): bool {
		return $this->enqueued;
	}
}
