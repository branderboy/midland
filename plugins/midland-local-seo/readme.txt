=== Midland Local SEO ===
Contributors: midlandfloorcare
Tags: local seo, google business profile, citations, schema, rank tracking
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.4.1
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

= 1.3.0 =
* Location + service pages are now fully self-contained in Local SEO (no Smart SEO dependency). Fixes location pages stuck on "Missing". New "Location Pages" module clones a page you designed so generated pages match your site exactly.

= 1.2.9 =
* Fix: location pages stayed marked "Missing" after creation. The list now detects the mfc_location page the engine creates (by city/state) and shows "Have it" correctly.

= 1.2.8 =
* Added one-click "Create all location pages" button (builds every service-area page in the Elementor template via the Smart SEO engine).

= 1.2.7 =
* GMB Mirror service pages now generate through the Smart SEO engine with the Elementor template (renders in your site design); added a one-click "Create all missing service pages" button.

= 1.2.6 =
* GMB Mirror service pages now generate full structured content (H2 sections, process, FAQ, no dashes) instead of a thin stub.

= 1.2.5 =
* Competitor Audit falls back to the organic SERP local pack when the Maps SERP endpoint is unavailable, so it works on more DataForSEO plans.

= 1.2.4 =
* Removed the Citation Audit consistency/mismatch column.

= 1.2.3 =
* Citation score now counts any directory with a listing URL (adding a URL marks it Listed); score no longer stuck low when URLs are present.

= 1.2.2 =
* Re-run the identity force-merge (new migration guard) so saved profiles pick up the latest canonical data on update.

= 1.2.1 =
* Citation Audit now pre-populated with your 13 known listings (was empty); YellowPages flagged for its wrong phone.

= 1.2.0 =
* Service Pages list cleaned up: dropped generic "Contractor" categories, added Carpet Installation + Hardwood Floor Cleaning.

= 1.1.9 =
* On update, force-refresh the saved profile to the canonical Midland data (one-time) so stale name/coords and blank listing URLs are corrected automatically.

= 1.1.8 =
* sameAs form now auto-fills every blank field with your Midland profile (existing installs no longer show empty profile URLs).

= 1.1.7 =
* Add Nextdoor listing to sameAs (15 listings total).

= 1.1.6 =
* GMB Competitor Audit + Optimizer now show a clear, actionable notice on DataForSEO authorization errors (instead of a raw error); checklists still render.

= 1.1.5 =
* More precise business coordinates (38.8222169, -76.9356121) for geo + geo-grid center.

= 1.1.4 =
* Geo-grid pre-filled with your top real keyword and your business location from the Profile.

= 1.1.3 =
* GMB Mirror now drives Service Page recommendations from your REAL, editable GBP category list (pre-filled with your actual categories) instead of live API guesses. Profile description synced to your GBP description.

= 1.1.2 =
* GMB Mirror/Optimizer now pull listing data from the SERP Maps API (serp/google/maps) instead of the Business Data API, so plans without Business Data access work. Authorization errors degrade to a friendly notice; location-page recommendations always render.

= 1.1.1 =
* Pre-fill business hours (Mon-Fri 9-5, Sat/Sun closed) so the schema emits openingHoursSpecification out of the box.

= 1.1.0 =
* sameAs now links your entire listing footprint (14 profiles incl. Bing, Apple Maps, MapQuest, YellowPages, Manta, Blue Book, Chamber) and merges every Citation Audit URL.
* New "Additional Listing URLs" field + one-click "Load Midland profile" to fill the whole identity form.
* Backlink Priority Score (0-100, ROI-weighted) with A/B/C tiers; DataForSEO link-gap discovery; weekly Insights email.
* Geo-Grid now tracks the true local pack (Google Maps), matching by name or domain.
* Fixes: dashboard fatal, schema @graph (geo + opening hours), object-cache-safe transient refresh, pre-filled Midland NAP defaults.

= 1.0.0 =
* Initial release: Citation Audit, sameAs / Identity, Geo-Grid, Local Backlinks, GMB Mirror, GMB Optimizer, and GMB Competitor Audit, plus the Local SEO dashboard and DataForSEO integration.
