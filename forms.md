# Midland Floor Care, Intake Forms & CRM Tagging Spec

This spec defines the lead intake forms and the CRM tagging logic that fires
when each form is submitted. Every submission is auto-tagged so the right
follow-up sequence runs (emergency vs. future project vs. referral vs.
out-of-scope) and the right service vertical owns the lead.

---

## 1. Services In Demand (Focus Verticals)

The forms and CRM are built around the four focus services where the demand
actually is:

1. **Hardwood floor finishing**, sand & refinish, recoat, stain colour change
2. **Carpet cleaning & installation**, residential carpet (allergy-wave
   seasonality, twice a year) + carpet installs
3. **Flooring installs**, hardwood, LVP, tile, laminate
4. **Concrete polishing**, mechanical grind, densify, seal (commercial focus)

### Brand / Segment Split

| Brand                          | Segment      | Services                                                                 |
|--------------------------------|--------------|--------------------------------------------------------------------------|
| Midland Carpet Care & Cleaning | Residential  | Carpet cleaning, carpet installation. Seasonality: 2x/year (allergy waves). |
| Midland Service                | Commercial   | Tile, hardwood, concrete polishing, flooring installs, recurring floor care. |

The intake form must capture which brand/segment the lead belongs to so the
right pipeline owns it.

---

## 2. The 4 Lead Triggers (Why People Contact Us)

Every lead falls into exactly one of these triggers. The CRM tag is set on
form submit and drives the follow-up sequence.

| #  | Trigger              | Description                                                                                       | Primary CRM Tags                                                |
|----|----------------------|---------------------------------------------------------------------------------------------------|-----------------------------------------------------------------|
| 1  | Emergency            | #1 reason people book, spill, flood, damage, last-minute event. Same-day response required.     | `trigger:emergency`, `priority:high`, `sla:same-day`            |
| 2  | Future Project       | Prospect is researching, wants to be an informed consumer but is not in-market yet. Long nurture. | `trigger:future-project`, `stage:research`, `nurture:long`      |
| 3  | Referral / Mgmt Visit| Faster turnaround, often triggered by an upper-management visit or property inspection.          | `trigger:referral`, `priority:expedite`, `source:referral`      |
| 4  | Out Of Scope         | Inquiry for a service we don't provide. Log, decline politely, optionally refer.                 | `trigger:out-of-scope`, `action:decline-or-refer`               |

### Lifecycle Path

```
Emergency Job → Service Completed → Happy Customer Follow-Up → Floor Care Plan Customer
```

The follow-up after a successful emergency service is what converts a one-time
customer into a recurring **Floor Care Plan** subscriber. That conversion is
where the lifetime value lives, and it must be tracked as a separate CRM
stage (`stage:floor-care-plan`).

---

## 3. Universal Form Fields (Every Form)

These fields appear on every intake form regardless of service.

- **Name** *(required)*, first, last
- **Business Name** *(optional, required if commercial)*
- **Email** *(required, with confirm)*
- **Phone** *(required)*
- **Service Address / ZIP** *(required)*, drives city × service routing
- **Property Type** *(required)*, Residential / Commercial / Multi-family / Other
- **Multiple Locations?** *(required, commercial only)*, Yes / No
- **How did you hear about us?**, Google / Referral / Repeat customer / Other → sets `source:*` tag
- **Reason for contacting us** *(required)*, the 4 triggers (sets `trigger:*` tag):
  - Emergency, need service now
  - Future project, researching options
  - Referral / management visit / inspection
  - Looking for a service not listed
- **Notes** *(required)*, free-text, captured as the lead memo on the CRM record
- **Preferred contact window**
- **Photos / attachments** *(optional)*

### Auto-tags fired on every submission

- `service:<vertical>`, set by which form was used
- `segment:residential` or `segment:commercial`
- `brand:carpet-care` or `brand:midland-service`
- `trigger:<one-of-four>`
- `source:<channel>`
- `city:<from-zip>` and `zip:<value>`, for geo reporting
- `lead-created:<iso-date>`

---

## 4. Service-Specific Forms

### 4.1 Hardwood Floor Finishing

Used for sand & refinish, recoat, stain change, restoration.

- **What do you need?** *(required, multi-select → sets sub-tags)*
  - Sand & refinish (full) → `service:hardwood-refinish`
  - Recoat / buff & coat → `service:hardwood-recoat`
  - Stain colour change → `service:hardwood-stain-change`
  - Repair / board replacement → `service:hardwood-repair`
  - Water / pet damage restoration → `service:hardwood-restoration`
- **Floor type**, Solid hardwood / Engineered / Parquet / Unsure
- **Approximate square footage**, number input
- **Number of rooms / areas**
- **Current finish condition**, Like new / Worn but intact / Heavily worn / Damaged
- **Desired finish**, Oil-based poly / Water-based poly / Hardwax oil / Unsure
- **Desired sheen**, Matte / Satin / Semi-gloss / Gloss
- **Furniture in place?**, Yes (need move) / No / Partial
- **Occupied during work?**, Yes / No / Flexible
- **Target completion date**
- **Photos of the floor** *(strongly encouraged)*

### 4.2 Carpet Cleaning & Installation

