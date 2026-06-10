#!/usr/bin/env python3
"""Fetch Google Maps and Yelp reviews for the MD/DC flooring market via Outscraper.

Outscraper handles the actual scraping (proxies, bot detection, pagination) —
this script just drives their REST API and normalizes the results into one CSV
that analyze_reviews.py consumes.

Setup:
    pip install -r requirements.txt
    export OUTSCRAPER_API_KEY=...   # from https://app.outscraper.com/profile

Usage:
    python fetch_reviews.py                          # all companies, Google only
    python fetch_reviews.py --yelp                   # also pull Yelp (needs yelp_url per company)
    python fetch_reviews.py --only "CB Flooring"     # single company
    python fetch_reviews.py --limit 250              # reviews per company (default 100)

Output:
    data/raw/<company>-google.json   raw API responses (kept for re-runs)
    data/reviews.csv                 normalized: company, segment, source, rating,
                                     date, text, owner_response
"""

import argparse
import csv
import json
import os
import re
import sys
import time
import urllib.parse
import urllib.request
from pathlib import Path

HERE = Path(__file__).parent
RAW_DIR = HERE / "data" / "raw"
OUT_CSV = HERE / "data" / "reviews.csv"
API_BASE = "https://api.app.outscraper.com"

CSV_FIELDS = ["company", "segment", "source", "rating", "date", "text", "owner_response"]


def slugify(name: str) -> str:
    return re.sub(r"[^a-z0-9]+", "-", name.lower()).strip("-")


def api_get(path: str, params: dict, api_key: str) -> dict:
    url = f"{API_BASE}{path}?{urllib.parse.urlencode(params, doseq=True)}"
    req = urllib.request.Request(url, headers={"X-API-KEY": api_key})
    with urllib.request.urlopen(req, timeout=120) as resp:
        return json.load(resp)


def fetch_async_result(request_id: str, api_key: str, poll_seconds: int = 15) -> dict:
    """Outscraper queues larger jobs; poll until the result is ready."""
    while True:
        result = api_get(f"/requests/{request_id}", {}, api_key)
        status = result.get("status")
        if status == "Success":
            return result
        if status in ("Failed", "Canceled"):
            raise RuntimeError(f"Outscraper request {request_id} ended with status {status}")
        print(f"  ...job {request_id} still {status}, waiting {poll_seconds}s")
        time.sleep(poll_seconds)


def fetch_google_reviews(query: str, limit: int, api_key: str) -> list[dict]:
    print(f"  Google: {query}")
    result = api_get(
        "/maps/reviews-v3",
        {"query": query, "reviewsLimit": limit, "sort": "newest", "language": "en"},
        api_key,
    )
    if result.get("status") == "Pending":
        result = fetch_async_result(result["id"], api_key)
    places = result.get("data", [])
    reviews = []
    for place in places:
        for r in place.get("reviews_data", []):
            reviews.append(
                {
                    "source": "google",
                    "rating": r.get("review_rating"),
                    "date": (r.get("review_datetime_utc") or "")[:10],
                    "text": (r.get("review_text") or "").strip(),
                    "owner_response": (r.get("owner_answer") or "").strip(),
                }
            )
    return reviews


def fetch_yelp_reviews(yelp_url: str, limit: int, api_key: str) -> list[dict]:
    print(f"  Yelp: {yelp_url}")
    result = api_get(
        "/yelp/reviews", {"query": yelp_url, "limit": limit, "sort": "date_desc"}, api_key
    )
    if result.get("status") == "Pending":
        result = fetch_async_result(result["id"], api_key)
    reviews = []
    for business in result.get("data", []):
        for r in business if isinstance(business, list) else [business]:
            reviews.append(
                {
                    "source": "yelp",
                    "rating": r.get("rating"),
                    "date": (r.get("date") or "")[:10],
                    "text": (r.get("text") or "").strip(),
                    "owner_response": "",
                }
            )
    return reviews


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--yelp", action="store_true", help="also fetch Yelp reviews")
    parser.add_argument("--only", help="fetch a single company by name")
    parser.add_argument("--limit", type=int, default=100, help="reviews per company")
    args = parser.parse_args()

    api_key = os.environ.get("OUTSCRAPER_API_KEY")
    if not api_key:
        print("error: set OUTSCRAPER_API_KEY (https://app.outscraper.com/profile)", file=sys.stderr)
        return 1

    config = json.loads((HERE / "competitors.json").read_text())
    companies = [config["own_business"], *config["competitors"]]
    if args.only:
        companies = [c for c in companies if args.only.lower() in c["name"].lower()]
        if not companies:
            print(f"error: no company matching {args.only!r}", file=sys.stderr)
            return 1

    RAW_DIR.mkdir(parents=True, exist_ok=True)
    rows = []
    for company in companies:
        print(f"{company['name']} ({company['segment']})")
        slug = slugify(company["name"])
        try:
            reviews = fetch_google_reviews(company["google_query"], args.limit, api_key)
            (RAW_DIR / f"{slug}-google.json").write_text(json.dumps(reviews, indent=2))
        except Exception as exc:
            print(f"  WARN google fetch failed: {exc}", file=sys.stderr)
            reviews = []

        if args.yelp and company.get("yelp_url"):
            try:
                yelp = fetch_yelp_reviews(company["yelp_url"], args.limit, api_key)
                (RAW_DIR / f"{slug}-yelp.json").write_text(json.dumps(yelp, indent=2))
                reviews.extend(yelp)
            except Exception as exc:
                print(f"  WARN yelp fetch failed: {exc}", file=sys.stderr)

        for r in reviews:
            if r["text"]:  # ratings-only reviews carry no aspect signal
                rows.append({"company": company["name"], "segment": company["segment"], **r})
        print(f"  {len(reviews)} reviews ({sum(1 for r in reviews if r['text'])} with text)")

    OUT_CSV.parent.mkdir(parents=True, exist_ok=True)
    with OUT_CSV.open("w", newline="", encoding="utf-8") as f:
        writer = csv.DictWriter(f, fieldnames=CSV_FIELDS)
        writer.writeheader()
        writer.writerows(rows)
    print(f"\nWrote {len(rows)} reviews -> {OUT_CSV}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
