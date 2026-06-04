# GEO Site Brain v2 — AI Visibility Command Center

> **Positioning shift:** from *"website embeddings tool"* to *"AI Visibility
> Command Center."* The product no longer builds embeddings — it builds an
> **AI‑readable version of the business**: a knowledge graph that ChatGPT,
> Claude, Gemini and Perplexity can understand and recommend.
>
> **Mental model:** `Business → Knowledge Graph → AI Understanding`
> (not `Site → Embeddings → Search`).
>
> **Invisible by rule:** the user never sees the words *embeddings, vectors,
> chunks, cosine similarity, retrieval, pgvector, Neon*. Those stay as plumbing.

---

## 1. Product surface (navigation)

| Screen | Question it answers | Built on |
|---|---|---|
| **Dashboard** | "How understandable is my business to AI?" | scores rollup |
| **Knowledge Graph** | "What does AI know about my business, and how is it connected?" | entities + relationships |
| **Scan Website** | "Read my site and build my business knowledge." | scanner + entity extraction |
| **AI Visibility Gaps** | "How would ChatGPT / Claude / Gemini / Perplexity describe me — and what can't they tell?" | visibility engine |
| **Fix Queue** | "What do I fix first, and can you do it for me?" | actionable fixes + apply handlers |
| **Ask My Website** | "Answer questions from my business knowledge." | agent over graph + content |
| **Reports** | "Give me a client‑facing summary." | report builder |
| **Settings** | "Connect AI." (advanced infra hidden) | settings |

---

## 2. Plugin architecture

```
                INVISIBLE INFRASTRUCTURE (unchanged core)
   ┌───────────────────────────────────────────────────────────────┐
   │ Scanner → Indexer → OpenAI(embeddings) → Vector Store(Neon|local)│
   └───────────────────────────────────────────────────────────────┘
                               │ scan analysis
                               ▼
   ┌───────────────────────────────────────────────────────────────┐
   │ ENTITY ENGINE        GSB_Entities                               │
   │   extract Business, Services, Locations, Products, FAQs,        │
   │   Testimonials, Authors, Case Studies, Reviews → gsb_entities   │
   │ KNOWLEDGE GRAPH      GSB_Knowledge_Graph                        │
   │   build relationships, find orphans / weak / missing links      │
   │ VISIBILITY ENGINE    GSB_Visibility                             │
   │   per-engine AI understanding scores + narrative simulation     │
   │ FIX ENGINE           GSB_Fixes                                  │
   │   problem · impact · reason · difficulty · one-click apply       │
   │ REPORTS              GSB_Reports                                │
   │ AGENT                GSB_Agent (queries the graph, not pages)   │
   └───────────────────────────────────────────────────────────────┘
                               │
                               ▼  business-language UI
        Dashboard · Knowledge Graph · Visibility · Fix Queue · Ask · Reports
```

Existing classes kept: `GSB_Database, GSB_Settings, GSB_Logger, GSB_OpenAI,
GSB_Vector_Store, GSB_Scanner, GSB_Indexer, GSB_Scorer`. New classes:
`GSB_Entities, GSB_Knowledge_Graph, GSB_Visibility, GSB_Fixes, GSB_Reports`.

Data flow (scan → understanding):

```
Scan Website
  → Scanner.analyze(post)               (services, locations, faqs, schema, NAP…)
  → Indexer (chunks + embeddings)        [invisible]
  → Scorer.score_post                    (page GEO sub-scores)
  → Entities.extract(analysis)           (upsert business-language entities)
  → KnowledgeGraph.rebuild               (relationships, gaps)
  → Visibility.recompute                 (per-engine scores + narrative)
  → Fixes.generate                       (actionable queue from gaps)
  → Dashboard / Graph / Visibility / Fix Queue / Reports render
```

---

## 3. Entity model

Every entity is one of these **types**, each with a **status** that mirrors the
agent's honesty rule:

- `found` — evidenced directly on the site
- `inferred` — derived from site signals
- `recommended` — missing; should be added

