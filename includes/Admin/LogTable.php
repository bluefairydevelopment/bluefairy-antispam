<?php
declare(strict_types=1);

namespace BlueFairy\AntiSpam\Admin;

use BlueFairy\AntiSpam\LogRepository;

if (! class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WP_List_Table implementation for the bfas_log table.
 */
class LogTable extends \WP_List_Table
{
    private LogRepository $repo;

    public function __construct(LogRepository $repo)
    {
        parent::__construct([
            'singular' => 'log_entry',
            'plural'   => 'log_entries',
            'ajax'     => false,
        ]);

        $this->repo = $repo;
    }

    /**
     * Define table columns.
     *
     * @return array<string, string>
     */
    public function get_columns(): array
    {
        return [
            'cb'           => '<input type="checkbox">',
            'blocked_at'   => __('Date', 'bluefairy-antispam'),
            'trap'         => __('Trap', 'bluefairy-antispam'),
            'form'         => __('Form', 'bluefairy-antispam'),
            'ip'           => __('IP Address', 'bluefairy-antispam'),
            'reported_sfs' => __('Reported to SFS', 'bluefairy-antispam'),
        ];
    }

    /**
     * Define sortable columns.
     *
     * @return array<string, array{string, bool}>
     */
    public function get_sortable_columns(): array
    {
        return [
            'blocked_at' => ['blocked_at', true],
            'trap'       => ['trap', false],
        ];
    }

    /**
     * Render the checkbox column.
     *
     * @param array<string, mixed> $item
     */
    public function column_cb( $item ): string
    {
        return '<input type="checkbox" name="log_ids[]" value="' . absint($item['id']) . '">';
    }

    /**
     * Default column renderer.
     *
     * @param array<string, mixed> $item
     * @param string               $column_name
     */
    public function column_default( $item, $column_name ): string
    {
        return match ($column_name) {
            'blocked_at'   => esc_html($item['blocked_at'] ?? ''),
            'trap'         => esc_html($item['trap'] ?? ''),
            'form'         => esc_html($item['form'] ?? ''),
            'ip'           => esc_html($item['ip'] ?? ''),
            'reported_sfs' => (bool) ($item['reported_sfs'] ?? false)
                ? '<span style="color:green;">&#10003;</span>'
                : '&mdash;',
            default        => esc_html((string) ($item[$column_name] ?? '')),
        };
    }

    /**
     * Message shown when there are no items.
     */
    public function no_items(): void
    {
        esc_html_e('No spam blocked yet. Either your site is spam-free (lucky you!), or the plugin was just activated.', 'bluefairy-antispam');
    }

    /**
     * Define bulk actions.
     *
     * @return array<string, string>
     */
    public function get_bulk_actions(): array
    {
        return [
            'delete' => __('Delete', 'bluefairy-antispam'),
        ];
    }

    /**
     * Fetch data and configure pagination.
     */
    public function prepare_items(): void
    {
        $per_page    = 20;
        $current     = $this->get_pagenum();
        $orderby     = isset($_GET['orderby']) ? sanitize_key($_GET['orderby']) : 'blocked_at'; // phpcs:ignore WordPress.Security.NonceVerification
        $order       = isset($_GET['order']) && strtoupper($_GET['order']) === 'ASC' ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification
        $trap_filter = isset($_GET['trap_filter']) ? sanitize_key($_GET['trap_filter']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $search_ip   = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        $total = $this->repo->count($trap_filter, $search_ip);

        $this->set_pagination_args([
            'total_items' => $total,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil($total / $per_page),
        ]);

        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
        ];

        $this->items = $this->repo->get_rows(
            $per_page,
            ($current - 1) * $per_page,
            $orderby,
            $order,
            $trap_filter,
            $search_ip
        );
    }

    /**
     * Render a trap-type filter dropdown above the table.
     *
     * @param string $which 'top' or 'bottom'
     */
    public function extra_tablenav( $which ): void
    {
        if ($which !== 'top') {
            return;
        }

        $trap_types = $this->repo->get_trap_types();
        $current    = isset($_GET['trap_filter']) ? sanitize_key($_GET['trap_filter']) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        echo '<div class="alignleft actions">';
        echo '<select name="trap_filter">';
        echo '<option value="">' . esc_html__('All trap types', 'bluefairy-antispam') . '</option>';
        foreach ($trap_types as $type) {
            echo '<option value="' . esc_attr($type) . '"' . selected($current, $type, false) . '>' . esc_html($type) . '</option>';
        }
        echo '</select>';
        submit_button(__('Filter', 'bluefairy-antispam'), 'button', 'filter_action', false);
        echo '</div>';
    }
}
