# Midland Floor Care — Standard Operating Procedures

Reference for everyone touching the Midland repo, GH preview, or Elementor kit.

---

## Brand Standards

### Colors (locked)
| Role | Hex |
|---|---|
| Brand green | `#43A94B` |
| Deep green (hover) | `#2F8137` |
| Dark green (sections) | `#0E2F14` |
| Ink / black text | `#0F1411` |
| Body text gray | `#4B5563` |
| Mint section bg | `#F3FCF4` |
| White | `#FFFFFF` |
| Gold (stars only) | `#FACC15` |
| Red (urgency only) | `#DC2525` |

### Type
- **Display + body**: `Manrope`, weights `500/700/800`
- Body weight: 500 (medium) — readable for 30–55 yr audience
- Headlines: 800
- Eyebrows: mono-spaced or strong-letterspacing 800 caps

### Voice
- Family-owned, locally operated.
- Trusted by DMV homeowners and facility managers.
- Geo: Washington DC · Maryland · Virginia.
- Phone always: **(240) 532-9097** (`tel:2405329097`)
- Headline: **"Professional Floor & Carpet Care in DC, MD & VA"**

### Required assets (in `/images/`)
| File | Use |
|---|---|
| `midland-small-logo.png` | Nav + footer logo |
| `Midland Floors Hero image.jpg` | Hero bg (founder in office) |
| `midland floor team.png` | Team selfie (Side Job section) |
| `carpet.png` | Residential services photo |
| `office floor.png` | Commercial services photo |
| `1000151618.jpg` / `1000151832.jpg` | Hardwood floor work |

---

## Repository Layout

```
midland/
├── index.html              GH preview — sales page (custom CSS OK)
├── presentation.html        Original ClickFunnels-style audit page
├── index2.html              90-Day Plan
├── images/                  ALL brand photos. Push to main before referencing.
├── elementor/
│   ├── manifest.json        Kit manifest (Envato Elements format)
│   ├── help.html            Import instructions
│   ├── midland-styles.css   Scoped CSS (.midland-page) for optional paste
│   ├── midland-elementor-kit.zip   Importable kit
│   ├── templates/           One JSON per template
│   └── screenshots/         Kit screenshots (optional)
└── commercialfloor...xml    WP export — source of secondary pages
```

---

## SOP 00 — Define Value Before Building

**The most important SOP. Skipping this is why we waste hours.**

Before writing code or building a deliverable, get clarity on these five things. If any of them is missing, **stop and ask** — don't guess.

### 1. Who is this for?

| Audience | What they need | What they don't care about |
|---|---|---|
| End user (DMV facility manager) | Trust signals, fast quote, clear phone number | Designer aesthetic, custom animations |
| Client buyer (Justin) | A working sales page he can show prospects today | Pixel-perfect WP imports |
| Developer hand-off | Clean, editable code with predictable patterns | Custom widget configurations |

If you don't know who the deliverable is *for this turn*, the work is guaranteed to miss.

### 2. What outcome does success look like?

State the **observable outcome** in one sentence before starting:
- ❌ "Build an Elementor template" — vague, can't test.
- ✅ "Justin can show a prospect the live home page on his phone in under 30 seconds and the prospect immediately sees the phone number, trust signals, and how to schedule." — testable.

If the work doesn't move that outcome closer, it's not value — it's effort.

### 3. What's the smallest thing that delivers the outcome?

Default to the **smallest deliverable that achieves the outcome**, not the most thorough.

| Bigger isn't better | Smaller is shipped |
|---|---|
| 37-template Elementor kit that crashes on import | 1 working home page that loads |
| Native Elementor widgets with perfect editability | HTML widget that renders identically to GH preview |
| 30 generated WP page templates | 3 hand-crafted service pages that convert |

Build the smallest version → ship → iterate from real feedback. Don't ship 37 of something that doesn't work.

### 4. What does "done" look like?

Done is **not** "I finished the code." Done is:
- The user can take the next action without you.
- A test case proves it works (the audit script, a manual click-through, a screenshot).
- The thing is committed, pushed, and accessible at a real URL.

If any of those is missing, it's not done — even if the code is "complete."

### 5. When do you stop iterating?

Keep iterating only if **the next change moves the outcome metric**. Stop when:
- The user says "this works" or moves on.
- You've fixed the same issue 3+ times — that's a sign the approach is wrong, not the implementation.
- You're guessing instead of measuring.

When stuck after 2 attempts, **change the approach** (e.g., HTML widget instead of native; ship the GH preview instead of the Elementor kit). Don't keep retrying the broken approach with small variations.

### Red flags that you're building something that won't be valuable

