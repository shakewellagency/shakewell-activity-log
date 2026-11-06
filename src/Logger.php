<?php
/**
 * Listens to WordPress and writes entries. Messages are short English sentences and never hold request bodies.
 *
 * @package Shakewell\ActivityLog
 */

namespace Shakewell\ActivityLog;

defined( 'ABSPATH' ) || exit;

/**
 * Hook handlers, one per event type.
 */
final class Logger {

	/**
	 * Option names whose old and new value are safe to store. Every other option is logged by name only.
	 */
	private const SAFE_VALUE_OPTIONS = array( 'blogname', 'blogdescription', 'siteurl', 'home', 'users_can_register', 'default_role', 'permalink_structure', 'timezone_string', 'blog_public', 'show_on_front', 'WPLANG' );

	/**
	 * Option names that are logged at all (name only unless also in SAFE_VALUE_OPTIONS).
	 */
	private const LOGGED_OPTIONS = array( 'admin_email', 'gmt_offset', 'date_format', 'time_format', 'page_on_front', 'page_for_posts', 'posts_per_page', 'default_comment_status', 'comment_moderation', 'active_plugins' );

	/**
	 * User ids already logged as logging in during this request (wp_login and set_auth_cookie both fire).
	 *
	 * @var int[]
	 */
	private static array $logged_in = array();

