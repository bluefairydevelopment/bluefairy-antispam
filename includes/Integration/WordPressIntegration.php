<?php
declare(strict_types=1);

namespace BlueFairy\AntiSpam\Integration;

use BlueFairy\AntiSpam\Logger;
use BlueFairy\AntiSpam\Settings;
use BlueFairy\AntiSpam\Sfs\SfsClient;
use BlueFairy\AntiSpam\Sfs\SfsReporter;
use BlueFairy\AntiSpam\Traps\Honeypot;
use BlueFairy\AntiSpam\Traps\TimeTrap;

/**
 * Hooks into core WordPress forms: comments and user registration.
 *
 * Check order: honeypot → time trap → SFS (short-circuit on first failure).
 */
class WordPressIntegration
{
    /**
     * BlueFairy Recipes Pro's review form prints this action inside its form,
     * and its comment type marks the reviews it inserts.
     */
    public const RECIPE_REVIEW_FIELDS_HOOK = 'bfrcp_review_form_fields';
    public const RECIPE_REVIEW_TYPE        = 'bfrc_review';

    private Logger      $logger;
    private Honeypot    $honeypot;
    private TimeTrap    $timetrap;
    private SfsClient   $sfs_client;
    private SfsReporter $sfs_reporter;

    public function __construct(Settings $settings, Logger $logger)
    {
        $this->logger       = $logger;
        $this->honeypot     = new Honeypot($settings);
        $this->timetrap     = new TimeTrap($settings);
        $this->sfs_client   = new SfsClient($settings);
        $this->sfs_reporter = new SfsReporter($settings, $logger);
    }

    /**
     * Register all WordPress front-end hooks.
     */
    public function register(): void
    {
        // Comment form.
        add_action('comment_form', [$this, 'render_comment_fields']);
        add_filter('pre_comment_approved', [$this, 'validate_comment'], 10, 2);
        add_filter('rest_pre_insert_comment', [$this, 'validate_rest_comment'], 10, 2);

        // BlueFairy Recipes Pro review form (posted to its own REST route, inserted by wp_new_comment()).
        add_action(self::RECIPE_REVIEW_FIELDS_HOOK, [$this, 'render_recipe_review_fields']);

        // Registration form.
        add_action('register_form', [$this, 'render_register_fields']);
        add_filter('registration_errors', [$this, 'validate_registration'], 10, 3);
    }

    /**
     * Register admin-only hooks (mark-as-spam → SFS reporting).
     */
    public function register_admin_hooks(): void
    {
        add_action('transition_comment_status', [$this, 'on_comment_marked_spam'], 10, 3);
    }

    /**
     * When an admin marks a comment as spam, log it and report to SFS.
     *
     * @param string      $new_status New comment status.
     * @param string      $old_status Previous comment status.
     * @param \WP_Comment $comment    The comment object.
     */
    public function on_comment_marked_spam(string $new_status, string $old_status, \WP_Comment $comment): void
    {
        if ($new_status !== 'spam' || $old_status === 'spam') {
            return;
        }

        $ip    = sanitize_text_field((string) $comment->comment_author_IP);
        $email = sanitize_email((string) $comment->comment_author_email);

        $log_id = $this->logger->log('manual_spam', 'wp_comment_admin', $ip, $email);
        $this->sfs_reporter->maybe_schedule_report($ip, $email, $log_id);
    }

    // -------------------------------------------------------------------------
    // Comment form
    // -------------------------------------------------------------------------

    /**
     * Render honeypot + time-trap fields in the comment form.
     * Skips on product pages — WooCommerceIntegration handles those.
     */
    public function render_comment_fields(): void
    {
        if (function_exists('is_product') && is_product()) {
            return;
        }

        $this->honeypot->render_field('wp_comment');
        $this->timetrap->render_fields('wp_comment');
    }

    /**
     * Render honeypot + time-trap fields in a BlueFairy Recipes Pro review form.
     */
    public function render_recipe_review_fields(): void
    {
        $this->honeypot->render_field('recipe_review');
        $this->timetrap->render_fields('recipe_review');
    }

    /**
     * Validate a traditional (non-REST) comment submission.
     *
     * @param int|string           $approved  Current approval status.
     * @param array<string, mixed> $comment_data Comment data array.
     * @return int|string|\WP_Error
     */
    public function validate_comment(mixed $approved, array $comment_data): mixed
    {
        if (is_user_logged_in()) {
            return $approved;
        }

        $ip    = $this->get_ip();
        $email = isset($comment_data['comment_author_email']) ? (string) $comment_data['comment_author_email'] : '';

        $form_type = $this->comment_form_type($comment_data);

        $spam_trap = $this->check_spam($form_type, $ip, $email);

        if ($spam_trap !== null) {
            $this->record_block($spam_trap, $form_type, $ip, $email);

            // Inside a REST request (a form that posts to its own route and inserts
            // with wp_new_comment()), refuse with a WP_Error: wp_new_comment() hands
            // it back to the route, which answers in JSON. wp_die() would send an
            // HTML page to a script expecting JSON.
            if ($this->is_rest_request()) {
                return new \WP_Error(
                    'bfas_spam',
                    $form_type === 'recipe_review'
                        ? __('Your review was blocked as spam. If this is wrong, please contact us.', 'bluefairy-antispam')
                        : __('Your comment was blocked as spam. If this is wrong, please contact us.', 'bluefairy-antispam'),
                    ['status' => 403]
                );
            }

            wp_die(
                esc_html__('Your comment was blocked as spam. If this is wrong, please contact us.', 'bluefairy-antispam'),
                esc_html__('Spam Detected', 'bluefairy-antispam'),
                ['response' => 403]
            );
        }

        return $approved;
    }

