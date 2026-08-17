<?php
declare(strict_types=1);

namespace BlueFairy\AntiSpam\Admin;

use BlueFairy\AntiSpam\Logger;
use BlueFairy\AntiSpam\LogRepository;
use BlueFairy\AntiSpam\Settings;

/**
 * Log admin page under Tools > Anti-Spam Log.
 */
class LogPage
{
    private Settings      $settings;
    private Logger        $logger;
    private LogRepository $repo;

    public function __construct(Settings $settings, Logger $logger)
    {
        $this->settings = $settings;
        $this->logger   = $logger;
        $this->repo     = new LogRepository();
    }

    /**
     * Register the menu item.
     */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu_page']);
    }

    /**
     * Add the Log sub-item under Tools.
     */
    public function add_menu_page(): void
    {
        add_management_page(
            __('Anti-Spam Log', 'bluefairy-antispam'),
            __('Anti-Spam Log', 'bluefairy-antispam'),
            'manage_options',
            'bfas-log',
            [$this, 'render']
        );
    }

    /**
     * Render the log page.
     */
    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $this->maybe_process_bulk_action();

        $table = new LogTable($this->repo);
        $table->prepare_items();

        $stats = $this->logger->get_stats();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Anti-Spam Log', 'bluefairy-antispam'); ?></h1>

            <p>
                <?php
                printf(
                    /* translators: %s = formatted number of blocked submissions */
                    esc_html(_n('%s spammer blocked total.', '%s spammers blocked total.', $stats['total'], 'bluefairy-antispam')),
                    esc_html(number_format_i18n($stats['total']))
                );
                ?>
            </p>

            <form method="get">
                <input type="hidden" name="page" value="bfas-log">
                <?php
                $table->search_box(__('Search by IP', 'bluefairy-antispam'), 'bfas-search');
                $table->display();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Handle bulk delete action, secured by nonce.
     */
    private function maybe_process_bulk_action(): void
    {
        if (
            ! isset($_REQUEST['action']) ||
            $_REQUEST['action'] !== 'delete' ||
            empty($_REQUEST['log_ids'])
        ) {
            return;
        }

        check_admin_referer('bulk-log_entries');

        if (! current_user_can('manage_options')) {
            return;
        }

        $ids = array_map('absint', (array) $_REQUEST['log_ids']);
        if (empty($ids)) {
            return;
        }

        global $wpdb;
        foreach ($ids as $id) {
            $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->prefix . 'bfas_log',
                ['id' => $id],
                ['%d']
            );
        }

        wp_safe_redirect(admin_url('tools.php?page=bfas-log&deleted=' . count($ids)));
        exit;
    }
}