- **What do you need?** *(required)*
  - Carpet cleaning (residential) → `service:carpet-clean-residential`
  - Carpet cleaning (commercial) → `service:carpet-clean-commercial`
  - Stain / spot emergency → `service:carpet-stain-emergency` + `trigger:emergency`
  - Carpet installation → `service:carpet-install`
  - Carpet repair / re-stretch → `service:carpet-repair`
- **Approximate square footage / number of rooms**
- **Carpet type**, Synthetic / Wool / Berber / Commercial loop / Unsure
- **Stains / problem areas**, checkbox list (pet / wine / coffee / oil / unknown / other)
- **Pets in the home?**, Yes / No
- **Allergy concerns?**, Yes / No → if Yes, tag `interest:allergy-program` for seasonal re-engagement
- **Last professional cleaning**, Within 6 months / 6-12 months / 12+ months / Never
- **Preferred service window**
- For installs: existing flooring being removed? Yes / No / Unsure

### 4.3 Flooring Installs

- **Flooring type to install** *(required, multi-select)*
  - Hardwood → `service:install-hardwood`
  - LVP / vinyl plank → `service:install-lvp`
  - Tile → `service:install-tile`
  - Laminate → `service:install-laminate`
  - Other / unsure → `service:install-other`
- **Approximate square footage**
- **Rooms / areas**
- **Existing floor**, and does it need removal / disposal?
- **Subfloor type**, Concrete slab / Plywood / Unsure
- **Subfloor condition**, Level / Uneven / Moisture issues / Unsure
- **Material supplied by**, Midland / Customer / Need recommendation
- **Need transitions / baseboards / quarter-round?**, Yes / No
- **Target completion date**
- **Photos of the space**

### 4.4 Concrete Polishing

- **Application** *(required)*
  - Warehouse / industrial → `service:concrete-warehouse`
  - Retail / commercial → `service:concrete-retail`
  - Garage (residential or commercial) → `service:concrete-garage`
  - Office / institutional → `service:concrete-office`
- **Approximate square footage**
- **Current floor condition**, Bare slab / Painted / Coated (epoxy etc.) / Tile-over-slab / Unsure
- **Desired sheen / level**, Satin / Semi-gloss / High-gloss
- **Densifier + sealer required?**, Yes / No / Recommend
- **Joint / crack repair needed?**, Yes / No / Unsure
- **Operational hours / access constraints**, free text (after-hours, weekends, etc.)
- **Forklift / heavy equipment traffic?**, Yes / No
- **Target completion date**
- **Photos of the slab**

### 4.5 Floor Care Plan (Recurring, Commercial)

Existing "Schedule An Audit" form, kept and extended.

- Package: Standard / Premium / Custom
- Multiple locations: Yes / No
- Biggest challenge: stains / shine / scratches / discoloration / dust
- Site visit requested: Yes / No
- Tags fired:
  - `service:floor-care-plan`
  - `package:<standard|premium|custom>`
  - `stage:audit-requested`
  - If converted from a prior emergency lead → `lifecycle:emergency-to-plan`

### 4.6 Out-Of-Scope Inquiry

When a prospect picks "Looking for a service not listed":

- Free-text **What service are you looking for?**
- Tag: `trigger:out-of-scope`
- Auto-reply: polite decline + optional referral list
- Logged for monthly review, recurring requests inform future service expansion

---

## 5. CRM Tag Taxonomy (Reference)

Namespaced tags. Combine freely.

```
trigger:emergency | trigger:future-project | trigger:referral | trigger:out-of-scope
priority:high     | priority:expedite      | priority:normal
sla:same-day      | sla:48h                | sla:standard
segment:residential | segment:commercial
brand:carpet-care   | brand:midland-service
service:hardwood-refinish | service:hardwood-recoat | service:hardwood-stain-change |
service:hardwood-repair   | service:hardwood-restoration |
service:carpet-clean-residential | service:carpet-clean-commercial |
service:carpet-stain-emergency   | service:carpet-install | service:carpet-repair |
service:install-hardwood | service:install-lvp | service:install-tile |
service:install-laminate | service:install-other |
service:concrete-warehouse | service:concrete-retail |
service:concrete-garage    | service:concrete-office |
service:floor-care-plan
package:standard | package:premium | package:custom
stage:research | stage:audit-requested | stage:quoted | stage:booked |
stage:in-service | stage:completed | stage:floor-care-plan
source:google | source:referral | source:repeat | source:other
interest:allergy-program
lifecycle:emergency-to-plan
city:<value> | zip:<value>
```

---

## 6. Follow-Up Sequences (By Trigger)

| Trigger          | First Touch       | Day 1               | Day 3               | Day 7              | Long-Term                                       |
|------------------|-------------------|---------------------|---------------------|--------------------|-------------------------------------------------|
| Emergency        | < 2 min auto-reply + SMS to owner | Quote / dispatch  | Service completed   | Review request     | Day 30 happy-customer → floor care plan offer   |
| Future Project   | < 2 min auto-reply + resource pack | Education email   | Education email     | Check-in           | Quarterly nurture, seasonal allergy reminder    |
| Referral         | < 2 min auto-reply + expedited quote | Site visit booked | Quote sent         | Service scheduled  | Floor care plan upsell post-completion          |
| Out Of Scope     | < 2 min polite decline + referral suggestion |, |, |, | Logged for monthly service-expansion review     |

The "happy customer → floor care plan" conversion at Day 30 is the single
highest-leverage automation in the system and must be tracked as its own
funnel metric.
