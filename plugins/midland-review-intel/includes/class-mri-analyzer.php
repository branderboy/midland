<?php
/**
 * Voice-of-customer analysis — pure PHP, no AI services required.
 *
 * Mines the market's own language: top praise/complaint phrases (the language
 * bank), complaint themes per competitor (the discontent map), and page
 * opportunities with the keywords to hand Smart SEO.
 *
 * @package Midland_Review_Intel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aggregate analysis over the stored reviews.
 */
class MRI_Analyzer {

	/**
	 * Complaint/praise theme buckets: theme => trigger words/phrases (matched
	 * case-insensitively against review text). Tuned for flooring & carpet.
	 *
	 * @var array
	 */
	const THEMES = array(
		'scheduling'    => array( 'no show', 'no-show', 'never showed', 'never came', 'rescheduled', 'reschedule', 'late', 'on time', 'on-time', 'waited', 'waiting', 'delay', 'took weeks', 'took months' ),
		'communication' => array( 'call back', 'called back', 'never called', 'no response', 'respond', 'returned my call', 'answer', 'ghosted', 'communication', 'kept me informed', 'unresponsive' ),
		'pricing'       => array( 'price', 'priced', 'pricing', 'quote', 'estimate', 'overcharged', 'expensive', 'affordable', 'reasonable', 'hidden fee', 'charged', 'cost', 'invoice', 'upcharge' ),
		'workmanship'   => array( 'quality', 'sloppy', 'redo', 'uneven', 'seams', 'gaps', 'bubbling', 'bubbles', 'crooked', 'damaged', 'scratched', 'beautiful job', 'great job', 'excellent work', 'craftsmanship', 'attention to detail' ),
		'cleanliness'   => array( 'mess', 'cleaned up', 'clean up', 'cleanup', 'debris', 'dust everywhere', 'spotless', 'left my home clean' ),
		'crew'          => array( 'rude', 'unprofessional', 'professional', 'courteous', 'polite', 'friendly', 'crew', 'installers', 'respectful', 'hard working', 'hardworking' ),
		'follow_up'     => array( 'warranty', 'follow up', 'follow-up', 'came back', 'never fixed', 'refused to', 'made it right', 'stand behind' ),
		'sales'         => array( 'salesman', 'sales rep', 'showroom', 'pushy', 'selection', 'samples', 'measure', 'consultation' ),
	);

	/**
	 * Human labels for themes.
	 *
	 * @var array
	 */
	const THEME_LABELS = array(
		'scheduling'    => 'Scheduling & punctuality',
		'communication' => 'Communication & callbacks',
		'pricing'       => 'Pricing & quotes',
		'workmanship'   => 'Workmanship & quality',
		'cleanliness'   => 'Cleanliness & site care',
		'crew'          => 'Crew professionalism',
		'follow_up'     => 'Warranty & follow-up',
		'sales'         => 'Sales & selection',
	);

	/**
	 * Stopwords excluded from the language bank.
	 *
	 * @var array
	 */
	const STOPWORDS = array(
		'the', 'and', 'for', 'was', 'were', 'with', 'that', 'this', 'they', 'them', 'their', 'have', 'had', 'has',
		'our', 'are', 'but', 'not', 'you', 'your', 'from', 'all', 'very', 'out', 'when', 'who', 'will', 'would',
		'his', 'her', 'she', 'him', 'did', 'done', 'been', 'also', 'than', 'then', 'there', 'what', 'which', 'about',
		'just', 'into', 'after', 'before', 'because', 'could', 'should', 'these', 'those', 'such', 'over', 'more',
		'can', 'get', 'got', 'one', 'two', 'were', 'its', 'it\'s', 'i\'m', 'we\'re', 'don\'t', 'didn\'t', 'a', 'i',
		'to', 'of', 'in', 'it', 'is', 'on', 'my', 'we', 'he', 'so', 'as', 'at', 'be', 'do', 'an', 'or', 'if', 'us', 'me',
	);

