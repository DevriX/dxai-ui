<?php
/**
 * What bin/build-block-library.php makes of each section it takes: the entry's name and role, which words are kept and which are
 * written again with a token, what is removed, what the library repeats, and what the section needs from the site it goes on.
 *
 * `texts` maps the words of every block that has words (the text as the block shows it) to what they become:
 *   true                              kept as the team wrote them (the sentence says nothing about one company);
 *   'text with {{tokens}}'            written again (HTML of the block's own element);
 *   array( 'text' => …, 'url' => … )  a button: its words and where it goes;
 *   false                             the block is removed.
 * Tokens: {{company}} {{company_short}} {{phone}} {{phone_url}} {{email}} {{address}} {{region|default}} {{title|default}}
 * {{contact_url|default}} and, in a repeated item, {{row:title}} {{row:url}} {{row:text}}.
 *
 * @package DXAI_UI
 */

$leaf_blocks = array( 'core/heading', 'core/paragraph', 'amr/span', 'core/button', 'core/list-item', 'dxai-ui/text' );

$forbidden = array(
	'a company name'                          => '/\b(arcus|archer|aarcher)\b/i',
	'a domain'                                => '/arcusrestoration|archerrestorations|aarcherservices|wpengine|dxdemos/i',
	'a phone number'                          => '/\(?\d{3}\)?[\s.\-]\d{3}[\s.\-]\d{4}/',
	'an e-mail address'                       => '/[\w.\-]+@[\w\-]+\.[a-z]{2,}/i',
	'a street address'                        => '/\b\d{3,5}\s+[A-Z][\w.]*(?:\s+[A-Z][\w.]*){0,3}\s+(?:St|Street|Ave|Avenue|Rd|Road|Blvd|Dr|Drive|Ln|Lane|Way|Pointe|Pkwy|Hwy)\b/',
	'a place'                                 => '/\b(Tennessee|Alabama|Georgia|Atlanta|Acworth|Alpharetta|Buckhead|Canton|Cartersville|Dallas|Decatur|Johns Creek|Kennesaw|Marietta|Powder Springs|Murfreesboro|Madison|Chattanooga|Huntsville|Franklin|Smyrna|Mt\. Juliet|Cleveland|Nashville|Middle and East)\b|\b(TN|AL|GA)\b/',
	'a number of customers, reviews or claims' => '/\b\d[\d,]*\+|\b\d{2,3},\d{3}\b|\babout \d+ minutes\b|\b\d+ minutes\b|\b\d+ years\b/i',
	'a review widget'                         => '/trustindex|data-widget-id/i',
	'a tool of one company'                   => '/Matterport/i',
	'an address of a site'                    => '/https?:\/\//i',
	'a colour of one brand'                   => '/#8e0c19|#ffd7db|rgb\(166, ?38, ?63\)|cr-red-gradient|#f6f4f1|#f4f5fb|#e6ebf1|#ece9e5|#faf8f6/i',
);

// The arrows of the questions page: four groups of three, three, three and four questions.
$faq_page_icons = array();
foreach ( array( 1 => 3, 2 => 3, 3 => 3, 4 => 4 ) as $group => $items ) {
	for ( $item = 1; $item <= $items; $item++ ) {
		$faq_page_icons[ '0.0.0.' . $group . '.' . $item . '.0.1' ] = 'chevron';
	}
}

