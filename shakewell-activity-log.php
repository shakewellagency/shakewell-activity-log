<?php
/**
 * Plugin Name:       Shakewell Activity Log
 * Plugin URI:        https://github.com/shakewellagency/shakewell-activity-log
 * Description:       A record of who changed what in wp-admin that an administrator cannot quietly edit.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Shakewell
 * Author URI:        https://shakewell.agency
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shakewell-activity-log
 *
 * @package Shakewell\ActivityLog
 */

namespace Shakewell\ActivityLog;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.1.0';
const FILE    = __FILE__;

require_once __DIR__ . '/src/Store.php';
require_once __DIR__ . '/src/Logger.php';
require_once __DIR__ . '/src/ListTable.php';
require_once __DIR__ . '/src/Admin.php';
require_once __DIR__ . '/src/Importer.php';
require_once __DIR__ . '/src/Plugin.php';

register_activation_hook( __FILE__, array( Store::class, 'install' ) );
register_deactivation_hook( __FILE__, array( Store::class, 'unschedule' ) );
add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
