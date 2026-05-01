# Presentation Restructure Prompt

Paste this into a new Claude / Claude Code session along with the existing
deck (or a description of what should go in it) to produce a presentation
in the same style as the Midland Floor Care plan.

---

## Prompt

> Restructure the attached presentation as a **strategic plan**, not an
> insight dump. Use the architecture, components, and visual system below.
> Output a single self-contained `index.html` with inline CSS (no external
> assets, no JS frameworks). Keep all copy tight: no em or en dashes,
> heavy font weights, scannable headlines.

### Narrative Spine (in this exact order)

1. **Hero** — pre-headline, main headline with one highlighted phrase, sub-headline, one supporting visual mockup (if relevant), single CTA button anchored to `#checkout`.
2. **Sticky TOC** — single horizontal row, dark navy bar with thick brand-green bottom border. Label `THE PLAN →` (animated arrow), then a pill per phase (`01 FOUNDATION`, `02 INSIGHTS`, `03 STRATEGY`, `04 PROOF`, `05 INVESTMENT`, `→ SIGN`). Each pill is a clickable anchor. `flex-wrap: nowrap` with horizontal overflow scroll.
3. **01 · Foundation — "What's Broken Today"** — the audit. Stat boxes for the 3-5 most damning numbers. Optional table of specific failures (queries lost, broken pages, etc.). Closing one-line summary. Tone: leaks to patch.
4. **02 · Insights — "Where The Leverage Is Hiding"** — numbered insights (`Insight 1 ·`, `Insight 2 ·` …). Mix two sources:
   - **Your own data** (analytics, customer behaviour, operational reality)
   - **Reverse-engineered competitor data** (what competitors have already published or revealed)
   Each insight is a card or grid with a clear takeaway. These directly feed the pillars in section 3.
5. **03 · Strategy — "The 3-Pillar Plan"** — three numbered pillar cards. Each pillar:
   - Number tile (72×72, coloured by pillar)
   - `Pillar N · Name` eyebrow in pillar colour
   - Pillar title (clamp 28-40px, weight 900)
   - One-line *Strategy:* italic statement explaining the why
   - Two-column body: **Deliverables** (bulleted, with coloured check bullets) and **Outcomes by Day 90** (KPI table with `label` ⟶ `value` rows showing `before → after`)
   Use distinct pillar colours: red for fix/cleanup, blue for visibility/growth, amber for capture/conversion, green for retention/lifecycle. Pick the 3 that fit the project.
6. **04 · Proof** — Before/After columns. Red header for Before bullets, green header for After. Optional stats row underneath (e.g., response time, deliverability, reviews/quarter).
7. **05 · Investment** — payment plan as 2-3 milestone cards with what ships in each phase + price + trigger date.
8. **Checkout** — name, company, email, date (auto), signature canvas, big CTA button. Success state replaces form on submit.

### Component System

- **Section eyebrow** (`.section-eyebrow`): `01 · Foundation` style, brand-green, uppercase, `clamp(16-20px)` font, 4px letter-spacing.
- **Section title** (`.section-title`): `clamp(42-76px)`, weight 900, letter-spacing -1.5, with an 110×7 brand-green underline accent below.
- **Subsection title** (`.subsection-title`): `clamp(26-36px)`, 6px brand-green left bar, with a small uppercase descriptor span (`subsection-sub`) inline.
- **Pillar card** (`.pillar`): rounded white card, soft mint-to-white gradient header, coloured number tile, two-column body (deliverables + KPI grid).
- **Trigger / lead cards**: white card with coloured 5px left border (red urgent, amber research, blue referral, gray out-of-scope). Include monospaced CRM-tag chips.
- **Geo / signal grids**: when illustrating spatial or scrape data, use a CSS grid heatmap or pill cloud with a clear legend.
- **Funnel flow**: pills connected by green arrows showing lifecycle path (e.g., Trigger → Service → Follow-up → Recurring).

### Visual System

- Brand: green `#3aa050` (CTA `#3fa652`), text dark `#0b1220`, body `#1f2937`, mint wash `#f0f7f2`.
- Sticky TOC: navy `#0f172a` bar, brand-green border-bottom, white pills with green number badges, hover lifts and turns brand green.
- Body font-weight base: **500** (not default 400). Paragraphs and list items at 500 minimum.
- Headlines weight 900 with negative letter-spacing.
- Section padding: 56px desktop, 40px mobile, with `scroll-margin-top: 110px` so anchor jumps clear the sticky TOC.
- Use brand-green underline accents under section titles (110px wide, 7px tall, rounded).
- Coloured pillar-specific accent everywhere within a pillar card (number tile, name eyebrow, deliverables checkmark bullet) so each pillar reads as a unit at a glance.
- White or `#f9fafb` backgrounds. Use the dark navy *only* for the sticky nav and the optional payment / hero supporting blocks. Avoid dark sections inside the body content.

### Copy Rules

- **No em dashes (`—`) or en dashes (`–`).** Use commas, periods, or hyphens.
- Use direct sentences. Avoid hedging ("might", "could potentially").
- Headlines should state a position, not a question.
- Every pillar's strategy line should follow the pattern: `Strategy: [verb] [object] so that [outcome].`
- Every KPI should be a `before → after` value or a hard target. Avoid vague KPIs.
- Insights should be specific (named tools, named competitors, named neighborhoods, named seasons). No generic claims.

### What to Strip From The Source Deck

- Any standalone "feature list" or "what's included" sections, fold them into the appropriate pillar's deliverables.
- Repetitive "why this matters" intros, the eyebrow + title + strategy line covers the framing.
- Decorative dark sections in the middle of the body, keep the deck on white with the navy reserved for the TOC and hero supporting elements.
- Marketing fluff like "We are excited to..." or "Our cutting-edge...", strip and replace with concrete deliverables.

### Output

A single `index.html` file. No external CSS, no images required (use inline SVG for icons). The file must work double-clicked from disk. Include a working signature canvas + form submit handler that stores the payload in `localStorage` and shows a success state.

---

## How To Use This Prompt

1. Open a new Claude Code session in the new presentation's repo.
2. Paste the prompt above.
3. Provide the raw content (existing deck text, audit data, feature list, customer interview notes, whatever you have).
4. Optionally specify: which 3 pillar names to use, which colours map to which pillar, what the headline KPI should be in the hero.
5. Iterate: ask Claude to bump headlines, reframe a pillar, swap in a different mockup, etc.

## Variants

- **2-pillar plan**: drop one pillar, widen the remaining two cards to 50/50 in the strategy section.
- **4-pillar plan**: keep the same pillar component, add a 4th colour (purple or teal recommended).
- **Internal stakeholder version**: skip the checkout / signature section entirely, replace with a "Next steps" checklist.
- **Client recap version**: add a `00 · Recap` section before Foundation summarising what was delivered and what changed.