```
Business (1)
 ├─ Service        (offers)
 ├─ Location       (serves)
 ├─ Product        (offers)
 ├─ FAQ            (answers)
 ├─ Testimonial    (proof)
 ├─ Review         (proof)
 ├─ Author         (authority)
 └─ CaseStudy      (authority)

Relationships (gsb_relationships):
 Service  --offered_in-->  Location      (found if co-mentioned, else recommended)
 Service  --has_faq------>  FAQ
 Service  --proven_by---->  Testimonial/Review
 Post     --authored_by-->  Author
 CaseStudy--about-------->  Service/Location
 Entity   --sourced_from->  Page (evidence_post_id)
```

A **relationship strength** (0–100) reflects evidence: a service+location
co-mentioned on a dedicated page = strong; merely co-mentioned in body = weak;
not present but expected = a *missing* relationship (a fix).

The **service × location matrix** is the headline graph view: rows = services,
columns = locations, cells = found / weak / missing.

---

## 4. Database schema (additions to v1)

Kept v1 tables: `gsb_chunks, gsb_scores, gsb_logs, gsb_settings`.
`gsb_recommendations` is **extended** into the Fix Queue.

### `gsb_entities`
| col | type | notes |
|---|---|---|
| id | bigint PK | |
| entity_type | varchar(30) | business, service, location, product, faq, testimonial, review, author, case_study |
| name | varchar(255) | display name |
| slug | varchar(191) | unique per type |
| description | longtext | |
| attributes | longtext | JSON (e.g. question/answer, NAP, rating) |
| confidence | int | 0–100 |
| status | varchar(20) | found / inferred / recommended |
| source_post_id | bigint | primary evidence page |
| created_at / updated_at | datetime | |

Unique: `(entity_type, slug)`. Keys: `entity_type`, `status`.

### `gsb_relationships`
id, from_id, to_id, rel_type varchar(40), strength int(0–100),
status varchar(20) (found/inferred/recommended), evidence_post_id bigint,
created_at. Keys: from_id, to_id, rel_type.

### `gsb_visibility`
id, engine varchar(20) UNIQUE (chatgpt/claude/gemini/perplexity), visibility_score,
confidence_score, knowledge_score, recommendation_score (all int 0–100),
summary longtext (AI narrative), details longtext (JSON checklist), computed_at.

### `gsb_recommendations` (now the Fix Queue) — added columns
`impact varchar(10)` (critical/high/medium/low), `reason text`,
`difficulty varchar(10)` (easy/medium/hard), `fix_action varchar(40)`
(create_service_page | create_location_page | generate_meta | generate_faq_schema
| generate_localbusiness_schema | manual), `fix_payload longtext` (JSON),
`applied_at datetime`.

---

## 5. AI Visibility scoring model

