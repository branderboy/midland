=== GEO Site Brain ===
Contributors: midlandfloorcare
Tags: geo, aeo, seo, embeddings, openai, neon, pgvector, ai, schema, recommendations
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.4.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An AI Visibility Command Center for WordPress: build an AI-readable version of
your business (a knowledge graph), see how ChatGPT, Claude, Gemini and
Perplexity understand and would recommend you, and work a one-click Fix Queue to
close the gaps.

== Description ==

GEO Site Brain scans your pages, posts, custom post types, services, locations,
FAQs and testimonials, breaks them into structured chunks (title, meta, H1,
sections, FAQs, CTAs, schema, internal links, location mentions), embeds them
with OpenAI, and stores the vectors in **Neon** (serverless Postgres +
pgvector). If Neon is unavailable or not configured, embeddings are stored in a
local table and similarity is computed in PHP — the plugin always works.

On top of the index it provides:

* **GEO Scoring Engine** — every page scored 1–100 from ten weighted dimensions
  (entity coverage, service clarity, location relevance, FAQ coverage, answer
  completeness, schema coverage, internal linking, trust signals, review usage,
  AI answer readiness), with a full breakdown.
* **Recommendations Engine** — missing FAQs/services/locations, weak pages,
  overlapping/duplicate pages, schema and internal-link gaps, meta rewrites, AI
  Overview answer blocks, and Google Business Profile post ideas.
* **Agent Chat** — retrieval-first Q&A that separates *Found on site*,
  *Inferred from site*, and *Recommended addition*, and never invents facts.

= Vector store: Neon =

Paste your Neon connection string in Settings and enable it. The pgvector
extension, table and HNSW index are created automatically. Requires the PDO
`pgsql` driver on the server; if it's missing, the local fallback is used.

== Installation ==

1. Upload the `geo-site-brain` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Go to **GEO Site Brain → Settings**, add your OpenAI API key and (optionally)
   your Neon connection string, set your business name, services and locations.
4. Go to **GEO Site Brain → Scan / Re-index** and click **Start full scan**.
5. Review **Scores**, **Recommendations**, and try **Agent Chat**.

== Security ==

All admin actions use nonces and the `manage_options` capability. Input is
sanitised, output escaped, and all SQL uses prepared statements. Secrets (OpenAI
key, Neon connection string) are stored privately and never returned to the
browser.

== Changelog ==

= 2.4.0 =
* Based on the maintainer's patched build (12 bug fixes) plus four hardening
  fixes and the REST API + webhooks reinstated on top:
  - Weekly cron now catches rebuild failures (lock/step) so it can't fatal or
    get stuck mid-run.
  - Differentiator scoring no longer uses GROUP_CONCAT (MySQL truncates it at
    ~1KB); it streams chunk text so the whole site is sampled.
  - Fix Queue rebuilds no longer duplicate items already in progress, applied,
    failed or dismissed (signature-based dedupe).
  - Hardened fix-apply rollbacks against a missing recommendation row.
* REST API (gsb/v1) + signed webhooks (visibility.updated, visibility.drop,
  fix.applied) restored.

= 2.2.0 =
* Competitive GEO: add competitor website URLs and analyse how AI-legible they
  are versus you — side-by-side AI score, services, locations, FAQs and schema,
  plus a per-service "who targets what" breakdown (advantage / parity / gap).
* Scheduled monitoring + email alerts: optional weekly AI Visibility digest with
  a drop alert, plus a "send test now" button.
* White-label reports: add your agency name, logo and footer to client-facing
  reports.

= 2.1.0 =
* Live per-engine probing: add a Claude, Gemini or Perplexity key (ChatGPT uses
  your OpenAI key) to probe the real models on the AI Visibility screen. Scores
  are derived from each model's actual answer vs your knowledge graph, with a
  Live / Estimated badge and "it identified / it missed" breakdown.
* Interactive business map on the Knowledge Graph screen — a dependency-free,
  draggable node-and-edge graph of your business, services and locations (no
  external library or CDN).

= 2.0.0 =
* Product refactor into an AI Visibility Command Center. New navigation:
  Dashboard, Knowledge Graph, Scan Website, AI Visibility Gaps, Fix Queue,
  Ask My Website, Reports, Settings.
* Business knowledge graph: extracts Business, Services, Locations, FAQs,
  Testimonials, Reviews, Authors and Case Studies as entities (found / inferred
  / recommended) with a Service × Location coverage matrix and orphan detection.
* AI Visibility engine: per-engine (ChatGPT/Claude/Gemini/Perplexity) scores —
  visibility, confidence, knowledge completeness, recommendation likelihood —
  plus a "can AI identify…?" checklist and an on-demand narrative.
* Fix Queue: every gap is an action with impact, reason and difficulty, plus
  one-click apply (create service/location draft pages, write meta descriptions,
  generate FAQ + LocalBusiness structured data).
* Client-facing Reports (printable). Agent now answers from the knowledge graph.
* Infrastructure vocabulary (embeddings, vectors, Neon) moved out of the way.

= 1.0.0 =
* Initial release: scanner + chunker, OpenAI embeddings, Neon pgvector store
  with local fallback, GEO scoring, recommendations, retrieval-first agent chat,
  save/delete hooks, weekly cron reindex, dashboard.