    /**
     * Validate a REST API comment submission.
     *
     * @param array<string, mixed>|\WP_Error $prepared_comment
     * @param \WP_REST_Request<array<string, mixed>> $request
     * @return array<string, mixed>|\WP_Error
     */
    public function validate_rest_comment(mixed $prepared_comment, \WP_REST_Request $request): mixed
    {
        if (is_user_logged_in()) {
            return $prepared_comment;
        }

        $ip    = $this->get_ip();
        $email = sanitize_email((string) $request->get_param('author_email'));

        $spam_trap = $this->check_spam_sfs_only('wp_comment_rest', $ip, $email);

        if ($spam_trap !== null) {
            $this->record_block($spam_trap, 'wp_comment_rest', $ip, $email);

            return new \WP_Error(
                'bfas_spam',
                __('Your comment was blocked as spam. If this is wrong, please contact us.', 'bluefairy-antispam'),
                ['status' => 403]
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
    public function render_register_fields(): void
    {
        $this->honeypot->render_field('wp_register');
        $this->timetrap->render_fields('wp_register');
    }

    /**
     * Validate a registration form submission.
     *
     * @param \WP_Error $errors     Current validation errors.
     * @param string    $sanitized_user_login
     * @param string    $user_email
     */
    public function validate_registration(\WP_Error $errors, string $sanitized_user_login, string $user_email): \WP_Error
    {
        if (is_user_logged_in()) {
            return $errors;
        }

        $ip        = $this->get_ip();
        $spam_trap = $this->check_spam('wp_register', $ip, $user_email);

        if ($spam_trap !== null) {
            $this->record_block($spam_trap, 'wp_register', $ip, $user_email);
            $errors->add(
                'bfas_spam',
                __('<strong>Error:</strong> Your registration was blocked as spam. If this is wrong, please contact us.', 'bluefairy-antispam')
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
     */
    private function check_spam(string $form_type, string $ip, string $email): ?string
    {
        if ($this->honeypot->is_spam($_POST, $form_type)) { // phpcs:ignore WordPress.Security.NonceVerification
            return 'honeypot';
        }

        if ($this->timetrap->is_spam($_POST, $form_type)) { // phpcs:ignore WordPress.Security.NonceVerification
            return 'timetrap';
        }

        if ($this->sfs_client->is_spammer($ip, $email)) {
            return 'sfs';
        }

        return null;
    }

    /**
     * SFS-only check for REST submissions (no POST fields available for trap checks).
     * Returns the trap name that fired, or null if clean.
     */
    private function check_spam_sfs_only(string $form_type, string $ip, string $email): ?string
    {
        if ($this->sfs_client->is_spammer($ip, $email)) {
            return 'sfs';
        }

        return null;
    }

    /**
     * Which form a comment came from, so the matching token field is checked.
     *
     * @param array<string, mixed> $comment_data Comment data array.
     */
    private function comment_form_type(array $comment_data): string
    {
        if (($comment_data['comment_type'] ?? '') === self::RECIPE_REVIEW_TYPE) {
            return 'recipe_review';
        }

        // WC reviews are submitted on product pages — use the wc_review form type
        // so the correct token field (bfas_tk_wc_review) is checked.
        $post = get_post($comment_data['comment_post_ID'] ?? 0);

        return ($post && $post->post_type === 'product') ? 'wc_review' : 'wp_comment';
    }

    /**
     * Whether this request is a REST API request.
     */
    private function is_rest_request(): bool
    {
        if (function_exists('wp_is_serving_rest_request')) {
            return wp_is_serving_rest_request();
        }

        return defined('REST_REQUEST') && REST_REQUEST;
    }

    /**
     * Log the block and optionally schedule an SFS report.
     */
    private function record_block(string $trap, string $form, string $ip, string $email): void
    {
        $log_id = $this->logger->log($trap, $form, $ip, $email);

        if ($trap === 'sfs') {
            $this->sfs_reporter->maybe_schedule_report($ip, $email, $log_id);
        }
    }

    /**
     * Get the visitor's IP address.
     */
    private function get_ip(): string
    {
        // Trust only REMOTE_ADDR — do not use X-Forwarded-For without proxy config.
        return sanitize_text_field(wp_unslash((string) ($_SERVER['REMOTE_ADDR'] ?? '')));
    }
}
