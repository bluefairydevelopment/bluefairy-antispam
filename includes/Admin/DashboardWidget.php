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
     * Register the dashboard widget.
     */
    public function register(): void
    {
        add_action('wp_dashboard_setup', [$this, 'add_widget']);
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
        <style>
            .bfas-stats { margin: 0; padding: 0; list-style: none; }
            .bfas-stats li { display: flex; justify-content: space-between; align-items: center; padding: 4px 0; border-bottom: 1px solid #f0f0f0; }
            .bfas-stats li:last-child { border-bottom: none; }
            .bfas-stats strong { font-size: 1.3em; color: #d63638; }
            .bfas-breakdown { margin: 8px 0 0; padding: 0; list-style: none; }
            .bfas-breakdown li { display: flex; justify-content: space-between; font-size: 0.9em; padding: 2px 0; }
            .bfas-widget-footer { margin-top: 10px; font-size: 0.85em; }
        </style>
        <?php
    }
}
