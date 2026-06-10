#!/usr/bin/env python3
"""Aspect-based sentiment analysis of flooring/carpet reviews via the Claude API.

Reads data/reviews.csv (from fetch_reviews.py, or any CSV with the same columns),
tags each review with overall sentiment plus per-aspect sentiment, and writes
data/analyzed.json for report.py.

Setup:
    pip install -r requirements.txt
    export ANTHROPIC_API_KEY=...

Usage:
    python analyze_reviews.py
    python analyze_reviews.py --input data/reviews.csv --batch-size 15
"""

import argparse
import csv
import json
import sys
from enum import Enum
from pathlib import Path

import anthropic
from pydantic import BaseModel, Field

HERE = Path(__file__).parent
MODEL = "claude-opus-4-8"

# Aspects that matter for flooring/carpet buyers in this market. The model is
# constrained to this list so counts aggregate cleanly across companies.
class Aspect(str, Enum):
    pricing_value = "pricing_value"
    quality_of_work = "quality_of_work"
    scheduling_punctuality = "scheduling_punctuality"
    communication = "communication"
    crew_professionalism = "crew_professionalism"
    cleanliness = "cleanliness"
    product_selection = "product_selection"
    durability_results = "durability_results"
    sales_experience = "sales_experience"
    responsiveness_followup = "responsiveness_followup"


class Sentiment(str, Enum):
    positive = "positive"
    negative = "negative"
    mixed = "mixed"
    neutral = "neutral"


class AspectMention(BaseModel):
    aspect: Aspect
    sentiment: Sentiment
    quote: str = Field(description="Short verbatim phrase from the review supporting this")


class ReviewAnalysis(BaseModel):
    index: int = Field(description="The review's index as given in the input")
    overall_sentiment: Sentiment
    aspects: list[AspectMention]
    is_commercial_customer: bool = Field(
        description="True if the reviewer appears to be a business/commercial client"
    )


class BatchResult(BaseModel):
    analyses: list[ReviewAnalysis]


SYSTEM = """You are analyzing customer reviews of commercial flooring and residential \
carpeting companies in the Maryland / Washington DC market for competitive research.

For each review, identify the overall sentiment and every service aspect the reviewer \
explicitly mentions, with the sentiment toward that specific aspect. Only tag aspects \
actually discussed — do not infer aspects from the star rating alone. Quotes must be \
verbatim substrings of the review text."""


def analyze_batch(client: anthropic.Anthropic, reviews: list[dict], model: str) -> list[dict]:
    numbered = "\n\n".join(
        f"[{i}] ({r['company']}, {r['rating']}★, {r['date']})\n{r['text'][:2000]}"
        for i, r in enumerate(reviews)
    )
    response = client.messages.parse(
        model=model,
        max_tokens=16000,
        system=SYSTEM,
        messages=[{"role": "user", "content": f"Analyze these reviews:\n\n{numbered}"}],
        output_format=BatchResult,
    )
    results = []
    by_index = {a.index: a for a in response.parsed_output.analyses}
    for i, review in enumerate(reviews):
        analysis = by_index.get(i)
        if analysis is None:
            print(f"  WARN no analysis returned for review {i}", file=sys.stderr)
            continue
        results.append(
            {
                **review,
                "overall_sentiment": analysis.overall_sentiment.value,
                "is_commercial_customer": analysis.is_commercial_customer,
                "aspects": [
                    {"aspect": m.aspect.value, "sentiment": m.sentiment.value, "quote": m.quote}
                    for m in analysis.aspects
                ],
            }
        )
    return results


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--input", default=str(HERE / "data" / "reviews.csv"))
    parser.add_argument("--output", default=str(HERE / "data" / "analyzed.json"))
    parser.add_argument("--batch-size", type=int, default=15)
    parser.add_argument("--model", default=MODEL)
    args = parser.parse_args()

    input_path = Path(args.input)
    if not input_path.exists():
        print(f"error: {input_path} not found — run fetch_reviews.py first", file=sys.stderr)
        return 1

    with input_path.open(encoding="utf-8") as f:
        reviews = [r for r in csv.DictReader(f) if r.get("text", "").strip()]
    print(f"Analyzing {len(reviews)} reviews in batches of {args.batch_size}...")

    client = anthropic.Anthropic()
    analyzed = []
    for start in range(0, len(reviews), args.batch_size):
        batch = reviews[start : start + args.batch_size]
        print(f"  batch {start // args.batch_size + 1}: reviews {start}-{start + len(batch) - 1}")
        analyzed.extend(analyze_batch(client, batch, args.model))

    output_path = Path(args.output)
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(json.dumps(analyzed, indent=2))
    print(f"Wrote {len(analyzed)} analyzed reviews -> {output_path}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
