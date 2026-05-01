# Presentation Restructure Prompt (General Outline)

A reusable prompt to turn any source material (audit, interview notes,
feature list, raw deck) into a structured **strategic plan** instead of
an insight dump. Brand, colour palette, industry, and angle are inputs,
not part of this template.

---

## The Spine

Every section must map to one of these phases. If it doesn't, fold it in
or cut it.

```
Hero
  └── Sticky TOC          one row, every phase clickable
01 · Foundation           current state, what's broken
02 · Insights             what we discovered (yours + reverse-engineered)
03 · Strategy             the pillars (typically 3)
04 · Proof                before vs after
05 · Investment           cost broken into milestones
[Bonus]                   optional sweetener
Signature                 name + signature canvas + submit
```

---

## Phase Intent

| Phase | Purpose | Tone |
|---|---|---|
| **Foundation** | Audit. Concrete current-state numbers framed as leaks to patch. | Diagnostic |
| **Insights** | Numbered insights from two sources: the client's own data and reverse-engineered competitor data. Each one feeds a pillar downstream. | Analytical, specific |
| **Strategy** | Numbered pillar cards. Each shows Strategy line + Deliverables + KPI table. | Decisive |
| **Proof** | Before vs After columns showing the behavioural delta. | Concrete |
| **Investment** | Payment milestones with what ships at each. | Transactional |
| **Bonus** | A free deliverable that ties back to Insights, sits right before signature. | Sweetener |
| **Signature** | Form, canvas signature, success state. | Frictionless |

---

## Component Library

| Component | Use |
|---|---|
| **Sticky TOC** | One horizontal row, pinned. Each pill = `[NUM] PHASE`. Animated arrow next to a label. |
| **Section eyebrow** | Small uppercase tag above each H2 (`01 · PHASE`). |
| **Section title** | Massive H2. Underline accent below. |
| **Subsection title** | H3 with thick coloured left bar. |
| **Stat box** | Big number + short label. Variants for warning / critical. |
| **Pillar card** | Numbered tile + eyebrow + title + italic strategy line + 2-col body (Deliverables, KPI table). |
| **Category cards** | Coloured left-border cards used for taxonomies. Tag chips inline if relevant. |
| **Funnel / lifecycle** | Pills connected by arrows for stage progression. |
| **Heatmap / grid** | CSS grid for spatial / scrape data with a legend. |
| **Signal pills** | Coloured pill cloud for grouped tags / keywords. |
| **Bonus card** | Dashed border, ribbon tag, sits right before signature. |

---

## Layout Rules (brand-agnostic)

- **One accent colour drives the deck.** TOC border, section underlines, eyebrow text, default pillar accent. Pick whatever fits the brand.
- **Three distinguishable pillar accent colours.** One per pillar. Apply consistently inside each pillar card (number tile, eyebrow, bullet checks, KPI accents).
- **Body surface is light/neutral.** Reserve dark blocks for the sticky TOC and (optionally) hero supporting elements.
- **Headlines are huge.** Section titles `clamp(40-70px+)`, weight 900, negative letter-spacing. Pillar titles `clamp(28-40px)`. Subsection titles `clamp(26-36px)`.
- **Body weight 500 minimum**, paragraphs and list items lifted from the browser default.
- **TOC stays on one line.** `flex-wrap: nowrap` with horizontal overflow scroll, scrollbar hidden.
- **Anchor scroll-margin-top** equal to TOC height + buffer so jumps don't hide the headline.
- **Tight section padding** (~50px desktop) so phases read as distinct slabs.

---

## Copy Rules

- **No em dashes (`—`) or en dashes (`–`).** Use commas, periods, or hyphens.
- Direct sentences. No hedging.
- Headlines state a position, not a question.
- Pillar strategy lines follow: `Strategy: [verb] [object] so [outcome].`
- KPIs are `before → after` or a hard target. No vague metrics.
- Insights are specific (named tools, places, segments, periods). No generic claims.
- Strip marketing fluff (`excited to`, `cutting-edge`). Replace with concrete deliverables.

---

## What To Strip From The Source

- Standalone feature lists, fold into pillar deliverables.
- Repetitive intros, the eyebrow + title + strategy line covers framing.
- Decorative dark sections in the body.
- Generic mission statements, replace with specific insights.
- Anything that doesn't map to a phase (or the bonus).

---

## Output Requirements

- Single `index.html`, inline CSS, no JS frameworks.
- Inline SVG for icons. No external image deps.
- Works when opened from disk.
- Working signature canvas + form handler that stores the payload in `localStorage` and shows a success state.

---

## How To Use

1. Open a new Claude session in the target repo.
2. Paste this prompt.
3. Provide as separate inputs:
   - **Brand & accent colour** (one hex)
   - **3 pillar names + 3 accent colours** (one hex each)
   - **Headline KPI for the hero** (one-liner)
   - **Source material** (existing deck, audit data, interview notes, feature list)
4. Iterate: bump headlines, reframe a pillar, swap a mockup, add a bonus.

## Variants

- **2 pillars**, widen each card to 50/50.
- **4 pillars**, add a fourth accent colour.
- **Internal version**, drop the signature, replace with a Next-Steps checklist.
- **Recap version**, prepend a `00 · Recap` summarising prior delivery.
