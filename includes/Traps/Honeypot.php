<?php
declare(strict_types=1);

namespace BlueFairy\AntiSpam\Traps;

use BlueFairy\AntiSpam\Settings;

/**
 * Honeypot trap — renders an invisible field that only bots fill in.
 *
 * Field name is chosen deterministically from a pool of 8 realistic-sounding
 * names based on the form type, so it varies per form but never changes between
 * render and validate for the same form.
 *
 * CSS (not inline style) hides the wrapper. aria-hidden + tabindex prevent
 * assistive-technology users from interacting with it.
 */
class Honeypot
{
    /** @var string[] */
    private const FIELD_POOL = [
        'website2',
        'address_confirm',
        'phone_verify',
        'url_field',
        'email_confirm2',
        'contact_url',
        'homepage_field',
        'site_address',
    ];

    private Settings $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Return the honeypot field name for a given form type.
     */
    public function field_name(string $form_type): string
    {
        $index = abs(crc32($form_type)) % 8;

        return self::FIELD_POOL[$index];
    }

    /**
     * Render the honeypot wrapper + input into the form.
     */
    public function render_field(string $form_type): void
    {
        if (! (bool) $this->settings->get('honeypot_enabled', true)) {
            return;
        }

        $name = esc_attr($this->field_name($form_type));
        echo '<div class="bfas-hp-wrap" aria-hidden="true">';
        echo '<label for="bfas_' . $name . '">' . esc_html__('Leave this field empty', 'bluefairy-antispam') . '</label>';
        echo '<input type="text" id="bfas_' . $name . '" name="' . $name . '" value="" class="bfas-hp-field" aria-hidden="true" tabindex="-1" autocomplete="off">';
        echo '</div>';
    }

    /**
     * Check whether the honeypot was filled in.
     *
     * Returns true when spam is detected.
     *
     * @param array<string, mixed> $post_data  $_POST data.
     * @param string               $form_type  The form identifier.
     */
    public function is_spam(array $post_data, string $form_type): bool
    {
        if (is_user_logged_in()) {
            return false;
        }

        if (! (bool) $this->settings->get('honeypot_enabled', true)) {
            return false;
        }

        $field = $this->field_name($form_type);

        // Spam if the field is present AND non-empty.
        if (isset($post_data[$field]) && trim((string) $post_data[$field]) !== '') {
            return true;
        }

        return false;
    }
}