	/**
	 * Run the full analysis over all stored reviews.
	 *
	 * @return array { summary, language_bank, discontent_map, opportunities }
	 */
	public static function analyze() {
		$reviews = MRI_DB::get_reviews();

		$positive_text = array();
		$negative_text = array();
		$theme_counts  = array(); // company => theme => [pos, neg].
		$theme_quotes  = array(); // theme => array of { company, quote }.

		foreach ( $reviews as $review ) {
			$text     = (string) $review['review_text'];
			$rating   = (int) $review['rating'];
			$company  = (string) $review['company'];
			$negative = ( $rating > 0 && $rating <= 3 );

			if ( $negative ) {
				$negative_text[] = $text;
			} else {
				$positive_text[] = $text;
			}

			$lower = strtolower( $text );
			foreach ( self::THEMES as $theme => $triggers ) {
				$hit = false;
				foreach ( $triggers as $trigger ) {
					if ( false !== strpos( $lower, $trigger ) ) {
						$hit = true;
						break;
					}
				}
				if ( ! $hit ) {
					continue;
				}
				if ( ! isset( $theme_counts[ $company ][ $theme ] ) ) {
					$theme_counts[ $company ][ $theme ] = array( 0, 0 );
				}
				$theme_counts[ $company ][ $theme ][ $negative ? 1 : 0 ]++;

				if ( $negative && count( $theme_quotes[ $theme ] ?? array() ) < 6 ) {
					$theme_quotes[ $theme ][] = array(
						'company' => $company,
						'quote'   => self::snippet( $text, $triggers ),
					);
				}
			}
		}

		return array(
			'summary'        => MRI_DB::get_summary(),
			'language_bank'  => array(
				'positive' => self::top_phrases( $positive_text, 25 ),
				'negative' => self::top_phrases( $negative_text, 25 ),
			),
			'discontent_map' => $theme_counts,
			'opportunities'  => self::opportunities( $theme_counts, $theme_quotes ),
		);
	}

	/**
	 * Top 2–3 word phrases across a set of texts (the language bank).
	 *
	 * @param string[] $texts Review texts.
	 * @param int      $limit Max phrases.
	 * @return array phrase => count.
	 */
	public static function top_phrases( $texts, $limit = 25 ) {
		$counts = array();
		foreach ( $texts as $text ) {
			$clean = strtolower( preg_replace( '/[^a-z0-9\' ]+/i', ' ', $text ) );
			$words = preg_split( '/\s+/', $clean, -1, PREG_SPLIT_NO_EMPTY );
			$n     = count( $words );
			for ( $len = 2; $len <= 3; $len++ ) {
				for ( $i = 0; $i + $len <= $n; $i++ ) {
					$slice = array_slice( $words, $i, $len );
					// A phrase is only meaningful if its edges are content words.
					if ( in_array( $slice[0], self::STOPWORDS, true ) || in_array( end( $slice ), self::STOPWORDS, true ) ) {
						continue;
					}
					$phrase = implode( ' ', $slice );
					if ( strlen( $phrase ) < 6 ) {
						continue;
					}
					$counts[ $phrase ] = ( $counts[ $phrase ] ?? 0 ) + 1;
				}
			}
		}
		// Keep phrases said by more than one reviewer; drop 2-gram prefixes of
		// equally-frequent 3-grams to avoid near-duplicates.
		$counts = array_filter(
			$counts,
			static function ( $c ) {
				return $c >= 2;
			}
		);
		arsort( $counts );
		return array_slice( $counts, 0, $limit, true );
	}

