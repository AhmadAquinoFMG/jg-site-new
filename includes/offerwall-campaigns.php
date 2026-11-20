<?php
declare(strict_types=1);

/**
 * Decline offers mirrored from https://topdebtoptions.com/offerwall.php.
 *
 * No lead fields or other PII are appended to a partner URL.
 *
 * 'ef_offer_id' is the Everflow network_offer_id. When the visit carries a
 * numeric ?affid=, offerwall.php swaps cta_link for a tracking link generated
 * by Everflow for that offer + affiliate (everflow_tracking_link() in
 * includes/everflow.php). cta_link stays as the fallback for visits with no
 * affid, no EVERFLOW_API_KEY, or an Everflow API failure. Fallback links use
 * 2PLFG38, Everflow's code for affiliate 1071 (Organic Traffic), so those
 * clicks are still tracked and credited to organic.
 *
 * General decline offers appear in display order, with Lending For Bad Credit
 * first. Eligible student-loan offers appear immediately after that first offer.
 * Set 'enabled' to false to temporarily hide a general offer; omitted means enabled.
 */
return [
    /* Student-loan offers. Gated; see offerwall.php. */
    'student_campaigns' => [
        // [
        //     'id' => 'docupop',
        //     'name' => 'DocuPop',
        //     'logo' => 'assets/img/offers/Docupop-Logo_v02.png',
        //     'description' => 'Find out which federal student loan repayment programs you may qualify for.',
        //     'benefits' => [
        //         'Federal repayment and forgiveness program matching',
        //         'Paperwork prepared and filed for you',
        //         'Free eligibility review',
        //     ],
        //     'cta_text' => 'Check My Options',
        //     // TODO: replace with the live DocuPop tracking link before launch.
        //     // Left as the bare site so a premature deploy sends the consumer
        //     // somewhere real and simply earns nothing, rather than 404ing them.
        //     'cta_link' => 'https://www.docupop.com/',
        // ],
    ],

    'campaigns' => [
        [
            'id' => 'lending_for_bad_credit',
            'name' => 'Lending For Bad Credit',
            'logo' => 'assets/img/offers/lending-for-bad-credit.webp',
            'description' => 'Personal loans from $100 to $40,000 for all credit types.',
            'benefits' => ['Bad credit? You may still qualify', 'Quick online request', 'Funds as soon as the next business day'],
            'cta_text' => 'See If I Qualify',
            'cta_link' => 'https://www.f0cg2trk.com/2PLFG38/5Q9RM9/',
            'ef_offer_id' => 96,    // Lending For Bad Credit - Top Debt Decline
        ],
        // [
        //     'id' => 'lendingtree',
        //     'name' => 'LendingTree',
        //     'logo' => 'assets/img/offers/lendingtree.png',
        //     'description' => 'Shop and compare personal loan options that work best for you.',
        //     'benefits' => ['Check rates without impacting your credit score', 'See personalized offers in minutes', 'Loans from $1,000 to $50,000'],
        //     'cta_text' => 'Get Offers',
        //     'cta_link' => 'https://www.f0cg2trk.com/2PLFG38/2S8WFZR/',
        //     'ef_offer_id' => 1034,  // LendingTree
        // ],
        [
            'id' => 'upstart',
            'enabled' => false, // Temporarily hidden; set true to show again.
            'name' => 'Upstart',
            'logo' => 'assets/img/offers/upstart.webp',
            'description' => 'Most borrowers are approved instantly.',
            'benefits' => ['Verify your details in minutes', 'Next-day funding', "Won't affect your credit score"],
            'cta_text' => 'Check My Rate',
            'cta_link' => 'https://www.f0cg2trk.com/2PLFG38/DMBKXN/',
            'ef_offer_id' => 235,   // Upstart - Top Debt Decline
        ],
        [
            'id' => 'five_k_funds',
            'name' => '5K Funds',
            'logo' => 'assets/img/offers/five-k-funds.webp',
            'description' => 'Get connected to a network of lenders for quick personal loan options.',
            'benefits' => ['No impact on credit score', 'Loans up to $35,000', 'Next-day funding available'],
            'cta_text' => 'See If You Qualify',
            'cta_link' => 'https://www.f0cg2trk.com/2PLFG38/5T3K4H/',
            'ef_offer_id' => 98,    // 5k Funds - EPCVIP - Top Debt Decline
        ],
        [
            'id' => 'usa_grants',
            'name' => 'USA Grants',
            'logo' => 'assets/img/offers/usa-grants.webp',
            'description' => 'Explore grant money you may be eligible to claim.',
            'benefits' => ['See if you qualify for grant money', 'Free to check eligibility', 'Billions in grants available'],
            'cta_text' => 'View Options',
            'cta_link' => 'https://www.f0cg2trk.com/2PLFG38/7XDN21/',
            'ef_offer_id' => 140,   // USA Grants - Top Debt Decline
            'sponsored' => true,
        ],
        [
            'id' => 'usa_assistance_guide',
            'name' => 'USA Assistance Guide',
            'logo' => 'assets/img/offers/usa-assistance-guide.webp',
            'description' => 'Find cash assistance programs available in your area.',
            'benefits' => ['Financial assistance for everyday expenses', 'Free to check what you qualify for', 'Funds may be available quickly'],
            'cta_text' => 'View Options',
            'cta_link' => 'https://www.f0cg2trk.com/2PLFG38/7NG8BZ/',
            'ef_offer_id' => 135,   // USA Assistance Guide
            'sponsored' => true,
        ],
        [
            'id' => 'cost_relief',
            'name' => 'Cost Relief',
            'logo' => 'assets/img/offers/Cost-Relief-Logo.png',
            'description' => "Millions are overpaying simply because they don't know what's available. See instantly if you qualify for cost relief programs.",
            'benefits' => ['You could be missing out on real savings', "Takes less than a minute — don't wait", 'See exactly what you qualify for now'],
            'cta_text' => 'Claim Relief Now',
            'cta_link' => 'https://www.f0cg2trk.com/2PLFG38/2S7HK86/',
            'ef_offer_id' => 1033,  // Cost Relief - Everyday Resources (10819)
            'sponsored' => true,
        ],
        [
            'id' => 'inflation_relief',
            'name' => 'Inflation Relief',
            'logo' => 'assets/img/offers/inflation-relief.svg',
            'description' => 'Inflation has made everyday expenses harder to keep up with. This quick check helps you find relief programs you may qualify for.',
            'benefits' => ['Check your eligibility in under a minute', 'Find programs that help offset rising costs', 'No cost or obligation to check'],
            'cta_text' => 'Check My Eligibility',
            'cta_link' => 'https://www.f0cg2trk.com/2PLFG38/2RX68SJ/',
            'ef_offer_id' => 1027,  // Inflation Relief - Everyday Resources (9259)
            'sponsored' => true,
        ],
        /* Billable action (CPA): SOI lead submit, i.e. the email on the first
           page of the partner's form. */
        [
            'id' => 'unclaimed_asset_match',
            'name' => 'Unclaimed Asset Match',
            'logo' => 'assets/img/offers/unclaimed-asset-match.png',
            'description' => 'States are holding billions in unclaimed money — forgotten paychecks, refunds, deposits and more. See if any of it is yours.',
            'benefits' => ['Free to search', 'Takes less than a minute', 'Old paychecks, refunds, deposits and more'],
            'cta_text' => 'Search For My Money',
            'cta_link' => 'https://www.f0cg2trk.com/2PLFG38/2SGFZX7/',
            'ef_offer_id' => 1038,  // Unclaimed Asset Match - CPA
            'sponsored' => true,
        ],
    ],
];