- ⚠️ User keeps saying "stop" or expressing frustration → you're solving the wrong problem.
- ⚠️ Audit passes but the user reports the imported version is broken → your audit is testing the wrong thing.
- ⚠️ You're adding features they didn't ask for → scope creep.
- ⚠️ Each fix introduces a new break → architectural mismatch, not a bug.
- ⚠️ Asking the user the same kind of clarifying question you've already asked → you're not retaining context.

When you see two of these, **stop and re-state the outcome from #2 above with the user before continuing.**

### Decision: when to declare a path "not viable"

If after **2 fix-it attempts** an approach (e.g., a specific Elementor widget) is still failing, declare it not viable, document it (SOP 09), and switch to a different approach. Don't keep banging on the same wall.

### What "valuable" means specifically for the Midland project

A valuable Midland deliverable does at least one of:
1. **Helps Justin show the brand to a client today** — live URL, working photos, working phone CTA.
2. **Helps a DMV prospect call the phone number** — clear above-the-fold CTA, mobile-friendly.
3. **Drives organic traffic** — proper SEO (single H1, schema, internal links).
4. **Reduces ongoing maintenance** — predictable, documented, reproducible.

If a deliverable does none of these, it's not value, it's churn. Cut it.

---

## SOP 01 — Branch & Push Discipline

1. Work happens on the assigned feature branch (`claude/redesign-elementor-homepage-sjpWP`).
2. **Every change gets committed and pushed immediately.** No uncommitted state at session end.
3. Image assets are pushed to **`main`** before any template references them — raw GitHub URLs only resolve from `main`.
4. Commit messages: imperative subject, then bullet body explaining *why*. Example: "Fix #schedule anchor: link target was missing."

---

## SOP 02 — GH Preview (`index.html`)

Use this for **client demos and stakeholder review**. It's the source of truth for visual design.

**Allowed**:
- Custom `<style>` blocks (Manrope, gradients, animations, photo frames).
- Relative image paths `images/...`.
- Inline `<a>` to other repo pages (`presentation.html`, `index2.html`).

**Required**:
- Mobile responsive (`@media max-width 720px` block exists).
- Section markers: `<!-- ============== NAME ============== -->`.
- Single H1 in the hero.
- Phone CTA visible above the fold.
- Scoped CSS rules — no `* { ... }` resets at top level if it could leak.

**Forbidden**:
- External CDN links to image hosts other than `raw.githubusercontent.com`.
- Inline JS that the Elementor build script can't strip cleanly.

---

## SOP 03 — Elementor Kit (`elementor/`)

**Use case**: importable WP/Elementor template kit via Envato Elements.

### Widget allowlist (CRITICAL — anything else has crashed imports)

✅ Safe:
- `heading`
- `text-editor` (HTML inside `editor` is fine, including `<ul>`, `<strong>`, inline styles)
- `button`
- `image`
- `spacer`, `divider`

❌ Forbidden — verified to break imports on this stack:
- `icon-box`, `testimonial`, `toggle`, `shortcode`, `html`

### Required template fields
```json
{
  "version": "0.4",
  "title": "Midland — <Page Name>",
  "type": "page" | "section",
  "metadata": {},
  "content": [...],
  "page_settings": []
}
```

### Required container settings
- `flex_direction`, `content_width`, `flex_gap`, `padding`
- `_title` set on every top-level container (shows up labelled in Elementor outline)
- `_css_classes: "midland-page"` so the scoped stylesheet (if pasted) applies

### Naming convention
- All template `title` and manifest `name` start with `"Midland — "`.
- Prevents 15 duplicate "Home" entries piling up in the Elementor library.

### Manifest format (`Envato Elements compatible`)
Required keys, in order:
```json
{
  "manifest_version": "1.0.21",
  "title": "Midland Floor Care - Sales Page Kit",
  "page_builder": "elementor",
  "kit_version": "1.0.0",
  "templates": [...]
}
```
**Do NOT include** `required_plugins` or `images` arrays unless the format exactly matches a known-working kit (ClayHive). Wrong format triggers "install failed" even when plugins are present.

### Image URLs
All images use:
```
https://raw.githubusercontent.com/branderboy/midland/main/images/<file>
```
Spaces URL-encoded (`%20`). Files must exist on `origin/main`.

### Header / Footer
`type: "section"` (NOT `"header"` / `"footer"`). The Theme Builder types require Elementor Pro and silently fail on free Elementor.

---

## SOP 04 — Audit Before Ship

Run on every kit before the user re-imports. Pass criteria:

1. **Manifest valid JSON**, has all 5 required keys.
2. **Every declared `source` exists** on disk.
3. **Every template parses as v0.4 JSON** with `version`, `title`, `type`, `content`.
4. **Zero forbidden widgets** (icon-box / testimonial / toggle / shortcode / html).
5. **Exactly 1 H1 per page** (SEO).
6. **All button `link.url`** anchors (`#x`) have a matching `_element_id: x` on some container.
7. **All image URLs** on `origin/main` (verify with `git ls-tree -r origin/main`).
8. **Zip contains** `manifest.json`, `help.html`, `midland-styles.css`, every template.

