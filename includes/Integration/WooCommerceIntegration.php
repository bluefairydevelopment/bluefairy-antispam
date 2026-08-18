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
 * Hooks into WooCommerce forms: reviews, My Account registration,
 * shortcode checkout, and Block checkout.
 *
 * Check order: honeypot → time trap → SFS (short-circuit on first failure).
 * All checks bypass logged-in users.
 */
class WooCommerceIntegration
{
    private Settings    $settings;
    private Logger      $logger;
    private Honeypot    $honeypot;
    private TimeTrap    $timetrap;
    private SfsClient   $sfs_client;
    private SfsReporter $sfs_reporter;

    public function __construct(Settings $settings, Logger $logger)
    {
        $this->settings     = $settings;
        $this->logger       = $logger;
        $this->honeypot     = new Honeypot($settings);
        $this->timetrap     = new TimeTrap($settings);
        $this->sfs_client   = new SfsClient($settings);
        $this->sfs_reporter = new SfsReporter($settings, $logger);
    }

    /**
     * Register all WooCommerce hooks.
     */
    public function register(): void
    {
        // Product reviews — use comment_form action (fired by WC's call to comment_form()),
        // guarded with is_product() so it only fires on product pages.
        add_action('comment_form', [$this, 'render_review_fields']);

        // My Account registration.
        add_action('woocommerce_register_form', [$this, 'render_wc_register_fields']);
        add_filter('woocommerce_registration_errors', [$this, 'validate_wc_registration'], 10, 3);

        // Shortcode checkout.
        add_action('woocommerce_checkout_after_order_notes', [$this, 'render_checkout_fields']);
        add_action('woocommerce_checkout_process', [$this, 'validate_checkout']);

        // Block checkout (Store API).
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'validate_block_checkout']);
    }

    // -------------------------------------------------------------------------
    // Product reviews
    // -------------------------------------------------------------------------

    /**
     * Render trap fields inside the WooCommerce review form.
     * Fires on the comment_form action; only runs on product pages.
     */
    public function render_review_fields(): void
    {
        if (! function_exists('is_product') || ! is_product()) {
            return;
        }

        $this->honeypot->render_field('wc_review');
        $this->timetrap->render_fields('wc_review');
    }

    // -------------------------------------------------------------------------
    // My Account registration
    // -------------------------------------------------------------------------

    /**
     * Render trap fields inside the WooCommerce My Account registration form.
     */
    public function render_wc_register_fields(): void
    {
        $this->honeypot->render_field('wc_register');
        $this->timetrap->render_fields('wc_register');
    }

    /**
     * Validate a WooCommerce My Account registration submission.
     *
     * @param \WP_Error $errors
     * @param string    $username
     * @param string    $email
     */
    public function validate_wc_registration(\WP_Error $errors, string $username, string $email): \WP_Error
    {
        if (is_user_logged_in()) {
            return $errors;
        }

        $ip        = $this->get_ip();
        $spam_trap = $this->check_spam('wc_register', $ip, $email);

        if ($spam_trap !== null) {
            $this->record_block($spam_trap, 'wc_register', $ip, $email);
            $errors->add(
                'bfas_spam',
                __('<strong>Error:</strong> Your registration was blocked as spam. If this is wrong, please contact us.', 'bluefairy-antispam')
            );
        }

        return $errors;
    }

    // -------------------------------------------------------------------------
    // Shortcode checkout
    // -------------------------------------------------------------------------

    /**
     * Render trap fields at the end of the shortcode checkout form.
     */
    public function render_checkout_fields(): void
    {
        if (is_user_logged_in()) {
            return;
        }

        $this->honeypot->render_field('wc_checkout');
        $this->timetrap->render_fields('wc_checkout');
    }

    /**
     * Validate the shortcode checkout submission.
     * Adds a WooCommerce notice on failure (does not die).
     */
    public function validate_checkout(): void
    {
        if (is_user_logged_in()) {
            return;
        }

        $ip    = $this->get_ip();
        $email = isset($_POST['billing_email']) ? sanitize_email(wp_unslash((string) $_POST['billing_email'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        $spam_trap = $this->check_spam('wc_checkout', $ip, $email);

        if ($spam_trap !== null) {
            $this->record_block($spam_trap, 'wc_checkout', $ip, $email);
            wc_add_notice(
                __('Your order was blocked as spam. If this is wrong, please contact us.', 'bluefairy-antispam'),
                'error'
            );
        }
    }

    // -------------------------------------------------------------------------
    // Block checkout (Store API)
    // -------------------------------------------------------------------------

    /**
     * Validate a WooCommerce Block checkout order.
     * Throws a RouteException (WC Store API mechanism) on failure.
     *
     * @param \WC_Order $order
     */
    public function validate_block_checkout(\WC_Order $order): void
    {
        if (is_user_logged_in()) {
            return;
        }

        $ip    = $this->get_ip();
        $email = $order->get_billing_email();

        // Block checkout sends JSON — POST-based trap fields are not available.
        // Apply SFS check only.
        $spam_trap = $this->sfs_client->is_spammer($ip, $email) ? 'sfs' : null;

        if ($spam_trap !== null) {
            $this->record_block($spam_trap, 'wc_checkout_block', $ip, $email);

            if (class_exists(\Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class)) {
                throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
                    'bfas_spam',
                    esc_html__('Your order was blocked as spam. If this is wrong, please contact us.', 'bluefairy-antispam'),
                    400
                );
            }
        }
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
     * Trusts only REMOTE_ADDR — never X-Forwarded-For without explicit proxy config.
     */
    private function get_ip(): string
    {
        return sanitize_text_field(wp_unslash((string) ($_SERVER['REMOTE_ADDR'] ?? '')));
    }
}
