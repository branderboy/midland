=== Midland Smart Reviews ===
Contributors: midland
Tags: reviews, google reviews, survey, contractor, reputation management
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Survey-gated Google review collection for contractors. Delighted customers go straight to Google; unhappy ones stay private.

== Description ==

**Midland Smart Reviews** closes the loop after every job without awkward review-begging.

After a job is marked complete in Midland Smart Forms, the plugin emails the customer a simple 1–5 star survey. High scores (configurable threshold) trigger the Google review link plus two automated follow-up reminders. Low scores capture private written feedback and notify the manager — no public review request is ever sent to a dissatisfied customer.

**How it works:**

* Listens for `sfco_lead_completed` and `sfco_lead_status_changed` actions from Midland Smart Forms / Smart CRM.
* Emails a branded survey with a secure, token-authenticated link (no login required).
* Score ≥ threshold → review link email + 2 timed reminders (24 h and 48 h).
* Score < threshold → private feedback captured, manager notified by email.
* All responses stored in a custom `srp_surveys` table for admin reporting.

**Requires:** Midland Smart Forms (smart-forms-for-midland)

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate **Midland Smart Reviews** through the *Plugins* menu.
3. Go to **Smart Reviews → Settings** and enter your Google review URL and score threshold.

== Frequently Asked Questions ==

= Does it work without Midland Smart Forms? =

No. The plugin listens for hooks fired by Midland Smart Forms / Smart CRM to know when a job is complete. It will activate but will never send surveys without those hooks.

= What email is used to send surveys? =

WordPress's built-in `wp_mail()`. Configure your outgoing mail (SMTP plugin recommended) before going live.

= How do I delete all data on uninstall? =

Set the option `srp_purge_on_uninstall` to `'1'` before deleting the plugin (e.g. via WP-CLI: `wp option update srp_purge_on_uninstall 1`). This drops the surveys table and all plugin options.

== Changelog ==

= 1.3.0 =
* Added deactivation hook to cancel scheduled cron reminders.
* Added languages/ directory and .pot file for translation readiness.
* Added uninstall.php with opt-in data purge.
* Added Update URI: false to prevent wp.org update hijacking.

= 1.2.0 =
* Improved email sanitization for owner notifications.
* Admin list table with filtering by score range.

= 1.0.0 =
* Initial release.
