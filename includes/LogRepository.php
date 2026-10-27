<?php
/**
 * Data-access layer for the bfas_log table — used by LogTable (WP_List_Table).
 *
 * @package BlueFairy\AntiSpam
 */

declare(strict_types=1);

namespace BlueFairy\AntiSpam;

/**
 * Data-access layer for the bfas_log table — used by LogTable (WP_List_Table).
 */
class LogRepository {

	/**
	 * Count log rows, optionally filtered by trap type, IP search, or SFS-reported-only.
	 *
	 * @param string $trap_filter Optional trap type filter.
	 * @param string $search_ip   Optional IP search string.
	 * @param bool   $sfs_only    When true, only rows reported to Stop Forum Spam.
	 */
	public function count( string $trap_filter = '', string $search_ip = '', bool $sfs_only = false ): int {
		global $wpdb;

		$where  = [];
		$params = [];

		if ( '' !== $trap_filter ) {
			$where[]  = '`trap` = %s';
			$params[] = $trap_filter;
		}

		if ( '' !== $search_ip ) {
			$where[]  = '`ip` LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search_ip ) . '%';
		}

		if ( $sfs_only ) {
			$where[] = '`reported_sfs` = 1';
		}

		$sql = "SELECT COUNT(*) FROM `{$wpdb->prefix}bfas_log`";
		if ( ! empty( $where ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}

		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, ...$params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Fetch a page of log rows.
	 *
	 * @param int    $per_page    Rows per page.
	 * @param int    $offset      Rows to skip.
	 * @param string $orderby     Column name — validated against allowlist.
	 * @param string $order       ASC|DESC.
	 * @param string $trap_filter Optional trap type filter.
	 * @param string $search_ip   Optional IP search string.
	 * @param bool   $sfs_only    When true, return only rows reported to SFS.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_rows(
		int $per_page,
		int $offset,
		string $orderby = 'blocked_at',
		string $order = 'DESC',
		string $trap_filter = '',
		string $search_ip = '',
		bool $sfs_only = false
	): array {
		global $wpdb;

		$allowed_orderby = [ 'blocked_at', 'trap', 'id' ];
		$orderby         = in_array( $orderby, $allowed_orderby, true ) ? $orderby : 'blocked_at';
		$order           = strtoupper( $order ) === 'ASC' ? 'ASC' : 'DESC';

		$where  = [];
		$params = [];

		if ( '' !== $trap_filter ) {
			$where[]  = '`trap` = %s';
			$params[] = $trap_filter;
		}

		if ( '' !== $search_ip ) {
			$where[]  = '`ip` LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search_ip ) . '%';
		}

		if ( $sfs_only ) {
			$where[] = '`reported_sfs` = 1';
		}

		$sql = "SELECT `id`, `blocked_at`, `trap`, `form`, `ip`, `reported_sfs` FROM `{$wpdb->prefix}bfas_log`";
		if ( ! empty( $where ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}
		$sql .= " ORDER BY `{$orderby}` {$order}";

		$params[] = $per_page;
		$params[] = $offset;
		$sql     .= ' LIMIT %d OFFSET %d';

		$prepared = $wpdb->prepare( $sql, ...$params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$rows = $wpdb->get_results( $prepared, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! is_array( $rows ) ) {
			return [];
		}

		return $rows;
	}

	/**
	 * Return an array of all distinct trap type values for the filter dropdown.
	 *
	 * @return string[]
	 */
	public function get_trap_types(): array {
		global $wpdb;

		$rows = $wpdb->get_col( "SELECT DISTINCT `trap` FROM `{$wpdb->prefix}bfas_log` ORDER BY `trap`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_map( 'strval', $rows );
	}
}
