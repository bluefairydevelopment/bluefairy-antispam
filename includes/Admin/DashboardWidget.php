<?php
declare(strict_types=1);

namespace BlueFairy\AntiSpam\Admin;

use BlueFairy\AntiSpam\Logger;

/**
 * Dashboard widget showing spam block stats.
 */
class DashboardWidget
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Register the dashboard widget and its stylesheet.
     */
    public function register(): void
    {
        add_action('wp_dashboard_setup',    [$this, 'add_widget']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Enqueue the widget stylesheet on the WP dashboard only.
     */
    public function enqueue_assets(string $hook): void
    {
        if ('index.php' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'bfas-dashboard-widget',
            BFAS_URL . 'assets/admin/css/dashboard-widget.css',
            [],
            BFAS_VERSION
        );
    }

    /**
     * Register the widget with WordPress.
     */
    public function add_widget(): void
    {
        wp_add_dashboard_widget(
            'bfas_dashboard_widget',
            __('Anti-Spam Shield', 'bluefairy-antispam'),
            [$this, 'render']
        );
    }

    /**
     * Render the dashboard widget HTML.
     */
    public function render(): void
    {
        $stats     = $this->logger->get_stats();
        $breakdown = $this->logger->get_trap_breakdown();
        ?>
        <div class="bfas-widget">
            <ul class="bfas-stats">
                <li>
                    <strong><?php echo esc_html(number_format_i18n($stats['total'])); ?></strong>
                    <span><?php esc_html_e('Spammers blocked (all time)', 'bluefairy-antispam'); ?></span>
                </li>
                <li>
                    <strong><?php echo esc_html(number_format_i18n($stats['week'])); ?></strong>
                    <span><?php esc_html_e('Blocked this week', 'bluefairy-antispam'); ?></span>
                </li>
                <li>
                    <strong><?php echo esc_html(number_format_i18n($stats['month'])); ?></strong>
                    <span><?php esc_html_e('Blocked this month', 'bluefairy-antispam'); ?></span>
                </li>
            </ul>

            <?php if (! empty($breakdown)) : ?>
                <h4><?php esc_html_e('By trap type', 'bluefairy-antispam'); ?></h4>
                <ul class="bfas-breakdown">
                    <?php foreach ($breakdown as $row) : ?>
                        <li>
                            <span class="bfas-trap-name"><?php echo esc_html($row['trap']); ?></span>
                            <span class="bfas-trap-count"><?php echo esc_html(number_format_i18n($row['count'])); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <p class="bfas-widget-footer">
                <a href="<?php echo esc_url(admin_url('tools.php?page=bfas-log')); ?>">
                    <?php esc_html_e('View full log →', 'bluefairy-antispam'); ?>
                </a>
                &nbsp;|&nbsp;
                <a href="<?php echo esc_url(admin_url('tools.php?page=bfas-settings')); ?>">
                    <?php esc_html_e('Settings', 'bluefairy-antispam'); ?>
                </a>
            </p>
        </div>
        <?php
    }
}