$specs = array(

	// --- The call to action: a line, a sentence and the call button. ---------------------------------------------------------------
	'cta-call'             => array(
		'from'    => array( 'arcus', 3 ),
		'role'    => 'cta',
		'label'   => 'Call to action: a line, a sentence and the call button',
		'colors'  => array( '#8e0c19' => array( 'bg' => 'primary-dark' ), '#ffd7db' => '#ffffffd9' ),
		'builder' => true,
		'needs'   => array( 'phone' ),
		'pages'   => array( 'service', 'location', 'about', 'testimonials', 'contact', 'faq', 'services', 'areas' ),
		'texts'   => array(
			'Call Arcus Restoration Today.'         => 'Call {{company}} Today.',
			'We work with all insurance companies.' => true,
			'Call Now: (615) 375-4143'              => array( 'text' => 'Call Now: {{phone}}', 'url' => '{{phone_url}}' ),
			'★★★★★'                                  => false,
			'Trusted by 350+ TN & AL property owners' => false,
		),
		'drop'    => array( '0.0.1.1' ),
	),

	'cta-banner'           => array(
		'from'    => array( 'archer', 11 ),
		'role'    => 'cta',
		'label'   => 'Call to action: a banner with the phone number',
		'colors'  => array( 'rgb(166, 38, 63)' => 'var(--wp--preset--color--primary-dark)' ),
		'drop_classes' => array( 'cr-red-gradient' ),
		'builder' => true,
		'needs'   => array( 'phone' ),
		'pages'   => array( 'service', 'location', 'about', 'testimonials', 'contact', 'faq', 'services', 'areas' ),
		'texts'   => array(
			"Damage won't wait. Neither do we."                       => true,
			'Talk to a real person 24/7 and get help on the way today.' => true,
			'770-697-0795'                                           => array( 'text' => '{{phone}}', 'url' => '{{phone_url}}' ),
		),
	),

	// --- The questions: an accordion the theme opens and closes (faq-question, faq-answer, faq-arrow). ------------------------------
	'faq-accordion'        => array(
		'from'    => array( 'arcus', 8 ),
		'role'    => 'faq',
		'label'   => 'Questions and answers: an accordion of six',
		'colors'  => array( '#f6f4f1' => array( 'bg' => 'secondary' ) ),
		'builder' => true,
		'needs'   => array(),
		'pages'   => array( 'service', 'location', 'about', 'faq', 'contact' ),
		'icons'   => array(
			'0.0.2.0.1' => 'chevron', '0.0.3.0.1' => 'chevron', '0.0.4.0.1' => 'chevron',
			'0.0.5.0.1' => 'chevron', '0.0.6.0.1' => 'chevron', '0.0.7.0.1' => 'chevron',
		),
		'texts'   => array(
			'Questions, Answered'                                    => true,
			'Restoration & Insurance FAQs'                           => true,
			'Do you work with my insurance company?'                 => true,
			'Yes. Arcus Restoration works with all insurance carriers and has handled more than 25,000 claims. We document the damage properly, communicate directly with your adjuster, and manage the paperwork, so you can focus on your family or business.' => 'Yes. {{company}} works with all insurance carriers. We document the damage properly, communicate directly with your adjuster, and manage the paperwork, so you can focus on your family or business.',
			'How fast can Arcus Restoration respond to an emergency?' => 'How fast can {{company}} respond to an emergency?',
			'We respond 24/7 and are typically on site in about 60 minutes across our Tennessee and Alabama service areas. Fast response stabilizes the damage and reduces the risk of mold and secondary loss.' => 'We respond 24/7 and have a crew on its way as soon as you call. Fast response stabilizes the damage and reduces the risk of mold and secondary loss.',
			'What will restoration cost me?'                         => true,
			'Most water, fire, and storm losses are covered by your policy. On a covered claim, you are typically responsible only for your deductible. We provide clear, documented estimates using Matterport 3D scans before any work begins.' => 'Most water, fire, and storm losses are covered by your policy. On a covered claim, you are typically responsible only for your deductible. We provide clear, documented estimates before any work begins.',
			'What areas do you serve?'                               => true,
			'Arcus Restoration serves Middle and East Tennessee and North Alabama from offices in Murfreesboro, Madison, Chattanooga, and Huntsville, including Franklin, Madison, Smyrna, Mt. Juliet, Cleveland, and Decatur. If you do not see your city, call us, and we will dispatch the nearest crew.' => '{{company}} serves {{region|the surrounding area}}. If you do not see your city, call us, and we will dispatch the nearest crew.',
			'Does Arcus Restoration offer both residential and commercial restoration services?' => 'Does {{company}} offer both residential and commercial restoration services?',
			'Yes. Arcus Restoration provides water, fire, storm, and reconstruction services for both residential and commercial properties across Tennessee and Alabama.' => 'Yes. {{company}} provides water, fire, storm, and reconstruction services for both residential and commercial properties.',
			'Do you handle both the mitigation and the rebuild?'     => true,
			'Yes. Arcus Restoration handles the full restoration process, from emergency mitigation and damage assessment through repairs and complete reconstruction.' => 'Yes. {{company}} handles the full restoration process, from emergency mitigation and damage assessment through repairs and complete reconstruction.',
		),
	),

	'faq-accordion-intro'  => array(
		'from'    => array( 'archer', 10 ),
		'role'    => 'faq',
		'label'   => 'Questions and answers: an accordion of seven, with a line of introduction',
		'builder' => true,
		'needs'   => array(),
		'pages'   => array( 'service', 'location', 'about', 'faq', 'contact' ),
		'icons'   => array(
			'0.0.3.0.1' => 'chevron', '0.0.4.0.1' => 'chevron', '0.0.5.0.1' => 'chevron', '0.0.6.0.1' => 'chevron',
			'0.0.7.0.1' => 'chevron', '0.0.8.0.1' => 'chevron', '0.0.9.0.1' => 'chevron',
		),
		'texts'   => array(
			'FAQ'                                                    => true,
			'Frequently Asked Questions'                             => true,
			'We know emergencies are stressful. Here are answers to the most common questions.' => true,
			'Are you available for emergency services on holidays?'  => true,
			'Yes. Archer Restoration Services provides 24/7 emergency response, including nights, weekends, and holidays. If you have water, fire, storm, or mold damage, our team is available anytime to respond quickly and begin mitigation.' => 'Yes. {{company}} provides 24/7 emergency response, including nights, weekends, and holidays. If you have water, fire, storm, or mold damage, our team is available anytime to respond quickly and begin mitigation.',
			'Will my insurance cover your bill?'                     => true,
			'In most cases, restoration services are covered by homeowners insurance when the damage is sudden and accidental. Coverage depends on your specific policy. We work directly with insurance adjusters and can help guide you through the claims process.' => true,
			"What if I can't afford to pay my insurance deductible right now?" => true,
			'Your deductible is required under your insurance policy. However, payment timing may vary depending on your carrier. In some cases, the deductible is subtracted from the total claim payout rather than paid upfront. We can help you review your options and coordinate with your adjuster.' => true,
			'What restoration services does Archer Restoration provide?' => 'What restoration services does {{company}} provide?',
			'Archer Restoration provides water damage restoration, fire and smoke damage restoration, mold remediation, sewage cleanup, asbestos cleanup, trauma and biohazard cleanup, fogging and air scrubbing, storm damage restoration, and reconstruction and repair services for properties throughout North Georgia.' => '{{company}} provides water damage restoration, fire and smoke damage restoration, mold remediation, sewage cleanup, storm damage restoration, and reconstruction and repair services for properties throughout {{region|the surrounding area}}.',
			'How quickly can Archer Restoration respond to an emergency?' => 'How quickly can {{company}} respond to an emergency?',
			'Archer Restoration is available 24/7 for property emergencies. Our goal is to schedule an emergency assessment within about 10 minutes, and in many cases technicians can arrive within about an hour to help stop further damage.' => '{{company}} is available 24/7 for property emergencies. Our goal is to schedule an emergency assessment as soon as you call, and in many cases technicians can arrive within about an hour to help stop further damage.',
			'Does Archer Restoration help with insurance claims?'    => 'Does {{company}} help with insurance claims?',
			'Yes. Archer Restoration can document the damage, provide carrier-ready scopes, photos, and other supporting documentation, and coordinate directly with your insurance company and adjuster to help keep your claim and restoration project moving.' => 'Yes. {{company}} can document the damage, provide carrier-ready scopes, photos, and other supporting documentation, and coordinate directly with your insurance company and adjuster to help keep your claim and restoration project moving.',
			'Does Archer Restoration provide commercial restoration services?' => 'Does {{company}} provide commercial restoration services?',
			'Yes. Archer Restoration provides complete commercial restoration services throughout North Georgia, from emergency mitigation and cleanup through reconstruction. The team works with properties including offices, retail spaces, healthcare facilities, restaurants, warehouses, multi-family properties, schools, churches, and other commercial buildings.' => 'Yes. {{company}} provides complete commercial restoration services, from emergency mitigation and cleanup through reconstruction. The team works with properties including offices, retail spaces, healthcare facilities, restaurants, warehouses, multi-family properties, schools, churches, and other commercial buildings.',
		),
	),

	// --- Where the company works: the places as links, and the way to reach it. --------------------------------------------------------
	'areas-places'         => array(
		'from'    => array( 'archer', 8 ),
		'role'    => 'areas',
		'label'   => 'Where we work: the places as links, and a card to call or ask for an inspection',
		'colors'  => array( '#f4f5fb' => array( 'bg' => 'secondary' ), '#e6ebf1' => '#e5e7eb' ),
		'builder' => true,
		// The places, and a way to reach the company to put in the card beside them (a phone to call, or a page to ask on).
		'needs'   => array( 'places', 'phone|contact_url' ),
		'pages'   => array( 'service', 'areas', 'location', 'about', 'contact' ),
		'links'   => array( '0.0.0.0.2.0.0' => '{{row:url}}' ),
		'attrs'   => array(
			'0.0.0.1.0.2.0' => array( 'dxaiIf' => 'phone' ),
			'0.0.0.1.0.2.1' => array( 'dxaiIf' => 'contact_url' ),
		),
		'icons'   => array( '0.0.0.0.2.0.0.0' => 'pin' ),
		'drop'    => array( '0.0.0.1.0.1' ),
		'repeat'  => array( '0.0.0.0.2' => array( 'name' => 'places', 'keep' => 1 ) ),
		'texts'   => array(
			'Where we work'                         => true,
			'Serving Acworth & Surrounding Areas'   => 'Areas We Serve',
			'Acworth'                               => '{{row:title}}',
			'Alpharetta' => true, 'Atlanta' => true, 'Buckhead' => true, 'Canton' => true, 'Cartersville' => true, 'Dallas' => true,
			'Decatur' => true, 'Johns Creek' => true, 'Kennesaw' => true, 'Marietta' => true, 'Powder Springs' => true,
			'Roswell' => true, 'Sandy Springs' => true, 'Woodstock' => true,
			'Contact Our Acworth Team'              => 'Contact Our Team',
			'3430 Novis Pointe Acworth, GA 30101'   => true,
			'770-697-0795'                          => true,
			'services@aarcherservices.com'          => true,
			'Office: Mon–Fri 8am–5pm · Emergency: 24/7 including holidays' => true,
			'Call Now 770-697-0795'                 => array( 'text' => 'Call Now {{phone}}', 'url' => '{{phone_url}}' ),
			'Request Free Inspection'               => array( 'text' => 'Request Free Inspection', 'url' => '{{contact_url}}' ),
		),
	),

	// --- How the work goes: five numbered steps. ----------------------------------------------------------------------------------
	'process-steps'        => array(
		'from'    => array( 'arcus', 4 ),
		'role'    => 'process',
		'label'   => 'The process: five numbered steps',
		'colors'  => array( '#ece9e5' => '#e5e7eb', '#faf8f6' => '#f9fafb' ),
		'builder' => true,
		'needs'   => array(),
		'pages'   => array( 'service', 'location', 'about' ),
		'texts'   => array(
			'How Our Restoration Process Works'                      => true,
			'Your Team, From Emergency Response to Final Repair'     => true,
			'As a full-service restoration company, the Arcus team will be there for you from the moment you call until you walk back through the front door.' => 'As a full-service restoration company, the {{company_short}} team will be there for you from the moment you call until you walk back through the front door.',
			'1' => true, '2' => true, '3' => true, '4' => true, '5' => true,
			'Form Your Restoration Team'                             => true,
			'One call connects you with your dedicated mitigation manager, project manager, and estimator.' => true,
			'Emergency Mitigation'                                   => true,
			'We stabilize the damage fast with water extraction, drying, board-up, and containment to stop further loss.' => true,
			'Inspect & Document'                                     => true,
			'A Matterport 3D scan and full inspection capture the complete scope, so nothing is missed in your estimate.' => 'A full inspection and detailed documentation capture the complete scope, so nothing is missed in your estimate.',
			'Plan & Approve'                                         => true,
			'We build a clear scope, coordinate directly with your insurance adjuster, and confirm the plan with you.' => true,
			'Rebuild & Restore'                                      => true,
			'Our reconstruction crews rebuild what was lost, then walk the site with you to ensure every detail is to your liking.' => true,
		),
	),

	// --- The questions page: four groups of questions, each with its own title. ----------------------------------------------------
	'faq-page'             => array(
		'from'       => array( 'arcus', 45 ),
		'role'       => 'faq',
		'label'      => 'The questions page: groups of questions about water, fire and storm damage, and about emergencies',
		'builder'    => true,
		'needs'      => array(),
		'pages'      => array( 'faq' ),
		// Fifteen answers of general sense: read whole (below), kept but for what the company's name and the claims of one company say.
		'texts_open' => true,
		// Some answers link the service page of the site they were written for: the words stay, the link does not.
		'unlink'     => true,
		'texts'      => array(
			'Arcus Restoration FAQs'                                       => '{{company}} FAQs',
			'Can water damage lead to mold growth if not treated quickly'  => 'Can water damage lead to mold growth if not treated quickly?',
			'Yes. Arcus Restoration provides 24/7 emergency response for water, fire, and storm damage across Tennessee and Alabama.' => 'Yes. {{company}} provides 24/7 emergency response for water, fire, and storm damage across {{region|the area we serve}}.',
			'Our crews are available 24/7 and are typically on site in about 60 minutes, depending on your location.' => 'Our crews are available 24/7 and arrive as quickly as they can, depending on your location.',
		),
		'replace'    => array( 'Arcus Restoration' => '{{company}}' ),
		'icons'      => $faq_page_icons,
	),
);
