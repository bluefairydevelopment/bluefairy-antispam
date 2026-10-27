<?php
/**
 * Hooks into core WordPress forms: comments and user registration.
 *
 * @package BlueFairy\AntiSpam
 */

declare(strict_types=1);

namespace BlueFairy\AntiSpam\Integration;

use BlueFairy\AntiSpam\Logger;
use BlueFairy\AntiSpam\Settings;
use BlueFairy\AntiSpam\Sfs\SfsClient;
use BlueFairy\AntiSpam\Sfs\SfsReporter;
use BlueFairy\AntiSpam\Traps\Honeypot;
use BlueFairy\AntiSpam\Traps\TimeTrap;
use BlueFairy\AntiSpam\VisitorIp;

/**
 * Hooks into core WordPress forms: comments and user registration.
 *
 * Check order: honeypot → time trap → SFS (short-circuit on first failure).
 */
class WordPressIntegration {

	/**
	 * BlueFairy Recipes Pro's review form prints this action inside its form,
	 * and its comment type marks the reviews it inserts.
	 */
	public const RECIPE_REVIEW_FIELDS_HOOK = 'bfrcp_review_form_fields';
	public const RECIPE_REVIEW_TYPE        = 'bfrc_review';

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Block-event logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Honeypot trap.
	 *
	 * @var Honeypot
	 */
	private Honeypot $honeypot;

	/**
	 * Time trap.
	 *
	 * @var TimeTrap
	 */
	private TimeTrap $timetrap;

	/**
	 * Stop Forum Spam lookup client.
	 *
	 * @var SfsClient
	 */
	private SfsClient $sfs_client;

	/**
	 * Stop Forum Spam reporter.
	 *
	 * @var SfsReporter
	 */
	private SfsReporter $sfs_reporter;

