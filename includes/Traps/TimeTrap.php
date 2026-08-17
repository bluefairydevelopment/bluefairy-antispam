<?php
declare(strict_types=1);

namespace BlueFairy\AntiSpam\Traps;

use BlueFairy\AntiSpam\Settings;

/**
 * Time-trap — blocks submissions that arrive faster than a human can type.
 *
 * Token format (base64-encoded): nonce|timestamp|HMAC-SHA256
 * The HMAC covers nonce:timestamp:form_type so the token is tamper-evident
 * and form-scoped. Tokens expire after 24 hours.
 */
class TimeTrap
{
    private Settings $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Render a hidden token field into the form.
     */
    public function render_fields(string $form_type): void
    {
        if (! (bool) $this->settings->get('timetrap_enabled', true)) {
            return;
        }

        $nonce     = wp_create_nonce('bfas_tt_' . $form_type);
        $timestamp = time();
        $hmac      = hash_hmac(
            'sha256',
            $nonce . ':' . $timestamp . ':' . $form_type,
            $this->get_secret()
        );
        $token = base64_encode($nonce . '|' . $timestamp . '|' . $hmac);

        echo '<input type="hidden" name="bfas_tk_' . esc_attr($form_type) . '" value="' . esc_attr($token) . '">';
    }

    /**
     * Verify the time-trap token from POST data.
     *
     * Returns true when spam is detected (too fast or expired / tampered).
     * Returns false when the submission is clean.
     *
     * @param array<string, mixed> $post_data  $_POST data.
     * @param string               $form_type  The form identifier.
     */
    public function is_spam(array $post_data, string $form_type): bool
    {
        if (is_user_logged_in()) {
            return false;
        }

        if (! (bool) $this->settings->get('timetrap_enabled', true)) {
            return false;
        }

        $field = 'bfas_tk_' . $form_type;
        $token = isset($post_data[$field]) ? (string) $post_data[$field] : '';

        if ($token === '') {
            // No token present — treat as spam (field was removed by bot).
            return true;
        }

        return $this->verify_token($token, $form_type);
    }

    /**
     * Verify a raw token string.
     *
     * Returns true when spam (invalid, too fast, or expired).
     * Returns false when clean.
     */
    private function verify_token(string $token, string $form_type): bool
    {
        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            return true; // malformed = spam
        }

        $parts = explode('|', $decoded, 3);
        if (count($parts) !== 3) {
            return true; // malformed = spam
        }

        [$nonce, $timestamp_str, $hmac] = $parts;
        $timestamp = (int) $timestamp_str;

        $expected = hash_hmac(
            'sha256',
            $nonce . ':' . $timestamp . ':' . $form_type,
            $this->get_secret()
        );

        if (! hash_equals($expected, $hmac)) {
            return true; // invalid signature = spam
        }

        $elapsed = time() - $timestamp;
        $min     = (int) $this->settings->get('min_time_seconds', 3);

        if ($elapsed < $min) {
            return true; // too fast = spam
        }

        if ($elapsed > 86400) {
            return true; // token expired = spam
        }

        return false; // passes
    }

    /**
     * Retrieve the HMAC secret from the non-autoloaded option.
     */
    private function get_secret(): string
    {
        return $this->settings->get_hmac_secret();
    }
}
