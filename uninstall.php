<?php
/**
 * Removes the schedule and the version option when the plugin is deleted.
 * The log table is deliberately kept: it is a record, and deleting a plugin must not erase it.
 * Drop {prefix}shakewell_activity_log by hand to remove it.
 *
 * @package Shakewell\ActivityLog
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'shakewell_activity_log_purge' );
delete_option( 'shakewell_activity_log_db_version' );
