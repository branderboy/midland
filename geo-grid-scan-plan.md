# Geo-Grid Scan List + Local Optimization Plan

Built from the AdWords keyword export (Apr 2025 - Mar 2026), the GBP categories,
and the first live geo-grid results (2026-06-10): Midland is #2 in the map pack
at the center point behind "Francos carpet"; metroflooringcontractors.com is #1
organic. Each 5x5 scan is 25 DataForSEO calls, roughly $0.08 to $0.15. The full
weekly tier below costs under $1 per week.

Center point for all tracking scans: 38.8222169, -76.9356121 (keep it fixed so
week-over-week grids are comparable). Spacing 1.5 km, grid 5x5.

## Tier 1: Money keywords (scan WEEKLY, Measure = Map pack)

| # | Keyword | Why |
|---|---------|-----|
| 1 | carpet cleaning near me | 500K national volume head term, near-me intent = map pack |
| 2 | commercial carpet cleaning | core commercial revenue line |
| 3 | carpet cleaning services | 50K volume, service intent |
| 4 | commercial floor cleaning | facility/property manager searches |
| 5 | janitorial services | GBP category, recurring contract revenue |
| 6 | commercial floor contractors near me | already baselined at rank 2; defend it |

## Tier 2: Service categories (scan MONTHLY, Measure = Map pack)

| # | Keyword | Maps to |
|---|---------|---------|
| 7 | tile and grout cleaning | Tile cleaning service page |
| 8 | upholstery cleaning near me | Upholstery cleaning service page |
| 9 | hardwood floor refinishing | Wood floor refinishing service page |
| 10 | carpet installation near me | Carpet installation services page |
| 11 | rug cleaning service | 50K volume cluster from AdWords data |
| 12 | floor waxing service | strip and wax / commercial maintenance |

## Tier 3: Organic checks for the new pages (scan MONTHLY, Measure = Organic)

| # | Keyword | Watching |
|---|---------|----------|
| 13 | commercial carpet cleaning | organic gap vs metroflooringcontractors.com |
| 14 | flooring contractor washington dc | Flooring contractor page + hub |
| 15 | carpet cleaning [city] (rotate: Bethesda, Rockville, Arlington) | location pages indexing |

How to run: Geo-Grid page, change Keyword + Measure, Save Settings, Run Scan
Now. One at a time; each finishes in about 3 minutes. The weekly cron re-runs
whatever settings are saved, so leave keyword #1 saved as the default.

Quarterly expansion audit: temporarily move the center to Bethesda
(38.9847, -77.0947) and Arlington (38.8816, -77.0910) and run Tier 1 once to
see how rank decays toward the edge of the service area.

## Optimization Plan

### A. Win the map pack center (target: #1, beat "Francos carpet")
Francos outranks Midland at the center with NO website. That means it wins on
GBP signals alone: review count/velocity, categories, proximity.
1. Review velocity: Smart Reviews already fires the post-job survey funnel;
   confirm it is active on every completed CRM job so new 5-star reviews land
   weekly. Map pack rank follows review velocity more than any other signal.
2. Run Review Intel on Francos carpet (add it to the target list) and the
   other top-5 map listings from the sample table. Mine what their reviewers
   praise; respond to every Midland review using the same language.
3. GMB Optimizer page: fill every gap it flags (photos monthly, services list,
   Q&A seeded, booking link). Listings with weekly photo uploads get a
   measurable map boost.
4. Keep NAP identical everywhere (Citation Audit page tracks this).

### B. Voice-of-customer content loop (Review Intel)
1. Fetch reviews for all 13 competitors plus Francos.
2. Take the Language Bank top phrases and rewrite the hero intros and FAQs on
   the 8 service pages to mirror the market's words (the Mirror's rewrite
   buttons regenerate per page; mirror-owned pages refresh safely).
3. Take the Discontent Map top theme and ship the matching opportunity page
   with the one-click button (likely scheduling/communication based on market
   patterns); make it a pillar linked from the Services hub.

### C. Organic gap vs metroflooringcontractors.com
1. They are #1 organic at the center for commercial terms. Run the Backlinks
   link-gap tool against them + cbflooring.com (requires turning on Backlinks
   API access in the DataForSEO dashboard: one click, pay as you go).
2. Pitch the gap domains (suppliers, local directories, chambers) for listings.
3. All 15 mirror pages published + hubs publish; confirm IndexNow pinged
   (Smart SEO does this on publish) and watch GSC Coverage for the new URLs.

### D. Tracking cadence
- Weekly: Tier 1 grids (cron) + GSC clicks on /services/ and /service-areas/.
- Monthly: Tier 2 + Tier 3 scans, Review Intel re-fetch, GMB Optimizer pass.
- KPIs: center-cell map rank per Tier 1 keyword (target all top-3 in 90 days),
  green-cell count per grid (coverage radius), organic top-10 count for the 8
  service pages, review count delta vs Francos.

### Expected timeline
- Weeks 1-2: map pack moves first (review velocity + GBP optimization).
- Weeks 3-6: new service/location pages index and start appearing in Tier 3
  organic grids.
- Weeks 6-12: organic top-10 entries for commercial terms as link gap closes.
