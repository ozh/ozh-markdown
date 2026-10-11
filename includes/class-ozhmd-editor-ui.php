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
	 * Largest text file the editor will inline as a code block, in bytes.
	 *
	 * Images are not subject to this: they upload, and the server decides.
	 * A text file is inlined into the post itself, so the limit is about what
	 * belongs in a post rather than what the site can store.
	 */
	public const MAX_TEXT_BYTES = 102400;

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
	 * Store the injected dependencies.
	 *
	 * @param Ozhmd_Post_Types   $post_types Eligibility resolver.
	 * @param Ozhmd_Post_Storage $storage    Post storage.
	 */
	public function __construct(Ozhmd_Post_Types $post_types, Ozhmd_Post_Storage $storage) {
		$this->post_types = $post_types;
		$this->storage    = $storage;
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

		// The sync state rides on the container so the editor can read it, and
		// so the toolbar can set it, without either knowing about the other.
		echo '<div class="ozhmd-editor" data-ozhmd-editor data-ozhmd-sync="on">';

		echo '<div class="ozhmd-pane ozhmd-pane-source">';
		printf(
			'<textarea id="ozhmd-markdown" name="ozhmd_markdown" class="ozhmd-textarea" rows="24">%s</textarea>',
			esc_textarea($value)
		);
		echo '</div>';

		$this->render_splitter();

		echo '<div class="ozhmd-pane ozhmd-pane-preview">';
		echo '<div class="ozhmd-preview" data-ozhmd-preview></div>';
		echo '</div>';

		echo '</div>';

		$this->render_resize_handle();
		$this->render_stats();
	}

	/**
	 * Render the line that counts the post as it is typed.
	 *
	 * Empty from the server: there is nothing to count until the preview has
	 * been rendered, and without JavaScript there is no preview at all. The
	 * stylesheet takes an empty one out of the flow, so the no-JavaScript path
	 * shows nothing rather than a blank strip.
	 *
	 * Below the resize handle rather than above it, so the grip stays touching
	 * the panes it resizes.
	 *
	 * No aria-live: the numbers change on every keystroke, and a live region
	 * would read the whole line out again each time.
	 *
	 * @return void
	 */
	private function render_stats(): void {
		echo '<div class="ozhmd-stats" data-ozhmd-stats></div>';
	}

	/**
	 * Render the divider that drags the two panes wider or narrower.
	 *
	 * A column of the flex row, written between the two panes because that is
	 * where the row will put it. Unlike the height, the split is not
	 * remembered: it goes back to even panes on every load, so there is nothing
	 * for the server to know about it.
	 *
	 * @return void
	 */
	private function render_splitter(): void {
		printf(
			'<div class="ozhmd-split" data-ozhmd-split role="separator" aria-orientation="vertical" tabindex="0" aria-label="%s" title="%s" hidden></div>',
			esc_attr__('Resize the panes', 'ozh-markdown'),
			esc_attr__('Drag, or use the arrow keys, to resize the panes', 'ozh-markdown')
		);
	}

	/**
	 * Render the bar that drags the editor taller or shorter.
	 *
	 * A sibling of the editor rather than a child of it: that element is the
	 * flex row holding the two panes, so a full width child would lay out as a
	 * third column beside them.
	 *
	 * It ships hidden and is unhidden by editor.js once the enhanced editor is
	 * running. Without JavaScript the plain textarea is what saves, and it
	 * carries a resize grip of its own, so a second one that did nothing would
	 * be worse than none.
	 *
	 * @return void
	 */
	private function render_resize_handle(): void {
		printf(
			'<div class="ozhmd-resize" data-ozhmd-resize role="separator" aria-orientation="horizontal" tabindex="0" aria-label="%s" title="%s" hidden></div>',
			esc_attr__('Resize the editor', 'ozh-markdown'),
			esc_attr__('Drag, or use the arrow keys, to resize the editor', 'ozh-markdown')
		);
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
			['action' => 'sync', 'icon' => 'lock', 'icon_off' => 'unlock', 'label' => __('Scroll both panes together', 'ozh-markdown'), 'key' => '', 'toggle' => true],
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

			// A toggle says which way it is set, for assistive technology and
			// for anyone reading the markup. The panes start tied together, so
			// it starts pressed.
			$pressed = empty($button['toggle']) ? '' : ' aria-pressed="true"';

			// A toggle that swaps glyph carries both names, so the JavaScript
			// has something to swap between without knowing which button it is
			// looking at. The label does not change with the state: the button
			// is still the one that ties the panes together, and aria-pressed
			// is what says whether it currently does.
			$icons = empty($button['icon_off']) ? '' : sprintf(
				' data-ozhmd-icon-on="%s" data-ozhmd-icon-off="%s"',
				esc_attr($button['icon']),
				esc_attr($button['icon_off'])
			);

			printf(
				'<button type="button" class="button ozhmd-tool" data-ozhmd-action="%1$s" title="%2$s" aria-label="%2$s"%4$s%5$s><span class="dashicons dashicons-%3$s" aria-hidden="true"></span></button>',
				esc_attr($button['action']),
				esc_attr($title),
				esc_attr($button['icon']),
				$pressed,
				$icons
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
		wp_enqueue_script('ozhmd-dompurify', OZHMD_URL . 'assets/js/vendor/purify.min.js', [], ozhmd_asset_version('assets/js/vendor/purify.min.js'), true);

		wp_enqueue_script(
			'ozhmd-scroll-lib',
			OZHMD_URL . 'assets/js/lib/ozhmd-scroll-lib.js',
			[],
			ozhmd_asset_version('assets/js/lib/ozhmd-scroll-lib.js'),
			true
		);

		wp_enqueue_script(
			'ozhmd-resize-lib',
			OZHMD_URL . 'assets/js/lib/ozhmd-resize-lib.js',
			[],
			ozhmd_asset_version('assets/js/lib/ozhmd-resize-lib.js'),
			true
		);

		wp_enqueue_script(
			'ozhmd-editor',
			OZHMD_URL . 'assets/js/editor.js',
			['ozhmd-codemirror', 'ozhmd-codemirror-markdown', 'ozhmd-marked', 'ozhmd-dompurify', 'ozhmd-prism', 'ozhmd-scroll-lib', 'ozhmd-resize-lib'],
			ozhmd_asset_version('assets/js/editor.js'),
			true
		);

		wp_localize_script('ozhmd-editor', 'ozhmdEditor', [
			'preClasses' => ozhmd_prism_pre_classes(),
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
			'ozhmd-stats-lib',
			OZHMD_URL . 'assets/js/lib/ozhmd-stats-lib.js',
			[],
			ozhmd_asset_version('assets/js/lib/ozhmd-stats-lib.js'),
			true
		);

		wp_enqueue_script(
			'ozhmd-stats',
			OZHMD_URL . 'assets/js/stats.js',
			['ozhmd-stats-lib', 'ozhmd-editor'],
			ozhmd_asset_version('assets/js/stats.js'),
			true
		);

		// Each counted template holds one %s, taking a number the browser has
		// already grouped for the reader. The singulars are separate strings
		// rather than a _n() call, because the number is only known in the
		// browser. The rate the reading time is quoted at is not here at all:
		// it is a property of the counting, and lives in the lib beside it.
		wp_localize_script('ozhmd-stats', 'ozhmdStats', [
			'i18n' => [
				/* translators: %s: number of words. */
				'words'          => __('%s words', 'ozh-markdown'),
				/* translators: %s: the number one. */
				'wordsOne'       => __('%s word', 'ozh-markdown'),
				/* translators: %s: average number of words per sentence. */
				'perSentence'    => __('%s words per sentence', 'ozh-markdown'),
				/* translators: %s: the number one. */
				'perSentenceOne' => __('%s word per sentence', 'ozh-markdown'),
				/* translators: %s: number of words in the longest sentence. Follows the average, as an aside on it. */
				'longest'        => __('(longest: %s words)', 'ozh-markdown'),
				/* translators: %s: the number one. */
				'longestOne'     => __('(longest: %s word)', 'ozh-markdown'),
				/* translators: %s: number of links in the post. */
				'links'          => __('%s links', 'ozh-markdown'),
				/* translators: %s: the number one. */
				'linksOne'       => __('%s link', 'ozh-markdown'),
				/* translators: %s: number of code blocks in the post. */
				'codeBlocks'     => __('%s code blocks', 'ozh-markdown'),
				/* translators: %s: the number one. */
				'codeBlocksOne'  => __('%s code block', 'ozh-markdown'),
				/* translators: %s: number of minutes. */
				'minutes'        => __('%s min read', 'ozh-markdown'),
				'minutesUnder'   => __('< 1 min read', 'ozh-markdown'),
				/* translators: separator between the stats, with its spaces. */
				'separator'      => __(' · ', 'ozh-markdown'),
			],
		]);

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
			'endpoint'     => rest_url(Ozhmd_Uploader::NAMESPACE . Ozhmd_Uploader::ROUTE),
			'nonce'        => wp_create_nonce('wp_rest'),
			'postId'       => (int) get_the_ID(),
			'maxTextBytes' => self::MAX_TEXT_BYTES,
			'i18n'         => [
				'notAccepted'  => __('is not an image, and does not look like a text file, so it was not inserted.', 'ozh-markdown'),
				/* translators: %s: file size limit, already formatted, e.g. "100 KB". */
				'tooLarge'     => sprintf(__('is larger than %s, so it was not inserted.', 'ozh-markdown'), size_format(self::MAX_TEXT_BYTES)),
				'readFailed'   => __('could not be read.', 'ozh-markdown'),
				'uploadFailed' => __('The upload failed.', 'ozh-markdown'),
			],
		]);
	}
}
