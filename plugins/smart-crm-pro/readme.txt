=== Smart CRM PRO - Lead Reactivation ===
Contributors: midland
Tags: crm, lead reactivation, email campaigns, cold leads, contractor crm, win-back, database reactivation
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Database reactivation engine for contractors. Pull cold leads, segment by value, and send personalized win-back campaigns to restart dead conversations.

== Description ==

**Smart CRM PRO** turns your dead lead database into revenue.

Every contractor has hundreds of cold leads sitting in their system — people who asked for quotes but never closed. Smart CRM PRO identifies these leads, segments them by reactivation potential, and sends personalized win-back email campaigns to restart those conversations.

**What it does:**

* **Finds cold leads automatically** — Scans your Smart Forms leads for contacts 30+ days old that never converted
* **Segments by potential** — Groups leads into High-Value Quoted, Recent Cold, Lost Win-Back, and Aging Database
* **Scores reactivation potential** — Each lead gets a 0-100 score based on estimate value, status, and age
* **Pre-built campaign templates** — Ready-to-send email sequences for each segment
* **Follow-up automation** — Initial email + configurable follow-up with delay
* **Campaign analytics** — Track reactivation rate, emails sent, revenue recovered
* **Daily cold lead alerts** — Get notified when leads go cold so you can act fast

**The math:**

If you have 200 cold leads with an average project value of $3,000, and you reactivate just 5% — that's 10 jobs worth $30,000 in recovered revenue. From leads you already paid to acquire.

**Requires:** Smart Forms for Contractors (free plugin)

== Installation ==

1. Install and activate Smart Forms for Contractors (free)
2. Upload Smart CRM PRO via Plugins > Add New > Upload Plugin
3. Activate and go to Smart Forms > CRM PRO License
4. Enter your license key
5. Go to Smart Forms > Reactivation to see your cold leads
6. Create a campaign targeting a segment
7. Launch it — emails send automatically

== Frequently Asked Questions ==

= Do I need the free Smart Forms plugin? =

Yes. Smart CRM PRO reads leads from the Smart Forms database. Install Smart Forms for Contractors first.

= How does it find cold leads? =

It queries your sfco_leads table for leads older than 30 days with status new, contacted, quoted, or lost. You can customize the age range and filters per campaign.

= Will it spam my leads? =

No. Each lead only gets the initial email + one optional follow-up per campaign. Leads marked as Won or Lost are excluded. You control everything.

= What segments are available? =

Four segments: High-Value Quoted (quoted $2,500+ but didn't close), Recent Cold (30-90 days old), Lost Win-Back (marked as lost), and Aging Database (90+ days old).

== Changelog ==

= 1.0.0 =
* Initial release
* Cold lead detection and scoring
* 4-segment lead classification
* Campaign builder with pre-built templates
* Initial + follow-up email automation via WP Cron
* Reactivation analytics dashboard
* Daily cold lead alert emails
* License key activation

== Upgrade Notice ==

= 1.0.0 =
Initial release. Turn your dead leads into revenue.
