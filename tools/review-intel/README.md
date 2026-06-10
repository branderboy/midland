# Competitor Review Intelligence — MD/DC Flooring & Carpeting

Mirror the market: pull competitors' Google/Yelp reviews with **Outscraper**
(web dashboard, no code), drop the exports in this folder, and Claude analyzes
them in-session to produce keywords, trends, problems, opportunities, and new
site pages built directly into `content/services/`.

## Workflow

1. **Outscraper** (app.outscraper.com) → *Google Maps Reviews* task.
   Paste the queries from `competitors.json` (10–15 businesses per run is fine).
   Set reviews limit ~100 per business, sort by newest. Export **CSV or XLSX**.
   For Yelp, use the *Yelp Reviews* task with the business page URLs.
2. **Drop the export(s) into `tools/review-intel/data/`** — or just copy/paste
   the review text into the Claude session, either works.
3. **Claude analyzes** and delivers:
   - **Keywords** — the actual language customers use (service terms, pain
     terms, neighborhood/city mentions) mapped against the programmatic SEO
     plan in `programmatic-content.md`
   - **Trends** — what drives 5-star vs 1-star reviews per segment
     (commercial flooring vs residential carpet)
   - **Problems** — recurring complaints per competitor, ranked by frequency
   - **Opportunities** — gaps Midland can own (e.g. competitors hammered on
     scheduling → "on-time guarantee" positioning)
   - **Pages** — recommended page list, then built as HTML in
     `content/services/` matching the existing page format

## Columns that matter in the export

Keep at least: business name, review rating, review date, review text, owner
response. Everything else can be dropped.

## Target list

`competitors.json` holds the current 13-company target list (6 commercial
flooring, 7 residential carpet) with ready-to-paste Outscraper queries.
Edit freely before a run.
