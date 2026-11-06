<?php
/**
 * Tools > Activity Log screen and CSV export.
 *
 * @package Shakewell\ActivityLog
 */

namespace Shakewell\ActivityLog;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only admin UI. There is no REST endpoint and no way to edit or delete an entry.
 */
final class Admin {

	private const SLUG   = 'shakewell-activity-log';
	private const EXPORT = 'shakewell_activity_log_export';

	/**
	 * Registers the screen and the export handler.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_' . self::EXPORT, array( self::class, 'export' ) );
	}

	/**
	 * Capability needed to view and export the log.
	 */
	public static function capability(): string {
		return (string) apply_filters( 'shakewell_activity_log_capability', 'manage_options' );
	}

	/**
	 * Adds the Tools submenu.
	 */
	public static function menu(): void {
		add_management_page( __( 'Activity Log', 'shakewell-activity-log' ), __( 'Activity Log', 'shakewell-activity-log' ), self::capability(), self::SLUG, array( self::class, 'render' ) );
	}

	/**
	 * Renders the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view the activity log.', 'shakewell-activity-log' ), 403 );
		}
		$f     = ListTable::filters();
		$table = new ListTable();
		$table->prepare_items();
		$export = wp_nonce_url(
			add_query_arg(
				array(
					'action'     => self::EXPORT,
					'user_login' => $f['user_login'],
					'log_action' => $f['action'],
					'from'       => $f['from'],
					'to'         => $f['to'],
					's'          => $f['s'],
				),
				admin_url( 'admin-post.php' )
			),
			self::EXPORT
		);
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Activity Log', 'shakewell-activity-log' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( 'Export CSV', 'shakewell-activity-log' ); ?></a>
			<hr class="wp-header-end">
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<p>
					<label for="sal-user"><?php esc_html_e( 'User', 'shakewell-activity-log' ); ?></label>
					<?php self::select( 'sal-user', 'user_login', Store::distinct( 'user_login' ), $f['user_login'] ); ?>
					<label for="sal-action"><?php esc_html_e( 'Action', 'shakewell-activity-log' ); ?></label>
					<?php self::select( 'sal-action', 'log_action', Store::distinct( 'action' ), $f['action'] ); ?>
					<label for="sal-from"><?php esc_html_e( 'From', 'shakewell-activity-log' ); ?></label>
					<input type="date" id="sal-from" name="from" value="<?php echo esc_attr( $f['from'] ); ?>">
					<label for="sal-to"><?php esc_html_e( 'To', 'shakewell-activity-log' ); ?></label>
					<input type="date" id="sal-to" name="to" value="<?php echo esc_attr( $f['to'] ); ?>">
				</p>
				<?php $table->search_box( __( 'Search log', 'shakewell-activity-log' ), 'sal-search' ); ?>
				<?php submit_button( __( 'Filter', 'shakewell-activity-log' ), 'secondary', '', false ); ?>
			</form>
			<?php $table->display(); ?>
		</div>
		<?php
	}

	/**
	 * Prints a labelled-by-id <select> with an "all" option.
	 *
	 * @param string   $id       Element id (the label points at it).
	 * @param string   $name     Field name.
	 * @param string[] $options  Values.
	 * @param string   $selected Current value.
	 */
	private static function select( string $id, string $name, array $options, string $selected ): void {
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"><option value="">' . esc_html__( 'All', 'shakewell-activity-log' ) . '</option>';
		foreach ( $options as $o ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $o ), selected( $selected, $o, false ), esc_html( $o ) );
		}
		echo '</select> ';
	}

	/**
	 * Streams the filtered log as CSV. Needs the capability and the nonce.
	 */
	public static function export(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to export the activity log.', 'shakewell-activity-log' ), 403 );
		}
		check_admin_referer( self::EXPORT );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="activity-log-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		self::write_csv( $out, ListTable::filters() );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Writes the header and every row matching the filters, newest first, in batches.
	 *
	 * @param resource $out Stream.
	 * @param array    $f   Filters.
	 */
	public static function write_csv( $out, array $f ): void {
		self::csv_line( $out, array( 'time_utc', 'user_id', 'user_login', 'role', 'action', 'object_type', 'object_id', 'message', 'ip' ) );
		do {
			$rows = Store::rows( $f, 1000 );
			$n    = count( $rows );
			foreach ( $rows as $r ) {
				self::csv_line( $out, array( $r['time'], $r['user_id'], $r['user_login'], $r['role'], $r['action'], $r['object_type'], $r['object_id'], $r['message'], $r['ip'] ) );
				$f['before_id'] = $r['id'];
			}
		} while ( 1000 === $n );
	}

	/**
	 * Writes one CSV line, neutralising spreadsheet formulas (= + - @ at the start of a cell).
	 *
	 * @param resource $out    Stream.
	 * @param array    $fields Cells.
	 */
	private static function csv_line( $out, array $fields ): void {
		$fields = array_map( static fn( $v ) => preg_match( '/^[=+\-@\t\r]/', (string) $v ) ? "'" . $v : (string) $v, $fields );
		fputcsv( $out, $fields, ',', '"', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
	}
}
