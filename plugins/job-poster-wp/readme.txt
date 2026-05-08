=== Job Manager Pro — Create & Distribute Jobs ===
Contributors: jobmanagerpro
Tags: jobs, careers, job board, hiring, recruitment, google for jobs, indeed, facebook, applications
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create job listings with built-in Google for Jobs schema. Distribute to Facebook, Indeed, Nextdoor, and Craigslist. Includes an application form with resume + cover letter uploads.

== Description ==

**Job Manager Pro** is a complete job creation and distribution plugin for WordPress. Post a job once, distribute it everywhere — and collect applications directly through your site.

= Key Features =

* **Custom job listings** — Title, trade, location, pay, employment type, requirements, contact info
* **Google for Jobs schema** — JobPosting JSON-LD auto-generated on every job page so jobs appear in Google's job search
* **Multi-platform distribution** — One-click post to Facebook + Indeed (API), copy-paste flow for Nextdoor + Craigslist
* **Built-in application form** — Candidates apply directly on your site with resume and cover letter uploads
* **Job listing shortcodes** — Display jobs anywhere with `[dpjp_jobs]` (grid or list, filter by trade/type)
* **Application popup or page** — Choose: dedicated `/apply/` page OR modal popup on the same page
* **Bulk import** — Upload CSV or JSON to create dozens of jobs at once
* **Customizable form** — Color picker, field toggles, custom text, notification email
* **Elementor integration** — When Elementor is installed, jobs auto-render with a clean banner + content layout
* **Application management** — All applications stored in WordPress admin with downloadable resume/cover letter
* **Email notifications** — Sent to your hiring contact (or override email) when someone applies

= Shortcodes =

* `[dpjp_jobs]` — Grid of all active jobs
* `[dpjp_jobs layout="list"]` — List layout
* `[dpjp_jobs columns="3"]` — 3-column grid
* `[dpjp_jobs trade="Plumbing"]` — Filter by trade
* `[dpjp_jobs apply="popup"]` — Apply buttons open modal
* `[dpjp_apply_form]` — Application form (place on your /apply/ page)
* `[dpjp_job id="123"]` — Display a single specific job

= How It Works =

1. Activate the plugin
2. Go to **Job Listings > Add New Job** — fill in title, pay, requirements, contact info
3. Place `[dpjp_jobs]` on a page (any page works — your `/jobs/` page, homepage, sidebar, etc.)
4. Optional: place `[dpjp_apply_form]` on a separate `/apply/` page, or use `apply="popup"` mode for inline modals
5. Configure social distribution credentials (Facebook Page Token, Indeed API) under **Job Listings > Settings**
6. From any job's edit screen, one-click post to Facebook and Indeed, or copy formatted text for Nextdoor/Craigslist

= Why this plugin =

Most job board plugins force candidates off your site to apply on Indeed, ZipRecruiter, etc. — you lose the lead, the data, and the SEO. **Job Manager Pro keeps everything on your site:** Google for Jobs visibility, your branding, your funnel, your data.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install via Plugins > Add New > Upload
2. Activate the plugin through the **Plugins** menu
3. Go to **Job Listings > Add New Job** and create your first listing
4. Place the `[dpjp_jobs]` shortcode on any page

== Frequently Asked Questions ==

= Does this work with any theme? =

Yes. The shortcodes use inline styles and respect your theme's container. The optional Elementor integration uses theme-default fonts and the colors you set in **Form Settings**.

= Do I need Elementor? =

No. Elementor is **optional**. Without it, jobs render using the default theme template. With it, jobs auto-get a clean banner + content layout.

= How do candidates apply? =

Two options:
1. **Dedicated /apply/ page** — Create a page, drop in `[dpjp_apply_form]`. Apply buttons link to `/apply/?job=ID` with the position pre-selected.
2. **Modal popup** — Use `[dpjp_jobs apply="popup"]` and apply buttons open the form right on the same page.

= Where do applications go? =

All applications appear in **Job Listings > Applications** with the candidate's info, resume, and cover letter. The hiring contact (or override email) also gets an email notification.

= Does it generate Google for Jobs schema? =

Yes — automatically. Every job page includes `JobPosting` JSON-LD with title, description, location, pay range, employment type, valid-through date, and qualifications. Google indexes these and shows them in job search results.

= Can I bulk import jobs? =

Yes. Go to **Job Listings > Import Jobs** and upload a CSV or JSON file. Templates are provided.

= How does Facebook/Indeed posting work? =

* **Facebook**: Add your Page ID and Page Access Token (from developers.facebook.com) under Settings. Then click "Post to Facebook" on any job.
* **Indeed**: Add your Indeed Employer API credentials. Then click "Post to Indeed".
* **Nextdoor / Craigslist**: No public APIs exist, so the plugin generates the post text and opens the platform — you paste and submit (takes ~60 seconds).

= Is there a cover letter upload? =

Yes. Toggle it on under **Job Listings > Form Settings > Form Fields**. Both resume and cover letter accept PDF, DOC, DOCX (and TXT for cover).

== Screenshots ==

1. Job listings displayed via `[dpjp_jobs]` shortcode
2. Application form with resume and cover letter uploads
3. Job edit screen with multi-platform distribution sidebar
4. Form Settings page with color picker
5. Applications admin list with downloadable files

== Changelog ==

= 1.2.0 =
* Added: Built-in application form with resume + cover letter uploads
* Added: Form Settings page with color customization
* Added: Multiple display modes (page or popup)
* Added: CSV/JSON bulk importer
* Added: Shortcodes admin reference page
* Added: Application management interface
* Added: Optional Elementor integration (theme-agnostic)
* Fixed: CPT archive no longer hijacks `/jobs/` WordPress page

= 1.1.0 =
* Added: Elementor integration
* Added: Importer for bulk job creation

= 1.0.0 =
* Initial release
* Custom job post type with Google for Jobs schema
* Facebook + Indeed API distribution
* Nextdoor + Craigslist copy-paste flow

== Upgrade Notice ==

= 1.2.0 =
Major release: built-in application form, color customization, CSV import, popup modal option, and shortcode reference page. Recommended for all users.

== License ==

This plugin is licensed under GPL v2 or later.
