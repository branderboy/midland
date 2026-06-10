# Competitor Review Intelligence — MD/DC Flooring & Carpeting

Mirror the market: pull competitors' Google/Yelp reviews with **Outscraper**
(web dashboard, no code), drop the exports in this folder, and Claude analyzes
them in-session to produce keywords, trends, problems, opportunities, and new
site pages built directly into `content/services/`.

## Workflow (automated — DataForSEO)

```bash
export DATAFORSEO_LOGIN=...       # app.dataforseo.com -> API Access
export DATAFORSEO_PASSWORD=...
python3 tools/review-intel/fetch_dataforseo.py --only "PriceCo"   # cheap one-company test
python3 tools/review-intel/fetch_dataforseo.py                    # full 14-company run
```

One command pulls every Google review for all companies in `competitors.json`
into `data/reviews.csv` — no manual copying. Stdlib only, no packages to install.

Manual fallback: Outscraper export or straight GMB copy/paste into
`data/` (or into the Claude session) works the same.

Then **Claude analyzes in-session** (no client-side AI subscription needed —
output is plain markdown + HTML in the repo) and delivers:
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
