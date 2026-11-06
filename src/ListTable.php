<?php
/**
 * The log table on the admin screen. Read only: no row actions, no bulk actions.
 *
 * @package Shakewell\ActivityLog
 */

namespace Shakewell\ActivityLog;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Paged, filtered view of the log.
 */
final class ListTable extends \WP_List_Table {

	private const PER_PAGE = 50;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'entry',
				'plural'   => 'entries',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Filters from the query string, sanitised. Display only, so no nonce.
	 *
	 * @return array<string,string>
	 */
	public static function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters of a capability-gated screen.
		$f = array();
		// The action filter travels as log_action: "action" belongs to admin-post.php and list-table bulk actions.
		foreach ( array(
			'user_login' => 'user_login',
			'action'     => 'log_action',
			's'          => 's',
		) as $key => $param ) {
			$f[ $key ] = isset( $_GET[ $param ] ) ? sanitize_text_field( wp_unslash( $_GET[ $param ] ) ) : '';
		}
		foreach ( array( 'from', 'to' ) as $k ) {
			$v       = isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : '';
			$f[ $k ] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
		}
		// phpcs:enable
		return $f;
	}

	/**
	 * Columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns(): array {
		return array(
			'time'       => __( 'Time (UTC)', 'shakewell-activity-log' ),
			'user_login' => __( 'User', 'shakewell-activity-log' ),
			'role'       => __( 'Role', 'shakewell-activity-log' ),
			'action'     => __( 'Action', 'shakewell-activity-log' ),
			'message'    => __( 'Details', 'shakewell-activity-log' ),
			'ip'         => __( 'IP address', 'shakewell-activity-log' ),
		);
	}

	/**
	 * Loads one page of rows.
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$filters               = self::filters();
		$page                  = $this->get_pagenum();
		$this->set_pagination_args(
			array(
				'total_items' => Store::count( $filters ),
				'per_page'    => self::PER_PAGE,
			)
		);
		$this->items = Store::rows( $filters, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );
	}

	/**
	 * Cell output, escaped.
	 *
	 * @param array  $item Row.
	 * @param string $column_name Column.
	 */
	protected function column_default( $item, $column_name ): string {
		$value = (string) ( $item[ $column_name ] ?? '' );
		if ( 'user_login' === $column_name && '' !== $value && (int) $item['user_id'] > 0 ) {
			$value .= ' (#' . (int) $item['user_id'] . ')';
		}
		return esc_html( $value );
	}
}
