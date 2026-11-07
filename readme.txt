=== Shakewell Activity Log ===
Contributors: shakewellagency
Tags: activity log, audit log, security, logging, accountability
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A record of who changed what in wp-admin that an administrator cannot quietly edit.

== Description ==

Shakewell Activity Log records who did what on your site: content changes, logins, user and role changes, plugin and theme changes and a short list of core settings. Entries are written to one database table and shown under Tools > Activity Log. Nothing in the admin can edit or delete an entry, and there is no REST endpoint. Optionally every entry is also written to the PHP error log, a destination WordPress administrators cannot change, so a log shipper (CloudWatch, Papertrail and so on) keeps an independent copy.

It is deliberately small and does not send data anywhere.

= What is logged =

* Posts and pages of any post type: created, updated, published, unpublished, trashed, restored, deleted. Revisions, autosaves and menu items are ignored (filterable).
* Media: uploaded, edited, deleted.
* Logins, including single sign-on that only sets the login cookie, failed logins (the username that was tried) and logouts.
* Users: created, deleted, role changed, email address changed.
* Plugins: activated, deactivated, updated, deleted. Themes: switched. WordPress core: updated.
* Options: only a fixed list of core settings (site title, URLs, admin email, permalinks, timezone, date formats, front page settings, default role, who can register, and the list of active plugins). The list is filterable.

Each entry holds: time (UTC), user ID and login at that moment, role, action, object type and ID, a short message and the IP address.

= What is never logged =

* Passwords. A failed login stores only the username that was typed.
* Request bodies, cookies, headers or post content.
* Option values, except for a short list of harmless ones (site title, tagline, URLs, default role, permalink structure, timezone, language, front page mode, search engine visibility, who can register). Every other option is logged by name only. The admin email and the list of active plugins are logged by name only.
* Email addresses. A changed email is logged as "changed", without the old or new address.

= Privacy =

The log contains usernames and, unless you turn it off, IP addresses. Both are personal data in many places. Turn IP storage off with `add_filter( 'shakewell_activity_log_ip', '__return_empty_string' );`. The address is the one PHP reports (`REMOTE_ADDR`). Behind a proxy or CDN that is the proxy's address; return the real client address from the same `shakewell_activity_log_ip` filter if you trust your proxy's header. The filter receives the address and a second argument, `'live'` for a request being logged or `'import'` for a row imported from Simple History. Only replace the address when the second argument is `'live'`: an imported row carries a historical address that the current request knows nothing about. Blanking the address, as in the example above, is safe for both. Tell your users the log exists in your privacy policy. Entries are deleted after the retention period.

= Retention =

Entries older than 365 days are deleted by a daily scheduled task, in batches. Change it in wp-config.php with `define( 'SHAKEWELL_ACTIVITY_LOG_RETENTION_DAYS', 180 );` (use 0 to keep everything), or with the `shakewell_activity_log_retention_days` filter. It is a constant and not a screen setting on purpose: someone who could shorten it from the admin could erase the evidence.

= Second destination: the PHP error log =

Add `define( 'SHAKEWELL_ACTIVITY_LOG_ERROR_LOG', true );` to wp-config.php and every entry is also written as one JSON line to the PHP error log, for example `{"channel":"shakewell_activity_log","time":"2026-11-06 01:02:03","action":"login","user_login":"alice", ...}`. It is off by default. To send entries somewhere else, hook the `shakewell_activity_log_entry_logged` action, which receives the entry as an array.

= The screen =

Tools > Activity Log (needs the `manage_options` capability, change it with the `shakewell_activity_log_capability` filter). Filter by user, action and date range, search, page through results and export the filtered list as CSV. Cells that start with = + - or @ are prefixed with an apostrophe so spreadsheets do not run them as formulas.

= Importing from Simple History =

If the Simple History tables are still in the database, run `wp shakewell-activity-log import-simple-history --dry-run` to see how many entries would be copied, then run it without `--dry-run`. Entries keep their original dates. Running it again skips what was already imported. Simple History's tables are only read, never changed. Only the fixed fields of each entry are copied (time, user, action, object, message, IP), not Simple History's other stored details.

= Filters =

`shakewell_activity_log_capability`, `shakewell_activity_log_ip`, `shakewell_activity_log_retention_days`, `shakewell_activity_log_ignored_post_types`, `shakewell_activity_log_options`, `shakewell_activity_log_safe_value_options`, `shakewell_activity_log_error_log_enabled`. Action: `shakewell_activity_log_entry_logged`.

= External services =

None. The plugin makes no outside requests.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it with Composer.
2. Activate it from the Plugins screen. The table is created on activation.
3. Open Tools > Activity Log.

== Frequently Asked Questions ==

= Can an administrator edit or delete entries? =

Not from WordPress: there is no edit or delete screen and no REST endpoint. An administrator with database access can, which is why the optional error-log destination exists. Send it to a log service they cannot change.

= Does it slow the site down? =

One small insert per event. Page views by visitors are not logged.

= What happens when I delete the plugin? =

The scheduled task and version setting are removed. The log table is kept on purpose. Drop the `shakewell_activity_log` table yourself if you want it gone.

= Why is an event missing? =

Changes made directly in the database, by WP-CLI scripts that bypass WordPress functions or by code that disables hooks are not seen.

= Where do I report a security problem? =

Please do not open a public issue. Follow SECURITY.md in the plugin's repository.

== Changelog ==

= 0.1.1 =
* Documented the IP address behind a proxy or CDN, and the readme contributor field.

= 0.1.0 =
* First release.
