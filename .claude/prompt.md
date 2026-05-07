You are an expert Elementor Template Kit developer.

Your job is to create a COMPLETE, import-ready Elementor Template Kit that matches the structure and quality of a ThemeForest kit.

You must follow Elementor’s JSON structure exactly.

-----------------------------------
GOAL
-----------------------------------

Create a full Elementor Template Kit that can be zipped and imported using:
Elementor → Tools → Import/Export → Template Kit

This is NOT HTML, NOT React, NOT a WordPress theme.

This is ONLY Elementor Template Kit JSON.

-----------------------------------
OUTPUT STRUCTURE
-----------------------------------

Return a full file structure like this:

/kit-name/
  manifest.json
  templates/
    global.json
    header.json
    footer.json
    home.json
    about.json
    services.json
    service-single.json
    contact.json
    blog-archive.json
    single-post.json
    404.json
    landing.json
    thank-you.json
  screenshots/
    home.jpg
    about.jpg
    services.jpg

-----------------------------------
ELEMENTOR RULES (CRITICAL)
-----------------------------------

- Use "version": "0.4"
- Use container-based layout (NOT sections)
- Structure = container → container → widget
- Every node must include correct Elementor keys
- Do NOT invent fields
- Do NOT skip required fields
- Do NOT output incomplete JSON

-----------------------------------
ALLOWED WIDGETS ONLY
-----------------------------------

Use ONLY these widgets:

heading  
text-editor  
image  
button  
icon-list  
image-box  
counter  
testimonial  
call-to-action  
posts  
image-carousel  

-----------------------------------
GLOBAL STYLES (global.json)
-----------------------------------

Define:

- Color system (primary, secondary, text, accent)
- Typography system:
  - Body
  - H1–H6
  - Buttons
- Container width
- Button styles
- Link styles

-----------------------------------
PAGE STRUCTURE RULES
-----------------------------------

Every page must include:

1. Hero section
2. Core content section
3. Trust / proof section
4. CTA section

-----------------------------------
CONTENT QUALITY
-----------------------------------

- Write sharp, real copy (no filler)
- Make it conversion-focused
- Use realistic business language
- No generic lorem ipsum

-----------------------------------
PLACEHOLDERS
-----------------------------------

- Use placeholder image URLs
- Use realistic names, services, testimonials

-----------------------------------
INPUT VARIABLES
-----------------------------------

Niche: [INSERT NICHE]  
Brand Name: [INSERT NAME]  
City/Market: [INSERT LOCATION]  
Style Direction: [luxury / minimal / bold / nightlife / corporate / etc]  
Primary Goal: [leads / bookings / events / sales]

-----------------------------------
IMPORTANT
-----------------------------------

- Do NOT explain anything
- Do NOT describe the files
- ONLY output the full kit structure with JSON content
- Make sure all JSON is valid and consistent

-----------------------------------
FINAL TASK
-----------------------------------

Generate the complete Elementor Template Kit now.



Upgrade move (this is where you win)
After Claude outputs the kit, run this follow-up:


Audit this Elementor kit for:
- invalid JSON
- missing Elementor keys
- broken widget structures
- responsiveness issues

Fix everything and return a clean, import-ready version.


Elementor Kit Anatomy
elementor-kit.zip
├── manifest.json
├── help.html
├── templates/
│   ├── global.json
│   ├── home.json
│   ├── about-us.json
│   ├── services.json
│   ├── contact-us.json
│   ├── header.json
│   ├── footer.json
│   ├── archive.json
│   ├── single-post.json
│   ├── 404.json
│   └── other-page.json
└── screenshots/
    ├── home.jpg
    ├── about-us.jpg
    ├── services.jpg
    └── etc.
What matters most
The kit is basically:
manifest.json
Controls the kit name, template list, screenshots, template types, required plugins.
templates/global.json
Stores global colors, fonts, button styles, body styles, container widths.
Each page JSON
Example: home.json, services.json, about-us.json.
Every page uses:
{
  "version": "0.4",
  "title": "Home",
  "type": "page",
  "metadata": {},
  "content": []
}

The content array
This is the whole page layout.
Elementor builds pages from nested:
container → container → widget

Widgets used
This kit mainly uses:
heading
text-editor
image
button
icon-list
image-box
call-to-action
testimonial
counter
posts
image-carousel
rating
So Claude does not need to invent crazy Elementor logic. It needs to generate clean Elementor JSON using containers and common widgets.
The build model for Claude
Use this prompt:
You are building a ThemeForest-style Elementor Template Kit.

Create a complete Elementor Template Kit structure with:

1. manifest.json
2. templates/global.json
3. templates/home.json
4. templates/about-us.json
5. templates/services.json
6. templates/contact-us.json
7. templates/header.json
8. templates/footer.json
9. templates/404.json
10. screenshots folder placeholders

Use Elementor JSON format:
- version: "0.4"
- title
- type
- metadata
- content array

Use nested Elementor containers, not sections.
Use only common Elementor widgets:
- heading
- text-editor
- image
- button
- icon-list
- image-box
- counter
- testimonial
- call-to-action
- posts
- image-carousel

Global style rules:
- Create system colors
- Create custom color palette
- Create typography system
- Define body, H1-H6, button, link, and container styles
- Use CSS variables or Elementor global references where possible

Each page must have:
- Hero section
- Trust/value section
- Service/content section
- CTA section
- Responsive tablet/mobile settings
- Clean container hierarchy
- Placeholder image URLs
- No broken widgets
- No missing required Elementor keys

The niche is: [INSERT NICHE]
Brand name: [INSERT BRAND]
Style direction: [INSERT STYLE]
Pages needed: [INSERT PAGES]

Important:
Do not create regular HTML.
Do not create React.
Do not create WordPress theme files.
Only create Elementor Template Kit JSON files that can be zipped and imported through Elementor Template Kit import.
The smart way to build your kits
Do not start from scratch.
Use this ClayHive kit as your base skeleton:
Keep:
- manifest structure
- global.json structure
- container nesting style
- widget types
- metadata format
- screenshot naming pattern

Replace:
- kit title
- page names
- colors
- fonts
- headings
- text
- images
- service sections
- testimonials
- CTAs
Claude workflow
Step 1: Give Claude the extracted kit folder.
Step 2: Tell it to clone the structure.
Step 3: Tell it to create a new kit for one niche.
Step 4: Tell it to only edit JSON content, labels, styles, images, and manifest.
Step 5: Zip the final folder.
Step 6: Import into Elementor Template Kit importer.
Step 7: Fix any broken widget settings.
Best niches for fast Elementor kits
contractor website kit
cleaning company kit
med spa kit
barber shop kit
cannabis dispensary kit
restaurant kit
event promoter kit
DJ / music artist kit
real estate investor kit
law firm kit
home remodeling kit
roofing company kit
fitness trainer kit
church / ministry kit
local nonprofit kit
The real product play
Make each kit include:
Home
About
Services
Single Service
Contact
Blog Archive
Single Post
404
Header
Footer
Landing Page
Thank You Page
