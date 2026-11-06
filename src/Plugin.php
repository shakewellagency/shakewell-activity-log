<?php
/**
 * Plugin bootstrap.
 *
 * @package Shakewell\ActivityLog
 */

namespace Shakewell\ActivityLog;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress.
 */
final class Plugin {

	/**
	 * Registers hooks. Called on plugins_loaded.
	 */
	public static function boot(): void {
		if ( get_option( Store::VERSION_OPTION ) !== VERSION ) {
			Store::install();
		}
		Logger::register();
		add_action( Store::CRON, array( Store::class, 'purge' ) );
		add_action( 'shakewell_activity_log_entry_logged', array( self::class, 'error_log_sink' ) );
		if ( is_admin() ) {
			Admin::register();
		}
		Importer::register_cli();
	}

	/**
	 * Second destination: one JSON line per entry in the PHP error log. Off unless
	 * SHAKEWELL_ACTIVITY_LOG_ERROR_LOG is true (or the filter says so).
	 *
	 * @param array $entry The entry just logged (id is 0 when the database write failed).
	 */
	public static function error_log_sink( array $entry ): void {
		$on = defined( 'SHAKEWELL_ACTIVITY_LOG_ERROR_LOG' ) && SHAKEWELL_ACTIVITY_LOG_ERROR_LOG;
		if ( ! apply_filters( 'shakewell_activity_log_error_log_enabled', $on ) ) {
			return;
		}
		// The PHP error log is the point of this sink: it is a destination admins cannot edit from wp-admin.
		error_log( wp_json_encode( array( 'channel' => 'shakewell_activity_log' ) + $entry ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
