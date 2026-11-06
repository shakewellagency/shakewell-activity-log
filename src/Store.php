<?php
/**
 * The log table: create, write, query, purge.
 *
 * @package Shakewell\ActivityLog
 */

namespace Shakewell\ActivityLog;

defined( 'ABSPATH' ) || exit;

/**
 * Every database access of the plugin lives here, all through $wpdb->prepare() or $wpdb->insert().
 * Nothing in the plugin updates or deletes a single row; the only delete is the retention purge.
 */
final class Store {

	public const VERSION_OPTION = 'shakewell_activity_log_db_version';
	public const CRON           = 'shakewell_activity_log_purge';
	private const BATCH         = 1000;

	/**
	 * Full table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'shakewell_activity_log';
	}

	/**
	 * Creates or upgrades the table and schedules the daily purge. Runs on activation and when the version changes.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			time datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_login varchar(60) NOT NULL DEFAULT '',
			role varchar(64) NOT NULL DEFAULT '',
			action varchar(64) NOT NULL,
			object_type varchar(40) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			message varchar(255) NOT NULL DEFAULT '',
			ip varchar(45) NOT NULL DEFAULT '',
			source varchar(64) DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY time (time),
			KEY user_id (user_id),
			KEY source (source)
			) {$charset};"
		);
		update_option( self::VERSION_OPTION, VERSION );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Stops the daily purge. Used on deactivation and uninstall.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON );
	}

	/**
	 * Logs one event as the current user (or the user given in $args['user']), then lets other destinations see it.
	 *
	 * @param string $action  Short machine name, e.g. post_published.
	 * @param string $message Short human sentence. Plain text; escaped on output.
	 * @param array  $args    Optional: user (WP_User), user_login (string, for failed logins), object_type, object_id.
	 */
	public static function log( string $action, string $message, array $args = array() ): void {
		$user        = $args['user'] ?? wp_get_current_user();
		$roles       = ( $user instanceof \WP_User ) ? array_values( $user->roles ) : array();
		$entry       = array(
			'time'        => gmdate( 'Y-m-d H:i:s' ),
			'user_id'     => ( $user instanceof \WP_User ) ? (int) $user->ID : 0,
			'user_login'  => mb_substr( (string) ( $args['user_login'] ?? ( $user->user_login ?? '' ) ), 0, 60 ),
			'role'        => $roles[0] ?? '',
			'action'      => $action,
			'object_type' => (string) ( $args['object_type'] ?? '' ),
			'object_id'   => (int) ( $args['object_id'] ?? 0 ),
			'message'     => mb_substr( wp_strip_all_tags( $message ), 0, 255 ),
			'ip'          => self::ip(),
			'source'      => null,
		);
		$entry['id'] = self::insert( $entry );
		// Fired even when the write failed (id 0), so a second destination still gets the entry.
		do_action( 'shakewell_activity_log_entry_logged', $entry );
	}

	/**
	 * Inserts a ready-made row without firing the second-destination action. Returns the new id, or 0.
	 *
	 * @param array $entry Column => value.
	 */
	public static function insert( array $entry ): int {
		global $wpdb;
		unset( $entry['id'] );
		// A privacy-minded site can return '' from the shakewell_activity_log_ip filter.
		$ok = $wpdb->insert( self::table(), $entry ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Visitor IP, filterable. Empty when the filter returns '' or something that is not an IP address.
	 */
	private static function ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = (string) apply_filters( 'shakewell_activity_log_ip', $ip );
		return false === filter_var( $ip, FILTER_VALIDATE_IP ) ? '' : $ip;
	}

	/**
	 * Deletes rows older than the retention period, in batches. Retention comes from the constant
	 * SHAKEWELL_ACTIVITY_LOG_RETENTION_DAYS (default 365; 0 keeps everything) and a filter.
	 * It is deliberately not an admin setting: whoever can shorten it could erase the record.
	 */
	public static function purge(): void {
		global $wpdb;
		$days = defined( 'SHAKEWELL_ACTIVITY_LOG_RETENTION_DAYS' ) ? (int) SHAKEWELL_ACTIVITY_LOG_RETENTION_DAYS : 365;
		$days = (int) apply_filters( 'shakewell_activity_log_retention_days', $days );
		if ( $days < 1 ) {
			return;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$table  = self::table();
		// ponytail: at most 100 batches (100,000 rows) per run; the next daily run continues.
		for ( $i = 0; $i < 100; $i++ ) {
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE time < %s LIMIT %d", $cutoff, self::BATCH ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( (int) $deleted < self::BATCH ) {
				break;
			}
		}
	}

	/**
	 * Builds the WHERE clause for the admin filters. Values are bound later by prepare().
	 *
	 * @param array $f Filters: user_login, action, from (Y-m-d), to (Y-m-d), s (search), before_id.
	 * @return array{0:string,1:array} SQL and its bound arguments.
	 */
	private static function where( array $f ): array {
		global $wpdb;
		$w = array( '1=1' );
		$a = array();
		foreach ( array( 'user_login', 'action' ) as $col ) {
			if ( ! empty( $f[ $col ] ) ) {
				$w[] = "{$col} = %s";
				$a[] = $f[ $col ];
			}
		}
		if ( ! empty( $f['from'] ) ) {
			$w[] = 'time >= %s';
			$a[] = $f['from'] . ' 00:00:00';
		}
		if ( ! empty( $f['to'] ) ) {
			$w[] = 'time <= %s';
			$a[] = $f['to'] . ' 23:59:59';
		}
		if ( ! empty( $f['s'] ) ) {
			$like = '%' . $wpdb->esc_like( $f['s'] ) . '%';
			$w[]  = '(message LIKE %s OR user_login LIKE %s OR ip LIKE %s)';
			array_push( $a, $like, $like, $like );
		}
		if ( ! empty( $f['before_id'] ) ) {
			$w[] = 'id < %d';
			$a[] = (int) $f['before_id'];
		}
		return array( implode( ' AND ', $w ), $a );
	}

	/**
	 * Newest-first rows matching the filters.
	 *
	 * @param array $filters See where().
	 * @param int   $limit   Page size.
	 * @param int   $offset  Offset.
	 * @return array<int,array<string,string>>
	 */
	public static function rows( array $filters, int $limit, int $offset = 0 ): array {
		global $wpdb;
		list( $where, $args ) = self::where( $filters );
		$table                = self::table();
		$args[]               = $limit;
		$args[]               = $offset;
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Number of rows matching the filters.
	 *
	 * @param array $filters See where().
	 */
	public static function count( array $filters ): int {
		global $wpdb;
		list( $where, $args ) = self::where( $filters );
		$table                = self::table();
		$sql                  = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		return (int) $wpdb->get_var( $args ? $wpdb->prepare( $sql, $args ) : $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Distinct values of a column, for the filter dropdowns.
	 *
	 * @param string $col user_login or action.
	 * @return string[]
	 */
	public static function distinct( string $col ): array {
		global $wpdb;
		$col   = 'action' === $col ? 'action' : 'user_login';
		$table = self::table();
		return array_filter( (array) $wpdb->get_col( "SELECT DISTINCT {$col} FROM {$table} ORDER BY {$col} LIMIT 500" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
