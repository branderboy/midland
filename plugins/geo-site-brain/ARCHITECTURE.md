# GEO Site Brain — Architecture

GEO Site Brain turns a WordPress site's pages, posts, services, FAQs and
business content into an AI‑readable knowledge base using OpenAI embeddings.
Vectors live in **Neon** (serverless Postgres + `pgvector`), with a built‑in
local MySQL fallback so the plugin works out of the box even before Neon is
configured. On top of the index it runs a GEO/AEO scoring engine, a
recommendations engine, and a retrieval‑first admin chat agent.

> Vector store: **Neon (Postgres + pgvector)**, accessed from PHP over a TLS
> Postgres connection (PDO `pgsql`). If the `pgsql` PDO driver is unavailable
> or Neon is not configured, embeddings are stored in the local MySQL chunk
> table and similarity is computed in PHP. Same API surface either way.

---

## 1. Plugin architecture

```
                         ┌─────────────────────────────────────────┐
                         │            WordPress (PHP)               │
                         │                                          │
  save_post / delete  ──▶│  GSB_Indexer  ──▶  GSB_Scanner (chunks)  │
  manual / weekly cron    │       │                                 │
                         │       ├──▶ GSB_OpenAI (embeddings) ──────┼──▶ OpenAI API
                         │       │                                 │
                         │       └──▶ GSB_Vector_Store ────────────┼──▶ Neon (pgvector)
                         │              (Neon | local MySQL)        │      or local MySQL
                         │                                          │
                         │  GSB_Scorer ─▶ gsb_scores                │
                         │  GSB_Recommendations ─▶ gsb_recommends   │
                         │                                          │
                         │  GSB_Agent (retrieve → LLM, no halluc.)  │
                         │  GSB_Admin (dashboard / settings / chat) │
                         └─────────────────────────────────────────┘
```

Design principles:

- **Singleton bootstrap** (`GSB_Plugin`) mirroring the other Midland plugins.
- **One responsibility per class**; everything is lazy‑loaded.
- **Degrade gracefully** — no OpenAI key → no embeddings but scan/score still
  run; no Neon → local vector search; no errors thrown to the page.
- **Security first** — nonces, capability checks, sanitize on input, escape on
  output, prepared SQL, secrets never printed back to the browser.

---

## 2. Folder structure

```
geo-site-brain/
├── geo-site-brain.php              Main plugin file + GSB_Plugin bootstrap
├── uninstall.php                   Drops tables + options on delete
├── readme.txt                      WordPress.org-style readme
├── ARCHITECTURE.md                 This document
├── includes/
│   ├── class-gsb-database.php      Table schema, install/upgrade, CRUD
│   ├── class-gsb-logger.php        Structured logging → gsb_logs + admin notices
│   ├── class-gsb-settings.php      Option/state accessor (+ gsb_settings KV)
│   ├── class-gsb-openai.php        Embeddings + chat completions client
│   ├── class-gsb-vector-store.php  Neon (pgvector) client w/ local MySQL fallback
│   ├── class-gsb-scanner.php       Scan a post → ordered content chunks
│   ├── class-gsb-indexer.php       Orchestrates scan→embed→store; WP hooks/cron
│   ├── class-gsb-scorer.php        GEO/AEO scoring engine (1–100)
│   ├── class-gsb-recommendations.php  Recommendations engine
│   ├── class-gsb-agent.php         Retrieval-first chat agent (anti-hallucination)
│   ├── class-gsb-admin.php         Admin menu, settings, AJAX endpoints
│   └── views/
│       ├── dashboard.php           GEO score dashboard
│       ├── settings.php            Keys + Neon + scan options
│       ├── scan.php                Scan / re-index controls + progress
│       ├── scores.php              Per-page score table
│       ├── recommendations.php     Recommendations list
│       └── chat.php                Agent chat UI
├── assets/
│   ├── css/admin.css
│   └── js/admin.js                 Scan progress + chat + AJAX
└── languages/
    └── geo-site-brain.pot
```

---

## 3. Database schema