	/**
	 * Build ranked opportunities from the discontent map: themes where
	 * competitors bleed, with target keywords and a suggested page.
	 *
	 * @param array $theme_counts company => theme => [pos, neg].
	 * @param array $theme_quotes theme => quotes.
	 * @return array[]
	 */
	private static function opportunities( $theme_counts, $theme_quotes ) {
		$totals = array(); // theme => [ negatives, companies => neg ].
		foreach ( $theme_counts as $company => $themes ) {
			$is_own = ( false !== stripos( $company, 'midland' ) );
			foreach ( $themes as $theme => $counts ) {
				if ( $is_own ) {
					continue; // Opportunities come from competitor pain only.
				}
				if ( ! isset( $totals[ $theme ] ) ) {
					$totals[ $theme ] = array(
						'negatives' => 0,
						'companies' => array(),
					);
				}
				$totals[ $theme ]['negatives']            += $counts[1];
				$totals[ $theme ]['companies'][ $company ] = ( $totals[ $theme ]['companies'][ $company ] ?? 0 ) + $counts[1];
			}
		}

		uasort(
			$totals,
			static function ( $a, $b ) {
				return $b['negatives'] <=> $a['negatives'];
			}
		);

		$angles = array(
			'scheduling'    => array(
				'page'     => 'On-Time Flooring Installation Guarantee',
				'keywords' => array( 'flooring contractor shows up on time', 'reliable flooring installer maryland', 'on time carpet installation dc' ),
				'pitch'    => 'Competitors are hammered for no-shows and delays — own "we show up when we say" with a written on-time guarantee.',
			),
			'communication' => array(
				'page'     => 'Flooring Quotes With Same-Day Callbacks',
				'keywords' => array( 'flooring company that calls back', 'flooring estimate same day response', 'responsive flooring contractor dmv' ),
				'pitch'    => 'The market\'s #1 sore spot is silence after the quote — promise and prove a callback SLA.',
			),
			'pricing'       => array(
				'page'     => 'Transparent Flooring Pricing — No Surprise Charges',
				'keywords' => array( 'flooring installation cost maryland', 'transparent flooring quote', 'no hidden fees carpet installation' ),
				'pitch'    => 'Quote-doubling and hidden charges fuel 1-star reviews — publish real price ranges and a no-surprise pledge.',
			),
			'workmanship'   => array(
				'page'     => 'Flawless Seams & Finish — Our Workmanship Standard',
				'keywords' => array( 'quality carpet installation maryland', 'carpet seams showing fix', 'professional hardwood refinishing dc' ),
				'pitch'    => 'Visible seams, gaps, and redo-jobs dominate quality complaints — lead with inspection checklists and close-up gallery proof.',
			),
			'cleanliness'   => array(
				'page'     => 'We Leave Your Home Cleaner Than We Found It',
				'keywords' => array( 'flooring installers clean up after', 'dust free floor refinishing', 'clean carpet installation service' ),
				'pitch'    => 'Mess left behind is a recurring complaint — make the cleanup protocol a headline feature.',
			),
			'crew'          => array(
				'page'     => 'Background-Checked, Courteous Installation Crews',
				'keywords' => array( 'professional flooring crew', 'trusted carpet installers maryland', 'courteous flooring installers' ),
				'pitch'    => 'Rude or unprofessional crews tank competitor ratings — humanize and vouch for yours.',
			),
			'follow_up'     => array(
				'page'     => 'Flooring Warranty We Actually Honor',
				'keywords' => array( 'flooring warranty maryland', 'flooring company stands behind work', 'carpet installation warranty claims' ),
				'pitch'    => 'Refused warranty fixes create the angriest reviews in the market — publish the warranty terms and the make-it-right stories.',
			),
			'sales'         => array(
				'page'     => 'Pressure-Free In-Home Flooring Consultations',
				'keywords' => array( 'flooring consultation no pressure', 'in home carpet samples maryland', 'flooring showroom dc' ),
				'pitch'    => 'Pushy sales experiences sour the funnel — sell the consultative, samples-to-your-door experience.',
			),
		);

		$out = array();
		foreach ( $totals as $theme => $data ) {
			if ( $data['negatives'] < 1 ) {
				continue;
			}
			arsort( $data['companies'] );
			$out[] = array(
				'theme'     => $theme,
				'label'     => self::THEME_LABELS[ $theme ] ?? $theme,
				'negatives' => $data['negatives'],
				'worst'     => array_slice( $data['companies'], 0, 3, true ),
				'quotes'    => $theme_quotes[ $theme ] ?? array(),
				'page'      => $angles[ $theme ]['page'] ?? '',
				'keywords'  => $angles[ $theme ]['keywords'] ?? array(),
				'pitch'     => $angles[ $theme ]['pitch'] ?? '',
			);
		}
		return $out;
	}

	/**
	 * Short quote snippet around the first matched trigger word.
	 *
	 * @param string $text     Full review text.
	 * @param array  $triggers Trigger words for the theme.
	 * @return string
	 */
	private static function snippet( $text, $triggers ) {
		$lower = strtolower( $text );
		$pos   = false;
		foreach ( $triggers as $trigger ) {
			$pos = strpos( $lower, $trigger );
			if ( false !== $pos ) {
				break;
			}
		}
		if ( false === $pos ) {
			$pos = 0;
		}
		$start   = max( 0, $pos - 60 );
		$snippet = substr( $text, $start, 160 );
		return ( $start > 0 ? '…' : '' ) . trim( $snippet ) . ( strlen( $text ) > $start + 160 ? '…' : '' );
	}
}
