#!/usr/bin/env python3
"""Generate the market love/hate report from analyzed reviews.

Aggregates the per-review aspect tags from analyze_reviews.py into a markdown
report, then asks Claude for a strategic synthesis: what the MD/DC market loves,
what it hates, and which competitor weaknesses Midland can turn into positioning.

Usage:
    python report.py                 # writes data/market-report.md
    python report.py --no-synthesis  # stats only, no API call
"""

import argparse
import json
import sys
from collections import Counter, defaultdict
from pathlib import Path

import anthropic

HERE = Path(__file__).parent
MODEL = "claude-opus-4-8"

ASPECT_LABELS = {
    "pricing_value": "Pricing / value",
    "quality_of_work": "Quality of work",
    "scheduling_punctuality": "Scheduling / punctuality",
    "communication": "Communication",
    "crew_professionalism": "Crew professionalism",
    "cleanliness": "Cleanliness / site care",
    "product_selection": "Product selection",
    "durability_results": "Durability / lasting results",
    "sales_experience": "Sales / estimate experience",
    "responsiveness_followup": "Responsiveness / follow-up",
}


def aspect_table(reviews: list[dict]) -> str:
    pos, neg, total = Counter(), Counter(), Counter()
    for r in reviews:
        for m in r["aspects"]:
            total[m["aspect"]] += 1
            if m["sentiment"] == "positive":
                pos[m["aspect"]] += 1
            elif m["sentiment"] in ("negative", "mixed"):
                neg[m["aspect"]] += 1
    lines = ["| Aspect | Mentions | Positive | Negative/Mixed |", "|---|---|---|---|"]
    for aspect, count in total.most_common():
        lines.append(
            f"| {ASPECT_LABELS.get(aspect, aspect)} | {count} "
            f"| {pos[aspect]} ({pos[aspect] * 100 // count}%) "
            f"| {neg[aspect]} ({neg[aspect] * 100 // count}%) |"
        )
    return "\n".join(lines)


def top_quotes(reviews: list[dict], sentiment: str, limit: int = 10) -> str:
    quotes = []
    for r in reviews:
        for m in r["aspects"]:
            if m["sentiment"] == sentiment and m["quote"]:
                quotes.append(f'- "{m["quote"]}" — {r["company"]}, {r["rating"]}★ ({ASPECT_LABELS.get(m["aspect"], m["aspect"])})')
    return "\n".join(quotes[:limit]) or "_none_"


def build_stats_report(reviews: list[dict]) -> str:
    by_segment = defaultdict(list)
    by_company = defaultdict(list)
    for r in reviews:
        by_segment[r["segment"]].append(r)
        by_company[r["company"]].append(r)

    parts = [
        "# Review Intelligence — MD/DC Commercial Flooring & Residential Carpeting",
        f"\n_{len(reviews)} reviews analyzed across {len(by_company)} companies._\n",
        "## Aspect sentiment — whole market\n",
        aspect_table(reviews),
    ]
    for segment, seg_reviews in sorted(by_segment.items()):
        parts += [f"\n## Segment: {segment} ({len(seg_reviews)} reviews)\n", aspect_table(seg_reviews)]

    parts.append("\n## Per-company breakdown\n")
    for company, comp_reviews in sorted(by_company.items(), key=lambda kv: -len(kv[1])):
        ratings = [float(r["rating"]) for r in comp_reviews if r.get("rating")]
        avg = sum(ratings) / len(ratings) if ratings else 0
        negatives = sum(1 for r in comp_reviews if r["overall_sentiment"] in ("negative", "mixed"))
        parts += [
            f"### {company} — {len(comp_reviews)} reviews, avg {avg:.1f}★, "
            f"{negatives} negative/mixed\n",
            aspect_table(comp_reviews),
            "",
        ]

    parts += [
        "\n## What the market loves (sample quotes)\n",
        top_quotes(reviews, "positive"),
        "\n## What the market hates (sample quotes)\n",
        top_quotes(reviews, "negative", limit=15),
    ]
    return "\n".join(parts)


def synthesize(stats_report: str, reviews: list[dict], model: str) -> str:
    client = anthropic.Anthropic()
    negative_detail = json.dumps(
        [
            {"company": r["company"], "rating": r["rating"], "text": r["text"][:600]}
            for r in reviews
            if r["overall_sentiment"] in ("negative", "mixed")
        ][:80]
    )
    with client.messages.stream(
        model=model,
        max_tokens=16000,
        thinking={"type": "adaptive"},
        system=(
            "You are a local-marketing strategist for Midland Floor Care, a commercial "
            "floor care company serving Maryland and Washington DC (midlandfloors.com). "
            "You are given aggregated review-sentiment data for the regional commercial "
            "flooring and residential carpeting market, including competitors."
        ),
        messages=[
            {
                "role": "user",
                "content": (
                    "Using the aggregate data and the raw negative reviews below, write a "
                    "strategic synthesis with three sections:\n"
                    "1. **What this market loves** — the recurring drivers of 5-star reviews.\n"
                    "2. **What this market hates** — the recurring complaints, ranked by "
                    "frequency, with which competitors are most exposed on each.\n"
                    "3. **Positioning opportunities for Midland** — concrete marketing angles, "
                    "ad copy hooks, and website proof points that exploit the gaps "
                    "(tie each to a specific complaint pattern).\n\n"
                    f"AGGREGATE DATA:\n{stats_report}\n\n"
                    f"RAW NEGATIVE/MIXED REVIEWS (JSON):\n{negative_detail}"
                ),
            }
        ],
    ) as stream:
        message = stream.get_final_message()
    return next(b.text for b in message.content if b.type == "text")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--input", default=str(HERE / "data" / "analyzed.json"))
    parser.add_argument("--output", default=str(HERE / "data" / "market-report.md"))
    parser.add_argument("--no-synthesis", action="store_true", help="skip the Claude synthesis")
    parser.add_argument("--model", default=MODEL)
    args = parser.parse_args()

    input_path = Path(args.input)
    if not input_path.exists():
        print(f"error: {input_path} not found — run analyze_reviews.py first", file=sys.stderr)
        return 1

    reviews = json.loads(input_path.read_text())
    report = build_stats_report(reviews)

    if not args.no_synthesis:
        print("Generating strategic synthesis...")
        report += "\n\n---\n\n# Strategic Synthesis\n\n" + synthesize(report, reviews, args.model)

    output_path = Path(args.output)
    output_path.write_text(report)
    print(f"Wrote report -> {output_path}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
