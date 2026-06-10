<?php
/**
 * Done-for-you service page copy library.
 *
 * Real, service-specific page copy (not a one-size template) for every GBP
 * category Midland offers, with a rich generic fallback for anything new.
 * House rule: generated copy NEVER contains em or en dashes; no_dashes() is
 * the enforcement point and is also applied to template output elsewhere.
 *
 * @package Real_Smart_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Service copy profiles + assembly.
 */
class RSSEO_Service_Copy {

    /**
     * Strip em/en dashes (and their HTML entities) from generated output.
     * Spaced dashes become a comma; tight dashes become a space.
     *
     * @param string $html Generated HTML/text.
     * @return string
     */
    public static function no_dashes( $html ) {
        $html = str_replace( array( ' — ', ' – ', ' &mdash; ', ' &ndash; ', ' &#8212; ', ' &#8211; ' ), ', ', (string) $html );
        return str_replace( array( '—', '–', '&mdash;', '&ndash;', '&#8212;', '&#8211;' ), ' ', $html );
    }

    /**
     * Whether a dedicated copy profile exists for this service.
     *
     * @param string $service Service name.
     * @return bool
     */
    public static function has( $service ) {
        return 'generic' !== self::match_key( $service );
    }

    /**
     * Full done-for-you page body for a service. Identity-aware (business name,
     * phone, service areas) and guaranteed dash-free.
     *
     * @param string $service Service name as it should read on the page.
     * @return string HTML.
     */
    public static function body_for( $service ) {
        $identity = get_option( 'rsseo_sameas_identity', array() );
        if ( empty( $identity ) && class_exists( 'MLS_SameAs' ) ) {
            $identity = MLS_SameAs::get_identity();
        }
        $business = ! empty( $identity['business_name'] ) ? $identity['business_name'] : get_bloginfo( 'name' );
        $phone    = ! empty( $identity['business_phone'] ) ? $identity['business_phone'] : '(240) 532-9097';
        $areas    = 'Washington DC, Montgomery County, Prince George\'s County, and Northern Virginia';
        if ( ! empty( $identity['service_areas'] ) ) {
            $list = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $identity['service_areas'] ) ) );
            if ( $list ) {
                $areas = implode( ', ', $list );
            }
        }

        $p = self::profiles()[ self::match_key( $service ) ];
        $b = esc_html( $business );
        $s = esc_html( ucfirst( strtolower( $service ) ) );

        $vars = array(
            '{business}' => $b,
            '{service}'  => $s,
            '{areas}'    => esc_html( $areas ),
            '{phone}'    => esc_html( $phone ),
        );

        $h   = array();
        $h[] = '<h2>' . strtr( $p['headline'], $vars ) . '</h2>';
        foreach ( $p['intro'] as $para ) {
            $h[] = '<p>' . strtr( $para, $vars ) . '</p>';
        }

        $h[] = '<h2>' . strtr( $p['included_title'], $vars ) . '</h2>';
        $h[] = '<ul><li>' . implode( '</li><li>', array_map( static function ( $i ) use ( $vars ) {
            return strtr( $i, $vars );
        }, $p['included'] ) ) . '</li></ul>';

        $h[] = '<h2>Why ' . $b . '</h2>';
        $h[] = '<ul><li>' . implode( '</li><li>', array_map( static function ( $i ) use ( $vars ) {
            return strtr( $i, $vars );
        }, $p['why'] ) ) . '</li></ul>';

        $h[] = '<h2>How It Works</h2>';
        $h[] = '<ol><li>' . implode( '</li><li>', array_map( static function ( $i ) use ( $vars ) {
            return strtr( $i, $vars );
        }, $p['steps'] ) ) . '</li></ol>';

        $h[] = '<h2>Frequently Asked Questions</h2>';
        foreach ( $p['faqs'] as $faq ) {
            $h[] = '<h3>' . strtr( $faq[0], $vars ) . '</h3><p>' . strtr( $faq[1], $vars ) . '</p>';
        }

        $h[] = '<h2>Get Your Free Quote</h2>';
        $h[] = '<p>' . strtr( $p['cta'] . ' <a href="/contact/">Request a free quote online</a> or call {phone}. {business} serves {areas}.', $vars ) . '</p>';

        return self::no_dashes( implode( "\n", $h ) );
    }

    /**
     * Map a service name to a copy profile key.
     *
     * @param string $service Service name.
     * @return string
     */
    private static function match_key( $service ) {
        $n = strtolower( (string) $service );
        if ( false !== strpos( $n, 'carpet' ) && ( false !== strpos( $n, 'install' ) ) ) {
            return 'carpet-installation';
        }
        if ( false !== strpos( $n, 'carpet' ) ) {
            return 'carpet-cleaning';
        }
        if ( false !== strpos( $n, 'janitorial' ) || false !== strpos( $n, 'cleaning company' ) ) {
            return 'janitorial';
        }
        if ( false !== strpos( $n, 'tile' ) || false !== strpos( $n, 'grout' ) ) {
            return 'tile-grout';
        }
        if ( false !== strpos( $n, 'upholstery' ) ) {
            return 'upholstery';
        }
        if ( false !== strpos( $n, 'wood' ) || false !== strpos( $n, 'hardwood' ) ) {
            return 'wood-refinishing';
        }
        if ( false !== strpos( $n, 'refinish' ) || false !== strpos( $n, 'buff' ) || false !== strpos( $n, 'strip' ) || false !== strpos( $n, 'wax' ) || false !== strpos( $n, 'vct' ) ) {
            return 'floor-refinishing';
        }
        if ( false !== strpos( $n, 'flooring contractor' ) || false !== strpos( $n, 'floor care' ) || false !== strpos( $n, 'floor maintenance' ) ) {
            return 'flooring-contractor';
        }
        return 'generic';
    }

    /**
     * The copy profiles. Every string must be dash-free; no_dashes() backstops.
     *
     * @return array
     */
    private static function profiles() {
        return array(

            'carpet-cleaning' => array(
                'headline'       => 'Carpet Cleaning That Pulls Out What Vacuums Leave Behind',
                'intro'          => array(
                    'Carpet holds on to everything your building tracks in. Soil grinds into the fibers, spills wick deep into the pad, and odors settle in where surface cleaning never reaches. {business} uses truck mounted hot water extraction and low moisture encapsulation to lift out embedded soil, allergens, and odors, then leaves carpet dry and ready for traffic fast.',
                    'We clean carpet in homes, offices, retail floors, medical suites, and apartment communities across {areas}. High traffic lanes, stubborn spots, pet accidents, and tenant turnover carpet are everyday work for our crews.',
                ),
                'included_title' => 'What Every Carpet Cleaning Includes',
                'included'       => array(
                    'Pre inspection and a walk through so you know exactly what to expect',
                    'Pre treatment of traffic lanes, spots, and stains before the main clean',
                    'Truck mounted hot water extraction or low moisture encapsulation, matched to your carpet',
                    'Pet odor and stain treatment when needed',
                    'Carpet protector application on request to slow down resoiling',
                    'Fast dry times with air movers on every residential job',
                ),
                'why'            => array(
                    'Trained, background checked technicians who treat your space with respect',
                    'Commercial grade equipment that outperforms rental machines and portable units',
                    'Safe products for kids, pets, and staff',
                    'Clear pricing before we start. The quote is the price',
                ),
                'steps'          => array(
                    'We inspect the carpet and identify fiber type, problem spots, and traffic damage',
                    'We pre treat stains and high traffic lanes so the deep clean works harder',
                    'We extract with hot water or encapsulate, depending on the carpet and the schedule',
                    'We groom the pile and set air movers so the carpet dries quickly',
                    'We walk the finished job with you before we pack up',
                ),
                'faqs'           => array(
                    array( 'How long does carpet take to dry?', 'Most carpet is dry to the touch in 4 to 8 hours. Low moisture encapsulation dries in about an hour, which is why offices and stores often choose it.' ),
                    array( 'Can you remove pet odors for good?', 'Yes, when we can treat the source. We use enzyme treatments that break down the odor in the carpet and pad instead of masking it.' ),
                    array( 'Do you move furniture?', 'We move and block light furniture as part of the job. Tell us about heavy pieces ahead of time and we will plan around them.' ),
                    array( 'How often should commercial carpet be cleaned?', 'High traffic commercial carpet does best on a quarterly schedule, with monthly touch ups for entrances and elevators. We will build a plan around your traffic, not a one size schedule.' ),
                ),
                'cta'            => 'Stop living with dingy traffic lanes and spots that keep coming back.',
            ),

            'carpet-installation' => array(
                'headline'       => 'Carpet Installation Done Right the First Time',
                'intro'          => array(
                    'A great carpet install disappears. No visible seams, no ripples, no gaps at the wall, just a clean floor that wears evenly for years. {business} installs carpet for homes and commercial spaces across {areas}, from single rooms to full office floors.',
                    'We handle the whole job: measurement, material guidance, tear out and haul away of the old floor, subfloor prep, and a tight, stretched in or glue down install with the seams placed where traffic will never find them.',
                ),
                'included_title' => 'What Your Installation Includes',
                'included'       => array(
                    'In home or on site measurement so you buy the right amount, not extra waste',
                    'Honest guidance on fiber, pad, and pile for how the space is actually used',
                    'Tear out and haul away of the old carpet and pad',
                    'Subfloor inspection and prep before anything goes down',
                    'Power stretched installation with seams placed away from traffic and light lines',
                    'Full cleanup and a final walk through with you',
                ),
                'why'            => array(
                    'Experienced installers, not day labor. The crew that quotes is the crew that shows up',
                    'Seam placement planned around traffic patterns and sight lines',
                    'Flexible scheduling, including evenings and weekends for businesses',
                    'A written workmanship warranty we actually honor',
                ),
                'steps'          => array(
                    'We measure the space and help you choose carpet and pad that fit the use and the budget',
                    'We schedule the install on your timeline, including after hours for offices',
                    'We remove the old floor, prep the subfloor, and fix squeaks and rough spots',
                    'We install, stretch, and seam the new carpet, then trim and finish the edges',
                    'We vacuum, haul away every scrap, and walk the job with you',
                ),
                'faqs'           => array(
                    array( 'How long does carpet installation take?', 'Most homes are one day. Larger commercial jobs are phased so your business keeps running while we work.' ),
                    array( 'Do I need new pad?', 'Almost always yes. Old pad carries odors and wear patterns into new carpet and voids most manufacturer warranties.' ),
                    array( 'Will the seams show?', 'Seams are planned before the first cut, placed away from main sight lines and traffic. On most installs you will have to hunt to find them.' ),
                    array( 'Can you install over concrete?', 'Yes. We moisture test concrete subfloors first, then use a glue down or stretched install depending on the space.' ),
                ),
                'cta'            => 'Get carpet installed by a crew that sweats the seams.',
            ),

            'janitorial' => array(
                'headline'       => 'Janitorial Service Your Tenants Never Have to Think About',
                'intro'          => array(
                    'Good janitorial service is invisible. The lobby shines every morning, the restrooms are stocked, the trash is gone, and nobody had to send an email about it. {business} delivers nightly, weekly, and day porter janitorial programs for offices, retail, medical, and industrial facilities across {areas}.',
                    'We build the scope around your building: the traffic, the hours, the security requirements, and the standards your tenants and customers expect. Then we hit it, every visit, with supervised crews and documented quality checks.',
                ),
                'included_title' => 'What a Janitorial Program Can Include',
                'included'       => array(
                    'Nightly or scheduled cleaning of offices, common areas, and restrooms',
                    'Restroom sanitation and restocking with consumables management',
                    'Trash, recycling, and breakroom service',
                    'Hard floor care: dust mop, damp mop, burnish, and periodic scrub and recoat',
                    'Carpet vacuuming with periodic extraction built into the schedule',
                    'Day porter coverage for lobbies, entrances, and event support',
                ),
                'why'            => array(
                    'Supervised crews with documented checklists, not a key and a hope',
                    'Background checked, uniformed staff who respect building security',
                    'One vendor for janitorial and floor care, one invoice, one number to call',
                    'Quality walks with you on a schedule, so problems get caught before tenants see them',
                ),
                'steps'          => array(
                    'We walk the building with you and document the scope room by room',
                    'We quote a flat monthly price with the visit schedule spelled out',
                    'Crews clean on schedule with supervisor checklists on every visit',
                    'We review quality with you monthly and tune the scope as the building changes',
                ),
                'faqs'           => array(
                    array( 'Do you clean after hours?', 'Yes. Most of our janitorial work happens nights and weekends so your business never works around us.' ),
                    array( 'Are your staff background checked?', 'Yes. Every crew member is background checked and uniformed, and we coordinate with your building security procedures.' ),
                    array( 'Can you handle medical or specialty facilities?', 'Yes. We service medical suites, labs, and child care facilities with the products and protocols those spaces require.' ),
                    array( 'What if something gets missed?', 'Call or text and it gets fixed on the next visit or sooner. Misses are logged and reviewed so they do not repeat.' ),
                ),
                'cta'            => 'Get a janitorial program your tenants never have to think about.',
            ),

            'tile-grout' => array(
                'headline'       => 'Tile and Grout Cleaning That Makes Old Floors Look New',
                'intro'          => array(
                    'Grout is a sponge. It drinks in mop water, soil, and grease until the lines turn dark and the whole floor looks dirty no matter how often it gets mopped. {business} deep cleans tile and grout with heated, high pressure extraction that pulls years of buildup out of the grout lines instead of pushing it around.',
                    'We clean kitchens, bathrooms, lobbies, restaurants, and commercial restrooms across {areas}, and we can seal the grout afterward so the results last.',
                ),
                'included_title' => 'What Tile and Grout Cleaning Includes',
                'included'       => array(
                    'Inspection and pH appropriate pre treatment for the soil type',
                    'Machine agitation of grout lines and textured tile',
                    'Heated high pressure rinse and extraction, contained so nothing splashes walls or cabinets',
                    'Spot treatment of grease, soap scum, and mineral deposits',
                    'Optional penetrating grout sealer while the floor is clean',
                    'Optional color seal to bring uniform color back to stained grout',
                ),
                'why'            => array(
                    'Heated extraction equipment that outperforms scrub brushes and mops by a mile',
                    'Safe on porcelain, ceramic, and natural stone, with stone safe products on hand',
                    'Sealing options that keep grout cleaner for years, not weeks',
                    'Clear pricing by the square foot, quoted before we start',
                ),
                'steps'          => array(
                    'We test a small area so you can see the result before we do the whole floor',
                    'We pre treat and machine scrub the tile and grout lines',
                    'We extract with heated, high pressure rinse that captures the soil',
                    'We dry the floor and apply sealer if you have chosen it',
                ),
                'faqs'           => array(
                    array( 'Will my grout look new again?', 'Cleaning removes the buildup and takes grout back to its true color. If the grout is permanently stained, color sealing restores a uniform finish.' ),
                    array( 'Should I seal grout after cleaning?', 'Yes if you want the result to last. Sealed grout sheds spills and mop water instead of absorbing them.' ),
                    array( 'Is the process safe for natural stone?', 'Yes. We identify the surface first and switch to stone safe, neutral products for marble, travertine, and other natural stone.' ),
                    array( 'How long before we can walk on the floor?', 'Right after cleaning in most cases. If we seal the grout, plan on keeping traffic off for about an hour.' ),
                ),
                'cta'            => 'Stop mopping a floor that never looks clean.',
            ),

            'upholstery' => array(
                'headline'       => 'Upholstery Cleaning for Furniture You Actually Live On',
                'intro'          => array(
                    'Sofas and office chairs collect body oils, food, dust, and odors faster than any carpet, and most of it hides deep in the cushions. {business} cleans upholstery with fiber appropriate methods that lift the soil out without soaking the frame or fading the fabric.',
                    'We clean residential furniture, restaurant booths, office seating, and waiting room furniture across {areas}.',
                ),
                'included_title' => 'What Upholstery Cleaning Includes',
                'included'       => array(
                    'Fabric identification and a hidden spot test before anything touches the fabric',
                    'Dry soil removal with high suction vacuuming',
                    'Pre treatment of body oil lines, food spots, and pet spots',
                    'Hot water extraction or low moisture cleaning, matched to the fabric',
                    'Deodorizing and optional fabric protector',
                    'Fast, even drying so cushions do not water mark',
                ),
                'why'            => array(
                    'Technicians trained on delicate and natural fiber fabrics, not just synthetics',
                    'Tools sized for furniture, so seams, tufting, and cushions get cleaned, not just the flat panels',
                    'Pet odor treatments that neutralize instead of perfume',
                    'Honest assessments. If a piece will not clean up well, we say so before you spend money',
                ),
                'steps'          => array(
                    'We identify the fabric and test for colorfastness in a hidden spot',
                    'We vacuum and pre treat the soil and oil lines',
                    'We clean with the method the fabric calls for and extract the soil',
                    'We groom the fabric and speed dry the cushions',
                ),
                'faqs'           => array(
                    array( 'Can you clean microfiber, linen, or wool?', 'Yes. Each fabric gets a different method. That is exactly why we identify the fiber and test before cleaning.' ),
                    array( 'How long until we can sit on the furniture?', 'Light use in 2 to 4 hours for most fabrics. We speed dry the cushions before we leave.' ),
                    array( 'Can you get pet smell out of a couch?', 'Usually yes, when the contamination is in the fabric and cushions. We treat with enzymes at the source and tell you honestly if the foam is too far gone.' ),
                    array( 'Do you clean office and restaurant seating?', 'Yes, in bulk and after hours. Task chairs, booths, banquettes, and waiting room furniture are regular work for us.' ),
                ),
                'cta'            => 'Make the furniture you sit on every day clean again.',
            ),

            'wood-refinishing' => array(
                'headline'       => 'Wood Floor Refinishing That Brings Dead Floors Back to Life',
                'intro'          => array(
                    'Hardwood does not need to be replaced when it goes gray and scratched. It needs the worn finish removed and a new one built right. {business} refinishes hardwood floors across {areas}, from buff and recoat maintenance to full sand and refinish restorations.',
                    'We use dust contained sanding equipment, commercial grade finishes, and a process that respects the fact that you live or work in the building while we do it.',
                ),
                'included_title' => 'Refinishing Options We Offer',
                'included'       => array(
                    'Screen and recoat to refresh dull finish before damage reaches the wood',
                    'Full sand and refinish for gray, scratched, or water marked floors',
                    'Stain color changes when you want a new look, with samples on your own floor',
                    'Board repairs and replacement for pet damage and water damage',
                    'Commercial wood floor maintenance programs for offices and retail',
                    'Matte, satin, or gloss sheen in durable waterborne or oil based finishes',
                ),
                'why'            => array(
                    'Dust containment on every sanding job, so the house does not wear the floor',
                    'Finish systems chosen for traffic, not whatever is on the truck',
                    'Sample boards and test patches before a stain color goes wall to wall',
                    'Straight talk about whether your floor needs a recoat or a full refinish. They are very different prices',
                ),
                'steps'          => array(
                    'We inspect the floor and tell you honestly whether it needs a recoat or a full sand',
                    'We protect the space and set up dust containment',
                    'We sand to clean wood, or abrade the old finish for a recoat',
                    'We apply stain if chosen, then build the new finish in coats',
                    'We walk the floor with you and leave care instructions that protect the finish',
                ),
                'faqs'           => array(
                    array( 'How do I know if I need a full refinish or just a recoat?', 'If the wear is only in the finish, a screen and recoat saves you most of the cost. If boards are gray, deeply scratched, or water marked, the floor needs a full sand. We tell you which one honestly.' ),
                    array( 'How long does refinishing take?', 'A typical home is 3 to 5 days including cure time between coats. A recoat is usually one day.' ),
                    array( 'How bad is the dust?', 'Our sanders run with vacuum containment that captures the overwhelming majority of dust. You will not be wiping the house down for weeks.' ),
                    array( 'When can we walk on the floors?', 'Socks the next day on most finishes, furniture after 3 days, rugs after 2 weeks while the finish fully cures.' ),
                ),
                'cta'            => 'Find out what your wood floors could look like again.',
            ),

            'floor-refinishing' => array(
                'headline'       => 'Floor Refinishing and Maintenance for Floors That Work for a Living',
                'intro'          => array(
                    'Commercial floors take a beating: carts, foot traffic, salt, and daily mopping that slowly dulls every finish. {business} restores and maintains hard floors across {areas}, including VCT strip and wax, concrete polishing and burnishing, stone honing, and wood recoats.',
                    'One crew, one schedule, every hard floor in your building kept at standard. That is the program.',
                ),
                'included_title' => 'Floor Refinishing Services We Offer',
                'included'       => array(
                    'VCT strip and wax with high solids finish that stands up to traffic',
                    'Burnishing programs that keep gloss up between refinish cycles',
                    'Concrete cleaning, sealing, and polish maintenance',
                    'Stone floor honing and polishing for marble, terrazzo, and granite',
                    'Wood floor screen and recoat for offices and retail',
                    'Scheduled maintenance programs so floors never reach the emergency stage',
                ),
                'why'            => array(
                    'Night and weekend scheduling, so floors are done when you open',
                    'Finish systems matched to your traffic, not a one product fits all approach',
                    'Photo documented work, so you see the result even when we work at 2 AM',
                    'Maintenance plans priced flat per month, which makes budgeting simple',
                ),
                'steps'          => array(
                    'We assess every hard floor surface and its current condition',
                    'We recommend a restoration step where needed and a maintenance cycle to keep it',
                    'Crews execute on a night or weekend schedule that never interrupts business',
                    'We inspect with you on a set schedule and adjust the program as traffic changes',
                ),
                'faqs'           => array(
                    array( 'How often should VCT be stripped and waxed?', 'Most facilities need a full strip and wax once or twice a year, with scrub and recoat plus burnishing in between. Heavy traffic entrances may need more.' ),
                    array( 'Can you work without closing my business?', 'Yes. Almost all of this work happens overnight or on weekends, sectioned so the floor is ready before you open.' ),
                    array( 'Do you handle polished concrete?', 'Yes. We maintain polished concrete with cleaning, conditioning, and burnishing schedules that keep the gloss and protect the densifier.' ),
                    array( 'What does a maintenance program cost?', 'It depends on square footage and traffic, but it is a flat monthly number, quoted after a walk through. Programs cost less than emergency restorations every time.' ),
                ),
                'cta'            => 'Put your hard floors on a program instead of a rescue mission.',
            ),

            'flooring-contractor' => array(
                'headline'       => 'The Flooring Contractor the DMV Calls When Floors Have to Be Right',
                'intro'          => array(
                    '{business} is a full service flooring contractor serving {areas}. We install, restore, and maintain commercial and residential floors: carpet, hardwood, tile, VCT, and concrete, with one crew accountable for the result.',
                    'Property managers, facility directors, and homeowners call us because we show up when we said we would, the price matches the quote, and the floor looks right when we leave. Those three things sound basic. In this market, they are rare.',
                ),
                'included_title' => 'What We Do',
                'included'       => array(
                    'Carpet installation and carpet care for homes and commercial space',
                    'Hardwood refinishing, repairs, and maintenance recoats',
                    'Tile and grout deep cleaning, sealing, and color sealing',
                    'VCT strip and wax and hard floor maintenance programs',
                    'Janitorial programs that fold floor care into one contract',
                    'Emergency response for floods, stains, and tenant turnovers',
                ),
                'why'            => array(
                    'On time arrival, with a real window and a call ahead, not a four hour maybe',
                    'Quotes that hold. The number we give you is the number you pay',
                    'Background checked, uniformed crews that respect your building and your tenants',
                    'A workmanship warranty in writing on every installation',
                ),
                'steps'          => array(
                    'We walk the space with you and scope exactly what the floor needs',
                    'You get a written quote with the schedule attached, usually within 24 hours',
                    'The crew arrives on time, does the work, and protects everything around it',
                    'We walk the finished floor with you, and we come back if anything is not right',
                ),
                'faqs'           => array(
                    array( 'Do you handle both commercial and residential work?', 'Yes. Commercial floor care is our core, and the same crews and standards handle residential installs and restorations across the DMV.' ),
                    array( 'How fast can you quote a job?', 'Most quotes go out within 24 hours of the walk through, and same day for straightforward spaces.' ),
                    array( 'Are you licensed and insured?', 'Yes. Fully licensed and insured, with documentation sent over before work starts, which most property managers require anyway.' ),
                    array( 'What areas do you cover?', 'We serve {areas}. If you are near that footprint, call. We likely cover you.' ),
                ),
                'cta'            => 'Work with a flooring contractor who treats your floor like the contract it is.',
            ),

            'generic' => array(
                'headline'       => 'Professional {service} You Can Actually Rely On',
                'intro'          => array(
                    '{business} provides professional {service} for homes and businesses across {areas}. Trained technicians, commercial grade equipment, and products that are safe for your family, your staff, and your customers.',
                    'Every job starts with a real assessment of your space and ends with a walk through, so the result matches what you were promised. No surprises on the work and none on the invoice.',
                ),
                'included_title' => 'What Our {service} Includes',
                'included'       => array(
                    'A walk through and assessment before any work begins',
                    'A written quote that does not change after the job starts',
                    'Trained, background checked, uniformed technicians',
                    'Commercial grade equipment and safe, effective products',
                    'Careful protection of the surrounding space while we work',
                    'A final walk through with you before we call the job done',
                ),
                'why'            => array(
                    'We show up when we said we would, with a call ahead',
                    'The quote is the price, with no surprise add ons',
                    'Same day and next day scheduling available in most of the DMV',
                    'A satisfaction guarantee we put in writing',
                ),
                'steps'          => array(
                    'We assess your space and recommend the right approach',
                    'You approve a written quote with the schedule attached',
                    'Our crew completes the work and protects everything around it',
                    'We walk the result with you and make anything right that is not',
                ),
                'faqs'           => array(
                    array( 'Do you serve both homes and businesses?', 'Yes. We handle residential and commercial work across {areas}, from single rooms to full facilities.' ),
                    array( 'How quickly can you schedule?', 'Same day or next day service is available in most of our coverage area. Call {phone} to check availability.' ),
                    array( 'Is your work guaranteed?', 'Yes. If something is not right, tell us and we come back and fix it. That guarantee is in writing on every quote.' ),
                    array( 'How do I get a price?', 'Request a quote online or call {phone}. Simple jobs get priced same day; larger spaces get a walk through first so the number is real.' ),
                ),
                'cta'            => 'Get {service} from a team that does what it said it would do.',
            ),
        );
    }
}
