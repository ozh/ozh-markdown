<?php
/**
 * REST endpoint that accepts a dropped or pasted image and attaches it.
 */

/**
 * Image upload endpoint.
 *
 * Deliberately narrow: one route, images only, and never wider than the MIME
 * types the site already allows. Uploads go through media_handle_upload(), so
 * WordPress owns the file handling, the attachment record and the sanitized
 * file name.
 */
class Ozhmd_Uploader {

	/**
	 * REST namespace for the plugin's routes.
	 */
	public const NAMESPACE = 'ozhmd/v1';

	/**
	 * Route accepting an uploaded image.
	 */
	public const ROUTE = '/upload';

	/**
	 * Register the REST hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action('rest_api_init', [$this, 'register_route']);
	}

	/**
	 * Register the upload route.
	 *
	 * @return void
	 */
	public function register_route(): void {
		register_rest_route(self::NAMESPACE, self::ROUTE, [
			'methods'             => 'POST',
			'callback'            => [$this, 'handle'],
			'permission_callback' => [$this, 'can_upload'],
			'args'                => [
				'post_id' => [
					'type'              => 'integer',
					'required'          => false,
					'default'           => 0,
					'sanitize_callback' => 'absint',
				],
			],
		]);
	}

	/**
	 * Decide whether the request may upload.
	 *
	 * Checks the general capability, and additionally the per post capability
	 * when a post ID is supplied. The post ID from the request is never
	 * trusted: a user who may upload files is not thereby allowed to attach
	 * them to somebody else's post.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return bool|WP_Error True when allowed, WP_Error otherwise.
	 */
	public function can_upload(WP_REST_Request $request) {
		if (!current_user_can('upload_files')) {
			return new WP_Error(
				'ozhmd_cannot_upload',
				__('You are not allowed to upload files.', 'ozh-markdown'),
				['status' => rest_authorization_required_code()]
			);
		}

		$post_id = (int) $request->get_param('post_id');

		if ($post_id > 0 && !current_user_can('edit_post', $post_id)) {
			return new WP_Error(
				'ozhmd_cannot_edit_post',
				__('You are not allowed to edit that post.', 'ozh-markdown'),
				['status' => rest_authorization_required_code()]
			);
		}

		return true;
	}

	/**
	 * Handle the upload and return the Markdown pieces the editor needs.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error Attachment details, or an error.
	 */
	public function handle(WP_REST_Request $request) {
		$files = $request->get_file_params();

		if (!isset($files['file'])) {
			return new WP_Error(
				'ozhmd_no_file',
				__('No file was uploaded.', 'ozh-markdown'),
				['status' => 400]
			);
		}

		$name = isset($files['file']['name']) ? (string) $files['file']['name'] : '';
		$type = $this->detect_mime($name);

		if (!ozhmd_is_allowed_image_mime($type, get_allowed_mime_types())) {
			return new WP_Error(
				'ozhmd_not_an_image',
				/* translators: %s: rejected MIME type. */
				sprintf(__('That file type is not an image this site accepts (%s).', 'ozh-markdown'), $type),
				['status' => 415]
			);
		}

		$post_id = (int) $request->get_param('post_id');

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_handle_upload('file', $post_id);

		if (is_wp_error($attachment_id)) {
			return new WP_Error(
				'ozhmd_upload_failed',
				$attachment_id->get_error_message(),
				['status' => 500]
			);
		}

		return new WP_REST_Response($this->payload((int) $attachment_id, $name), 201);
	}

	/**
	 * Build the response body for a stored attachment.
	 *
	 * @param int    $attachment_id Stored attachment.
	 * @param string $filename      Original file name, used for the alt text.
	 * @return array<string, mixed> Response body.
	 */
	private function payload(int $attachment_id, string $filename): array {
		$alt = ozhmd_alt_from_filename($filename);

		update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);

		return [
			'id'  => $attachment_id,
			'url' => (string) wp_get_attachment_url($attachment_id),
			'alt' => $alt,
		];
	}

	/**
	 * Determine the MIME type of an upload without trusting the browser.
	 *
	 * The type reported in the request is attacker controlled and is never
	 * consulted. Only wp_check_filetype() against the site's allowed types
	 * decides, so a name WordPress cannot classify yields an empty string and
	 * is refused.
	 *
	 * Falling back to the reported type would be a real hole: a request naming
	 * payload.php while claiming image/png would pass this check, since the
	 * extension is unclassifiable but the claimed type looks like an image.
	 *
	 * @param string $name Original file name.
	 * @return string MIME type, empty when the name is not an allowed type.
	 */
	private function detect_mime(string $name): string {
		$checked = wp_check_filetype($name, get_allowed_mime_types());

		return empty($checked['type']) ? '' : (string) $checked['type'];
	}
}
