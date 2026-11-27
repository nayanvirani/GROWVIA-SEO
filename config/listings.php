<?php

/*
| Trusted places where software buyers look. Listing here is a normal, accepted way to get
| relevant backlinks. Each needs a one-time sign-up by the owner; the app prepares every field.
| verify: whether the app can check the live listing automatically (some sites block bots).
*/
return [
    'shopify_app_store' => [
        'name' => 'Shopify App Store',
        'url' => 'https://www.shopify.com/partners',
        'priority' => 1, 'minutes' => 0, 'verify' => true,
        'why' => 'Where Shopify merchants find apps. If Growvia is already listed, just paste your listing link below.',
        'steps' => ['Log in to your Shopify Partner Dashboard.', 'Apps → your app → Distribution → App Store listing.', 'Use the tagline, description and features below.'],
    ],
    'g2' => [
        'name' => 'G2',
        'url' => 'https://sell.g2.com',
        'priority' => 4, 'minutes' => 15, 'verify' => false,
        'why' => 'The biggest software review site. Buyers compare tools here.',
        'steps' => [
            'Open sell.g2.com (the seller side) and choose the free profile option.',
            'If G2 instead asks "Select the products you use at work", that is the reviewer sign-up: click Continue to skip it, then go back to sell.g2.com.',
            'G2 requires verification: "Continue with LinkedIn" (any LinkedIn account, its email does not need to match) or an email on your own domain. Gmail alone is not accepted.',
            'No domain mailbox? Free forwarding (Cloudflare Email Routing or ImprovMX) can send hello@yourdomain to your Gmail.',
            'Add Growvia as a product and paste the fields below.',
            'Ask 2–3 happy customers for a review.',
        ],
    ],
    'capterra' => [
        'name' => 'Capterra, GetApp & Software Advice',
        'url' => 'https://www.g2.com/seller_login',
        'priority' => 5, 'minutes' => 15, 'verify' => false,
        'why' => 'One free listing appears on three big comparison sites run by Gartner.',
        'steps' => ['These sites are now managed from the same my.G2 seller account as G2: do G2 first, then add the listings from that dashboard.', 'Paste the fields below; choose the categories shown.'],
    ],
    'saashub' => [
        'name' => 'SaaSHub',
        'url' => 'https://www.saashub.com/submit',
        'priority' => 2, 'minutes' => 5, 'verify' => true,
        'why' => 'Software alternatives directory with a free listing and a link to your site.',
        'steps' => ['Open the link and sign in.', 'Submit your website URL and paste the fields below.'],
    ],
    'alternativeto' => [
        'name' => 'AlternativeTo',
        'url' => 'https://alternativeto.net',
        'priority' => 3, 'minutes' => 10, 'verify' => false,
        'why' => 'People search "alternatives to <competitor>". Being listed puts you next to the apps you compete with.',
        'steps' => ['Sign in, then use "Add application" from your profile menu.', 'Paste the fields below.', 'Mark the competitor apps as alternatives.'],
    ],
    'product_hunt' => [
        'name' => 'Product Hunt',
        'url' => 'https://www.producthunt.com',
        'priority' => 6, 'minutes' => 20, 'verify' => false,
        'why' => 'A launch page that links to your site and can bring early users. Best done on a weekday morning (US time).',
        'steps' => ['Sign in and click "Submit" (top right).', 'Use the tagline (60 characters max) and description below.', 'Add 3–5 screenshots.'],
    ],
    'crunchbase' => [
        'name' => 'Crunchbase',
        'url' => 'https://www.crunchbase.com',
        'priority' => 7, 'minutes' => 10, 'verify' => false,
        'why' => 'A trusted company profile that links to your website.',
        'steps' => ['Sign in (free) and search for your company.', 'If it is not there, choose "Add organization".', 'Paste the short description and website.'],
    ],
    'linkedin' => [
        'name' => 'LinkedIn company page',
        'url' => 'https://www.linkedin.com/company/setup/new/',
        'priority' => 8, 'minutes' => 10, 'verify' => false,
        'why' => 'Your official company profile; Google often shows it for brand searches.',
        'steps' => ['Open the link while logged in to LinkedIn.', 'Create the page and paste the tagline and description.'],
    ],
    'trustpilot' => [
        'name' => 'Trustpilot',
        'url' => 'https://business.trustpilot.com/signup',
        'priority' => 9, 'minutes' => 10, 'verify' => true,
        'why' => 'A free business profile that links to your site and collects reviews.',
        'steps' => ['Create a free business account with your domain email.', 'Claim your profile and paste the description below.'],
    ],
    'sourceforge' => [
        'name' => 'SourceForge (business software)',
        'url' => 'https://sourceforge.net',
        'priority' => 10, 'minutes' => 10, 'verify' => false,
        'why' => 'Large software directory with free business listings.',
        'steps' => ['Sign in, open "For Vendors" → "List your software".', 'Paste the fields below.'],
    ],
    'indie_hackers' => [
        'name' => 'Indie Hackers',
        'url' => 'https://www.indiehackers.com/products',
        'priority' => 11, 'minutes' => 5, 'verify' => true,
        'why' => 'Community product page for independent founders, with a link to your site.',
        'steps' => ['Sign in and add your product.', 'Paste the tagline and description below.'],
    ],
    'betalist' => [
        'name' => 'BetaList',
        'url' => 'https://betalist.com/submit',
        'priority' => 12, 'minutes' => 5, 'verify' => true,
        'why' => 'For new or recently launched products. Skip it if Growvia has been public for a long time.',
        'steps' => ['Open the link, sign in and submit your product with the fields below.'],
    ],
];
