<?php
declare(strict_types=1);

namespace BlueFairy\AntiSpam\Sfs;

use BlueFairy\AntiSpam\Logger;
use BlueFairy\AntiSpam\Settings;

/**
 * Asynchronously reports confirmed spammers to Stop Forum Spam.
 *
 * Reporting is deduped via a 24-hour transient so the same IP+email is
 * never reported twice within a day. Reports are scheduled via WP-Cron
 * to avoid adding latency to the blocking response.
 */
class SfsReporter
{
    private Settings $settings;
    private Logger $logger;

    public function __construct(Settings $settings, Logger $logger)
    {
        $this->settings = $settings;
        $this->logger   = $logger;
    }

    /**
     * Register the async action hook for scheduled reporting.
     */
    public function register(): void
    {
        add_action('bfas_sfs_report_event', [$this, 'do_report'], 10, 3);
    }

    /**
     * Schedule a report if SFS is enabled and reporting has not been done recently.
     *
     * @param string $ip         Visitor IP address.
     * @param string $email      Raw email address.
     * @param int    $log_id     DB row ID for marking as reported.
     */
    public function maybe_schedule_report(string $ip, string $email, int $log_id): void
    {
        if (! (bool) $this->settings->get('sfs_enabled', false)) {
            return;
        }

        if ($this->settings->get_sfs_api_key() === '') {
            return;
        }

        $email_hash  = hash_hmac('sha256', strtolower(trim($email)), $this->settings->get_hmac_secret());
        $dedup_key   = 'bfas_sfs_rep_' . substr(md5($ip . $email_hash), 0, 12);

        if (get_transient($dedup_key)) {
            return; // already reported recently
        }

        set_transient($dedup_key, 1, 86400); // 24-hour dedup window

        wp_schedule_single_event(time(), 'bfas_sfs_report_event', [$ip, $email, $log_id]);
    }

    /**
     * Execute the HTTP report to Stop Forum Spam.
     *
     * @param string $ip     Visitor IP address.
     * @param string $email  Raw email address.
     * @param int    $log_id DB row ID to mark as reported on success.
     */
    public function do_report(string $ip, string $email, int $log_id): void
    {
        $api_key = $this->settings->get_sfs_api_key();
        if ($api_key === '') {
            return;
        }

        $response = wp_remote_post(
            'https://www.stopforumspam.com/add.php',
            [
                'timeout' => 10,
                'body'    => [
                    'api_key'  => $api_key,
                    'ip'       => $ip,
                    'email'    => $email,
                    'evidence' => 'Blocked by BlueFairy Anti-Spam',
                ],
            ]
        );

        if (! is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $this->logger->mark_reported($log_id);
        }
    }
}
