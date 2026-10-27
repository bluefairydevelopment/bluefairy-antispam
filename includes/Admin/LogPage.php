<?php
/**
 * Log admin page under Tools > Anti-Spam Log.
 *
 * @package BlueFairy\AntiSpam
 */

declare(strict_types=1);

namespace BlueFairy\AntiSpam\Admin;

use BlueFairy\AntiSpam\Logger;
use BlueFairy\AntiSpam\LogRepository;

/**
 * Log admin page under Tools > Anti-Spam Log.
 */
class LogPage {

	/**
	 * Block-event logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Log table data access.
	 *
	 * @var LogRepository
	 */
	private LogRepository $repo;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Block-event logger.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
		$this->repo   = new LogRepository();
	}

	/**
	 * Register the menu item.
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
	}

	/**
	 * Add the Log sub-item under Tools.
	 */
	public function add_menu_page(): void {
		add_management_page(
			__( 'Anti-Spam Log', 'bluefairy-antispam' ),
			__( 'Anti-Spam Log', 'bluefairy-antispam' ),
			'manage_options',
			'bfas-log',
			[ $this, 'render' ]
		);
	}

	/**
	 * Render the log page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->maybe_process_bulk_action();

		$table = new LogTable( $this->repo );
		$table->prepare_items();

		$stats = $this->logger->get_stats();

		$deleted_count = isset( $_GET['deleted'] ) ? absint( $_GET['deleted'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Anti-Spam Log', 'bluefairy-antispam' ); ?></h1>
			<?php if ( $deleted_count > 0 ) : ?>
			<div class="notice notice-success is-dismissible"><p>
				<?php
				echo esc_html(
					/* translators: %d = number of deleted log entries */
					sprintf( _n( '%d log entry deleted.', '%d log entries deleted.', $deleted_count, 'bluefairy-antispam' ), $deleted_count )
				);
				?>
			</p></div>
			<?php endif; ?>

			<p>
				<?php
				printf(
					/* translators: %s = formatted number of blocked submissions */
					esc_html( _n( '%s spammer blocked total.', '%s spammers blocked total.', $stats['total'], 'bluefairy-antispam' ) ),
					esc_html( number_format_i18n( $stats['total'] ) )
				);
				?>
			</p>

			<form method="post">
				<input type="hidden" name="page" value="bfas-log">
				<?php
				$table->search_box( __( 'Search by IP', 'bluefairy-antispam' ), 'bfas-search' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle bulk delete action, secured by nonce.
	 */
	private function maybe_process_bulk_action(): void {
		if (
			! isset( $_REQUEST['action'] ) ||
			'delete' !== $_REQUEST['action'] ||
			empty( $_REQUEST['log_ids'] )
		) {
			return;
		}

		check_admin_referer( 'bulk-log_entries' );

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$ids = array_map( 'absint', (array) $_REQUEST['log_ids'] );
		if ( empty( $ids ) ) {
			return;
		}

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"DELETE FROM `{$wpdb->prefix}bfas_log` WHERE `id` IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				...$ids
			)
		);

		$redirect = add_query_arg(
			array_filter(
				[
					'page'        => 'bfas-log',
					'deleted'     => count( $ids ),
					'trap_filter' => isset( $_GET['trap_filter'] ) ? sanitize_key( wp_unslash( $_GET['trap_filter'] ) ) : '',
					's'           => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
					'paged'       => isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : '',
				]
			),
			admin_url( 'tools.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}
}
