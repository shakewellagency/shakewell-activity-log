<?php
/**
 * One-off import of Simple History entries: wp shakewell-activity-log import-simple-history [--dry-run].
 *
 * @package Shakewell\ActivityLog
 */

namespace Shakewell\ActivityLog;

defined( 'ABSPATH' ) || exit;

/**
 * Reads {prefix}simple_history and {prefix}simple_history_contexts, never writes to them.
 * Imported rows carry source "simple_history:<id>", which is what makes a second run skip them.
 */
final class Importer {

	private const BATCH = 500;

	/**
	 * Adds the WP-CLI command when running under WP-CLI.
	 */
	public static function register_cli(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'shakewell-activity-log import-simple-history', array( self::class, 'cli' ) );
		}
	}

	/**
	 * Imports entries from Simple History.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Count what would be imported without writing anything.
	 *
	 * @param array $args  Unused.
	 * @param array $assoc Flags.
	 */
	public static function cli( $args, $assoc ): void {
		$dry = ! empty( $assoc['dry-run'] );
		$r   = self::run( $dry );
		if ( null === $r ) {
			\WP_CLI::error( 'Simple History tables not found.' );
		}
		\WP_CLI::success( sprintf( '%s: %d found, %d %s, %d already imported.', $dry ? 'Dry run' : 'Done', $r['found'], $r['imported'], $dry ? 'would be imported' : 'imported', $r['skipped'] ) );
	}

	/**
	 * Runs the import. Returns null when Simple History's tables are not there.
	 *
	 * @param bool $dry When true nothing is written.
	 * @return array{found:int,imported:int,skipped:int}|null
	 */
	public static function run( bool $dry = false ): ?array {
		global $wpdb;
		$history  = $wpdb->prefix . 'simple_history';
		$contexts = $wpdb->prefix . 'simple_history_contexts';
		$wpdb->suppress_errors( true );
		$wpdb->last_error = '';
		$wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 1', $history ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 1', $contexts ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$missing = '' !== $wpdb->last_error;
		$wpdb->suppress_errors( false );
		if ( $missing ) {
			return null;
		}

		$out  = array(
			'found'    => 0,
			'imported' => 0,
			'skipped'  => 0,
		);
		$last = 0;
		$log  = Store::table();
		do {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, date, logger, message FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d', $history, $last, self::BATCH ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $rows ) {
				break;
			}
			$n    = count( $rows );
			$ids  = array_map( 'intval', array_column( $rows, 'id' ) );
			$last = max( $ids );
			$in   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

			$sources = array_map( static fn( $id ) => "simple_history:$id", $ids );
			$done    = $wpdb->get_col( $wpdb->prepare( 'SELECT source FROM %i WHERE source IN (' . implode( ',', array_fill( 0, count( $sources ), '%s' ) ) . ')', array_merge( array( $log ), $sources ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

			$ctx = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT history_id, `key`, value FROM %i WHERE history_id IN ($in)", array_merge( array( $contexts ), $ids ) ), ARRAY_A ) as $c ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$ctx[ $c['history_id'] ][ $c['key'] ] = $c['value'];
			}

			foreach ( $rows as $row ) {
				++$out['found'];
				if ( in_array( 'simple_history:' . $row['id'], $done, true ) ) {
					++$out['skipped'];
					continue;
				}
				++$out['imported'];
				if ( ! $dry ) {
					Store::insert( self::map( $row, $ctx[ $row['id'] ] ?? array() ) );
				}
			}
		} while ( self::BATCH === $n );
		return $out;
	}

	/**
	 * Maps one Simple History row and its context to a log row. Only a fixed set of context keys is read,
	 * so nothing else (such as request details) is carried over.
	 *
	 * @param array $row Row from simple_history (date is already UTC).
	 * @param array $c   Its context, key => value.
	 */
	private static function map( array $row, array $c ): array {
		$message = preg_replace_callback(
			'/\{(\w+)\}/',
			static fn( $m ) => (string) ( $c[ $m[1] ] ?? $m[0] ),
			(string) $row['message']
		);
		$ip      = (string) apply_filters( 'shakewell_activity_log_ip', (string) ( $c['_server_remote_addr'] ?? '' ), 'import' );
		$type    = (string) ( $c['_post_type'] ?? ( isset( $c['_edited_user_id'] ) ? 'user' : '' ) );
		return array(
			'time'        => $row['date'],
			'user_id'     => (int) ( $c['_user_id'] ?? 0 ),
			'user_login'  => mb_substr( (string) ( $c['_user_login'] ?? '' ), 0, 60 ),
			'role'        => mb_substr( (string) ( $c['_user_role'] ?? '' ), 0, 64 ),
			'action'      => mb_substr( (string) ( $c['_message_key'] ?? $row['logger'] ), 0, 64 ),
			'object_type' => mb_substr( $type, 0, 40 ),
			'object_id'   => (int) ( $c['_post_id'] ?? $c['_attachment_id'] ?? $c['_edited_user_id'] ?? 0 ),
			'message'     => mb_substr( wp_strip_all_tags( $message ), 0, 255 ),
			'ip'          => false === filter_var( $ip, FILTER_VALIDATE_IP ) ? '' : $ip,
			'source'      => 'simple_history:' . $row['id'],
		);
	}
}
