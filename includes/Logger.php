<?php
declare(strict_types=1);

namespace BlueFairy\AntiSpam;

/**
 * Writes spam-block events to the log table and handles retention cleanup.
 */
class Logger
{
    private Settings $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Record a blocked submission.
     *
     * @param string $trap         Which trap fired (honeypot|timetrap|sfs).
     * @param string $form         Form identifier.
     * @param string $ip           Visitor IP address.
     * @param string $email        Raw email address (will be hashed before storage).
     * @param bool   $reported_sfs Whether the event was reported to Stop Forum Spam.
     */
    public function log(
        string $trap,
        string $form,
        string $ip,
        string $email = '',
        bool $reported_sfs = false
    ): int {
        global $wpdb;

        $email_hash = $email !== ''
            ? hash_hmac('sha256', strtolower(trim($email)), $this->settings->get_hmac_secret())
            : '';

        $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prefix . 'bfas_log',
            [
                'trap'         => sanitize_key($trap),
                'form'         => sanitize_key($form),
                'ip'           => sanitize_text_field($ip),
                'email_hash'   => $email_hash,
                'reported_sfs' => $reported_sfs ? 1 : 0,
            ],
            ['%s', '%s', '%s', '%s', '%d']
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Mark a log row as reported to Stop Forum Spam.
     */
    public function mark_reported(int $id): void
    {
        global $wpdb;

        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prefix . 'bfas_log',
            ['reported_sfs' => 1],
            ['id' => $id],
            ['%d'],
            ['%d']
        );
    }

    /**
     * Delete log rows older than the retention period (max 1000 per run).
     */
    public function cleanup_old_logs(): void
    {
        global $wpdb;

        $days = absint($this->settings->get('log_retention_days', 90));
        if ($days < 1) {
            return;
        }

        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "DELETE FROM `{$wpdb->prefix}bfas_log` WHERE `blocked_at` < DATE_SUB(NOW(), INTERVAL %d DAY) LIMIT 1000",
                $days
            )
        );
    }

    /**
     * Fetch overall stats for the dashboard widget.
     *
     * @return array{total: int, week: int, month: int}
     */
    public function get_stats(): array
    {
        global $wpdb;

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->prefix}bfas_log`"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        $week = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT COUNT(*) FROM `{$wpdb->prefix}bfas_log` WHERE `blocked_at` >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );

        $month = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT COUNT(*) FROM `{$wpdb->prefix}bfas_log` WHERE `blocked_at` >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );

        return compact('total', 'week', 'month');
    }

    /**
     * Fetch recent blocked-submission log rows, newest first.
     *
     * @return array<int, array{id:int,trap:string,form:string,ip:string,email_hash:string,reported_sfs:bool,blocked_at:string}>
     */
    public function get_recent_entries(int $limit = 50, int $offset = 0): array
    {
        global $wpdb;

        $limit  = max(1, min(500, $limit));
        $offset = max(0, $offset);

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT `id`, `trap`, `form`, `ip`, `email_hash`, `reported_sfs`, `blocked_at`
                 FROM `{$wpdb->prefix}bfas_log`
                 ORDER BY `blocked_at` DESC
                 LIMIT %d OFFSET %d",
                $limit,
                $offset
            ),
            ARRAY_A
        );

        if (! is_array($rows)) {
            return [];
        }

        return array_map(
            static fn (array $r): array => [
                'id'           => (int) $r['id'],
                'trap'         => (string) $r['trap'],
                'form'         => (string) $r['form'],
                'ip'           => (string) $r['ip'],
                'email_hash'   => (string) $r['email_hash'],
                'reported_sfs' => (bool) $r['reported_sfs'],
                'blocked_at'   => (string) $r['blocked_at'],
            ],
            $rows
        );
    }

    /**
     * Fetch a breakdown of blocks per trap type.
     *
     * @return array<int, array{trap: string, count: int}>
     */
    public function get_trap_breakdown(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT `trap`, COUNT(*) as `count` FROM `{$wpdb->prefix}bfas_log` GROUP BY `trap` ORDER BY `count` DESC LIMIT 10",
            ARRAY_A
        );

        if (! is_array($rows)) {
            return [];
        }

        return array_map(
            static fn (array $row): array => [
                'trap'  => (string) $row['trap'],
                'count' => (int) $row['count'],
            ],
            $rows
        );
    }
}
