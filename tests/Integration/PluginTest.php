<?php
/**
 * Behaviour tests. One test per behaviour, named for what it proves.
 *
 * @package Shakewell\ActivityLog
 */

namespace Shakewell\ActivityLog\Tests;

use Shakewell\ActivityLog\Admin;
use Shakewell\ActivityLog\Importer;
use Shakewell\ActivityLog\Plugin;
use Shakewell\ActivityLog\Store;
use WP_UnitTestCase;

/**
 * Tests for the activity log.
 */
final class PluginTest extends WP_UnitTestCase {

	/**
	 * Actor for most tests.
	 *
	 * @var int
	 */
	private int $admin;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Store::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator', 'user_login' => 'alice' ) );
		wp_set_current_user( $this->admin );
		$wpdb->query( 'DELETE FROM ' . Store::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Rows with this action, newest first.
	 *
	 * @param string $action Action name.
	 */
	private function rows( string $action = '' ): array {
		return Store::rows( '' === $action ? array() : array( 'action' => $action ), 100 );
	}

	private function assert_logged_once( string $action ): array {
		$rows = $this->rows( $action );
		$this->assertCount( 1, $rows, "$action should be logged once" );
		return $rows[0];
	}

	public function test_table_is_created_with_indexes(): void {
		global $wpdb;
		$keys = array_column( $wpdb->get_results( 'SHOW INDEX FROM ' . Store::table(), ARRAY_A ), 'Column_name' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertContains( 'time', $keys );
		$this->assertContains( 'user_id', $keys );
		$this->assertNotFalse( wp_next_scheduled( Store::CRON ) );
	}

	public function test_post_lifecycle_each_event_logs_once_with_fields(): void {
		$id = self::factory()->post->create( array( 'post_status' => 'draft', 'post_title' => 'Hello' ) );
		$row = $this->assert_logged_once( 'post_created' );
		$this->assertSame( (string) $this->admin, $row['user_id'] );
		$this->assertSame( 'alice', $row['user_login'] );
		$this->assertSame( 'administrator', $row['role'] );
		$this->assertSame( 'post', $row['object_type'] );
		$this->assertSame( (string) $id, $row['object_id'] );
		$this->assertSame( 'Created post "Hello"', $row['message'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['time'] );

		wp_update_post( array( 'ID' => $id, 'post_title' => 'Hello 2' ) );
		$this->assert_logged_once( 'post_updated' );

		wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
		$this->assert_logged_once( 'post_published' );
		$this->assertCount( 1, $this->rows( 'post_updated' ), 'a status change is not also an update' );

		wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
		$this->assert_logged_once( 'post_unpublished' );

		wp_trash_post( $id );
		$this->assert_logged_once( 'post_trashed' );
		wp_untrash_post( $id );
		$this->assert_logged_once( 'post_restored' );
		wp_delete_post( $id, true );
		$this->assert_logged_once( 'post_deleted' );
	}

	public function test_media_upload_edit_delete(): void {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assert_logged_once( 'media_uploaded' );
		wp_update_post( array( 'ID' => $id, 'post_title' => 'x' ) );
		$this->assert_logged_once( 'media_edited' );
		wp_delete_attachment( $id, true );
		$this->assert_logged_once( 'media_deleted' );
	}

	public function test_login_logs_once_even_when_cookie_and_wp_login_both_fire(): void {
		wp_set_current_user( 0 );
		$user = get_userdata( $this->admin );
		do_action( 'set_auth_cookie', 'c', 0, 0, $user->ID, 'logged_in', 't' );
		do_action( 'wp_login', $user->user_login, $user );
		$row = $this->assert_logged_once( 'login' );
		$this->assertSame( 'alice', $row['user_login'] );
		$this->assertSame( 'administrator', $row['role'] );
	}

	public function test_sso_login_via_auth_cookie_alone_is_logged(): void {
		wp_set_current_user( 0 );
		do_action( 'set_auth_cookie', 'c', 0, 0, $this->admin, 'logged_in', 't' );
		$this->assert_logged_once( 'login' );
	}

	public function test_failed_login_stores_username_but_never_the_password(): void {
		wp_set_current_user( 0 );
		$_POST['pwd'] = 'hunter2-secret';
		wp_authenticate( 'mallory', 'hunter2-secret' );
		unset( $_POST['pwd'] );
		$row = $this->assert_logged_once( 'login_failed' );
		$this->assertSame( 'mallory', $row['user_login'] );
		$this->assertSame( '0', $row['user_id'] );
		$this->assertStringNotContainsString( 'hunter2', implode( ' ', $row ) );
	}

	public function test_logout_is_logged_with_the_user(): void {
		do_action( 'wp_logout', $this->admin );
		$row = $this->assert_logged_once( 'logout' );
		$this->assertSame( 'alice', $row['user_login'] );
	}

	public function test_user_created_deleted_role_and_email_changed(): void {
		$uid = self::factory()->user->create( array( 'role' => 'editor', 'user_login' => 'bob' ) );
		$row = $this->assert_logged_once( 'user_created' );
		$this->assertSame( 'alice', $row['user_login'] );
		$this->assertSame( (string) $uid, $row['object_id'] );
		$this->assertSame( array(), $this->rows( 'user_role_changed' ), 'new users are not a role change' );

		( new \WP_User( $uid ) )->set_role( 'author' );
		( new \WP_User( $uid ) )->set_role( 'author' );
		$row = $this->assert_logged_once( 'user_role_changed' );
		$this->assertSame( 'Changed role of "bob" from editor to author', $row['message'] );

		wp_update_user( array( 'ID' => $uid, 'user_email' => 'new-bob@example.org' ) );
		$row = $this->assert_logged_once( 'user_email_changed' );
		$this->assertStringNotContainsString( 'example.org', implode( ' ', $row ) );

		wp_delete_user( $uid );
		$this->assert_logged_once( 'user_deleted' );
	}

	public function test_plugin_theme_and_core_events(): void {
		require_once ABSPATH . "wp-admin/includes/class-wp-upgrader.php";
		do_action( 'activated_plugin', 'a/a.php' );
		do_action( 'deactivated_plugin', 'a/a.php' );
		do_action( 'deleted_plugin', 'a/a.php', true );
		do_action( 'deleted_plugin', 'b/b.php', false );
		do_action( 'upgrader_process_complete', null, array( 'action' => 'update', 'type' => 'plugin', 'plugins' => array( 'a/a.php' ) ) );
		do_action( 'upgrader_process_complete', null, array( 'action' => 'update', 'type' => 'core' ) );
		do_action( 'upgrader_process_complete', null, array( 'action' => 'install', 'type' => 'plugin', 'plugin' => 'z/z.php' ) );
		do_action( 'switch_theme', 'Twenty', wp_get_theme( get_stylesheet() ) );
		foreach ( array( 'plugin_activated', 'plugin_deactivated', 'plugin_deleted', 'plugin_updated', 'core_updated', 'theme_switched' ) as $a ) {
			$this->assert_logged_once( $a );
		}
	}

	public function test_option_values_are_never_stored_unless_allow_listed(): void {
		update_option( 'admin_email', 'secret-admin@example.org' );
		update_option( 'blogname', 'Visible Name' );
		update_option( 'some_api_key', 'sk_live_123' );
		update_option( 'active_plugins', array( 'x/x.php' ) );
		$rows = $this->rows( 'option_changed' );
		$all  = implode( ' | ', array_column( $rows, 'message' ) );
		$this->assertStringNotContainsString( 'secret-admin', $all );
		$this->assertStringNotContainsString( 'sk_live', $all );
		$this->assertStringNotContainsString( 'x/x.php', $all );
		$this->assertStringContainsString( 'Changed option admin_email', $all );
		$this->assertStringContainsString( 'Changed option active_plugins', $all );
		$this->assertStringNotContainsString( 'some_api_key', $all );
		$this->assertStringContainsString( 'Visible Name', $all );
	}

	public function test_ip_is_filterable_and_can_be_turned_off(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		do_action( 'switch_theme', 'T', wp_get_theme( get_stylesheet() ) );
		$this->assertSame( '203.0.113.9', $this->rows( 'theme_switched' )[0]['ip'] );
		add_filter( 'shakewell_activity_log_ip', '__return_empty_string' );
		do_action( 'activated_plugin', 'a/a.php' );
		$this->assertSame( '', $this->rows( 'plugin_activated' )[0]['ip'] );
	}

	public function test_ip_filter_tells_live_from_import(): void {
		$seen = array();
		add_filter(
			'shakewell_activity_log_ip',
			static function ( $ip, $context ) use ( &$seen ) {
				$seen[] = $context;
				return $ip;
			},
			10,
			2
		);
		do_action( 'switch_theme', 'T', wp_get_theme( get_stylesheet() ) );
		$this->assertSame( array( 'live' ), $seen );
	}

	public function test_purge_removes_only_old_rows(): void {
		global $wpdb;
		Store::insert( array( 'time' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ), 'action' => 'old' ) );
		Store::insert( array( 'time' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ), 'action' => 'recent' ) );
		Store::purge();
		$this->assertSame( array(), $this->rows( 'old' ) );
		$this->assertCount( 1, $this->rows( 'recent' ) );
		add_filter( 'shakewell_activity_log_retention_days', static fn() => 5 );
		Store::purge();
		$this->assertSame( array(), $this->rows( 'recent' ) );
	}

	public function test_screen_and_export_require_the_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		foreach ( array( array( Admin::class, 'render' ), array( Admin::class, 'export' ) ) as $cb ) {
			try {
				call_user_func( $cb );
				$this->fail( 'expected wp_die' );
			} catch ( \WPDieException $e ) {
				$this->assertSame( 403, $e->getCode() );
			}
		}
	}

