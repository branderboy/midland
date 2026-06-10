=== Real Smart SEO ===
Contributors: tagglefish
Tags: seo, ai seo, content analysis, google search console, pagespeed, seo report
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered SEO analysis that doesn't just tell you what's wrong — it fixes it.

== Description ==

Most SEO plugins give you a score. Real Smart SEO gives you a report, a plan, and one-click fixes.

Upload your Screaming Frog crawl, Google Search Console data, Google Analytics data, and PageSpeed results. Real Smart SEO sends everything to Perplexity Sonar and gets back a full prioritized report — then lets you apply fixes directly to your WordPress content with one click.

**What it does:**

* Analyzes your Screaming Frog crawl export (titles, metas, H1s, status codes, word count)
* Analyzes your GSC data (impressions, clicks, CTR, keyword rankings)
* Analyzes your GA data (sessions, bounce rate, engagement by page)
* Analyzes your PageSpeed / Core Web Vitals results
* Generates a full SEO report with Critical / High / Medium / Low priority issues
* Creates a prioritized action plan
* Identifies quick wins (under 30 minutes each)
* Identifies growth opportunities
* Applies fixes directly to WordPress — title tags, meta descriptions, content, alt text — in one click

**No OAuth. No app registration. No subscriptions.**

Upload CSV exports (or paste data), add your Perplexity API key, and analyze. That's it.

= Third-Party Services =

**Perplexity Sonar API**

This plugin sends your uploaded SEO data to Perplexity's API to generate reports and recommendations. Data sent includes the content of your uploaded CSV files and pasted text.

* You must provide your own Perplexity API key (create at perplexity.ai/settings/api)
* API URL: https://api.perplexity.ai/chat/completions
* Perplexity Terms of Service: https://www.perplexity.ai/hub/legal/terms-of-service
* Perplexity Privacy Policy: https://www.perplexity.ai/hub/legal/privacy-policy

No data is sent to Perplexity until you run a scan.

== Installation ==

1. Upload the `real-smart-seo` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Go to Real Smart SEO → Settings and enter your Perplexity API key.
4. Go to Real Smart SEO → New Scan, upload your data, and click Analyze.

= Getting your data =

**Screaming Frog:** Download the free version from screamingfrog.co.uk. Crawl your site, then go to Internal tab → Export → Export All (CSV).

**Google Search Console:** Go to Performance → Search Results. Set date to Last 3 months. Enable all metrics. Export → Download CSV. Repeat for the Pages tab.

**Google Analytics:** Go to Reports → Engagement → Pages and screens. Set date to Last 3 months. Download → CSV.

**PageSpeed:** Go to pagespeed.web.dev. Enter your URL. Copy and paste the results into the PageSpeed field.

== Frequently Asked Questions ==

= Do I need a paid Perplexity plan? =

Pay-as-you-go API credits are required. A typical scan costs roughly $0.01–$0.10 depending on data size and which Sonar model you select (sonar, sonar-pro, or sonar-reasoning).

= Does it work with Yoast or Rank Math? =

Yes. The plugin detects Yoast SEO and Rank Math automatically and writes fixes to the correct meta fields.

= Is my data sent anywhere besides Perplexity? =

No. Your data is stored in your WordPress database and sent only to Perplexity's API when you run a scan.

= What is the free version of Screaming Frog limited to? =

The free version crawls up to 500 URLs, which is sufficient for most small to medium sites.

== Changelog ==

= 2.1.0 =
* Refocused on organic SEO. The sameAs / Identity schema, Geo-Grid (Local Falcon) rank tracking, and Backlinks modules have moved to the separate **Midland Local SEO** plugin. All organic features remain: audit, AI analysis, one-click fixes with rollback, programmatic city × service pages, internal links, keyword clustering, content briefs, schema, GSC cleanup, IndexNow, page speed, and rank tracking.

= 1.0.1 =
* Docs only: replaced stale Anthropic / Claude mentions with Perplexity Sonar. The underlying code has been Perplexity-backed since the switch; readme.txt was the last leftover.

= 1.0.0 =
* Initial release
