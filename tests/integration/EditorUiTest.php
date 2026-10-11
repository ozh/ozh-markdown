<?php

declare(strict_types=1);

require_once __DIR__ . '/class-ozhmd-integration-test-case.php';

/**
 * The editor screen: classic editor removal, meta box, and toolbar markup.
 */
final class EditorUiTest extends Ozhmd_Integration_Test_Case {

	/**
	 * Post type service under test.
	 *
	 * @var Ozhmd_Post_Types
	 */
	private Ozhmd_Post_Types $post_types;

	/**
	 * Editor UI under test.
	 *
	 * @var Ozhmd_Editor_UI
	 */
	private Ozhmd_Editor_UI $editor;

	/**
	 * Build the services the edit screen depends on.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->post_types = new Ozhmd_Post_Types();

		$factory = new Ozhmd_Converter_Factory();
		$storage = new Ozhmd_Post_Storage(new Ozhmd_Converter($factory->create()), $this->post_types);

		$this->editor = new Ozhmd_Editor_UI($this->post_types, $storage);
	}

	/**
	 * Editor support is dropped so edit-form-advanced.php stops rendering
	 * the classic content box.
	 *
	 * @return void
	 */
	public function test_the_classic_editor_is_removed_for_posts(): void {
		$this->assertTrue(post_type_supports('post', 'editor'));

		$this->post_types->remove_default_editor('post');

		$this->assertFalse(post_type_supports('post', 'editor'));
	}

	/**
	 * The same holds for pages, which the bug report named specifically.
	 *
	 * @return void
	 */
	public function test_the_classic_editor_is_removed_for_pages(): void {
		$this->post_types->remove_default_editor('page');

		$this->assertFalse(post_type_supports('page', 'editor'));
	}

	/**
	 * An excluded post type keeps its editor.
	 *
	 * @return void
	 */
	public function test_an_excluded_post_type_keeps_its_editor(): void {
		$this->post_types->remove_default_editor('wp_block');

		$this->assertTrue(post_type_supports('wp_block', 'editor'));
	}

	/**
	 * Removal runs after registration, so the meta box still lands.
	 *
	 * Ordering is the whole point: eligible() reads editor support, so a
	 * removal running first would leave nothing eligible and no meta box.
	 *
	 * @return void
	 */
	public function test_the_meta_box_is_registered_before_support_is_removed(): void {
		global $wp_meta_boxes;

		$this->require_admin_includes();

		$this->post_types->register_hooks();
		$this->editor->register_hooks();

		do_action('add_meta_boxes', 'post', null);

		$this->assertArrayHasKey('ozhmd_editor', $wp_meta_boxes['post']['normal']['high']);
		$this->assertFalse(post_type_supports('post', 'editor'));
	}

	/**
	 * Assets still enqueue after the classic editor has been removed.
	 *
	 * This is the real edit screen order: edit-form-advanced.php fires
	 * add_meta_boxes at line 264, which strips editor support, and
	 * admin_enqueue_scripts only later at line 418. A previous version asked
	 * eligible() at that point, got nothing back because it reads editor
	 * support, and enqueued no assets at all.
	 *
	 * @return void
	 */
	public function test_assets_enqueue_after_the_editor_has_been_removed(): void {
		$this->require_admin_includes();

		// Calls the hook callbacks directly rather than firing add_meta_boxes.
		// The plugin's bootstrap already has its own Ozhmd_Post_Types hooked,
		// and it would strip support before this instance saw the action, so
		// the action route would exercise two instances where production has
		// exactly one.
		$this->post_types->remove_default_editor('post');

		$this->assertFalse(post_type_supports('post', 'editor'), 'Precondition: support must be gone.');
		$this->assertContains('post', $this->post_types->eligible(), 'post must stay eligible after the strip.');

		set_current_screen('post');
		$GLOBALS['current_screen']->post_type = 'post';

		$this->editor->enqueue_assets('post-new.php');

		foreach (['ozhmd-editor', 'ozhmd-toolbar', 'ozhmd-upload', 'ozhmd-codemirror', 'ozhmd-marked', 'ozhmd-dompurify', 'ozhmd-prism', 'ozhmd-scroll-lib', 'ozhmd-resize-lib'] as $handle) {
			$this->assertTrue(wp_script_is($handle, 'enqueued'), 'not enqueued: ' . $handle);
		}
	}

	/**
	 * Every toolbar button carries an action, an icon and a label.
	 *
	 * @return void
	 */
	public function test_every_toolbar_button_is_fully_described(): void {
		foreach ($this->editor->toolbar_buttons() as $button) {
			$this->assertNotSame('', $button['action']);
			$this->assertNotSame('', $button['icon']);
			$this->assertNotSame('', $button['label']);
		}
	}

	/**
	 * The sync toggle renders everything its JavaScript needs to find.
	 *
	 * toolbar.js reads the pair of icon names off the button and swaps between
	 * them, and editor.js reads the sync state off the container. Both are
	 * written here, so a rename on this side breaks them silently.
	 *
	 * @return void
	 */
	public function test_the_sync_toggle_renders_its_state_and_its_icon_pair(): void {
		$this->require_admin_includes();

		$post_id = (int) wp_insert_post([
			'post_title'  => 'Toggle check',
			'post_status' => 'draft',
		]);

		ob_start();

		try {
			$this->editor->render(get_post($post_id));
		} finally {
			$html = (string) ob_get_clean();
		}

		$this->assertStringContainsString('data-ozhmd-sync="on"', $html, 'panes start tied together');
		$this->assertStringContainsString('aria-pressed="true"', $html, 'the toggle starts pressed');
		$this->assertStringContainsString('data-ozhmd-icon-on="lock"', $html);
		$this->assertStringContainsString('data-ozhmd-icon-off="unlock"', $html);
		$this->assertStringContainsString('dashicons-lock', $html, 'it is drawn shut');

		// The buttons that carry no pair must not sprout the attributes.
		$this->assertSame(1, substr_count($html, 'data-ozhmd-icon-on='));
		$this->assertSame(1, substr_count($html, 'aria-pressed='));
	}

