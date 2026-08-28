<?php
/**
 * Settings page under Settings > Ozh' Markdown.
 */

/**
 * Plugin options page.
 *
 * All three toggles live in one option array. There are no per-post-type
 * checkboxes by design: fine-grained control is the ozhmd_excluded_post_types
 * filter, and the page shows the resolved list read-only.
 */
class Ozhmd_Settings {

	/**
	 * Name of the single option array holding every setting.
	 */
	public const OPTION_NAME = 'ozhmd_options';

	/**
	 * Resolver for the post types currently handled.
	 *
	 * @var Ozhmd_Post_Types
	 */
	private Ozhmd_Post_Types $post_types;

	/**
	 * Store the injected post type resolver.
	 *
	 * @param Ozhmd_Post_Types $post_types Eligibility resolver.
	 */
	public function __construct(Ozhmd_Post_Types $post_types) {
		$this->post_types = $post_types;
	}

	/**
	 * Register the admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action('admin_menu', [$this, 'add_page']);
		add_action('admin_init', [$this, 'register_settings']);
	}

	/**
	 * Add the options page to the Settings menu.
	 *
	 * @return void
	 */
	public function add_page(): void {
		add_options_page(
			__("Ozh' Markdown", 'ozh-markdown'),
			__("Ozh' Markdown", 'ozh-markdown'),
			'manage_options',
			'ozh-markdown',
			[$this, 'render_page']
		);
	}

	/**
	 * Register the setting, its section and its fields.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_NAME,
			self::OPTION_NAME,
			[
				'type'              => 'array',
				'sanitize_callback' => [$this, 'sanitize'],
				'default'           => ozhmd_default_options(),
			]
		);

		add_settings_section(
			'ozhmd_main',
			__('Behaviour', 'ozh-markdown'),
			'__return_false',
			'ozh-markdown'
		);

		$fields = [
			'comments_enabled'   => __('Enable Markdown for comments', 'ozh-markdown'),
			'comment_preview'    => __('Show a live preview under the comment form', 'ozh-markdown'),
			'prism_line_numbers' => __('Show line numbers on code blocks', 'ozh-markdown'),
		];

		foreach ($fields as $key => $label) {
			add_settings_field(
				$key,
				$label,
				[$this, 'render_checkbox'],
				'ozh-markdown',
				'ozhmd_main',
				['key' => $key, 'label' => $label]
			);
		}
	}

	/**
	 * Sanitize a submitted settings payload.
	 *
	 * @param mixed $input Raw submitted value, of any type.
	 * @return array<string, bool> Sanitized options.
	 */
	public function sanitize($input): array {
		return ozhmd_sanitize_options((array) $input, ozhmd_default_options());
	}

	/**
	 * Read one option, falling back to its default.
	 *
	 * Tolerates a missing, corrupted or partial stored value, so a hand-edited
	 * option row cannot break the plugin.
	 *
	 * @param string $key Option key.
	 * @return bool Option value, false for an unknown key.
	 */
	public function option(string $key): bool {
		$defaults = ozhmd_default_options();

		if (!array_key_exists($key, $defaults)) {
			return false;
		}

		$stored = get_option(self::OPTION_NAME, []);

		if (!is_array($stored) || !array_key_exists($key, $stored)) {
			return $defaults[$key];
		}

		return (bool) $stored[$key];
	}

	/**
	 * Render one checkbox field.
	 *
	 * @param array{key: string, label: string} $args Field arguments.
	 * @return void
	 */
	public function render_checkbox(array $args): void {
		printf(
			'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
			esc_attr(self::OPTION_NAME),
			esc_attr($args['key']),
			checked($this->option($args['key']), true, false),
			esc_html($args['label'])
		);
	}

	/**
	 * Render the options page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__("Ozh' Markdown", 'ozh-markdown') . '</h1>';

		echo '<form action="options.php" method="post">';
		settings_fields(self::OPTION_NAME);
		do_settings_sections('ozh-markdown');
		submit_button();
		echo '</form>';

		$this->render_post_types();

		echo '</div>';
	}

	/**
	 * Render the read-only list of post types currently handled.
	 *
	 * @return void
	 */
	public function render_post_types(): void {
		echo '<h2>' . esc_html__('Post types using the Markdown editor', 'ozh-markdown') . '</h2>';
		echo '<p>' . esc_html__('Change this list with the ozhmd_excluded_post_types filter.', 'ozh-markdown') . '</p>';
		echo '<ul>';

		foreach ($this->post_types->eligible() as $post_type) {
			echo '<li><code>' . esc_html($post_type) . '</code></li>';
		}

		echo '</ul>';
	}
}
