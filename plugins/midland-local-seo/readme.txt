=== Midland Local SEO ===
Contributors: midlandfloorcare
Tags: local seo, google business profile, citations, schema, rank tracking
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A complete local-SEO command center for Midland Floors — citations, identity schema, geo-grid rank tracking, GBP mirroring & optimization, backlinks, and competitor audits.

== Description ==

Midland Local SEO bundles the seven pillars of a local-SEO program into one admin area, powered by your own DataForSEO account (bring your own key).

**The 7 pillars:**

1. **Citation Audit** — Track your NAP listings across ~20 curated local directories (Google Business, Bing Places, Apple Maps, Yelp, BBB, Nextdoor, Angi, HomeAdvisor, and more). Records status, listing URL, and the NAP exactly as listed; computes a citation score and flags Name/Phone inconsistencies against your canonical identity.
2. **sameAs / Identity** — Emits LocalBusiness JSON-LD on every page with your full profile URL set (Google Business, Apple Maps, Bing Places, Facebook, Instagram, LinkedIn, Yelp, BBB, Nextdoor, YouTube, Angi, HomeAdvisor, Thumbtack) so Google can confirm your entity in the Knowledge Graph.
3. **Geo-Grid (Local Falcon)** — NxN keyword rank scan around a center point, processed asynchronously one cell per cron tick, rendered as a heat-map. Weekly cron + manual run.
4. **Local Backlinks** — Track link targets (domain | type | notes) and cross-reference them against the live referring-domains profile from DataForSEO; progress score + summary stats.
5. **GMB Mirror** — Turn your Google Business Profile categories and service areas into on-site service and location pages, with a one-click "Create draft" for anything missing.
6. **GMB Optimizer** — Scorecard of your Google Business Profile against best practices (categories, description length, photos, reviews, hours, claimed) with actionable fixes.
7. **GMB Competitor Audit** — Top Google Maps rivals for your category in your area vs. your listing, highlighting gaps where a rival beats you on rating, reviews, or photos.

A Dashboard ties it together with a summary of every pillar plus the DataForSEO credentials settings (encrypted at rest) and a one-click connection test.

== Installation ==

1. Upload the `midland-local-seo` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu in WordPress.
3. Open **Local SEO** in the admin menu and add your DataForSEO API login + password on the Dashboard.
4. Configure each module's submenu.

== Frequently Asked Questions ==

= Do I need a DataForSEO account? =

The Citation Audit and sameAs / Identity modules work without it. Geo-Grid, GMB Optimizer, GMB Mirror (category pull), Competitor Audit, and live backlink cross-referencing require a DataForSEO account (pay-as-you-go).

= Where is my API password stored? =

Encrypted with AES-256-CBC using a key derived from your site's auth salt, with a random IV per value. It is never echoed back into the settings field.

== Changelog ==

= 1.1.0 =
* sameAs now links your entire listing footprint (14 profiles incl. Bing, Apple Maps, MapQuest, YellowPages, Manta, Blue Book, Chamber) and merges every Citation Audit URL.
* New "Additional Listing URLs" field + one-click "Load Midland profile" to fill the whole identity form.
* Backlink Priority Score (0-100, ROI-weighted) with A/B/C tiers; DataForSEO link-gap discovery; weekly Insights email.
* Geo-Grid now tracks the true local pack (Google Maps), matching by name or domain.
* Fixes: dashboard fatal, schema @graph (geo + opening hours), object-cache-safe transient refresh, pre-filled Midland NAP defaults.

= 1.0.0 =
* Initial release: Citation Audit, sameAs / Identity, Geo-Grid, Local Backlinks, GMB Mirror, GMB Optimizer, and GMB Competitor Audit, plus the Local SEO dashboard and DataForSEO integration.
