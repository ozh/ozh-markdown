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
		$state    = ozhmd_post_state($markdown, $post->post_content);
		$value    = 'legacy' === $state ? $post->post_content : (string) $markdown;

		wp_nonce_field('ozhmd_save_' . $post->ID, 'ozhmd_nonce');

		if ('legacy' === $state) {
			$this->render_legacy_notice();
		}

		echo '<div class="ozhmd-upload-errors" data-ozhmd-errors></div>';

		$this->render_toolbar();

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
	 * Formatting buttons offered above the editor.
	 *
	 * Each entry names the action the JavaScript performs, the dashicon to
	 * draw, and the label used for both the tooltip and the accessible name.
	 * Keyboard shortcuts are advertised in the tooltip where one exists.
	 *
	 * The order is meaningful: inline marks, then block elements, then lists,
	 * then the things that carry their own content, then the view. The last
	 * button of each of those runs is given room to its right by the CSS.
	 *
	 * @return array<int, array<string, string>> Button definitions.
	 */
	public function toolbar_buttons(): array {
		return [
			['action' => 'heading', 'icon' => 'heading', 'label' => __('Heading', 'ozh-markdown'), 'key' => ''],
			['action' => 'bold', 'icon' => 'editor-bold', 'label' => __('Bold', 'ozh-markdown'), 'key' => 'Ctrl+B'],
			['action' => 'italic', 'icon' => 'editor-italic', 'label' => __('Italic', 'ozh-markdown'), 'key' => 'Ctrl+I'],
			['action' => 'strikethrough', 'icon' => 'editor-strikethrough', 'label' => __('Strikethrough', 'ozh-markdown'), 'key' => ''],

			['action' => 'quote', 'icon' => 'editor-quote', 'label' => __('Quote', 'ozh-markdown'), 'key' => ''],
			['action' => 'hr', 'icon' => 'minus', 'label' => __('Horizontal rule', 'ozh-markdown'), 'key' => ''],

			['action' => 'ol', 'icon' => 'editor-ol', 'label' => __('Numbered list', 'ozh-markdown'), 'key' => ''],
			['action' => 'ul', 'icon' => 'editor-ul', 'label' => __('Bulleted list', 'ozh-markdown'), 'key' => ''],
			['action' => 'indent', 'icon' => 'controls-forward', 'label' => __('Indent list item', 'ozh-markdown'), 'key' => ''],
			['action' => 'outdent', 'icon' => 'controls-back', 'label' => __('Outdent list item', 'ozh-markdown'), 'key' => ''],
			['action' => 'task', 'icon' => 'yes', 'label' => __('Task list', 'ozh-markdown'), 'key' => ''],

			['action' => 'code', 'icon' => 'editor-code', 'label' => __('Code', 'ozh-markdown'), 'key' => ''],
			['action' => 'link', 'icon' => 'admin-links', 'label' => __('Link', 'ozh-markdown'), 'key' => 'Ctrl+K'],
			['action' => 'image', 'icon' => 'format-image', 'label' => __('Image', 'ozh-markdown'), 'key' => ''],
			['action' => 'table', 'icon' => 'grid-view', 'label' => __('Table', 'ozh-markdown'), 'key' => ''],

			['action' => 'view', 'icon' => 'visibility', 'label' => __('Show source, preview or both', 'ozh-markdown'), 'key' => ''],
		];
	}

	/**
	 * Render the formatting toolbar and the Add Media button.
	 *
	 * @return void
	 */
	private function render_toolbar(): void {
		echo '<div class="ozhmd-toolbar" data-ozhmd-toolbar>';

		echo '<div class="ozhmd-toolbar-group">';

		foreach ($this->toolbar_buttons() as $button) {
			$title = '' === $button['key']
				? $button['label']
				: $button['label'] . ' (' . $button['key'] . ')';

			printf(
				'<button type="button" class="button ozhmd-tool" data-ozhmd-action="%1$s" title="%2$s" aria-label="%2$s"><span class="dashicons dashicons-%3$s" aria-hidden="true"></span></button>',
				esc_attr($button['action']),
				esc_attr($title),
				esc_attr($button['icon'])
			);
		}

		echo '</div>';

		echo '<div class="ozhmd-toolbar-group ozhmd-toolbar-media">';

		// The classic editor is gone, so the media button is rendered here.
		// Passing our textarea id makes send_to_editor target it.
		do_action('media_buttons', 'ozhmd-markdown');

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

		// The classic editor is removed, so its media modal has to be loaded
		// explicitly for the Add Media button to work.
		// get_the_ID() is false on post-new.php, so pass a real ID or nothing.
		$post_id = (int) get_the_ID();
		wp_enqueue_media($post_id > 0 ? ['post' => $post_id] : []);

		wp_enqueue_style('dashicons');
		wp_enqueue_style('ozhmd-codemirror', OZHMD_URL . 'assets/css/vendor/codemirror.css', [], ozhmd_asset_version('assets/css/vendor/codemirror.css'));
		wp_enqueue_style('ozhmd-prism', OZHMD_URL . 'assets/css/vendor/prism.css', [], ozhmd_asset_version('assets/css/vendor/prism.css'));
		wp_enqueue_style('ozhmd-editor', OZHMD_URL . 'assets/css/editor.css', ['ozhmd-codemirror'], ozhmd_asset_version('assets/css/editor.css'));

		wp_enqueue_script('ozhmd-codemirror', OZHMD_URL . 'assets/js/vendor/codemirror.js', [], ozhmd_asset_version('assets/js/vendor/codemirror.js'), true);
		wp_enqueue_script('ozhmd-codemirror-markdown', OZHMD_URL . 'assets/js/vendor/codemirror-markdown.js', ['ozhmd-codemirror'], ozhmd_asset_version('assets/js/vendor/codemirror-markdown.js'), true);
		wp_enqueue_script('ozhmd-marked', OZHMD_URL . 'assets/js/vendor/marked.min.js', [], ozhmd_asset_version('assets/js/vendor/marked.min.js'), true);
		wp_enqueue_script('ozhmd-prism', OZHMD_URL . 'assets/js/vendor/prism.js', [], ozhmd_asset_version('assets/js/vendor/prism.js'), true);

		wp_enqueue_script(
			'ozhmd-editor',
			OZHMD_URL . 'assets/js/editor.js',
			['ozhmd-codemirror', 'ozhmd-codemirror-markdown', 'ozhmd-marked', 'ozhmd-prism'],
			ozhmd_asset_version('assets/js/editor.js'),
			true
		);

		wp_localize_script('ozhmd-editor', 'ozhmdEditor', [
			'preClasses' => ozhmd_prism_pre_classes($this->settings->option('prism_line_numbers')),
		]);

		wp_enqueue_script(
			'ozhmd-toolbar-lib',
			OZHMD_URL . 'assets/js/lib/ozhmd-toolbar-lib.js',
			[],
			ozhmd_asset_version('assets/js/lib/ozhmd-toolbar-lib.js'),
			true
		);

		wp_enqueue_script(
			'ozhmd-toolbar',
			OZHMD_URL . 'assets/js/toolbar.js',
			['ozhmd-toolbar-lib', 'ozhmd-editor'],
			ozhmd_asset_version('assets/js/toolbar.js'),
			true
		);

		wp_enqueue_script(
			'ozhmd-upload-lib',
			OZHMD_URL . 'assets/js/lib/ozhmd-upload-lib.js',
			[],
			ozhmd_asset_version('assets/js/lib/ozhmd-upload-lib.js'),
			true
		);

		wp_enqueue_script(
			'ozhmd-upload',
			OZHMD_URL . 'assets/js/upload.js',
			['ozhmd-upload-lib', 'ozhmd-editor'],
			ozhmd_asset_version('assets/js/upload.js'),
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
