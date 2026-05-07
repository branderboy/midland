# Midland — Hard Standards (Locked)

These are non-negotiable rules for every future commit on this repo. No experiments, no reinvention, no "let me try a different approach."

---

## Rule 1 — Locked baseline

**Canonical kit version: `9a2693e`** — 37-template safe kit, last confirmed working state.

Every future change is a **targeted patch** against this baseline. If a change requires more than ~50 lines of code or rewrites a section from scratch, it's an experiment — STOP and ask the user first.

---

## Rule 2 — Locked widget allowlist

Only these Elementor widgets are allowed in any template. Period.

- `heading`
- `text-editor`
- `button`
- `image`

Forbidden (verified to crash on this stack):
- `icon-box`, `testimonial`, `toggle`, `shortcode`, `html`, `image-box`, `call-to-action`, `counter`, `rating`, `image-carousel`, `posts`

If a feature can't be done with the 4 allowed widgets, it doesn't go in. The user accepts the simpler look.

---

## Rule 3 — Locked manifest schema

```json
{
  "manifest_version": "1.0.21",
  "title": "Midland Floor Care - Sales Page Kit",
  "page_builder": "elementor",
  "kit_version": "1.0.0",
  "templates": [...]
}
```

No `required_plugins`, no `images`, no `preview_url`, no `elementor_pro_required`. Just the 5 keys above.

Per-template entry:
```json
{
  "name": "Midland — <Title>",
  "screenshot": "screenshots/<slug>.jpg",
  "source": "templates/<slug>.json",
  "preview_url": "",
  "type": "page" | "section",
  "category": "page" | "section",
  "metadata": { "template_type": "single-page" | "section", "include_in_zip": "1" }
}
```

---

## Rule 4 — Locked image hosting

All images live in `/images/` at the repo root and are referenced as:

```
https://raw.githubusercontent.com/branderboy/midland/main/images/<file>
```

URL-encoded for spaces (`%20`). Files committed to `main` before any template references them.

---

## Rule 5 — Locked color palette

```
GREEN       #43A94B
GREEN_DEEP  #2F8137
GREEN_DARK  #0E2F14
INK         #0F1411
INK_2       #4B5563
WHITE       #FFFFFF
MINT        #F3FCF4
GOLD        #FACC15  (stars only)
RED         #DC2525  (urgency only)
```

No other colors enter the templates. No experimenting with new shades.

---

## Rule 6 — Locked section background mapping

Match the GH preview exactly:

| Section | Background |
|---|---|
| Hero | `#0F1411` + bg image |
| Process | Mint `#F3FCF4` |
| Side Job + CTA | White `#FFFFFF` |
| About | Mint |
| Residential Services | White |
| Stats Band | Green-dark `#0E2F14` |
| Commercial Services | White |
| Testimonials | Green-dark |
| FAQ | Mint |
| CTA | Mint |

---

## Rule 7 — Change protocol

For any kit change requested:

1. **Identify which templates touch this change.** Most changes affect home.json only. Section-only changes affect the matching section file too.
2. **Make the targeted edit.** No rewrites. No "let me regenerate from scratch."
3. **Re-zip** (`zip -qr midland-elementor-kit.zip manifest.json help.html midland-styles.css templates/ screenshots/`).
4. **Commit with one-sentence subject describing the change.**
5. **Push.**
6. **Report**: commit SHA + what changed.

---

## Rule 8 — When the user reports the kit is broken

1. **Ask which version they imported** (commit SHA or kit zip date).
2. **Don't change widget types or container structures** — that ripples.
3. **Don't swap approaches** (HTML widget ↔ native widget ↔ shortcode). Pick one and stay.
4. **If a fix requires switching approaches**, declare it an experiment and ask before doing it.

---

## Rule 9 — Never break working state

Before any commit:
- Last known good kit zip is preserved in `git log`.
- New commit MUST keep at least the same number of working templates as the previous commit.
- If a commit reduces the count or removes a working feature, it's a regression — revert immediately.

---

## Rule 10 — Communicate before experimenting

If a user request requires:
- A new widget type
- A different container approach
- A schema change
- More than 100 lines of generated code

**STOP and ask first.** State the proposed approach + risk + alternative. Don't ship and hope.

---

## What this repo will NOT do anymore

- ❌ Generate v4 atomic widget templates (no docs, blind guessing)
- ❌ Add widgets outside the 4-widget allowlist
- ❌ Swap between native ↔ HTML ↔ shortcode mid-session
- ❌ Rebuild the entire kit when one section needs a fix
- ❌ Add `required_plugins`, `images` arrays, or other fancy manifest fields
- ❌ Auto-rename or restructure templates without an explicit user request
