technical SEO (crawlability/indexability/speed) → on-page (titles/meta/schema/entities) → off-page (links/citations/sameAs) → content (intent match/freshness/E-E-A-T)


Here's the SEO reasoning behind each change — phrased the way you'd say it in an interview:
1. Tailwind CDN → built local CSS
The CDN script (cdn.tailwindcss.com) is a JIT (just-in-time) compiler that runs in the browser. It's render-blocking — the browser pauses parsing HTML until it downloads the script, executes it, scans the DOM, and generates CSS rules. That delays paint.
This hurts three Core Web Vitals signals Google uses for ranking:
LCP (Largest Contentful Paint) — the hero, headline, and CTA can't render until styles exist. CDN delays this.
FCP (First Contentful Paint) — same blocker.
CLS (Cumulative Layout Shift) — when styles load late, elements jump as they restyle.
Pre-building a static .css file (33KB, minified, cacheable) ships ready-to-paint CSS. The browser parses it in parallel with HTML, no JS execution required, no DOM scan. That moves Core Web Vitals from "Poor/Needs Improvement" toward "Good," and Google explicitly uses CWV as a ranking factor (the Page Experience update). It also reduces total page weight by hundreds of KB since the CDN ships a runtime engine, not just classes.
Bonus: Tailwind themselves explicitly say the CDN is dev-only and not for production.
2. og:image SVG → 1200×630 raster + summary_large_image
This isn't a direct ranking factor — it's a CTR/engagement amplifier. Three things were broken:
Facebook, LinkedIn, Nextdoor, Slack don't reliably render SVG OG images. Most fall back to no preview or a tiny logo. That kills click-through when someone shares your page.
1200×630 is the dimension Facebook/LinkedIn explicitly recommend. Anything else gets cropped weirdly or shown small.
twitter:card="summary" shows a tiny thumbnail. summary_large_image shows a full-width hero card. Click-through difference is significant — multiple studies put it at 2–3x.
Social CTR matters for SEO indirectly: more shares = more crawl signals + more backlinks + more branded searches. Branded search is a strong trust signal Google uses.
3. Business sameAs (skipped pending URLs)
This is entity SEO — the modern frontier. Google's Knowledge Graph treats entities (places, businesses, people) as nodes with relationships. sameAs is the explicit declaration: "this business in my schema is the same entity as this Google Business Profile, this Facebook page, this Wikipedia article."
Without sameAs, Google has to guess you're the same business as your GBP listing. With sameAs, you reduce ambiguity, accelerate knowledge panel attachment, and consolidate authority across platforms. This is what gets you:
The right knowledge panel on the right side of the SERP
Map pack inclusion when someone searches "drywall contractor near me"
Inclusion in AI Overviews (SGE) and ChatGPT/Perplexity responses, which lean heavily on entity-linked schema
For city pages, I added Wikipedia sameAs to the City and State schema. That tells Google: "this 'Bethesda' is en.wikipedia.org/wiki/Bethesda,_Maryland — not the one in Texas, not the suburb in Switzerland." Wikipedia URLs are the highest-trust entity references because Google built much of the Knowledge Graph from Wikidata + Wikipedia.
4. Why I noted the og:image should eventually be self-hosted
Two reasons:
First-party trust — Google's E-E-A-T factors prefer authoritative content on the brand's own domain. An og:image on unsplash.com is fine technically but signals "stock photo" to anyone inspecting.
Resilience — if Unsplash changes URL structure, drops the image, or rate-limits hotlinking, your social previews break silently. You won't notice until shares stop converting.
If you're prepping for an SEO interview, the framework I'd recommend is: technical SEO (crawlability/indexability/speed) → on-page (titles/meta/schema/entities) → off-page (links/citations/sameAs) → content (intent match/freshness/E-E-A-T). Every change I made on this branch maps to one of those four buckets — call them out by name and you'll sound senior.

Right? It's one of the highest-leverage, lowest-effort moves in modern SEO — and most small-business sites miss it entirely.
The key mental model for an interview: sameAs turns your business from a string into a thing. Google goes from pattern-matching text ("does 'All Purpose Anytime Services' appear on this page?") to entity-matching a node in its Knowledge Graph (Q-number in Wikidata terms). Once you're a confirmed entity, you stop competing on keywords alone and start showing up in:
Knowledge panels (right-rail on desktop SERPs)
Map pack / local 3-pack
"People also search for" carousels
Google Discover feeds
AI Overviews / SGE answers
ChatGPT, Perplexity, Claude — all of which lean on schema + Wikidata for grounding
The practical sameAs checklist for a local business:
Google Business Profile URL (the g.co/kgs/... or full Maps URL)
Facebook page
Instagram
LinkedIn company page
Yelp listing
BBB profile
Nextdoor business page
YouTube channel (if you have one)
Angie's List / HomeAdvisor / Thumbtack profiles
Wikipedia/Wikidata (rare for SMB, but Fortune 500s always have this)
The pattern is: any platform where your business has a claimed/verified profile goes in sameAs. Google uses it to cross-verify NAP (Name, Address, Phone) consistency — which is a huge local SEO trust signal.
Power move for your interview: mention that sameAs works best when paired with @id (a stable URI identifier for your business — I added "@id": "https://www.allpurposeanytimeservices.com/#business" to the homepage schema). @id lets you reference the same entity across multiple schema blocks (e.g., breadcrumbs, service pages, blog posts all point back to the same business node) — that's called schema nesting and it's how big-brand sites build an internal entity graph that Google treats as one unified signal.
Send me those URLs when you have them and I'll wire the sameAs block into the homepage schema — you'll be able to see it live.



