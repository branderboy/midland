=== Smart Forms for Midland ===
Contributors: midland
Tags: contractors, leads, quotes, estimates, construction
Requires at least: 5.5
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.19.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Capture and qualify contractor leads with instant ballpark estimates. Stop losing jobs to competitors who respond faster.

== Description ==

**Stop playing phone tag. Start closing more jobs.**

Homeowners contact 3-5 contractors for every project. The one who responds first with a real number usually wins. Smart Forms gives your website visitors instant ballpark estimates while capturing every detail you need to close the deal.

**What you get:**

* Instant estimate calculator based on square footage and project type
* Photo uploads (up to 5 per lead) so you see the job before you call
* Timeline urgency badges (ASAP, This Week, Next Month) so you prioritize hot leads
* All leads in one dashboard with email, phone, and project details ready to go
* Email notifications the second a new lead hits
* Works on mobile, tablets, desktop - looks professional everywhere

**Perfect for:**

Drywall contractors, painters, general contractors, remodelers - anyone who needs to qualify leads and give ballpark numbers fast.

**How it works:**

1. Drop the `[sfco_quote]` shortcode on your Contact or Quote page
2. Visitors fill out project details, upload photos, get instant estimate
3. You get email notification with full lead details
4. Call them back with a real quote and close the job

No complex setup. No monthly fees. Just install, add shortcode, start capturing leads.

**The difference:**

Most contact forms collect a name and email. Smart Forms collects square footage, material preferences, timeline, photos, and ZIP code. Then calculates an instant estimate so the customer knows you're serious. You get qualified leads. They get immediate answers. Everyone wins.

== Installation ==

1. Upload plugin files to `/wp-content/plugins/smart-forms-for-midland/`
2. Activate the plugin
3. Add `[sfco_quote]` shortcode to any page
4. Done. Start getting leads.

View all leads under WordPress Admin → Smart Forms.

== Frequently Asked Questions ==

= Does this replace my contact form? =

Yes. This is a full lead capture system built specifically for contractors. Better than generic contact forms because it collects project-specific details and calculates estimates.

= Can I customize the estimate calculations? =

Current version uses industry-standard rates per square foot by project type. Custom pricing coming in future updates.

= Where do leads go? =

Saved in your WordPress database. View them under Smart Forms in your admin dashboard. You also get instant email notifications.

= Does it work with my page builder? =

Yes. Works with Elementor, Divi, Gutenberg, Beaver Builder, and any other builder. Just drop in the `[sfco_quote]` shortcode.

= What about photo storage? =

Photos upload to your WordPress site. Maximum 5 photos per lead, 5MB each. They're linked to each lead so you can review them before calling.

= Is it mobile-friendly? =

Completely responsive. Looks professional on phones, tablets, and desktops.

== Screenshots ==

1. Lead capture form with instant estimate calculator
2. Admin dashboard showing all leads with urgency badges
3. Individual lead view with photos and project details
4. Mobile-responsive design

== Changelog ==

= 2.19.3 =
* Diagnostic: form submit now shows the real error on screen (file + line) instead of a bare "An error occurred", and a thrown journey listener is caught so the lead still saves and the form succeeds.

= 2.19.2 =
* Fixed form submit 500 ('An error occurred'): the post-save journey hook (Vapi, ServiceM8, ops notifications, visit-draft, webhooks) is now wrapped so a failing listener can never break the submission. The lead saves and the visitor gets a success response; listener errors are logged.

= 2.19.1 =
* Fixed form submit: bind by class instead of ID (duplicate form IDs on one page broke the handler binding), resolve ajaxurl/nonce defensively, restore the button label, and surface the real error message
* Submission handler now validates against the form's own fields, connects the lead to the submitted form, maps name/email/phone across common field-key spellings, and stores all custom fields — so DB-built forms (like the chat form) capture leads instead of erroring

= 2.19.0 =
* Added a per-form Booking link (Calendly) field in the form editor. Only forms that should send people to schedule set it; others leave it blank.

= 2.18.0 =
* Forms list rebuilt on WordPress core's WP_List_Table — Gravity-Forms-style management
* Bulk actions: Activate / Deactivate / Move to Trash (Restore / Delete Permanently in Trash view)
* Status filter views (All | Active | Inactive | Trash) with counts, search box, and sortable columns
* Per-row actions: Edit | Entries | Duplicate | Activate/Deactivate | Trash
* Trash is a soft delete; trashed forms stop accepting submissions but entries are preserved until permanently deleted

= 2.17.1 =
* Added a Delete action to the forms list row actions

= 1.2.0 =
* Renamed shortcode to [sfco_quote] for prefix compliance
* Fixed all prefixes to sfco_ standard
* Improved input sanitization

= 1.0.0 =
* Initial release
* Lead capture with instant estimates
* Photo upload support (up to 5 photos)
* Admin dashboard with filtering
* Email notifications
* Timeline urgency tracking
* Mobile responsive design

== Upgrade Notice ==

= 1.2.0 =
Prefix and sanitization improvements.

= 1.0.0 =
Initial release.