Local metadata lives in MySQL (created with `dbDelta`). Vectors live in Neon
when configured; the local `embedding` column doubles as the fallback store.

### `{prefix}gsb_chunks`
| column | type | notes |
|---|---|---|
| id | bigint PK | |
| post_id | bigint | source post/CPT id (0 for synthetic) |
| url | text | permalink |
| content_type | varchar(50) | page, post, CPT slug, menu, faq… |
| section_type | varchar(50) | title, meta_title, meta_desc, h1, h2, hero, service, faq, testimonial, cta, schema, internal_link |
| chunk_index | int | order within the post |
| chunk_text | longtext | the text that was embedded |
| content_hash | char(40) | sha1(chunk_text) — skip re-embedding when unchanged |
| token_estimate | int | rough token count |
| embedding | longtext | JSON float[] — local fallback / cache |
| vector_ref | varchar(191) | id of the row in Neon |
| embedded | tinyint | 1 once an embedding exists |
| indexed_at | datetime | |
| updated_at | datetime | |

Keys: `post_id`, `content_hash`, `(content_type, section_type)`.

### `{prefix}gsb_scores`
post_id (unique), url, score (0–100), subscores JSON (the 10 dimensions),
details JSON (evidence used), scored_at.

### `{prefix}gsb_recommendations`
id, post_id, rec_type, priority (high/medium/low), title, detail (text),
status (open/done/dismissed), source (heuristic/ai), created_at.

### `{prefix}gsb_logs`
id, level (info/warning/error), context (scan/embed/neon/agent…), message,
created_at.

### `{prefix}gsb_settings`
Key/value runtime state (scan cursor, last full reindex, queue, Neon health).
Plugin **configuration** (API keys, Neon DSN, options) is stored in `wp_options`
under the `gsb_*` namespace so it integrates with standard WP tooling; this
table holds operational state.

### Neon (Postgres) — `gsb_chunks` (vector table)
```sql
CREATE EXTENSION IF NOT EXISTS vector;
CREATE TABLE IF NOT EXISTS gsb_chunks (
  id            bigint PRIMARY KEY,        -- mirrors the WP chunk id
  site          text NOT NULL,             -- site host, multi-site safe
  post_id       bigint,
  url           text,
  content_type  text,
  section_type  text,
  chunk_text    text,
  embedding     vector(1536),              -- text-embedding-3-small
  indexed_at    timestamptz DEFAULT now()
);
CREATE INDEX IF NOT EXISTS gsb_chunks_embedding_idx
  ON gsb_chunks USING hnsw (embedding vector_cosine_ops);
```
Search: `ORDER BY embedding <=> $1 LIMIT $k` (cosine distance).

---

## 4. Admin screen plan

`GEO Site Brain` top-level menu (`dashicons-superhero`), capability
`manage_options`:

1. **Dashboard** — site GEO score, score distribution, weakest pages, index
   stats (chunks, embedded %, last reindex), Neon/OpenAI health.
2. **Scan / Re-index** — manual full scan, single-post reindex, progress bar
   (batched AJAX), weekly cron toggle, error log tail.
3. **Scores** — sortable per-page table with the 10 sub-scores and a drill-down.
4. **Recommendations** — grouped, prioritized list with dismiss/done actions.
5. **Agent Chat** — retrieval-first chat; answers tagged Found / Inferred /
   Recommended with source links.
6. **Settings** — OpenAI key + model, Neon connection string + enable toggle,
   "Test connection" buttons, post types to index, chunk size, cron schedule.

---

## 5. Embedding workflow

```
post saved / scan ──▶ GSB_Scanner::scan(post)         → ordered chunks
                  ──▶ for each chunk:
                        hash = sha1(text)
                        if hash unchanged and embedded → skip (idempotent)
                        else queue
                  ──▶ GSB_OpenAI::embed(batch of texts) → float[1536][]
                  ──▶ GSB_Vector_Store::upsert(chunk, vector)
                            ├─ Neon enabled  → INSERT … ON CONFLICT (id) (pgvector)
                            └─ always        → store JSON in MySQL (cache/fallback)
                  ──▶ mark chunk embedded, stamp indexed_at, content_hash
```