If any check fails → DO NOT push the zip until fixed.

---

## SOP 05 — Change Request Flow

When the user requests a change:

1. **Identify scope**: GH preview only? Elementor kit only? Both?
2. **One change per commit.** Don't bundle "fix font + add photo + change button" into one commit.
3. **Confirm before site-wide changes** (e.g., "change all buttons" → ask which buttons, or get a screenshot).
4. **Get a screenshot before guessing** what's broken.
5. **Audit after change** (SOP 04).
6. **Commit + push** with descriptive message.
7. **Tell the user the commit SHA** + what's in it + what they need to do next (re-import / re-test).

---

## SOP 06 — When the Kit Crashes

1. **Site recovery first**:
   - WP admin still loads → trash the imported template via Pages / Elementor → Templates / Elements → Installed Kits.
   - White screen → rename `wp-content/plugins/elementor` (or kit plugin) folder via FTP/file manager to disable.
   - Total brick → restore from host's daily backup.
2. **Reproduce locally**: validate the kit zip with the audit script.
3. **Bisect widgets**: strip the kit to bare minimum (heading + text-editor only). If THAT imports, re-add widgets one at a time until you find the offender.
4. **Add the offending widget to SOP 03's forbidden list.**

---

## SOP 07 — SEO Standards (per template)

- **One H1 per page**, descriptive, includes primary keyword.
- **Meta title** ≤ 60 chars, format: `<Title> | Midland Floor Care DC, MD & VA`.
- **Meta description** ≤ 155 chars, includes phone CTA when natural.
- **Headings hierarchical**: H1 → H2 → H3, no skipping.
- **Internal links**: every page links to ≥3 other Midland pages (related services, schedule, FAQ).
- **JSON-LD schema** on every page (when feasible):
  - `ProfessionalService` for the business node (`@id` consistent across pages).
  - `Service` for service pages, `Article` for posts, `WebPage` for everything else.
  - `BreadcrumbList` always.
- **Canonical URL** set in page metadata.
- **Alt text on every image** (descriptive, not stuffed).

---

## SOP 08 — Stylesheet Discipline

If the kit ships with `midland-styles.css`:

- **Every rule scoped to `.midland-page`** so pasting into Site CSS doesn't bleed into other pages.
- Top-level container in every Midland template has `_css_classes: "midland-page"`.
- `:root` and `html` selectors stay unscoped (CSS variables are global by design).
- No `* { ... }` resets above the scope.
- Stripped of CSS comments before scoping (comments confuse the scope-prefix script).

Verification: `grep -E '^\.midland-page' midland-styles.css | head` should show every rule starts with `.midland-page`.

---

## SOP 09 — Things That Have Burned Us (Don't Repeat)

| Mistake | Symptom | Fix |
|---|---|---|
| Used `icon-box` / `testimonial` / `toggle` widgets | Site crashed on kit import | Native fallback: heading + text-editor + image only. |
| Image URLs pointed at relative `images/...` in Elementor JSON | Photos missing on import | Use full `raw.githubusercontent.com/.../main/...` URLs. |
| Header / Footer used `type: "header"` / `"footer"` | Required Elementor Pro, silently failed on free | Use `type: "section"` so they import as regular blocks. |
| Manifest had `required_plugins` with wrong field names (`plugin` vs `file`) | "Plugin install failed: Elementor" warning even though installed | Match ClayHive format exactly OR omit the array. |
| Reused "Home" as template name | 15 duplicate "Home" entries in Elementor saved templates | Always prefix `"Midland — "`. |
| Pasted unscoped CSS into Site Settings | Other pages restyled | Every rule prefixed `.midland-page`. |
| Stripped IntersectionObserver `<script>` but kept `class="reveal"` | Sections sat at `opacity:0` after import | Strip both, OR add `.reveal { opacity:1 !important }` safety override. |
| Embedded forms via `<form>` HTML widget | Form widget rendered but submission did nothing | Use `[smart-forms-pro id='X']` shortcode placeholder, or omit and direct to phone CTA. |

---

## SOP 10 — Final Hand-off Checklist

Before declaring "ready for the client":

- [ ] GH preview at `https://branderboy.github.io/midland/` renders correctly desktop + mobile.
- [ ] Phone CTA (240) 532-9097 in hero, footer, and CTA blocks.
- [ ] All images load (no broken-image icons in DevTools).
- [ ] All internal links resolve (no 404s on `presentation.html`, `index2.html`).
- [ ] Elementor kit zip in `elementor/` is the latest commit.
- [ ] Audit script (SOP 04) passes 8/8 checks.
- [ ] Last commit pushed to remote (`git status` → "nothing to commit").
- [ ] User notified with commit SHA + what to test.
