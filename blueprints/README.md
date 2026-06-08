# WordPress Playground blueprints

[WordPress Playground](https://playground.wordpress.net) runs a full WordPress
in the browser (PHP compiled to WebAssembly + SQLite). A **Blueprint** is a JSON
file that declaratively boots WP, pins the PHP/WP versions, logs in as admin, and
installs + activates plugins. This gives us a real, shareable, throwaway WP to
verify the Midland plugins — including the admin UI, the chat widget, and form
rendering, which the local CLI smoke harness (`tools/smoke-test.sh`) can't show.

These blueprints install all four plugins **in dependency order**: Smart Forms →
Smart Chat → Smart CRM → Real Smart SEO. Order matters because **Smart CRM Pro
hard-requires Smart Forms** (it returns early with an admin notice if
`SFCO_VERSION` is undefined).

## One-click launch

> These point at the `claude/optimistic-allen-r4wzay` branch. After merging to
> `main`, swap `refs/heads/claude/optimistic-allen-r4wzay` for `refs/heads/main`
> in the blueprint files (and in the links below).

**Suite, from the prebuilt `dist/` zips (fast, recommended):**

```
https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/branderboy/midland/refs/heads/claude/optimistic-allen-r4wzay/blueprints/midland-suite.json
```

**Suite, from the `plugins/` source via `git:directory` (always-latest source, slower):**

```
https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/branderboy/midland/refs/heads/claude/optimistic-allen-r4wzay/blueprints/midland-suite-git.json
```

**ALL 11 plugins (full-suite / cross-plugin check, from source):**

```
https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/branderboy/midland/refs/heads/claude/optimistic-allen-r4wzay/blueprints/all-plugins-git.json
```

### All plugins covered

Verified locally on **WP 7.1-alpha / PHP 8.4 / SQLite** (clean activate + full
lifecycle boot, individually and all-active): **all 11 pass — zero fatals, zero
deprecations.** `all-plugins-git.json` installs every one for in-browser checks:

| Plugin | Version | dist zip | In `all-plugins-git.json` |
|---|---|---|---|
| smart-forms-for-midland | 2.19.11 | ✅ | ✅ (installed first — CRM depends on it) |
| smart-chat-ai | 1.9.38 | ✅ | ✅ |
| smart-crm-pro | 2.4.2 | ✅ | ✅ |
| real-smart-seo | 2.0.0 | ✅ | ✅ |
| smart-reviews-pro | 1.5.3 | ✅ | ✅ |
| content-traffic-maker | 1.8.1 | ✅ | ✅ |
| geo-site-brain | 2.4.0 | ✅ | ✅ |
| job-poster-wp | 1.7.1 | ✅ | ✅ |
| midland-contractor-gallery | 1.0.0 | — (source only) | ✅ |
| midland-pcloud-embed | 1.1.0 | — (source only) | ✅ |
| wp-github-backup | 3.6.5 | ✅ | ✅ |

To verify a **single** plugin in-browser, open `all-plugins-git.json` in the
Blueprint Builder and delete the steps you don't want (keep `smart-forms-for-midland`
if you're testing `smart-crm-pro`).

Paste either URL into a browser. Playground boots WP, installs the four plugins,
logs you in, and drops you on **Plugins** with all four active.

## How it works

- `?blueprint-url=<raw JSON url>` — Playground fetches that Blueprint and runs it.
  The branch uses a slash, so the raw URL uses the unambiguous
  `.../refs/heads/claude/optimistic-allen-r4wzay/...` form.
- `installPlugin` with `pluginData.resource: "url"` installs a plugin zip from a
  public URL; `"git:directory"` checks a subdirectory straight out of the repo
  (branch refs require `"refType": "branch"`).
- `"login": true` logs in as `admin` / `password`.
- `"features": { "networking": true }` allows outbound HTTP so the AI chat,
  sitemap crawler, and the CRM/AC/ServiceM8/Resend integrations can actually
  make calls (you still need to enter real API keys in each plugin's settings).
- `"preferredVersions.php"` pins PHP. To re-verify the **PHP 7.4** compatibility
  fix in Smart CRM (`str_contains` → `strpos`), copy `midland-suite.json`, set
  `"php": "7.4"`, and confirm activation + a deal-value calc stays fatal-free.

To tweak/validate interactively, open the Playground **Blueprint Builder** and
paste a blueprint — it live-validates against the schema and has an
"Edit in builder" round-trip.

## Local iteration (no push required, works on a private repo too)

For tight dev loops or before a branch is public, run Playground locally and
mount the plugin directory live (changes appear on reload — no rebuild/push):

```bash
npx @wp-playground/cli@latest server \
  --mount=./plugins/smart-chat-ai:/wordpress/wp-content/plugins/smart-chat-ai \
  --blueprint=./blueprints/midland-suite.json
```

(Use `--mount` once per plugin you want to edit live; mounted dirs override what
the blueprint installs.)

## What to verify here (UI things the CLI harness can't)

- All four plugins activate with no error notice; Smart CRM shows its dependency
  notice (not a fatal) if Smart Forms is deactivated.
- Each settings screen renders: Smart Forms (incl. the **CRM** and **Team**
  pages — confirm "Test Connection" / "Remove member" buttons are wired, the
  recent enqueue fix), Smart CRM tabs, Smart SEO dashboard, Smart Chat settings.
- Secret fields (Smart Forms CRM/Resend/GCal) render **blank with a "saved"
  placeholder**, not the stored key (the C2 fix).
- Front end: place `[sfco_quote]` on a page and load the chat widget; submit a
  form and a chat message and confirm a lead appears under the admin.

## Caveats

- Playground is ephemeral — everything resets on reload. It's for verification,
  not persistence.
- External API calls require real keys and that the remote service allows the
  request; some providers block CORS/WASM origins.
- `git:directory` clones over a CORS proxy and can be slow or rate-limited;
  prefer the `dist`-zip blueprint for routine checks.
