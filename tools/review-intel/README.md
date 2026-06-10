# Review Intelligence — MD/DC Flooring & Carpeting Market

Pipeline that scrapes Google (and optionally Yelp) reviews for Midland Floor Care
and its commercial-flooring / residential-carpeting competitors in Maryland and
Washington DC, then runs aspect-based sentiment analysis to answer:

1. **What does this market love?**
2. **What does it dislike and hate?**
3. **Where are the positioning gaps Midland can exploit?**

## Pipeline

```
competitors.json ──▶ fetch_reviews.py ──▶ data/reviews.csv
                         (Outscraper)          │
                                               ▼
                                     analyze_reviews.py ──▶ data/analyzed.json
                                         (Claude API)            │
                                                                 ▼
                                                            report.py ──▶ data/market-report.md
```

## Setup

```bash
cd tools/review-intel
pip install -r requirements.txt
export OUTSCRAPER_API_KEY=...   # https://app.outscraper.com/profile  (~$3 per 1,000 Google reviews)
export ANTHROPIC_API_KEY=...    # https://platform.claude.com
```

## Run

```bash
python fetch_reviews.py --limit 100      # pull reviews for all companies in competitors.json
python analyze_reviews.py                # tag sentiment + aspects per review
python report.py                         # aggregate stats + strategic synthesis
```

The report lands at `data/market-report.md`: per-aspect sentiment tables for the
whole market, each segment, and each company, plus verbatim love/hate quotes and
a Claude-written strategy section (complaint patterns → ad copy / positioning angles).

## Notes

- **Companies** are seeded in `competitors.json` — 6 commercial flooring contractors
  (CB Flooring, Metro Flooring Contractors, Precision Flooring Services, GreenEdge,
  Abbey Commercial Flooring, Direct Solutions Flooring) and 7 residential carpet
  installers (JG Carpet, Maryland Carpet & Tile, PriceCo Floors, Moe's, The Carpet
  Center, Aladdin, Classic Carpets). Edit the file to add/remove companies; the
  `google_query` should be specific enough to match exactly one Google Maps listing.
- **Yelp** needs a `yelp_url` field per company in `competitors.json` (the business
  page URL), then run `fetch_reviews.py --yelp`. Yelp coverage for B2B commercial
  flooring is thin — Google is the primary signal for that segment.
- **Own reviews:** for Midland's own listing you can also export reviews directly
  from Google Business Profile (free, no scraping) and append them to
  `data/reviews.csv` in the same column format.
- **Cost:** ~1,300 reviews ≈ $4 of Outscraper credits + a few dollars of Claude API
  usage for analysis.
- Reviews with no text are skipped — star-only ratings carry no aspect signal.
- The aspect taxonomy (pricing, quality, scheduling, communication, crew,
  cleanliness, selection, durability, sales, follow-up) lives in
  `analyze_reviews.py`; counts aggregate cleanly because the model is constrained
  to that enum via structured outputs.