	public function test_capability_is_filterable(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter( 'shakewell_activity_log_capability', static fn() => 'edit_posts' );
		ob_start();
		Admin::render();
		$this->assertStringContainsString( 'Activity Log', ob_get_clean() );
	}

	public function test_export_needs_a_valid_nonce(): void {
		$this->expectException( \WPDieException::class );
		Admin::export();
	}

	public function test_export_streams_csv_and_neutralises_formulas(): void {
		Store::insert( array( 'time' => gmdate( 'Y-m-d H:i:s' ), 'action' => 'x', 'user_login' => '=cmd|calc', 'message' => 'm' ) );
		Store::insert( array( 'time' => gmdate( 'Y-m-d H:i:s' ), 'action' => 'y', 'message' => 'other' ) );
		$out = fopen( 'php://memory', 'w+' );
		Admin::write_csv( $out, array( 'action' => 'x' ) );
		rewind( $out );
		$csv = stream_get_contents( $out );
		$this->assertStringContainsString( 'time_utc,user_id', $csv );
		$this->assertStringContainsString( "'=cmd|calc", $csv );
		$this->assertStringNotContainsString( 'other', $csv );
	}

	public function test_error_log_sink_writes_one_json_line_when_on(): void {
		$file = wp_tempnam( 'sal' );
		$prev = ini_set( 'error_log', $file ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		do_action( 'activated_plugin', 'a/a.php' );
		$this->assertSame( '', (string) file_get_contents( $file ), 'off by default' );
		add_filter( 'shakewell_activity_log_error_log_enabled', '__return_true' );
		do_action( 'activated_plugin', 'b/b.php' );
		ini_set( 'error_log', $prev ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		$lines = array_values( array_filter( explode( "\n", (string) file_get_contents( $file ) ) ) );
		unlink( $file );
		$this->assertCount( 1, $lines );
		$json = json_decode( preg_replace( '/^\[[^\]]+\] /', '', $lines[0] ), true );
		$this->assertSame( 'plugin_activated', $json['action'] );
		$this->assertSame( 'shakewell_activity_log', $json['channel'] );
		$this->assertSame( 'Activated plugin b/b.php', $json['message'] );
	}

	public function test_no_rest_route_is_registered(): void {
		$this->assertNotContains( '/shakewell-activity-log/v1', array_keys( rest_get_server()->get_namespaces() ) );
		$this->assertFalse( has_action( 'rest_api_init', array( Plugin::class, 'boot' ) ) );
	}

	/**
	 * Creates Simple History's real tables (schema from simple-history 5.x, class-setup-database.php).
	 */
	private function create_simple_history(): void {
		global $wpdb;
		$p       = $wpdb->prefix;
		$charset = $wpdb->get_charset_collate();
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$p}simple_history" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$p}simple_history_contexts" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "CREATE TABLE {$p}simple_history (id bigint(20) NOT NULL AUTO_INCREMENT, date datetime NOT NULL, logger varchar(30) DEFAULT NULL, level varchar(20) DEFAULT NULL, message varchar(255) DEFAULT NULL, occasionsID varchar(32) DEFAULT NULL, initiator varchar(16) DEFAULT NULL, PRIMARY KEY (id), KEY date (date), KEY loggerdate (logger,date)) {$charset}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "CREATE TABLE {$p}simple_history_contexts (context_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, history_id bigint(20) unsigned NOT NULL, `key` varchar(255) DEFAULT NULL, value longtext, PRIMARY KEY (context_id), KEY history_id (history_id), KEY `key` (`key`(191))) {$charset}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->insert( "{$p}simple_history", array( 'date' => '2024-03-05 10:11:12', 'logger' => 'SimplePostLogger', 'level' => 'info', 'message' => '{post_type} "{post_title}" updated', 'initiator' => 'wp_user' ) );
		$id = $wpdb->insert_id;
		foreach ( array( '_message_key' => 'post_updated', '_user_id' => '7', '_user_login' => 'carol', '_server_remote_addr' => '198.51.100.4', '_post_id' => '42', '_post_type' => 'page', 'post_type' => 'page', 'post_title' => 'About', '_server_http_user_agent' => 'secret-ua' ) as $k => $v ) {
			$wpdb->insert( "{$p}simple_history_contexts", array( 'history_id' => $id, 'key' => $k, 'value' => $v ) );
		}
	}

	public function test_simple_history_import_copies_rows_with_dates(): void {
		$this->create_simple_history();
		$r = Importer::run();
		$this->assertSame( 1, $r['imported'] );
		$row = $this->assert_logged_once( 'post_updated' );
		$this->assertSame( '2024-03-05 10:11:12', $row['time'] );
		$this->assertSame( 'carol', $row['user_login'] );
		$this->assertSame( '7', $row['user_id'] );
		$this->assertSame( 'page "About" updated', $row['message'] );
		$this->assertSame( '42', $row['object_id'] );
		$this->assertSame( '198.51.100.4', $row['ip'] );
		$this->assertStringNotContainsString( 'secret-ua', implode( ' ', $row ) );
	}

	public function test_simple_history_import_is_idempotent(): void {
		$this->create_simple_history();
		Importer::run();
		$r = Importer::run();
		$this->assertSame( array( 'found' => 1, 'imported' => 0, 'skipped' => 1 ), $r );
		$this->assertCount( 1, $this->rows( 'post_updated' ) );
	}

	public function test_simple_history_dry_run_writes_nothing(): void {
		$this->create_simple_history();
		$r = Importer::run( true );
		$this->assertSame( 1, $r['imported'] );
		$this->assertSame( array(), $this->rows() );
	}

	public function test_import_without_simple_history_tables_returns_null(): void {
		global $wpdb;
		$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . $wpdb->prefix . 'simple_history' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . $wpdb->prefix . 'simple_history_contexts' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertNull( Importer::run() );
	}

	public function test_zz_revisions_menu_items_and_autosaves_are_ignored(): void {
		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Store::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		wp_save_post_revision( $id );
		self::factory()->post->create( array( 'post_type' => 'nav_menu_item' ) );
		// Defined for the rest of the process, so this test must stay last in the file.
		define( 'DOING_AUTOSAVE', true );
		wp_update_post( array( 'ID' => $id, 'post_title' => 'Autosaved' ) );
		$this->assertSame( array(), $this->rows() );
	}
}
