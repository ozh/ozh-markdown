<?php
/**
 * Stores raw Markdown in post meta and writes the converted HTML to post_content.
 */

/**
 * The post save pipeline.
 *
 * Two hooks on save_post. The first stores whatever the editor submitted. The
 * second, running later so it sees meta written by either the admin form or the
 * REST controller, converts that Markdown into post_content.
 *
 * A post with no stored Markdown is legacy: the second hook leaves it alone
 * entirely, so an untouched archive is never rewritten.
 */
class Ozhmd_Post_Storage {

	/**
	 * Converter used to turn post Markdown into HTML.
	 *
	 * @var Ozhmd_Converter
	 */
	private Ozhmd_Converter $converter;

	/**
	 * Post type eligibility resolver.
	 *
	 * @var Ozhmd_Post_Types
	 */
	private Ozhmd_Post_Types $post_types;

	/**
	 * Guards against re-entering the conversion when we update the post.
	 *
	 * @var bool
	 */
	private bool $converting = false;

	/**
	 * Store the injected dependencies.
	 *
	 * @param Ozhmd_Converter  $converter  Markdown converter pair.
	 * @param Ozhmd_Post_Types $post_types Eligibility resolver.
	 */
	public function __construct(Ozhmd_Converter $converter, Ozhmd_Post_Types $post_types) {
		$this->converter  = $converter;
		$this->post_types = $post_types;
	}

	/**
	 * Register the save hooks and the meta registration.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action('init', [$this, 'register_meta']);
		add_action('save_post', [$this, 'store_submitted_markdown'], 10, 2);
		add_action('save_post', [$this, 'convert_to_post_content'], 20, 2);
	}

	/**
	 * Register the Markdown meta key so REST clients can read and write it.
	 *
	 * @return void
	 */
	public function register_meta(): void {
		foreach ($this->post_types->eligible() as $post_type) {
			register_post_meta($post_type, OZHMD_POST_META_KEY, [
				'type'          => 'string',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => static function (bool $allowed, string $meta_key, int $post_id): bool {
					return current_user_can('edit_post', $post_id);
				},
			]);
		}
	}

	/**
	 * Store the Markdown submitted by the editor form.
	 *
	 * Only handles the admin form path. REST and XML-RPC write the meta
	 * themselves, and both are picked up by convert_to_post_content().
	 *
	 * @param int     $post_id Post being saved.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function store_submitted_markdown(int $post_id, WP_Post $post): void {
		if (!$this->should_handle($post_id, $post)) {
			return;
		}

		if (!isset($_POST['ozhmd_markdown'], $_POST['ozhmd_nonce'])) {
			return;
		}

		if (!wp_verify_nonce(sanitize_key(wp_unslash($_POST['ozhmd_nonce'])), 'ozhmd_save_' . $post_id)) {
			return;
		}

		if (!current_user_can('edit_post', $post_id)) {
			return;
		}

		$markdown = (string) wp_unslash($_POST['ozhmd_markdown']);

		// A legacy post stays legacy until it is explicitly converted, so the
		// submitted text is stored as post_content verbatim rather than being
		// promoted to Markdown behind the author's back.
		if ('legacy' === ozhmd_post_state($this->stored_markdown($post_id)) && !isset($_POST['ozhmd_convert'])) {
			$this->update_content($post_id, $markdown);

			return;
		}

		if (isset($_POST['ozhmd_convert']) && !metadata_exists('post', $post_id, OZHMD_ORIGINAL_META_KEY)) {
			update_post_meta($post_id, OZHMD_ORIGINAL_META_KEY, $post->post_content);
		}

		update_post_meta($post_id, OZHMD_POST_META_KEY, $markdown);
	}

	/**
	 * Convert stored Markdown into post_content.
	 *
	 * Runs for every save path, including REST and XML-RPC, because they all
	 * reach save_post with the meta already written.
	 *
	 * @param int     $post_id Post being saved.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function convert_to_post_content(int $post_id, WP_Post $post): void {
		if ($this->converting || !$this->should_handle($post_id, $post)) {
			return;
		}

		$markdown = $this->stored_markdown($post_id);

		if ('legacy' === ozhmd_post_state($markdown)) {
			return;
		}

		$this->update_content($post_id, $this->converter->to_html((string) $markdown));
	}

	/**
	 * Read the stored Markdown for a post.
	 *
	 * Returns null when the meta key is absent, which is what makes a post
	 * legacy. An empty string means a Markdown post with an empty body.
	 *
	 * @param int $post_id Post to read.
	 * @return string|null Stored Markdown, or null when absent.
	 */
	public function stored_markdown(int $post_id): ?string {
		if (!metadata_exists('post', $post_id, OZHMD_POST_META_KEY)) {
			return null;
		}

		return (string) get_post_meta($post_id, OZHMD_POST_META_KEY, true);
	}

	/**
	 * Tell whether this save should be handled at all.
	 *
	 * @param int     $post_id Post being saved.
	 * @param WP_Post $post    Post object.
	 * @return bool True when the post is an eligible, non-autosave revision-free save.
	 */
	private function should_handle(int $post_id, WP_Post $post): bool {
		if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
			return false;
		}

		return in_array($post->post_type, $this->post_types->eligible(), true);
	}

	/**
	 * Write post_content, skipping the write when nothing changed.
	 *
	 * @param int    $post_id Post to update.
	 * @param string $content New post_content.
	 * @return void
	 */
	private function update_content(int $post_id, string $content): void {
		$post = get_post($post_id);

		if (null === $post || $post->post_content === $content) {
			return;
		}

		$this->converting = true;

		wp_update_post([
			'ID'           => $post_id,
			'post_content' => $content,
		]);

		$this->converting = false;
	}
}