	/**
	 * The resize handle renders, hidden, and outside the flex row.
	 *
	 * Two things would break silently. The container is the flex row holding
	 * the two panes, so a handle inside it would lay out as a third column
	 * rather than as a bar underneath; the structure is therefore asserted
	 * rather than the markup. And the handle ships hidden, because editor.js
	 * unhides it only once it has a CodeMirror instance: with JavaScript off,
	 * the plain textarea is what saves, and a resize handle would do nothing.
	 *
	 * @return void
	 */
	public function test_the_resize_handle_renders_hidden_and_beside_the_editor(): void {
		$this->require_admin_includes();

		$post_id = (int) wp_insert_post([
			'post_title'  => 'Handle check',
			'post_status' => 'draft',
		]);

		ob_start();

		try {
			$this->editor->render(get_post($post_id));
		} finally {
			$html = (string) ob_get_clean();
		}

		$this->assertStringContainsString('data-ozhmd-resize', $html);

		$document = new DOMDocument();

		// The fragment is a run of divs, which libxml will not take on its own.
		$this->assertTrue($document->loadHTML('<html><body>' . $html . '</body></html>', LIBXML_NOERROR));

		$handle = (new DOMXPath($document))->query('//*[@data-ozhmd-resize]')->item(0);

		$this->assertInstanceOf(DOMElement::class, $handle, 'the handle is in the rendered screen');
		$this->assertTrue($handle->hasAttribute('hidden'), 'it starts hidden');
		$this->assertSame('separator', $handle->getAttribute('role'));
		$this->assertSame('0', $handle->getAttribute('tabindex'), 'it is reachable by keyboard');

		$parent = $handle->parentNode;

		$this->assertInstanceOf(DOMElement::class, $parent);
		$this->assertFalse(
			$parent->hasAttribute('data-ozhmd-editor'),
			'the handle must be a sibling of the pane row, not a third column inside it'
		);
	}

	/**
	 * The splitter renders, hidden, between the two panes.
	 *
	 * The opposite of the resize handle: this one is a child of the flex row,
	 * because it is the column the two panes are divided by, and its position
	 * among its siblings is what makes it that divider rather than a bar at one
	 * end. Both facts are asserted, since neither is visible in a string search.
	 *
	 * @return void
	 */
	public function test_the_splitter_renders_hidden_between_the_two_panes(): void {
		$this->require_admin_includes();

		$post_id = (int) wp_insert_post([
			'post_title'  => 'Splitter check',
			'post_status' => 'draft',
		]);

		ob_start();

		try {
			$this->editor->render(get_post($post_id));
		} finally {
			$html = (string) ob_get_clean();
		}

		$document = new DOMDocument();

		$this->assertTrue($document->loadHTML('<html><body>' . $html . '</body></html>', LIBXML_NOERROR));

		$xpath     = new DOMXPath($document);
		$splitter  = $xpath->query('//*[@data-ozhmd-split]')->item(0);

		$this->assertInstanceOf(DOMElement::class, $splitter, 'the splitter is in the rendered screen');
		$this->assertTrue($splitter->hasAttribute('hidden'), 'it starts hidden');
		$this->assertSame('separator', $splitter->getAttribute('role'));
		$this->assertSame('vertical', $splitter->getAttribute('aria-orientation'), 'it divides two columns');
		$this->assertSame('0', $splitter->getAttribute('tabindex'), 'it is reachable by keyboard');

		$parent = $splitter->parentNode;

		$this->assertInstanceOf(DOMElement::class, $parent);
		$this->assertTrue($parent->hasAttribute('data-ozhmd-editor'), 'it is a column of the pane row');

		// Source, splitter, preview, in that order and with nothing else between
		// them: the flex row lays its children out exactly as they are written.
		$columns = $xpath->query('//*[@data-ozhmd-editor]/*');

		$this->assertSame(3, $columns->length);
		$this->assertStringContainsString('ozhmd-pane-source', $columns->item(0)->getAttribute('class'));
		$this->assertTrue($columns->item(1)->hasAttribute('data-ozhmd-split'));
		$this->assertStringContainsString('ozhmd-pane-preview', $columns->item(2)->getAttribute('class'));
	}

	/**
	 * The rendered screen carries the toolbar, the textarea and the nonce.
	 *
	 * @return void
	 */
	public function test_the_rendered_editor_contains_its_parts(): void {
		$this->require_admin_includes();

		$post_id = (int) wp_insert_post([
			'post_title'  => 'Render check',
			'post_status' => 'draft',
		]);

		ob_start();

		try {
			$this->editor->render(get_post($post_id));
		} finally {
			$html = (string) ob_get_clean();
		}

		$this->assertStringContainsString('data-ozhmd-toolbar', $html);
		$this->assertStringContainsString('data-ozhmd-editor', $html);
		$this->assertStringContainsString('data-ozhmd-preview', $html);
		$this->assertStringContainsString('name="ozhmd_markdown"', $html);
		$this->assertStringContainsString('name="ozhmd_nonce"', $html);

		foreach ($this->editor->toolbar_buttons() as $button) {
			$this->assertStringContainsString('data-ozhmd-action="' . $button['action'] . '"', $html);
		}
	}
}