	/**
	 * Registers every listener.
	 */
	public static function register(): void {
		add_action( 'transition_post_status', array( self::class, 'post_transition' ), 10, 3 );
		add_action( 'post_updated', array( self::class, 'post_updated' ), 10, 3 );
		add_action( 'before_delete_post', array( self::class, 'post_deleted' ), 10, 2 );
		add_action( 'add_attachment', array( self::class, 'media_uploaded' ) );
		add_action( 'attachment_updated', array( self::class, 'media_edited' ) );
		add_action( 'delete_attachment', array( self::class, 'media_deleted' ), 10, 2 );
		add_action( 'wp_login', array( self::class, 'login' ), 10, 2 );
		add_action( 'set_auth_cookie', array( self::class, 'auth_cookie' ), 10, 4 );
		add_action( 'wp_login_failed', array( self::class, 'login_failed' ) );
		add_action( 'wp_logout', array( self::class, 'logout' ) );
		add_action( 'user_register', array( self::class, 'user_created' ) );
		add_action( 'delete_user', array( self::class, 'user_deleted' ) );
		add_action( 'set_user_role', array( self::class, 'role_changed' ), 10, 3 );
		add_action( 'profile_update', array( self::class, 'email_changed' ), 10, 2 );
		add_action( 'activated_plugin', array( self::class, 'plugin_activated' ) );
		add_action( 'deactivated_plugin', array( self::class, 'plugin_deactivated' ) );
		add_action( 'deleted_plugin', array( self::class, 'plugin_deleted' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( self::class, 'upgraded' ), 10, 2 );
		add_action( 'switch_theme', array( self::class, 'theme_switched' ), 10, 2 );
		add_action( 'updated_option', array( self::class, 'option_changed' ), 10, 3 );
		add_action( 'added_option', array( self::class, 'option_added' ), 10, 2 );
	}

	/**
	 * Whether a post is one we log: not a revision, autosave, menu item or other ignored type.
	 *
	 * @param \WP_Post $post The post.
	 */
	private static function tracked( $post ): bool {
		if ( ! $post instanceof \WP_Post || 'attachment' === $post->post_type || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return false;
		}
		$ignored = (array) apply_filters( 'shakewell_activity_log_ignored_post_types', array( 'revision', 'nav_menu_item', 'oembed_cache', 'customize_changeset', 'user_request' ) );
		return ! in_array( $post->post_type, $ignored, true );
	}

	/**
	 * Logs a post event.
	 *
	 * @param string   $action Action name.
	 * @param string   $verb   Past-tense verb for the message.
	 * @param \WP_Post $post   The post.
	 */
	private static function post_event( string $action, string $verb, \WP_Post $post ): void {
		Store::log(
			$action,
			sprintf( '%s %s "%s"', $verb, $post->post_type, '' === $post->post_title ? '(no title)' : $post->post_title ),
			array(
				'object_type' => $post->post_type,
				'object_id'   => $post->ID,
			)
		);
	}

	/**
	 * Created, published, unpublished, trashed, restored.
	 *
	 * @param string   $to   New status.
	 * @param string   $from Old status.
	 * @param \WP_Post $post The post.
	 */
	public static function post_transition( $to, $from, $post ): void {
		if ( ! self::tracked( $post ) || $to === $from ) {
			return;
		}
		if ( 'trash' === $to ) {
			self::post_event( 'post_trashed', 'Trashed', $post );
		} elseif ( 'trash' === $from ) {
			self::post_event( 'post_restored', 'Restored', $post );
		} elseif ( 'publish' === $to && 'publish' !== $from ) {
			self::post_event( 'post_published', 'Published', $post );
		} elseif ( 'publish' === $from ) {
			self::post_event( 'post_unpublished', 'Unpublished', $post );
		} elseif ( in_array( $from, array( 'new', 'auto-draft' ), true ) && 'auto-draft' !== $to ) {
			self::post_event( 'post_created', 'Created', $post );
		}
	}

	/**
	 * Updated: a save that does not change the status (status changes are logged above).
	 *
	 * @param int      $id     Post id.
	 * @param \WP_Post $after  Post after.
	 * @param \WP_Post $before Post before.
	 */
	public static function post_updated( $id, $after, $before ): void {
		if ( self::tracked( $after ) && $after->post_status === $before->post_status && 'auto-draft' !== $after->post_status && 'trash' !== $after->post_status ) {
			self::post_event( 'post_updated', 'Updated', $after );
		}
	}

	/**
	 * Deleted for good.
	 *
	 * @param int      $id   Post id.
	 * @param \WP_Post $post The post.
	 */
	public static function post_deleted( $id, $post = null ): void {
		$post = $post ?? get_post( $id );
		if ( self::tracked( $post ) && 'auto-draft' !== $post->post_status ) {
			self::post_event( 'post_deleted', 'Deleted', $post );
		}
	}

	/**
	 * Logs a media event.
	 *
	 * @param string $action Action name.
	 * @param string $verb   Verb for the message.
	 * @param int    $id     Attachment id.
	 */
	private static function media_event( string $action, string $verb, int $id ): void {
		Store::log(
			$action,
			sprintf( '%s media file "%s"', $verb, basename( (string) get_attached_file( $id ) ) ),
			array(
				'object_type' => 'attachment',
				'object_id'   => $id,
			)
		);
	}

	/**
	 * Media uploaded.
	 *
	 * @param int $id Attachment id.
	 */
	public static function media_uploaded( $id ): void {
		self::media_event( 'media_uploaded', 'Uploaded', (int) $id );
	}

	/**
	 * Media edited.
	 *
	 * @param int $id Attachment id.
	 */
	public static function media_edited( $id ): void {
		self::media_event( 'media_edited', 'Edited', (int) $id );
	}

	/**
	 * Media deleted (runs before the file goes).
	 *
	 * @param int $id Attachment id.
	 */
	public static function media_deleted( $id ): void {
		self::media_event( 'media_deleted', 'Deleted', (int) $id );
	}

	/**
	 * Successful login through wp_signon (and most SSO plugins that fire wp_login).
	 *
	 * @param string   $login Login name.
	 * @param \WP_User $user  The user.
	 */
	public static function login( $login, $user ): void {
		self::log_in( $user );
	}

	/**
	 * Successful login by SSO plugins that only call wp_set_auth_cookie().
	 *
	 * @param string $cookie     Unused.
	 * @param int    $expire     Unused.
	 * @param int    $expiration Unused.
	 * @param int    $user_id    User id.
	 */
	public static function auth_cookie( $cookie, $expire, $expiration, $user_id ): void {
		self::log_in( get_userdata( (int) $user_id ) );
	}

	/**
	 * Logs a login once per user per request.
	 *
	 * @param \WP_User|false $user The user.
	 */
	private static function log_in( $user ): void {
		if ( ! $user instanceof \WP_User || in_array( $user->ID, self::$logged_in, true ) ) {
			return;
		}
		self::$logged_in[] = $user->ID;
		Store::log(
			'login',
			'Logged in',
			array(
				'user'        => $user,
				'object_type' => 'user',
				'object_id'   => $user->ID,
			)
		);
	}

	/**
	 * Failed login: the username that was tried, never the password.
	 *
	 * @param string $username Username as typed.
	 */
	public static function login_failed( $username ): void {
		Store::log(
			'login_failed',
			'Failed login attempt',
			array(
				'user'       => new \WP_User( 0 ),
				'user_login' => (string) $username,
			)
		);
	}

	/**
	 * Logout. The current user is already cleared when this fires, so look the user up.
	 *
	 * @param int $user_id User id.
	 */
	public static function logout( $user_id ): void {
		$user = get_userdata( (int) $user_id );
		if ( $user ) {
			self::$logged_in = array_diff( self::$logged_in, array( $user->ID ) );
			Store::log(
				'logout',
				'Logged out',
				array(
					'user'        => $user,
					'object_type' => 'user',
					'object_id'   => $user->ID,
				)
			);
		}
	}

	/**
	 * User created.
	 *
	 * @param int $id New user id.
	 */
	public static function user_created( $id ): void {
		$u = get_userdata( (int) $id );
		Store::log(
			'user_created',
			sprintf( 'Created user "%s"', $u ? $u->user_login : $id ),
			array(
				'object_type' => 'user',
				'object_id'   => $id,
			)
		);
	}

	/**
	 * User deleted.
	 *
	 * @param int $id User id.
	 */
	public static function user_deleted( $id ): void {
		$u = get_userdata( (int) $id );
		Store::log(
			'user_deleted',
			sprintf( 'Deleted user "%s"', $u ? $u->user_login : $id ),
			array(
				'object_type' => 'user',
				'object_id'   => $id,
			)
		);
	}

	/**
	 * Role changed. Skipped for brand-new users (no old role) and for no-op re-assignments, which SSO plugins do on every login.
	 *
	 * @param int      $id    User id.
	 * @param string   $role  New role.
	 * @param string[] $old   Old roles.
	 */
	public static function role_changed( $id, $role, $old ): void {
		if ( empty( $old ) || array( $role ) === array_values( $old ) ) {
			return;
		}
		$u = get_userdata( (int) $id );
		Store::log(
			'user_role_changed',
			sprintf( 'Changed role of "%s" from %s to %s', $u ? $u->user_login : $id, implode( ', ', $old ), $role ),
			array(
				'object_type' => 'user',
				'object_id'   => $id,
			)
		);
	}

	/**
	 * Profile email changed. The addresses themselves are not stored.
	 *
	 * @param int      $id  User id.
	 * @param \WP_User $old User before.
	 */
	public static function email_changed( $id, $old ): void {
		$u = get_userdata( (int) $id );
		if ( $u && $old->user_email !== $u->user_email ) {
			Store::log(
				'user_email_changed',
				sprintf( 'Changed the email address of "%s"', $u->user_login ),
				array(
					'object_type' => 'user',
					'object_id'   => $id,
				)
			);
		}
	}

	/**
	 * Plugin activated.
	 *
	 * @param string $file Plugin file.
	 */
	public static function plugin_activated( $file ): void {
		Store::log( 'plugin_activated', sprintf( 'Activated plugin %s', $file ), array( 'object_type' => 'plugin' ) );
	}

	/**
	 * Plugin deactivated.
	 *
	 * @param string $file Plugin file.
	 */
	public static function plugin_deactivated( $file ): void {
		Store::log( 'plugin_deactivated', sprintf( 'Deactivated plugin %s', $file ), array( 'object_type' => 'plugin' ) );
	}

	/**
	 * Plugin deleted.
	 *
	 * @param string $file    Plugin file.
	 * @param bool   $deleted Whether the delete worked.
	 */
	public static function plugin_deleted( $file, $deleted ): void {
		if ( $deleted ) {
			Store::log( 'plugin_deleted', sprintf( 'Deleted plugin %s', $file ), array( 'object_type' => 'plugin' ) );
		}
	}

	/**
	 * Plugin or core updated.
	 *
	 * @param object $upgrader Unused.
	 * @param array  $extra    What was upgraded.
	 */
	public static function upgraded( $upgrader, $extra ): void {
		if ( 'update' !== ( $extra['action'] ?? '' ) ) {
			return;
		}
		if ( 'core' === ( $extra['type'] ?? '' ) ) {
			Store::log( 'core_updated', 'Updated WordPress core', array( 'object_type' => 'core' ) );
		} elseif ( 'plugin' === ( $extra['type'] ?? '' ) ) {
			foreach ( (array) ( $extra['plugins'] ?? ( isset( $extra['plugin'] ) ? array( $extra['plugin'] ) : array() ) ) as $file ) {
				Store::log( 'plugin_updated', sprintf( 'Updated plugin %s', $file ), array( 'object_type' => 'plugin' ) );
			}
		}
	}

	/**
	 * Theme switched.
	 *
	 * @param string    $name  New theme name.
	 * @param \WP_Theme $theme New theme.
	 */
	public static function theme_switched( $name, $theme ): void {
		Store::log( 'theme_switched', sprintf( 'Switched theme to %s', $theme->get_stylesheet() ), array( 'object_type' => 'theme' ) );
	}

	/**
	 * Option changed.
	 *
	 * @param string $option Name.
	 * @param mixed  $old    Old value.
	 * @param mixed  $to    New value.
	 */
	public static function option_changed( $option, $old, $to ): void {
		self::option_event( (string) $option, $old, $to );
	}

	/**
	 * Option created (first save of a setting).
	 *
	 * @param string $option Name.
	 * @param mixed  $value  Value.
	 */
	public static function option_added( $option, $value ): void {
		self::option_event( (string) $option, null, $value );
	}

	/**
	 * Logs an allow-listed option. Values are included only for the safe list, and only when scalar.
	 *
	 * @param string $option Name.
	 * @param mixed  $old    Old value.
	 * @param mixed  $to    New value.
	 */
	private static function option_event( string $option, $old, $to ): void {
		$logged = (array) apply_filters( 'shakewell_activity_log_options', array_merge( self::LOGGED_OPTIONS, self::SAFE_VALUE_OPTIONS ) );
		if ( ! in_array( $option, $logged, true ) ) {
			return;
		}
		$safe    = (array) apply_filters( 'shakewell_activity_log_safe_value_options', self::SAFE_VALUE_OPTIONS );
		$message = sprintf( 'Changed option %s', $option );
		if ( in_array( $option, $safe, true ) && is_scalar( $to ) && ( null === $old || is_scalar( $old ) ) ) {
			$message .= sprintf( ' from "%s" to "%s"', mb_substr( (string) $old, 0, 60 ), mb_substr( (string) $to, 0, 60 ) );
		}
		Store::log( 'option_changed', $message, array( 'object_type' => 'option' ) );
	}
}