	/**
	 * The request of a comment core's comments API (/wp/v2/comments) is creating,
	 * from rest_preprocess_comment until pre_comment_approved reads it.
	 *
	 * @var \WP_REST_Request<array<string, mixed>>|null
	 */
	private ?\WP_REST_Request $core_api_request = null;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Plugin settings.
	 * @param Logger   $logger   Block-event logger.
	 */
	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings     = $settings;
		$this->logger       = $logger;
		$this->honeypot     = new Honeypot( $settings );
		$this->timetrap     = new TimeTrap( $settings );
		$this->sfs_client   = new SfsClient( $settings );
		$this->sfs_reporter = new SfsReporter( $settings, $logger );
	}

	/**
	 * Register all WordPress front-end hooks.
	 */
	public function register(): void {
		// Comment form.
		add_action( 'comment_form', [ $this, 'render_comment_fields' ] );
		add_filter( 'pre_comment_approved', [ $this, 'validate_comment' ], 10, 2 );
		add_filter( 'rest_pre_insert_comment', [ $this, 'validate_rest_comment' ], 10, 2 );
		add_filter( 'rest_preprocess_comment', [ $this, 'remember_core_rest_comment' ], 10, 2 );
		add_filter( 'rest_post_dispatch', [ $this, 'forget_core_rest_comment' ] );

		// BlueFairy Recipes Pro review form (posted to its own REST route, inserted by wp_new_comment()).
		add_action( self::RECIPE_REVIEW_FIELDS_HOOK, [ $this, 'render_recipe_review_fields' ] );

		// Registration form.
		add_action( 'register_form', [ $this, 'render_register_fields' ] );
		add_filter( 'registration_errors', [ $this, 'validate_registration' ], 10, 3 );
	}

	/**
	 * Register admin-only hooks (mark-as-spam → SFS reporting).
	 */
	public function register_admin_hooks(): void {
		add_action( 'transition_comment_status', [ $this, 'on_comment_marked_spam' ], 10, 3 );
	}

	/**
	 * When an admin marks a comment as spam, log it and report to SFS.
	 *
	 * @param string      $new_status New comment status.
	 * @param string      $old_status Previous comment status.
	 * @param \WP_Comment $comment    The comment object.
	 */
	public function on_comment_marked_spam( string $new_status, string $old_status, \WP_Comment $comment ): void {
		if ( 'spam' !== $new_status || 'spam' === $old_status ) {
			return;
		}

		$ip    = sanitize_text_field( (string) $comment->comment_author_IP );
		$email = sanitize_email( (string) $comment->comment_author_email );

		$log_id = $this->logger->log( 'manual_spam', 'wp_comment_admin', $ip, $email );
		$this->sfs_reporter->maybe_schedule_report( $ip, $email, $log_id );
	}

	// -------------------------------------------------------------------------
	// Comment form
	// -------------------------------------------------------------------------

	/**
	 * Render honeypot + time-trap fields in the comment form.
	 * Skips on product pages — WooCommerceIntegration handles those.
	 */
	public function render_comment_fields(): void {
		if ( function_exists( 'is_product' ) && is_product() ) {
			return;
		}

		$this->honeypot->render_field( 'wp_comment' );
		$this->timetrap->render_fields( 'wp_comment' );
	}

	/**
	 * Render honeypot + time-trap fields in a BlueFairy Recipes Pro review form.
	 */
	public function render_recipe_review_fields(): void {
		$this->honeypot->render_field( 'recipe_review' );
		$this->timetrap->render_fields( 'recipe_review' );
	}

	/**
	 * Validate a traditional (non-REST) comment submission.
	 *
	 * @param int|string|\WP_Error $approved     Current approval status.
	 * @param array<string, mixed> $comment_data Comment data array.
	 * @return int|string|\WP_Error
	 */
	public function validate_comment( mixed $approved, array $comment_data ): mixed {
		// Taken first, so a logged-in comment cannot leave it for the next one.
		$core_api_request = $this->take_core_api_request();

		// Another filter already refused the comment: keep its error.
		if ( is_wp_error( $approved ) ) {
			return $approved;
		}

		if ( is_user_logged_in() ) {
			return $approved;
		}

		$ip    = $this->get_ip();
		$email = isset( $comment_data['comment_author_email'] ) ? (string) $comment_data['comment_author_email'] : '';

		$form_type = $this->comment_form_type( $comment_data );

		if ( null !== $core_api_request ) {
			return $this->validate_core_api_comment( $approved, $form_type, $ip, $email, $core_api_request );
		}

		$spam_trap = $this->check_spam( $form_type, $ip, $email );

		if ( null !== $spam_trap ) {
			$this->record_block( $spam_trap, $form_type, $ip, $email );

			// Inside a REST request (a form that posts to its own route and inserts
			// with wp_new_comment()), refuse with a WP_Error: wp_new_comment() hands
			// it back to the route, which answers in JSON. wp_die() would send an
			// HTML page to a script expecting JSON.
			if ( $this->is_rest_request() ) {
				return new \WP_Error(
					'bfas_spam',
					'recipe_review' === $form_type
						? __( 'Your review was blocked as spam. If this is wrong, please contact us.', 'bluefairy-antispam' )
						: __( 'Your comment was blocked as spam. If this is wrong, please contact us.', 'bluefairy-antispam' ),
					[ 'status' => 403 ]
				);
			}

			wp_die(
				esc_html__( 'Your comment was blocked as spam. If this is wrong, please contact us.', 'bluefairy-antispam' ),
				esc_html__( 'Spam Detected', 'bluefairy-antispam' ),
				[ 'response' => 403 ]
			);
		}

		return $approved;
	}

	/**
	 * Remember the request of a comment core's comments API is creating.
	 *
	 * Core's comments controller fires rest_preprocess_comment before it runs
	 * wp_allow_comment(), so validate_comment() can tell a /wp/v2/comments
	 * comment from one a form inserts with wp_new_comment() inside its own
	 * REST route (the recipe review route). Only core's controller fires this
	 * filter.
	 *
	 * @param array<string, mixed> $prepared_comment The comment data core prepared.
	 * @param \WP_REST_Request     $request          The REST request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return array<string, mixed>
	 */
	public function remember_core_rest_comment( array $prepared_comment, \WP_REST_Request $request ): array {
		$this->core_api_request = $request;

		return $prepared_comment;
	}

	/**
	 * Forget the remembered comments API request once its response is ready.
	 *
	 * Core's comments controller can stop after rest_preprocess_comment without
	 * creating the comment (a 400 for a missing author, too long content and so
	 * on). Forgetting the request when every REST response is dispatched stops
	 * a later wp_new_comment() in the same PHP process being taken for it.
	 *
	 * @param mixed $response The REST response.
	 * @return mixed
	 */
	public function forget_core_rest_comment( mixed $response ): mixed {
		$this->core_api_request = null;

		return $response;
	}

	/**
	 * Validate a guest comment sent through core's comments API.
	 *
	 * That API carries no form, so a missing token is not proof of a bot. The
	 * checks that need no form still refuse outright: a filled honeypot, a
	 * token that was sent but is forged, too fast or expired, and a Stop Forum
	 * Spam listing. A comment that passes them but has no token is held for
	 * moderation, never published straight away. Core's own checks (flood,
	 * duplicates, disallowed and moderation keys, the links limit) have already
	 * run in wp_allow_comment().
	 *
	 * @param int|string       $approved  Core's decision so far.
	 * @param string           $form_type Form identifier, such as wp_comment or wc_review.
	 * @param string           $ip        Visitor IP address.
	 * @param string           $email     Submitted e-mail address.
	 * @param \WP_REST_Request $request   The REST request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return int|string|\WP_Error
	 */
	private function validate_core_api_comment( mixed $approved, string $form_type, string $ip, string $email, \WP_REST_Request $request ): mixed {
		$params    = $request->get_params();
		$has_token = isset( $params[ 'bfas_tk_' . $form_type ] );

		$spam_trap = null;
		if ( $this->honeypot->is_spam( $params, $form_type ) ) {
			$spam_trap = 'honeypot';
		} elseif ( $has_token && $this->timetrap->is_spam( $params, $form_type ) ) {
			$spam_trap = 'timetrap';
		} elseif ( $this->sfs_client->is_spammer( $ip, $email ) ) {
			$spam_trap = 'sfs';
		}

		if ( null !== $spam_trap ) {
			$this->record_block( $spam_trap, 'wp_comment_rest', $ip, $email );

			return new \WP_Error(
				'bfas_spam',
				__( 'Your comment was blocked as spam. If this is wrong, please contact us.', 'bluefairy-antispam' ),
				[ 'status' => 403 ]
			);
		}

		// No token, and the time trap would have wanted one: hold it, unless
		// core already decided something stricter (spam or trash).
		if ( ! $has_token && $this->timetrap->is_spam( [], $form_type ) && ! in_array( $approved, [ 'spam', 'trash' ], true ) ) {
			return 0;
		}

		return $approved;
	}

	/**
	 * The remembered core comments API request, once, and only inside REST.
	 *
	 * @return \WP_REST_Request<array<string, mixed>>|null
	 */
	private function take_core_api_request(): ?\WP_REST_Request {
		$request                = $this->core_api_request;
		$this->core_api_request = null;

		return ( null !== $request && $this->is_rest_request() ) ? $request : null;
	}

	/**
	 * Validate a REST API comment submission.
	 *
	 * @param array<string, mixed>|\WP_Error $prepared_comment The comment data core prepared.
	 * @param \WP_REST_Request               $request          The REST request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return array<string, mixed>|\WP_Error
	 */
	public function validate_rest_comment( mixed $prepared_comment, \WP_REST_Request $request ): mixed {
		if ( is_user_logged_in() ) {
			return $prepared_comment;
		}

		$ip    = $this->get_ip();
		$email = sanitize_email( (string) $request->get_param( 'author_email' ) );

		$spam_trap = $this->check_spam_sfs_only( 'wp_comment_rest', $ip, $email );

		if ( null !== $spam_trap ) {
			$this->record_block( $spam_trap, 'wp_comment_rest', $ip, $email );

			return new \WP_Error(
				'bfas_spam',
				__( 'Your comment was blocked as spam. If this is wrong, please contact us.', 'bluefairy-antispam' ),
				[ 'status' => 403 ]
			);
		}

		return $prepared_comment;
	}

	// -------------------------------------------------------------------------
	// Registration form
	// -------------------------------------------------------------------------

	/**
	 * Render honeypot + time-trap fields in the registration form.
	 */
	public function render_register_fields(): void {
		$this->honeypot->render_field( 'wp_register' );
		$this->timetrap->render_fields( 'wp_register' );
	}

	/**
	 * Validate a registration form submission.
	 *
	 * @param \WP_Error $errors               Current validation errors.
	 * @param string    $sanitized_user_login Submitted username.
	 * @param string    $user_email           Submitted e-mail address.
	 */
	public function validate_registration( \WP_Error $errors, string $sanitized_user_login, string $user_email ): \WP_Error {
		if ( is_user_logged_in() ) {
			return $errors;
		}

		$ip        = $this->get_ip();
		$spam_trap = $this->check_spam( 'wp_register', $ip, $user_email );

		if ( null !== $spam_trap ) {
			$this->record_block( $spam_trap, 'wp_register', $ip, $user_email );
			$errors->add(
				'bfas_spam',
				__( '<strong>Error:</strong> Your registration was blocked as spam. If this is wrong, please contact us.', 'bluefairy-antispam' )
			);
		}

		return $errors;
	}

	// -------------------------------------------------------------------------
	// Shared helpers
	// -------------------------------------------------------------------------

	/**
	 * Run all checks in order: honeypot → time trap → SFS.
	 * Returns the trap name that fired, or null if clean.
	 *
	 * @param string $form_type Form identifier, such as wp_comment or wc_review.
	 * @param string $ip        Visitor IP address.
	 * @param string $email     Submitted e-mail address.
	 */
	private function check_spam( string $form_type, string $ip, string $email ): ?string {
		if ( $this->honeypot->is_spam( $_POST, $form_type ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return 'honeypot';
		}

		if ( $this->timetrap->is_spam( $_POST, $form_type ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return 'timetrap';
		}

		if ( $this->sfs_client->is_spammer( $ip, $email ) ) {
			return 'sfs';
		}

		return null;
	}

	/**
	 * SFS-only check for REST submissions (no POST fields available for trap checks).
	 * Returns the trap name that fired, or null if clean.
	 *
	 * @param string $form_type Form identifier, such as wp_comment or wc_review.
	 * @param string $ip        Visitor IP address.
	 * @param string $email     Submitted e-mail address.
	 */
	private function check_spam_sfs_only( string $form_type, string $ip, string $email ): ?string {
		if ( $this->sfs_client->is_spammer( $ip, $email ) ) {
			return 'sfs';
		}

		return null;
	}

	/**
	 * Which form a comment came from, so the matching token field is checked.
	 *
	 * @param array<string, mixed> $comment_data Comment data array.
	 */
	private function comment_form_type( array $comment_data ): string {
		if ( ( $comment_data['comment_type'] ?? '' ) === self::RECIPE_REVIEW_TYPE ) {
			return 'recipe_review';
		}

		// WC reviews are submitted on product pages — use the wc_review form type
		// so the correct token field (bfas_tk_wc_review) is checked.
		$post = get_post( $comment_data['comment_post_ID'] ?? 0 );

		return ( $post && 'product' === $post->post_type ) ? 'wc_review' : 'wp_comment';
	}

	/**
	 * Whether this request is a REST API request.
	 */
	private function is_rest_request(): bool {
		if ( function_exists( 'wp_is_serving_rest_request' ) ) {
			return wp_is_serving_rest_request();
		}

		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	/**
	 * Log the block and optionally schedule an SFS report.
	 *
	 * @param string $trap  Name of the trap that fired.
	 * @param string $form  Form identifier recorded in the log.
	 * @param string $ip    Visitor IP address.
	 * @param string $email Submitted e-mail address.
	 */
	private function record_block( string $trap, string $form, string $ip, string $email ): void {
		$log_id = $this->logger->log( $trap, $form, $ip, $email );

		if ( 'sfs' === $trap ) {
			$this->sfs_reporter->maybe_schedule_report( $ip, $email, $log_id );
		}
	}

	/**
	 * Get the visitor's IP address.
	 */
	private function get_ip(): string {
		return VisitorIp::get( $this->settings );
	}
}
