=== Midland GitHub Vault & Deploy ===
Contributors: midland
Tags: backup, github, deploy, git, sync
Requires at least: 5.6
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 3.4.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up WordPress content, database, themes, plugins, and uploads to a GitHub repo, and deploy page/post content from GitHub back into WordPress.

== Description ==

Midland GitHub Vault & Deploy treats a GitHub repository as a bidirectional source of truth for a WordPress site. The plugin can:

* **Back up** posts, pages, database, themes, plugins, and uploads to a configured GitHub repo on a schedule or on demand.
* **Deploy** page/post HTML from the repo back into WordPress, including Yoast SEO meta and JSON-LD structured data.
* **Round-trip Elementor data** so edits made in git render on the frontend (not just stored in `post_content`).
* **Purge page caches** automatically after a successful deploy — detects WP Rocket, LiteSpeed, W3 Total Cache, WP Super Cache, Cache Enabler, Autoptimize, Hummingbird, SiteGround, Kinsta, GoDaddy WPaaS, and the official Cloudflare plugin.
* **Verify** each deploy reached the frontend by fetching a sample URL and checking for the expected post title, warning you if a cache is still serving stale HTML.
* Expose an audit log of every file a deploy created, updated, skipped, or failed.

= What this plugin deploys =

* `pages/*.html` → WordPress pages
* `posts/*.html` → WordPress blog posts
* `content/elementor/{slug}.json` → `_elementor_data` for the matching page

= What it does not deploy =

* `.htaccess` / server configs
* Plugin ZIP files
* Markdown documentation
* Elementor global headers/footers / library templates

These must be uploaded manually.

= Third-party services =

This plugin communicates with the following third-party services. Data leaves your site only for the purposes described below, and only after you configure the integration.

**1. GitHub API** — `https://api.github.com`

* **What is sent:** the content you choose to back up (posts, pages, DB dump, themes, plugins, uploads) and HTTPS requests using your personal access token.
* **When:** on manual backup, scheduled backup, or deploy (pull).
* **Required to use the plugin:** yes.
* [GitHub Terms of Service](https://docs.github.com/en/site-policy/github-terms) · [GitHub Privacy Statement](https://docs.github.com/en/site-policy/privacy-policies/github-privacy-statement)

**2. Anthropic API** — `https://api.anthropic.com`

* **What is sent:** post title + post content of whatever page you explicitly run the AI helper on.
* **When:** ONLY when (a) you save an Anthropic API key in Settings → AI Assistant AND (b) you tick the "I understand this sends content to api.anthropic.com" consent checkbox AND (c) you explicitly click an AI action on a specific post. No automatic sends, no batch processing.
* **Required to use the plugin:** no. Every AI feature is optional.
* [Anthropic Terms of Service](https://www.anthropic.com/legal/consumer-terms) · [Anthropic Privacy Policy](https://www.anthropic.com/legal/privacy)

No data is sent to the plugin author.

== Installation ==

1. Upload the plugin ZIP via Plugins → Add New → Upload.
2. Activate through the Plugins menu in WordPress.
3. Go to Tools → Midland GitHub Vault → Settings and provide a GitHub personal access token (scopes: `repo`).
4. Configure your target repo, schedule, and deploy branch.

== Frequently Asked Questions ==

= Does deploying overwrite my pages? =

Yes. The deploy button imports HTML files from the repo into WordPress pages with matching slugs. Existing content is updated in place. Keep WordPress as the source of truth if you are not comfortable with this workflow.

= Will it work with my page builder? =

Elementor is fully supported — `_elementor_data` round-trips through the export and deploy pipeline. Other builders (Divi, Beaver Builder, Bricks) are not specifically handled; builder state may not deploy.

= Does it store my GitHub token securely? =

The token is stored in the WordPress options table with `autoload = no`. Access is restricted to users with the `manage_options` capability. For higher-security environments, consider defining the token as a PHP constant and extending the settings class to read from it.

= How do I uninstall cleanly? =

On uninstall, runtime state (transients, step progress) is always purged. Credentials and historical logs are preserved by default so a reinstall doesn't require reconfiguration. Set `wgb_purge_on_uninstall` to `1` in the options table, or define `WGB_PURGE_ON_UNINSTALL` in `wp-config.php`, before deleting the plugin to wipe everything.

== Screenshots ==

1. Deploy tab with scope explainer showing what the button does and does not touch.
2. Per-file audit after a deploy run — exactly which files were written, skipped, or failed.
3. Settings page for GitHub credentials and deploy target configuration.

== Changelog ==

= 3.3.0 =
* WordPress.org compliance refactor: real Plugin URI, Text Domain + Domain Path headers, load_plugin_textdomain hooked on plugins_loaded, Anthropic opt-in consent gate, webhook HMAC now mandatory (no-secret = 403), self-updater gated behind WGB_ALLOW_SELF_UPDATE constant, readme.txt in canonical wp.org format.
* Cache-purge logic extracted into WGB_Cache_Purge for maintainability.

= 3.2.0 =
* Auto-purge page caches on every successful deploy (WP Rocket, LiteSpeed, W3TC, WP Super Cache, Cache Enabler, Autoptimize, Hummingbird, SiteGround, Kinsta, GoDaddy WPaaS, Cloudflare).
* Live-verify after deploy: fetches the first imported page and confirms the new content rendered, warns when a stale cache is intercepting.
* Per-file audit list in the deploy result ("Written (3):" + "Skipped (91)" + "Failed (...)").
* Scope explainer on the Deploy tab.

= 3.1.0 =
* Per-file audit log surfaced to the admin UI.

= 3.0.0 =
* Honest "nothing changed" diagnostics instead of "0 items imported" with no explanation.

= 2.x =
* Elementor data round-trip in export + deploy.
* Incremental deploy watches `content/*` changes and re-imports matching HTML.
* `jobs-*.html` files route to `dpjp_job` custom post type when the post-type plugin is active.
* Fixed the deploy-cursor bug that silently skipped commits after an empty run.

== Upgrade Notice ==

= 3.2.0 =
Adds automatic cache purging and post-deploy verification. No data migration required.

== Privacy ==

This plugin transmits data to GitHub (backups, deploys) and optionally to Anthropic (AI helpers, opt-in only). No data is sent to the plugin author. No user tracking or analytics are embedded.