Batched in groups (default 64 chunks / request). `save_post` schedules a
single async event so editor saves stay fast. A full scan walks post types in
batches via AJAX (admin) or cron (weekly), persisting a cursor in
`gsb_settings` so it is resumable.

---

## 6. Neon vector workflow

- Admin pastes the Neon **connection string** (`postgresql://user:pass@host/db`).
- `GSB_Vector_Store` connects with PDO `pgsql` (TLS `sslmode=require`), and on
  first use runs the `CREATE EXTENSION` / `CREATE TABLE` / `CREATE INDEX` above
  (idempotent).
- **Upsert:** `INSERT … ON CONFLICT (id) DO UPDATE` with the vector formatted as
  a pgvector literal `'[0.1,0.2,…]'`.
- **Search:** embed the query, then
  `SELECT id, post_id, url, section_type, chunk_text,
   1 - (embedding <=> :q) AS score FROM gsb_chunks
   WHERE site = :site ORDER BY embedding <=> :q LIMIT :k`.
- **Delete:** by `post_id` on post delete, by `id` for stale chunks.
- **Fallback:** if `pgsql` PDO is missing or Neon is off/unreachable, the same
  methods read the JSON `embedding` column from MySQL and rank with cosine
  similarity computed in PHP. The store reports which backend served the query
  so the UI can show it. Connection string is stored in `wp_options` and never
  echoed back.

---

## 7. GEO scoring methodology (1–100)

Each page gets ten heuristic sub-scores (0–100), combined with weights into the
final score. Heuristics are deterministic (no API needed) and read from the
scanned chunks + post meta; an optional AI pass can refine narrative sub-scores.

| Dimension | Weight | Signals |
|---|---|---|
| Entity coverage | 12 | named entities, services, brand, locations present |
| Service clarity | 12 | clear service section, what/where/for-whom |
| Location relevance | 10 | city/region mentions, service-area, NAP |
| FAQ / question coverage | 12 | Q-style headings, FAQ block, How/What/Why |
| Answer completeness | 12 | direct answers, length, lists, definitions |
| Schema coverage | 12 | JSON-LD present + relevant @type (FAQ/LocalBusiness/Service) |
| Internal linking | 8 | count + relevance of internal links in/out |
| Trust signals | 8 | credentials, certifications, guarantees, contact |
| Review / testimonial usage | 6 | testimonials/reviews present + Review schema |
| AI answer readiness | 8 | concise lead answer, headings, extractable blocks |

`final = round(Σ weightᵢ · subᵢ / Σ weightᵢ)`. Sub-scores and the evidence
behind them are stored as JSON so the dashboard can explain every number and the
recommendations engine can target the weakest dimensions.

---

## 8. Recommendations engine

Derived from scores + scan gaps (heuristic), optionally expanded by the agent:

- Missing / thin FAQs, missing service pages, missing location pages
- Weak pages (low overall) and duplicate/overlapping pages (vector near-dupes)
- Schema improvements (add FAQ/LocalBusiness/Service/Review JSON-LD)
- Internal link opportunities (high-similarity page pairs not yet linked)
- Title / meta rewrites (missing/short/long meta)
- AI Overview answer blocks (pages lacking an extractable lead answer)
- Google Business Profile post ideas (from services × locations)

Each recommendation: type, priority, target post, human detail, status.

---

## 9. Development roadmap

- **Phase 1 (this version):** settings, DB tables, scanner+chunker, OpenAI
  embeddings, Neon upsert/search with local fallback, manual + per-post + cron
  indexing, heuristic GEO score, recommendations, retrieval-first agent chat,
  dashboard.
- **Phase 2:** richer CPT/FAQ/testimonial detection, Elementor/Gutenberg block
  parsing, duplicate detection UI, one-click schema/meta fixes.
- **Phase 3:** AI-refined sub-scores, scheduled GEO digest email, GBP export,
  competitor gap analysis, multi-site rollup.
```
