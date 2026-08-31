<?php

declare(strict_types=1);

require_once __DIR__ . '/class-ozhmd-integration-test-case.php';

/**
 * Conditional Prism enqueue on the front end.
 */
final class FrontendTest extends Ozhmd_Integration_Test_Case {

	/**
	 * Front end service under test.
	 *
	 * @var Ozhmd_Frontend
	 */
	private Ozhmd_Frontend $frontend;

	/**
	 * Build the service and register the Prism handles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->frontend = new Ozhmd_Frontend();
		$this->frontend->register_assets();
	}

	/**
	 * Both Prism handles exist but sit unqueued until something needs them.
	 *
	 * @return void
	 */
	public function test_assets_are_registered_but_not_enqueued(): void {
		$this->assertTrue(wp_style_is('ozhmd-prism', 'registered'));
		$this->assertTrue(wp_script_is('ozhmd-prism', 'registered'));
		$this->assertFalse(wp_style_is('ozhmd-prism', 'enqueued'));
		$this->assertFalse(wp_script_is('ozhmd-prism', 'enqueued'));
	}

	/**
	 * Content carrying a code block pulls in the stylesheet and the script.
	 *
	 * @return void
	 */
	public function test_content_with_a_code_block_enqueues_prism(): void {
		$this->frontend->maybe_enqueue_for_content('<p>hi</p><pre><code class="language-php">echo 1;</code></pre>');

		$this->assertTrue($this->frontend->is_enqueued());
		$this->assertTrue(wp_style_is('ozhmd-prism', 'enqueued'));
		$this->assertTrue(wp_script_is('ozhmd-prism', 'enqueued'));
	}

	/**
	 * A page with prose only loads nothing, which is the whole point.
	 *
	 * @return void
	 */
	public function test_content_without_a_code_block_enqueues_nothing(): void {
		$this->frontend->maybe_enqueue_for_content('<p>Just prose, and some <code>inline code</code>.</p>');

		$this->assertFalse($this->frontend->is_enqueued());
		$this->assertFalse(wp_style_is('ozhmd-prism', 'enqueued'));
		$this->assertFalse(wp_script_is('ozhmd-prism', 'enqueued'));
	}

	/**
	 * The filter returns the content untouched, whether or not it enqueues.
	 *
	 * @return void
	 */
	public function test_the_content_filter_does_not_alter_the_content(): void {
		$with    = '<pre><code>x</code></pre>';
		$without = '<p>x</p>';

		$this->assertSame($without, $this->frontend->maybe_enqueue_for_content($without));
		$this->assertSame($with, $this->frontend->maybe_enqueue_for_content($with));
	}

	/**
	 * A queried post containing code enqueues Prism before the head is printed.
	 *
	 * @return void
	 */
	public function test_a_queried_post_with_code_enqueues_prism(): void {
		$post_id = (int) wp_insert_post([
			'post_title'   => 'Has code',
			'post_content' => "<pre><code class=\"language-php\">echo 1;</code></pre>",
			'post_status'  => 'publish',
		]);

		$this->go_to_single($post_id);
		$this->frontend->maybe_enqueue_for_query();

		$this->assertTrue($this->frontend->is_enqueued());
	}

	/**
	 * A queried post with no code leaves the front end clean.
	 *
	 * @return void
	 */
	public function test_a_queried_post_without_code_enqueues_nothing(): void {
		$post_id = (int) wp_insert_post([
			'post_title'   => 'No code',
			'post_content' => '<p>Just prose.</p>',
			'post_status'  => 'publish',
		]);

		$this->go_to_single($post_id);
		$this->frontend->maybe_enqueue_for_query();

		$this->assertFalse($this->frontend->is_enqueued());
	}

	/**
	 * An archive view does not scan post_content, because it renders excerpts.
	 *
	 * @return void
	 */
	public function test_an_archive_view_does_not_enqueue_from_post_content(): void {
		$post_id = (int) wp_insert_post([
			'post_title'   => 'Has code',
			'post_content' => '<pre><code class="language-php">echo 1;</code></pre>',
			'post_status'  => 'publish',
		]);

		// A listing query, not a singular one: the code block lives in
		// post_content but the page renders only an excerpt.
		$GLOBALS['wp_query']     = new WP_Query(['post_type' => 'post']);
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];

		$this->frontend->maybe_enqueue_for_query();

		$this->assertFalse($this->frontend->is_enqueued(), 'Prism must not load for content that is not rendered.');
		$this->assertGreaterThan(0, $post_id);
	}

	/**
	 * Excerpt generation does not enqueue, though it runs the_content.
	 *
	 * wp_trim_excerpt() applies the_content to the full post body before
	 * trimming it, so a code block seen there may never reach the page.
	 *
	 * @return void
	 */
	public function test_excerpt_generation_does_not_enqueue(): void {
		$post_id = (int) wp_insert_post([
			'post_title'   => 'Has code',
			'post_content' => "Intro paragraph.\n\n<pre><code class=\"language-php\">echo 1;</code></pre>",
			'post_status'  => 'publish',
		]);

		add_filter('the_content', [$this->frontend, 'maybe_enqueue_for_content'], 20);
		get_the_excerpt($post_id);

		$this->assertFalse($this->frontend->is_enqueued(), 'Building an excerpt must not load Prism.');
	}

	/**
	 * Enqueueing twice does not register the handles twice.
	 *
	 * @return void
	 */
	public function test_enqueueing_is_idempotent(): void {
		$code = '<pre><code>x</code></pre>';

		$this->frontend->maybe_enqueue_for_content($code);
		$this->frontend->maybe_enqueue_for_content($code);

		$this->assertTrue($this->frontend->is_enqueued());
	}

	/**
	 * Point the main query at a single post, standing in for go_to().
	 *
	 * The WordPress test factories are not available in this harness, so the
	 * query is built directly rather than by simulating a request.
	 *
	 * @param int $post_id Post to query.
	 * @return void
	 */
	private function go_to_single(int $post_id): void {
		$GLOBALS['wp_query'] = new WP_Query([
			'p'         => $post_id,
			'post_type' => 'post',
		]);

		// is_singular() reads the global query, which maybe_enqueue_for_query()
		// gates on, so the fixture has to look like a real single post view.
		$GLOBALS['wp_query']->is_single    = true;
		$GLOBALS['wp_query']->is_singular  = true;
		$GLOBALS['wp_the_query']           = $GLOBALS['wp_query'];
	}
}
