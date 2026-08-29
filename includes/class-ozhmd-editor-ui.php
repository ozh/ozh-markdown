<?php
/**
 * Renders the split pane Markdown editor on the post edit screen.
 */

/**
 * The editor meta box and its assets.
 *
 * The textarea is the real form field: it posts correctly with JavaScript
 * disabled, and the enhanced editor writes back into it, so both paths submit
 * through exactly the same input.
 */
class Ozhmd_Editor_UI {

	/**
	 * Post type eligibility resolver.
	 *
	 * @var Ozhmd_Post_Types
	 */
	private Ozhmd_Post_Types $post_types;

	/**
	 * Post storage, used to read the stored Markdown.
	 *
	 * @var Ozhmd_Post_Storage
	 */
	private Ozhmd_Post_Storage $storage;

	/**
	 * Settings, used for the Prism line numbers toggle.
	 *
	 * @var Ozhmd_Settings
	 */
	private Ozhmd_Settings $settings;

	/**
	 * Store the injected dependencies.
	 *
	 * @param Ozhmd_Post_Types   $post_types Eligibility resolver.
	 * @param Ozhmd_Post_Storage $storage    Post storage.
	 * @param Ozhmd_Settings     $settings   Plugin settings.
	 */
	public function __construct(Ozhmd_Post_Types $post_types, Ozhmd_Post_Storage $storage, Ozhmd_Settings $settings) {
		$this->post_types = $post_types;
		$this->storage    = $storage;
		$this->settings   = $settings;
	}

	/**
	 * Register the admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action('add_meta_boxes', [$this, 'add_meta_box']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
	}

	/**
	 * Add the editor meta box to every eligible post type.
	 *
	 * @return void
	 */
	public function add_meta_box(): void {
		foreach ($this->post_types->eligible() as $post_type) {
			add_meta_box(
				'ozhmd_editor',
				__('Content', 'ozh-markdown'),
				[$this, 'render'],
				$post_type,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Render the split pane editor.
	 *
	 * @param WP_Post $post Post being edited.
	 * @return void
	 */
	public function render(WP_Post $post): void {
		$markdown = $this->storage->stored_markdown($post->ID);
		$state    = ozhmd_post_state($markdown);
		$value    = 'legacy' === $state ? $post->post_content : (string) $markdown;

		wp_nonce_field('ozhmd_save_' . $post->ID, 'ozhmd_nonce');

		if ('legacy' === $state) {
			$this->render_legacy_notice();
		}

		echo '<div class="ozhmd-upload-errors" data-ozhmd-errors></div>';
		echo '<div class="ozhmd-editor" data-ozhmd-editor>';

		echo '<div class="ozhmd-pane ozhmd-pane-source">';
		printf(
			'<textarea id="ozhmd-markdown" name="ozhmd_markdown" class="ozhmd-textarea" rows="24">%s</textarea>',
			esc_textarea($value)
		);
		echo '</div>';

		echo '<div class="ozhmd-pane ozhmd-pane-preview">';
		echo '<div class="ozhmd-preview" data-ozhmd-preview></div>';
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Render the notice offering to convert a legacy post.
	 *
	 * @return void
	 */
	private function render_legacy_notice(): void {
		echo '<div class="ozhmd-legacy notice notice-warning inline">';
		echo '<p><strong>' . esc_html__('This post is not Markdown yet.', 'ozh-markdown') . '</strong> ';
		echo esc_html__('Its HTML is shown below and will be saved exactly as typed. Converting stores a backup of the original first.', 'ozh-markdown') . '</p>';
		echo '<p><label><input type="checkbox" name="ozhmd_convert" value="1" /> ';
		echo esc_html__('Convert this post to Markdown when I save', 'ozh-markdown') . '</label></p>';
		echo '</div>';
	}

	/**
	 * Enqueue the editor assets on eligible edit screens only.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_assets(string $hook_suffix): void {
		if (!in_array($hook_suffix, ['post.php', 'post-new.php'], true)) {
			return;
		}

		$screen = get_current_screen();

		if (null === $screen || !in_array($screen->post_type, $this->post_types->eligible(), true)) {
			return;
		}

		wp_enqueue_style('ozhmd-codemirror', OZHMD_URL . 'assets/css/vendor/codemirror.css', [], OZHMD_VERSION);
		wp_enqueue_style('ozhmd-prism', OZHMD_URL . 'assets/css/vendor/prism.css', [], OZHMD_VERSION);
		wp_enqueue_style('ozhmd-editor', OZHMD_URL . 'assets/css/editor.css', ['ozhmd-codemirror'], OZHMD_VERSION);

		wp_enqueue_script('ozhmd-codemirror', OZHMD_URL . 'assets/js/vendor/codemirror.js', [], OZHMD_VERSION, true);
		wp_enqueue_script('ozhmd-codemirror-markdown', OZHMD_URL . 'assets/js/vendor/codemirror-markdown.js', ['ozhmd-codemirror'], OZHMD_VERSION, true);
		wp_enqueue_script('ozhmd-marked', OZHMD_URL . 'assets/js/vendor/marked.min.js', [], OZHMD_VERSION, true);
		wp_enqueue_script('ozhmd-prism', OZHMD_URL . 'assets/js/vendor/prism.js', [], OZHMD_VERSION, true);

		wp_enqueue_script(
			'ozhmd-editor',
			OZHMD_URL . 'assets/js/editor.js',
			['ozhmd-codemirror', 'ozhmd-codemirror-markdown', 'ozhmd-marked', 'ozhmd-prism'],
			OZHMD_VERSION,
			true
		);

		wp_localize_script('ozhmd-editor', 'ozhmdEditor', [
			'lineNumbers' => $this->settings->option('prism_line_numbers'),
		]);

		wp_enqueue_script(
			'ozhmd-upload-lib',
			OZHMD_URL . 'assets/js/lib/ozhmd-upload-lib.js',
			[],
			OZHMD_VERSION,
			true
		);

		wp_enqueue_script(
			'ozhmd-upload',
			OZHMD_URL . 'assets/js/upload.js',
			['ozhmd-upload-lib', 'ozhmd-editor'],
			OZHMD_VERSION,
			true
		);

		wp_localize_script('ozhmd-upload', 'ozhmdUpload', [
			'endpoint' => rest_url(Ozhmd_Uploader::NAMESPACE . Ozhmd_Uploader::ROUTE),
			'nonce'    => wp_create_nonce('wp_rest'),
			'postId'   => (int) get_the_ID(),
			'i18n'     => [
				'notAnImage'   => __('is not an image, so it was not uploaded.', 'ozh-markdown'),
				'uploadFailed' => __('The upload failed.', 'ozh-markdown'),
			],
		]);
	}
}