Four site-level scores per engine, each 0–100, plus an overall **AI Visibility
Score** (average of the engines' visibility scores).

**Shared signals** (computed once from entities + page scores), each normalized 0–100:
`business_clarity, services, locations, faqs, testimonials, schema, trust,
answers, authority, freshness`.

**Per-engine weighting profiles** (what each engine cares about most):

| Signal | ChatGPT | Claude | Gemini | Perplexity |
|---|---|---|---|---|
| business_clarity | ●●● | ●● | ●● | ●● |
| services | ●●● | ●● | ●● | ●● |
| locations | ● | ● | ●●● | ●● |
| faqs | ●● | ●●● | ●● | ●●● |
| schema | ●●● | ●● | ●●● | ●● |
| trust | ●● | ●● | ●●● | ●●● |
| answers | ●● | ●●● | ●● | ●●● |
| authority | ●● | ●● | ●● | ●●● |
| freshness | ● | ● | ●● | ●●● |

- **Visibility** = weighted sum of signals by the engine profile.
- **Confidence** = f(business_clarity, schema, NAP consistency) — how
  unambiguously the engine can state facts.
- **Knowledge completeness** = coverage of *expected* entities (each configured
  service/location present? business description? ≥N FAQs? ≥N testimonials?).
- **Recommendation** = f(trust, reviews/testimonials, differentiators, answers)
  — how likely the engine is to *recommend* the business.

**"Can AI identify…?" checklist** (booleans surfaced per engine): what the
business does · service areas · expertise · trust signals · differentiators ·
authority · can answer customer questions.

**Narrative simulation (optional, when an AI key is present):** the engine is
asked to describe the business *using only the knowledge graph*, and to list
what it *cannot* determine. Gaps it reports become Fix Queue items. With no key,
scores + checklist still render (deterministic).

---

## 6. GEO scoring model (page-level, kept)

Unchanged v1 engine: ten weighted sub-scores per page feeding `trust`, `schema`,
`answers`, `authority` signals above. The page Scorecard remains, but it's now a
*supporting* view under the business-level Visibility narrative.

---

## 7. Fix Queue model

Every gap is an action, never a passive note:

```
Problem        No FAQ schema on "Commercial Carpet Cleaning"
Why it matters AI engines use structured FAQs to answer customer questions.
Impact         High
Difficulty     Easy
Action         [ Apply Fix ]  → generate_faq_schema
```

Apply handlers (all nonce + capability gated, reversible):
- `create_service_page` / `create_location_page` → create a **draft** page from a
  starter template, return the edit link (nothing goes live without review).
- `generate_meta` → write an AI meta title/description into the active SEO plugin
  field (Yoast/RankMath/AIOSEO).
- `generate_faq_schema` / `generate_localbusiness_schema` → build JSON-LD from the
  graph, store as post meta, and output via `wp_head` for that page.
- `manual` → guidance + deep link to the right editor.

---

## 8. Admin UI wireframes (text)

```
DASHBOARD
┌───────────────────────────────────────────────────────────┐
│  AI Visibility Score  72   │ Knowledge  64% │ Entities 38   │
│  [ChatGPT 70][Claude 75][Gemini 66][Perplexity 71]          │
│  Services 8  Locations 12  FAQs 9  Testimonials 4           │
│  ── Priority fixes ─────────  ── Recent changes ──────────  │
│  • Add FAQ schema (High)      • +3 services found            │
│  • Create "…in Bowie" (Crit)  • Visibility +6 this week      │
│  ── Growth over time ───── [ sparkline ] ─────────────────  │
└───────────────────────────────────────────────────────────┘

KNOWLEDGE GRAPH
┌───────────────────────────────────────────────────────────┐
│ Filter: [All ▼]   Status: found / inferred / recommended   │
│  Service × Location matrix                                  │
│            DC   Bethesda  Arlington  Bowie                  │
│  Carpet    ✓     ✓          weak       —(add)               │
│  Tile      ✓     —(add)     ✓          —(add)               │
│  Orphan entities: "Author: J. Smith" (no linked content)    │
└───────────────────────────────────────────────────────────┘

AI VISIBILITY GAPS
┌───────────────────────────────────────────────────────────┐
│ [ChatGPT]  Visibility 70 · Confidence 64 · Knowledge 60     │
│  "ChatGPT would describe you as…" (narrative)               │
│  ✓ what you do  ✓ service areas  ✗ differentiators  ✗ reviews│
│  → 3 fixes to improve                                       │
└───────────────────────────────────────────────────────────┘
```

---

## 9. Development roadmap

**Phase 1 — MVP (this build)**
- New navigation + business-language UI; all infra vocabulary hidden.
- Entity engine (business, services, locations, FAQs, testimonials, authors,
  case studies, reviews) + Knowledge Graph with service×location matrix, orphan
  & missing-link detection.
- AI Visibility engine: 4 engines × 4 scores + checklist + optional narrative.
- Fix Queue with impact/reason/difficulty + one-click apply handlers.
- Reports: Executive GEO + AI Visibility summary (printable, client-facing).
- Agent answers from the knowledge graph.

**Phase 2 — Advanced GEO**
- Real per-engine probing (live ChatGPT/Claude/Gemini/Perplexity calls) and
  citation tracking; differentiator mining; products & case-study modeling;
  graph visualization (D3) ; scheduled visibility tracking + email digests.

**Phase 3 — Enterprise**
- Competitive GEO (compare entity coverage vs competitors), multi-site rollups,
  white-label client reports & PDF export, team roles, API/webhooks, alerting
  when visibility drops.
```
