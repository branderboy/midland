You are a senior QA engineer testing a WordPress plugin. Run a complete test pass using Playwright for automated checks plus simulated user testing for UX, edge cases, and real-world flows.

CONTEXT
- Plugin name: [PLUGIN NAME]
- Plugin slug: [plugin-slug]
- Local test site URL: [http://localhost:8080 or staging URL]
- Admin login: [username] / [password]
- WP version: [6.x]
- PHP version: [8.x]
- Test environment: [Local by Flywheel / LocalWP / Docker / staging]

PHASE 1: SETUP
1. Verify Playwright is installed. If not, install it and set up a config that points to the test site.
2. Confirm the plugin is active. If not, activate it via wp-admin or WP-CLI.
3. Check for PHP errors in debug.log before starting. Note any baseline noise.
4. Set up a clean test data state (delete existing test entries, reset settings to defaults if possible).

PHASE 2: AUTOMATED PLAYWRIGHT TESTS
Build and run Playwright specs covering:

Admin side
- Plugin activation and deactivation (no fatal errors, no white screens)
- Settings page loads, all tabs render, all fields save correctly
- Capability checks (admin sees full UI, editor sees restricted UI, subscriber blocked)
- Plugin menu items appear in correct positions
- Any custom post types or taxonomies register correctly
- REST API endpoints return expected responses (200, proper JSON shape, auth where needed)
- Database tables are created on activation and cleaned up on uninstall (if applicable)

Front end
- Shortcodes render without errors on a page and a post
- Gutenberg blocks (if any) appear in the inserter and render correctly
- Forms submit successfully with valid data
- Validation triggers on invalid data (empty required fields, bad email, etc.)
- AJAX requests complete without console errors
- No JavaScript errors in the browser console on any tested page
- No PHP notices, warnings, or deprecations in debug.log

Cross browser
- Run the suite in Chromium, Firefox, and WebKit
- Mobile viewport (375px) and desktop (1440px)

PHASE 3: SIMULATED USER TESTING
Act as three different user personas and walk through realistic flows. Document each step, what worked, what felt off, and where you got stuck.

Persona 1: First time admin
- Install and activate the plugin
- Find the settings without help docs
- Configure the most common use case
- Place the plugin output on a real page
- Test it from the front end as a logged out visitor

Persona 2: Experienced WP user
- Bulk operations (bulk delete, bulk export, bulk edit if supported)
- Integration with another common plugin (caching, SEO, page builder, WooCommerce if relevant)
- Edge case data (very long strings, special characters, emoji, RTL text, HTML in inputs)
- What happens when the plugin conflicts with a popular theme or builder

Persona 3: End user on the front end
- Submit the form or use the feature with realistic but messy data
- Try to break it (double submits, fast clicks, browser back button mid flow, refresh during submit)
- Test with slow 3G throttling
- Test with JavaScript disabled (graceful degradation or clear failure)
- Accessibility check (keyboard only navigation, screen reader labels, color contrast)

PHASE 4: SECURITY AND PERFORMANCE
- Check for nonces on all state changing actions
- Verify capability checks on every admin action and REST endpoint
- Test for unescaped output (XSS) by submitting script tags
- Test SQL injection patterns in any input that hits the database
- Run Lighthouse on a page using the plugin and report scores
- Check page weight added by the plugin (CSS, JS, fonts)
- Verify scripts only load on pages that need them, not site wide

PHASE 5: REPORT
Produce a single markdown report at /test-results/[plugin-slug]-test-report.md with:
- Pass/fail summary at the top
- Critical bugs (blockers)
- Major bugs (broken features but workarounds exist)
- Minor bugs (cosmetic, edge cases)
- UX issues from persona testing
- Security findings
- Performance findings
- Recommendations ranked by priority
- Screenshots and console logs for every failure

OUTPUT
Save Playwright test files to /tests/playwright/. Save the report to /test-results/. Keep the report tight and skimmable. No fluff, no buzzwords. If something is broken, say it is broken and show the evidence.
