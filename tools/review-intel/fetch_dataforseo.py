#!/usr/bin/env python3
"""Pull competitor Google reviews automatically via the DataForSEO API.

Reads the target list from competitors.json, posts one Google Reviews task per
company, waits for DataForSEO to collect them, and writes everything to
data/reviews.csv. No manual copying anywhere.

Setup (uses the DataForSEO account you already have):
    export DATAFORSEO_LOGIN=...      # from app.dataforseo.com -> API Access
    export DATAFORSEO_PASSWORD=...

Usage:
    python3 fetch_dataforseo.py                     # all companies, 100 reviews each
    python3 fetch_dataforseo.py --depth 250         # more reviews per company
    python3 fetch_dataforseo.py --only "PriceCo"    # single company (cheap test run)

No third-party packages needed — stdlib only.
"""

import argparse
import base64
import csv
import json
import os
import re
import sys
import time
import urllib.request
from pathlib import Path

HERE = Path(__file__).parent
RAW_DIR = HERE / "data" / "raw"
OUT_CSV = HERE / "data" / "reviews.csv"
API = "https://api.dataforseo.com/v3/business_data/google/reviews"

CSV_FIELDS = ["company", "segment", "source", "rating", "date", "text", "owner_response"]


def auth_header() -> str:
    login = os.environ.get("DATAFORSEO_LOGIN")
    password = os.environ.get("DATAFORSEO_PASSWORD")
    if not login or not password:
        print("error: set DATAFORSEO_LOGIN and DATAFORSEO_PASSWORD", file=sys.stderr)
        print("       (app.dataforseo.com -> API Access)", file=sys.stderr)
        sys.exit(1)
    token = base64.b64encode(f"{login}:{password}".encode()).decode()
    return f"Basic {token}"


def call(method: str, path: str, body=None) -> dict:
    req = urllib.request.Request(
        f"{API}{path}",
        data=json.dumps(body).encode() if body is not None else None,
        method=method,
        headers={"Authorization": auth_header(), "Content-Type": "application/json"},
    )
    with urllib.request.urlopen(req, timeout=120) as resp:
        return json.load(resp)


def slugify(name: str) -> str:
    return re.sub(r"[^a-z0-9]+", "-", name.lower()).strip("-")


def post_tasks(companies: list[dict], depth: int) -> dict:
    """Post one reviews task per company. Returns {task_id: company}."""
    payload = [
        {
            "keyword": c["google_query"],
            "location_name": "United States",
            "language_name": "English",
            "depth": depth,
            "sort_by": "newest",
        }
        for c in companies
    ]
    response = call("POST", "/task_post", payload)
    task_map = {}
    for task, company in zip(response.get("tasks", []), companies):
        if task.get("status_code") in (20000, 20100):  # ok / task created
            task_map[task["id"]] = company
            print(f"  queued: {company['name']} (task {task['id']})")
        else:
            print(
                f"  WARN {company['name']}: {task.get('status_code')} "
                f"{task.get('status_message')}",
                file=sys.stderr,
            )
    return task_map


def collect_results(task_map: dict, poll_seconds: int = 30) -> list[dict]:
    """Poll task_get for each task until all are finished. Returns review rows."""
    pending = dict(task_map)
    rows = []
    while pending:
        time.sleep(poll_seconds)
        for task_id in list(pending):
            company = pending[task_id]
            result = call("GET", f"/task_get/{task_id}")
            task = result.get("tasks", [{}])[0]
            code = task.get("status_code")
            if code == 40602:  # task in queue / handed to another process
                continue
            if code != 20000:
                print(
                    f"  WARN {company['name']}: {code} {task.get('status_message')}",
                    file=sys.stderr,
                )
                del pending[task_id]
                continue

            items = []
            for r in task.get("result") or []:
                items.extend(r.get("items") or [])
            (RAW_DIR / f"{slugify(company['name'])}-google.json").write_text(
                json.dumps(items, indent=2)
            )
            kept = 0
            for item in items:
                rating = item.get("rating")
                if isinstance(rating, dict):
                    rating = rating.get("value")
                text = (item.get("review_text") or item.get("original_review_text") or "").strip()
                if not text:
                    continue  # star-only reviews carry no language signal
                rows.append(
                    {
                        "company": company["name"],
                        "segment": company["segment"],
                        "source": "google",
                        "rating": rating,
                        "date": (item.get("timestamp") or "")[:10],
                        "text": text,
                        "owner_response": (item.get("owner_answer") or "").strip(),
                    }
                )
                kept += 1
            print(f"  done: {company['name']} — {len(items)} reviews, {kept} with text")
            del pending[task_id]
        if pending:
            print(f"  waiting on {len(pending)} task(s)...")
    return rows


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--depth", type=int, default=100, help="reviews per company")
    parser.add_argument("--only", help="run a single company by (partial) name")
    args = parser.parse_args()

    auth_header()  # fail fast on missing credentials

    config = json.loads((HERE / "competitors.json").read_text())
    companies = [config["own_business"], *config["competitors"]]
    if args.only:
        companies = [c for c in companies if args.only.lower() in c["name"].lower()]
        if not companies:
            print(f"error: no company matching {args.only!r}", file=sys.stderr)
            return 1

    RAW_DIR.mkdir(parents=True, exist_ok=True)
    print(f"Posting {len(companies)} review task(s), depth {args.depth}...")
    task_map = post_tasks(companies, args.depth)
    if not task_map:
        print("error: no tasks were accepted", file=sys.stderr)
        return 1

    print("Collecting results (DataForSEO usually takes a few minutes)...")
    rows = collect_results(task_map)

    OUT_CSV.parent.mkdir(parents=True, exist_ok=True)
    with OUT_CSV.open("w", newline="", encoding="utf-8") as f:
        writer = csv.DictWriter(f, fieldnames=CSV_FIELDS)
        writer.writeheader()
        writer.writerows(rows)
    print(f"\nWrote {len(rows)} reviews -> {OUT_CSV}")
    print("Next: hand the CSV to Claude in-session for the language bank + pages.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
