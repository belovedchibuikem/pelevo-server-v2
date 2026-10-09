<?php

namespace App\Support;

final class MarketingLegal
{
    /**
     * @return list<array{heading: string, body: list<string|array{list: list<string>}|array{table: array{headers: list<string>, rows: list<list<string>}}>}>
     */
    public static function privacy(): array
    {
        return [
            ['heading' => '1. Who We Are', 'body' => [
                'This Privacy Policy explains how Pod Emeralds Limited ("Pelevo," "we," "us," or "our"), registered address Plot 109, Girls Mall Estate, Coal City Garden, Enugu State, Nigeria, collects, uses, shares, and protects personal data in connection with the Pelevo app (the "Service"). We are the data controller for the personal data described in this Policy, unless stated otherwise. Pod Emeralds Limited is committed to protecting the rights and privacy of individuals in accordance with the Data Protection Legislation. This Privacy Policy ("Policy") is designed to give you information on our privacy practices and the measures we take to protect the security of your data, and to help you understand your rights and choices when we collect or process your personal data.',
                'This Policy applies to all Pelevo services accessed by you.',
                'This Policy should be read alongside our [Terms and Conditions of Use](/terms).',
            ]],
            ['heading' => '2. What Personal Data We Collect', 'body' => [
                '2.1. Account data. Name, email address, password (stored in hashed form, never in plain text), and account preferences.',
                '2.2. Authentication data from third-party sign-in. If you register or log in using Google Sign-In, we collect your full legal name, profile picture, verified email address, and a unique Google user identifier token, as permitted by Google\'s access protocols. We are also in the process of deploying Apple Sign-In and Facebook Login as additional sign-in options; once available, choosing either will share your name, verified email address, and an authentication token with us in the same way. This data is used strictly to establish, authenticate, and secure your account — we do not access or retain your search history, contacts, friends lists, or calendar data from these providers. You may revoke Pelevo\'s access to your Google, Apple, or Facebook account at any time through that provider\'s own security settings, though doing so may affect your ability to log in automatically.',
                '2.3. Usage data. Podcasts you subscribe to, ratings you give, comments you post, your listening history (including how much of an episode you listened to), search queries, and category/podcast preferences you select during onboarding.',
                '2.4. User Content. Reels you upload, including the video file itself and any metadata associated with it (linked episode, upload date, view/engagement counts).',
                '2.5. Creator claim data. If you claim a podcast: the verification evidence used to confirm your claim (such as the email address matched against the podcast\'s RSS feed, or the description-code verification record).',
                '2.6. Financial and payout data. If you enable monetization as a creator, or if you are a US-based user who cashes out Earn rewards, we collect your payout details: for Nigerian bank payouts, your bank account number and the financial institution you select; for US cash-outs, the payout account details our payment processors require (for example, a PayPal account identifier) and any identity or tax information those processors or applicable law require. This data is:',
                ['list' => [
                    'transmitted via encrypted API to our payment processors — Paystack, Flutterwave, and PayPal — who resolve it against your registered name to verify payout accuracy;',
                    'stored in our database with AES-256 field-level encryption at rest, and masked within your account dashboard view;',
                    'never extended to your Bank Verification Number (BVN), National Identification Number (NIN), debit card PINs, or online banking passwords — we do not collect or store any of these.',
                ]],
                'Nigerian creators specifically are required to upload a digital image proving bank account ownership, to cross-reference your Pelevo registration against the banking details resolved by our payment processors, and to help prevent unauthorized payouts, financial fraud, and attempts to evade our age-eligibility requirements. This image is stored in a secure, non-public directory, accessible only to authorized compliance personnel, and is permanently deleted once verification is complete.',
                '2.7. Purchase data. Records of coin purchases made through the Apple App Store or Google Play Store. Pelevo does not receive or store your card number — purchases are handled entirely by Apple or Google, and we receive only a purchase confirmation/receipt.',
                '2.8. Location data. Your IP address is used to determine eligibility for the Earn program, which is currently restricted to users in the United States.',
                '2.9. Device and technical data. Device type, operating system version, app version, IP address, and a push-notification token (used to deliver notifications via Firebase Cloud Messaging).',
                '2.10. Advertising identifiers. Your device\'s advertising identifier (such as Android\'s GAID or iOS\'s IDFA), and general ad-interaction data (impressions, clicks), collected via Google AdMob and any additional ad networks used through its mediation, to display and measure ads within the Reels feed. On iOS, this is collected only where you have granted tracking permission via Apple\'s App Tracking Transparency prompt. Where you have not confirmed you are 18 or older, or where we cannot verify your age, ads shown to you are limited to non-personalized formats.',
                '2.11. Communications. Any correspondence you send us, including support requests and dispute notices under Section 16.2 of the Terms.',
            ]],
            ['heading' => '3. How and Why We Use Your Data', 'body' => [
                'We process your personal data for the following purposes, and on the following legal bases under the Data Protection Legislation:',
                ['table' => [
                    'headers' => ['Purpose', 'Legal basis'],
                    'rows' => [
                        ['Creating and maintaining your account, including via third-party sign-in', 'Performance of a contract (these Terms)'],
                        ['Providing podcast discovery, ratings, comments, and listening features', 'Performance of a contract'],
                        ['Processing coin purchases and gifts', 'Performance of a contract'],
                        ['Verifying podcast claims', 'Performance of a contract / legitimate interest (preventing fraudulent claims)'],
                        ['Processing creator payouts and US Earn cash-outs, including proof-of-ownership verification', 'Performance of a contract, and legal obligation (anti-money-laundering / KYC compliance under CBN regulations)'],
                        ['Determining Earn program eligibility', 'Performance of a contract'],
                        ['Sending push notifications about new episodes or replies', 'Consent (adjustable in-app)'],
                        ['Preventing fraud and abuse (e.g., duplicate accounts, bot activity, age-eligibility evasion)', 'Legitimate interest'],
                        ['Complying with legal obligations (tax, AML/CFT, regulatory requests)', 'Legal obligation'],
                        ['Improving the Service (aggregated, where possible de-identified analytics)', 'Legitimate interest'],
                    ],
                ]],
            ]],
            ['heading' => '4. Sharing Your Data', 'body' => [
                'We share personal data with the following categories of third parties, only as necessary for the purposes above:',
                '4.1. Service providers we use to operate Pelevo:',
                ['list' => [
                    'Podcast Index API — sources podcast discovery data (an inbound data source, not a recipient of your personal data).',
                    'Mux — hosts and processes video for Reels you upload.',
                    'Firebase Cloud Messaging (Google) — delivers push notifications, using your device\'s notification token.',
                    'Google, Apple, and Facebook — process authentication when you choose to sign in via those providers, as described in Section 2.2.',
                    'Branch.io — powers deep links when you share or receive a shared podcast, episode, or Reel link.',
                    'Google AdMob, and additional ad networks connected through its mediation — displays advertising within the Reels feed and processes the advertising identifier and ad-interaction data described in Section 2.10. We also use ad-interaction and Reels view data to calculate and pay creators a share of advertising revenue, as described in Section 10.3 of the Terms.',
                    'Flutterwave, Paystack, and PayPal — process creator payouts, as described in the Terms and Section 2.6.',
                    'Apple App Store / Google Play Store — process coin purchases; we receive purchase confirmations, not payment card details.',
                    'Low-code internal tooling (e.g., Retool) — used by our support team to review podcast claims submitted via the description-code method.',
                ]],
                '4.2. Legal and safety disclosures. We may disclose personal data where required by Nigerian law, in response to a valid legal request, or where necessary to protect the rights, property, or safety of Pelevo, our users, or the public.',
                '4.3. Business transfers. If Pod Emeralds Limited is involved in a merger, acquisition, or sale of assets, personal data may be transferred as part of that transaction, subject to this Policy continuing to apply or you being notified of any material change.',
                '4.4. We do not sell your personal data.',
            ]],
            ['heading' => '5. International Data Transfers and Cross-Border Hosting', 'body' => [
                'Pelevo\'s infrastructure is hosted on Laravel VPS (Laravel Forge\'s own first-party server product), located in Germany, outside the Federal Republic of Nigeria.',
                'Under Part VIII of the Data Protection Legislation and Schedule 5 of the GAID 2025, cross-border transfer of personal data out of Nigeria is prohibited by default unless a recognized legal basis applies: an adequacy decision issued by the Nigeria Data Protection Commission (NDPC) for the recipient country, an approved Cross-Border Data Transfer Instrument (CBDTI — Nigeria\'s equivalent of standard contractual clauses), or another lawful basis recognized under Schedule 5, including your explicit consent or necessity for performing our contract with you.',
                'As of the date of this Policy, the NDPC has not yet published a formal adequacy decision for Germany, the European Union, or the United States under the current Data Protection Legislation, as implemented by the GAID 2025, specifically. The country whitelist that existed under the prior NDPR 2019 regime does not automatically carry over, though it remains a reasonable benchmark, and EU/GDPR-governed jurisdictions such as Germany are widely expected to be favorably assessed given the strength of that regime once the NDPC issues updated guidance.',
                'Pending that formal recognition, we rely on your explicit consent — given by creating an account and using the Service, having been informed of this transfer through this Policy — as our operative legal basis for transferring your personal data to Germany (our hosting infrastructure) and to our other service providers located outside Nigeria (Section 4.1). Each of these providers processes your data only as strictly necessary to deliver the specific feature you use — for example, a payout provider cannot process your payout without receiving the relevant financial data, which is independently a recognized "necessary for contract performance" basis for that specific transfer.',
                'We intend to put in place formal Cross-Border Data Transfer Instruments with our principal service providers as an additional safeguard.',
            ]],
            ['heading' => '6. Data Retention', 'body' => [
                'We retain personal data according to the following principles:',
                ['list' => [
                    'Basic personal data (account details, usage data, listening history) is retained for two (2) to three (3) years from the date of your last activity or account closure.',
                    'Core financial and transaction records (including payout, purchase, and gift transaction history) are retained for up to seven (7) years, in line with our anti-money-laundering and corporate record-keeping obligations.',
                    'Where a statutory retention period applies under anti-money-laundering, tax, or corporate regulation, that obligation overrides a user\'s deletion request for the affected data until the retention period expires.',
                ]],
                'Proof-of-bank-ownership images (Section 2.6) remain the one exception to the above — these are permanently deleted immediately once verification is complete, not retained for the periods described here.',
                'If your account is terminated, your coin balance is forfeited as described in the Terms; underlying account and transaction records are still retained for the periods above even after termination.',
            ]],
            ['heading' => '7. Data Security', 'body' => [
                'We implement reasonable technical and organizational measures to protect personal data, including AES-256 field-level encryption of financial data at rest, hashed password storage, TLS 1.3 encryption of data in transit, restricted internal access to sensitive data (including payout and claim-verification records, and proof-of-ownership images restricted to authorized compliance personnel only), and secure transmission of data between the app and our servers. No system is completely secure, and we cannot guarantee absolute security.',
            ]],
            ['heading' => '8. Children\'s Data Privacy Policy', 'body' => [
                'Pelevo is available to users aged 13 and older for general discovery, listening, and social features, consistent with the Terms and Conditions. Purchasing coins and receiving creator payouts specifically require you to be 18 years of age or older, and cashing out Earn rewards (available to US users only) requires you to be at least 18, or the age of majority in your US state of residence if higher — we do not knowingly collect payment or payout-related financial data (Section 2.6) from anyone under 18.',
                'If you are a parent or legal guardian and discover that your minor child (under 18) has bypassed our age gates to make a purchase, input bank details, or set up a payout profile on our platform, please contact us immediately at privacy@podemeralds.com. Upon verification, we will permanently purge the affected financial data from our servers within twenty-four (24) hours.',
                'Pelevo is not directed to children under 13, and we do not knowingly collect personal data from anyone under 13. If you believe a child under 13 has created an account, contact privacy@podemeralds.com and we will promptly delete the account and its associated data.',
            ]],
            ['heading' => '9. Your Rights Under the Data Protection Legislation', 'body' => [
                'Subject to applicable law, you have the right to:',
                ['list' => [
                    'Access the personal data we hold about you;',
                    'Correct inaccurate or incomplete personal data;',
                    'Withdraw consent at any time, where processing is based on consent (for example, push notifications);',
                    'Request deletion of your personal data, subject to our legal retention obligations described in Section 6;',
                    'Object to or restrict certain processing, including processing based on legitimate interest;',
                    'Data portability, where technically feasible;',
                    'Lodge a complaint with the Nigeria Data Protection Commission [here](https://www.ndpc.gov.ng/), if you believe we have processed your data unlawfully.',
                ]],
                'To exercise any of these rights, contact us at the addresses in Section 13. We will respond within the timeframe required by the Data Protection Legislation.',
            ]],
            ['heading' => '10. Data Breach Notification', 'body' => [
                'In the event of a personal data breach, we will notify the Nigeria Data Protection Commission within 72 hours of becoming aware of the breach, and will notify affected users without undue delay where the breach is likely to adversely affect their rights and freedoms, in accordance with the Data Protection Legislation.',
            ]],
            ['heading' => '11. Cookies and Similar Technologies', 'body' => [
                'The Pelevo app uses device identifiers and similar technologies to enable core functionality (including those used by Branch.io for deep-link attribution and Firebase for push notifications) and, as described in Sections 2.10 and 4.1, for advertising within the Reels feed via Google AdMob and its mediated networks. On iOS, advertising identifiers are only accessed with your permission via Apple\'s App Tracking Transparency framework; you may decline this, in which case you will still see ads, but they will not be personalized to you. Users who have not confirmed they are 18 or older receive non-personalized ads only, regardless of platform.',
            ]],
            ['heading' => '12. Data Protection Contact', 'body' => [
                'For data protection queries, complaints, or to report a minor\'s account as described in Section 8, contact privacy@podemeralds.com.',
            ]],
            ['heading' => '13. Contact Us', 'body' => [
                'For privacy questions or to exercise your rights under Section 9: privacy@podemeralds.com. For general enquiries: info@podemeralds.com. For account or app support: support@podemeralds.com.',
                'You may also lodge a complaint directly with the Nigeria Data Protection Commission (NDPC) [here](https://www.ndpc.gov.ng/).',
            ]],
            ['heading' => '14. Changes to This Policy', 'body' => [
                'We may update this Privacy Policy from time to time. Material changes will be communicated through the app or by email to your registered address. Continued use of the Service after a change takes effect constitutes acceptance of the updated Policy.',
            ]],
        ];
    }

    public static function privacyCmsBody(): string
    {
        $chunks = ['Last updated: 30th September 2026'];
        foreach (self::privacy() as $section) {
            $chunks[] = '## '.$section['heading'];
            $chunks[] = self::privacySectionPlain($section['body']);
        }

        return implode("\n\n", $chunks);
    }

    public static function termsCmsBody(): string
    {
        $chunks = [
            'Last updated: 30th September 2026',
            ...self::termsIntro(),
        ];
        foreach (self::terms() as $section) {
            $chunks[] = '## '.$section['heading'];
            $chunks[] = self::privacySectionPlain($section['body']);
        }

        return implode("\n\n", $chunks);
    }

    /**
     * Opening paragraphs on the public Terms page, before the numbered sections.
     *
     * @return list<string>
     */
    private static function termsIntro(): array
    {
        return [
            'These Terms and Conditions ("Terms") govern your access to and use of the Pelevo mobile application and any related services (together, the "Service"), provided by Pod Emeralds Limited ("Pelevo," "we," "us," or "our"), a company incorporated under the laws of the Federal Republic of Nigeria, registered address Plot 109, Girls Mall Estate, Coal City Garden, Enugu State, Nigeria.',
            'By creating an account, downloading the app, or otherwise using the Service, you agree to be bound by these Terms and by our [Privacy Policy](/privacy) (available in the app and at pelevo.com), which is incorporated by reference. If you do not agree, do not use the Service.',
        ];
    }

    /**
     * @param  list<string|array<string, mixed>>  $body
     */
    private static function privacySectionPlain(array $body): string
    {
        $parts = [];
        foreach ($body as $block) {
            if (is_string($block)) {
                $parts[] = self::plainText($block);

                continue;
            }
            if (isset($block['list']) && is_array($block['list'])) {
                $parts[] = implode("\n", array_map(
                    fn (string $item): string => '• '.self::plainText($item),
                    $block['list'],
                ));

                continue;
            }
            if (isset($block['table']['headers'], $block['table']['rows']) && is_array($block['table']['rows'])) {
                $lines = [implode(' — ', $block['table']['headers'])];
                foreach ($block['table']['rows'] as $row) {
                    $lines[] = implode(' — ', $row);
                }
                $parts[] = implode("\n", $lines);
            }
        }

        return implode("\n\n", $parts);
    }

    private static function plainText(string $text): string
    {
        return (string) preg_replace_callback(
            '/\[([^\]]+)\]\(([^)]+)\)/',
            static function (array $match): string {
                if ($match[2] === '/privacy' || $match[2] === '/terms') {
                    return $match[0];
                }
                if (str_starts_with($match[2], 'http')) {
                    return $match[1].' ('.$match[2].')';
                }

                return $match[1];
            },
            $text,
        );
    }

    /**
     * @return list<array{heading: string, body: list<string|array{list: list<string>}>}>
     */
    public static function terms(): array
    {
        return [
            ['heading' => '1. Eligibility', 'body' => [
                '1.1. You must be at least 13 years old to create a Pelevo account.',
                '1.2. In-app purchases (coin packages) and creator monetization/payout features are restricted to users who confirm they are 18 years of age or older. Cashing out Earn rewards (Section 5) is restricted to users located in the United States who are at least 18 years old, or the age of majority in their US state of residence if that is higher. If you are between 13 and 17, you may use the general discovery and listening features of the Service, but you will be required to separately confirm you are 18+ before making a purchase or accessing creator payout features, and may not do so without a parent or legal guardian completing that step on your behalf where required by law.',
                '1.3. By using the Service, you represent that you have the legal capacity to enter into a binding agreement, and that your use of the Service does not violate any law applicable to you.',
            ]],
            ['heading' => '2. Your Account', 'body' => [
                '2.1. You must provide accurate information when creating an account and verify your email address. Certain features — including the signup coin bonus described in Section 4 — are conditioned on email verification specifically, not account creation alone.',
                '2.2. You are responsible for maintaining the confidentiality of your account credentials and for all activity under your account.',
                '2.3. You may not create more than one account for the purpose of obtaining bonus coins, Earn rewards, or any other promotional benefit more than once. We reserve the right to suspend accounts and reclaim coins obtained through duplicate accounts or fraudulent means.',
                '2.4. We reserve the right to suspend or terminate your account for violation of these Terms, at our reasonable discretion, as further described in Section 14.',
            ]],
            ['heading' => '3. Description of the Service', 'body' => [
                '3.1. Pelevo allows you to discover, subscribe to, rate, comment on, and listen to podcasts, using podcast data and RSS feeds sourced from third parties, including but not limited to the Podcast Index API and podcasts\' own publicly available RSS feeds.',
                '3.2. Pelevo does not create, own, or control the podcast content available for discovery and listening through the Service, except where explicitly identified as Pelevo\'s own content in the Earn program (Section 5). Podcast episodes, artwork, descriptions, and related content remain the property of their respective creators, hosts, and rights holders. Pelevo aggregates, indexes, and streams this content for discovery purposes and does not endorse, verify, or take responsibility for its accuracy, legality, or availability.',
                '3.3. Podcast content on Pelevo may become unavailable, be updated, or be removed at any time by its original publisher, outside of Pelevo\'s control. We make reasonable efforts to keep podcast information current but do not guarantee real-time accuracy.',
                '3.4. The Service also includes "Reels" — short-form video clips (1–3 minutes) uploaded by verified creators, which may be linked to a full podcast episode.',
            ]],
            ['heading' => '4. Coins and In-App Purchases', 'body' => [
                '4.1. "Coins" are a limited, non-transferable, virtual item held in one of two separate wallets within your account:',
                '(a) the Gifting Wallet, which holds Coins you purchase and any welcome bonus Coins, and which may be used only to send Gifts to creators. Coins in the Gifting Wallet have no cash value and cannot be cashed out, transferred, or converted; and',
                '(b) the Earnings Wallet, which holds Coins earned through the Earn program (Section 5) and which may be cashed out only by eligible users in accordance with Section 5.5. Coins in the Earnings Wallet cannot currently be used to send Gifts.',
                'Except as described for the Earnings Wallet, Coins have no monetary value outside the Service. Gifts received by creators are not held in either wallet; they are credited to the creator\'s monetization balance in Creator Studio, as described in Section 6.',
                '4.2. Coins may be obtained by: (a) purchasing coin packages through the Apple App Store or Google Play Store, subject to their respective terms of service and payment terms, which govern the purchase transaction itself (credited to the Gifting Wallet); (b) the one-time welcome bonus described in 4.3 (credited to the Gifting Wallet); or (c) participation in the Earn program described in Section 5 (credited to the Earnings Wallet).',
                '4.3. Launch welcome bonus. The first 500 users to sign up and verify their email address will receive 250 Coins, credited to the Gifting Wallet. Welcome bonus Coins cannot be cashed out. This bonus is limited to launch, offered at Pelevo\'s sole discretion, and will not be reinstated or extended without notice.',
                '4.4. Coins are non-refundable once purchased or credited, except as required by applicable law or by the terms of the Apple App Store or Google Play Store through which a purchase was made.',
                '4.5. Gifting. You may send Coins from your Gifting Wallet to a creator as a gift ("Gift"), subject to a minimum Gift amount set within the app. Gifts, once sent, are final and non-refundable, and are credited to the recipient creator\'s monetization balance in Creator Studio (Section 6). Gifting is a voluntary act of support and does not constitute a purchase of goods or services from the creator, and does not entitle you to any product, service, or ownership interest.',
                '4.6. We reserve the right to adjust coin pricing, bonus structures, gifting thresholds, and Earn eligibility at any time, with reasonable notice where required by law.',
            ]],
            ['heading' => '5. The Earn Program', 'body' => [
                '5.1. Pelevo operates a limited "Earn" section featuring podcasts owned and monetized by Pelevo itself (not third-party creator content), through which eligible users may earn Coins, credited to their Earnings Wallet, by listening.',
                '5.2. The Earn program is currently available only to users located in the United States, verified by IP-based geolocation. We reserve the right to modify Earn\'s geographic availability, reward structure, or discontinue the program at any time.',
                '5.3. Earn rewards are credited based on verified listening activity and are subject to reasonable anti-fraud measures. We reserve the right to withhold or reclaim Earn rewards obtained through fraudulent, automated, or bad-faith means (including but not limited to background/muted playback intended solely to accrue rewards).',
                '5.4. Earn rewards are not guaranteed and are subject to available promotional budget at any given time.',
                '5.5. Cash-out eligibility. Earnings Wallet balances may be cashed out only by users who (a) are located in the United States, (b) are at least 18 years old, or the age of majority in their US state of residence if that is higher, (c) have completed any identity, tax, or payout-verification steps required by us, our Payment Processors, or applicable law, and (d) meet any minimum cash-out threshold shown in the app. Cash-outs are processed through our Payment Processors and are subject to Sections 11 and 12. Taxes on cash-out amounts are your responsibility under Section 6.7.',
            ]],
            ['heading' => '6. Creator Terms', 'body' => [
                '6.1. Claiming a podcast. Any user may attempt to claim a podcast featured on Pelevo as its creator or authorized representative, through the verification methods made available in the app (currently: verification via the email address listed in the podcast\'s RSS feed, or a verification code added to the podcast\'s description and reviewed by our support team).',
                '6.2. By submitting a claim, you represent and warrant that you are the podcast\'s owner, an authorized representative of the owner, or otherwise have the legal right to claim and manage the podcast on Pelevo. Submitting a false or unauthorized claim is a violation of these Terms and may result in account suspension and, where applicable, referral to the appropriate authorities.',
                '6.3. A claim, once verified and approved, is permanent and will not be automatically revoked or re-validated based on later changes to the podcast\'s RSS feed (for example, a change in the feed\'s listed owner email). Disputes over an existing claim are handled through manual review by Pelevo\'s support team, whose decision is final at Pelevo\'s reasonable discretion, subject to Section 16 (Governing Law and Dispute Resolution).',
                '6.4. Creator Studio. Verified creators may access Creator Studio to view engagement with their podcast, respond to comments, upload and manage Reels, and manage monetization and payout settings.',
                '6.5. Reels monetization. Verified creators may enable monetization on their Reels and receive Gifts from users, as described in Section 4.5. Monetized Reels also earn a share of advertising revenue, as described in Section 10.3. Gifts you receive, and your advertising and Reels earnings, are shown in Creator Studio under the Monetization page.',
                '6.6. Payouts. Creators may request payout of their accumulated monetization balance shown in Creator Studio (Gifts received and earnings from monetized Reels) in United States dollars (USD) or Nigerian naira (NGN), using an eligible payout method provided through Flutterwave, Paystack, or PayPal, subject to each provider\'s own terms, supported currencies, and any minimum payout threshold set within the app. Before your first payout to a given bank account, we will confirm the account holder\'s name through the applicable payment provider\'s account-verification service; you are responsible for ensuring your payout details are accurate.',
                '6.7. Tax and legal compliance. The creator or user is solely responsible for reporting and paying any taxes owed on income earned through the Service, in your jurisdiction.',
                '6.8. We reserve the right to suspend a creator\'s monetization or payout access pending investigation of suspected fraud, policy violation, or a payout dispute.',
            ]],
            ['heading' => '7. User Content', 'body' => [
                '7.1. "User Content" means anything you submit to the Service, including ratings, comments, and Reels you upload.',
                '7.2. You retain ownership of your User Content. By submitting User Content, you grant Pelevo a worldwide, non-exclusive, royalty-free, sublicensable license to host, store, reproduce, display, and distribute it within the Service, for the purpose of operating and promoting the Service (for example, featuring a Reel in the app\'s Reels feed).',
                '7.3. You represent that you own or have the necessary rights to any User Content you submit, including any audio or video used in a Reel, and that it does not infringe any third party\'s intellectual property, publicity, or privacy rights.',
                '7.4. Reels specifically. If your Reel includes audio or clips from a podcast episode, you represent that you have the right to use that material — whether because you are the podcast\'s verified creator, or you otherwise hold the necessary rights or a valid license. Uploading a clip from someone else\'s podcast without authorization is a violation of these Terms and may constitute copyright infringement, for which you, not Pelevo, bear responsibility. If you believe a Reel infringes your rights, you may report it directly within the app; we will review reported content and take appropriate action, including removal, at our reasonable discretion.',
                '7.5. We reserve the right, but do not assume the obligation, to remove any User Content that violates these Terms or that we determine, in our reasonable discretion, to be harmful, unlawful, or otherwise inappropriate.',
            ]],
            ['heading' => '8. Prohibited Conduct', 'body' => [
                'You agree not to:',
                '8.1. Create multiple accounts to obtain bonus coins, Earn rewards, or any promotional benefit more than once;',
                '8.2. Use bots, scripts, emulators, or automated means to simulate listening activity, inflate engagement metrics, or otherwise defraud the Earn program, trending rankings, or any other feature of the Service;',
                '8.3. Submit a false claim to a podcast you do not own or represent;',
                '8.4. Upload Reels or other User Content that infringes another party\'s intellectual property rights, is unlawful, defamatory, obscene, or violates the rights of others;',
                '8.5. Harass, impersonate, or misrepresent your identity or affiliation with any person or entity;',
                '8.6. Attempt to reverse-engineer, decompile, or circumvent the security or functionality of the Service;',
                '8.7. Use the Service for any purpose that violates applicable law.',
                'Violation of this Section may result in suspension or termination of your account, forfeiture of coins obtained through the violation, and, where applicable, referral to law enforcement.',
            ]],
            ['heading' => '9. Intellectual Property', 'body' => [
                '9.1. The Pelevo name, logo, app design, and underlying software are the property of Pod Emeralds Limited and are protected by applicable intellectual property laws. Trademark registration for the Pelevo name and logo is currently in process; rights are asserted on a common-law basis in the interim.',
                '9.2. Except as expressly permitted by these Terms, you may not copy, modify, distribute, or create derivative works based on the Service, its software, or its branding.',
                '9.3. Podcast content indexed or streamed through the Service remains the intellectual property of its respective owners, as described in Section 3.2.',
            ]],
            ['heading' => '10. Third-Party Services (General)', 'body' => [
                '10.1. The Service integrates with third-party providers, including but not limited to the Podcast Index API (podcast discovery), Mux (video hosting for Reels), Firebase Cloud Messaging (notifications), Branch.io (link sharing), Google AdMob and its mediated ad networks (advertising), Google, Apple, and Facebook (optional sign-in), and the Apple App Store and Google Play Store (app distribution and in-app purchases).',
                '10.2. Your use of features involving these third parties is also subject to their own terms of service and privacy policies. Pelevo is not responsible for the availability, accuracy, security, or performance of these third-party services.',
                '10.3. Advertising and Reels Revenue Share. The Service displays advertising, including within the Reels feed, served by Google AdMob and other ad networks connected through its mediation. We are not responsible for the content of third-party advertisements or for any dealings you have with advertisers. How advertising identifiers are used is described in our Privacy Policy.',
                '(a) Eligible views. A view of your Reel counts toward advertising revenue only if it meets our validity criteria (currently: watched for at least 30 seconds, or 100% of the Reel\'s duration if shorter, with a maximum of one valid view per viewer per Reel per 24 hours). We may change these criteria at any time to maintain the integrity of the Service.',
                '(b) Revenue share. When the advertising SDK confirms that a Native Advanced advertisement in the Reels feed produced a valid impression, we allocate a share of the advertising revenue for that impression among the creators whose Reels received an eligible view since that user\'s previous advertisement, in proportion to the number of eligible views each creator contributed. Loading or requesting an advertisement does not qualify. If no confirmed impression occurs, no revenue is allocated for that viewing session. The rate we pay (currently $0.20 per 1,000 confirmed advertisement impressions, subject to change at our discretion with reasonable notice) is an amount we determine, and is not a representation of the actual amount any advertiser pays us.',
                '(c) Pending status, reconciliation hold, reserve, and currency. Amounts allocated under this Section originate and remain shown as pending in USD and are not available for payout until they clear a review and reconciliation period of thirty (30) days. During this period, we reconcile pending amounts against the revenue actually confirmed as paid to us by our advertising partners, and we reserve the right to reduce, withhold, or reverse any pending or already-credited amount that is later invalidated, disputed, or unpaid due to invalid traffic, fraud, advertiser chargebacks, or any other adjustment made by our advertising partners — including where this occurs after the 30-day period. At release, we currently retain five percent (5%) of the confirmed amount in a standing USD reserve for later adjustments. The remaining amount is released to the creator\'s selected USD balance or converted once into NGN using the approved exchange rate shown in the transaction record. Changing payout currency applies to future releases and does not automatically convert an existing available balance.',
                '(d) No guaranteed earnings. Advertising and Reels revenue share is not guaranteed, may be discontinued or modified at any time, and depends on factors outside our control, including advertiser demand and ad network performance.',
            ]],
            ['heading' => '11. Independent Third-Party Payment Processors', 'body' => [
                'The Platform utilizes independent, third-party payment gateways and processors, including but not limited to Paystack (Paystack Payments Limited), Flutterwave (Flutterwave Technology Solutions Limited), and PayPal Inc. (collectively, "Payment Processors"), to facilitate multi-currency payouts to Creators and to eligible Listeners (Section 5.5). You acknowledge and agree that:',
                '11.1. No Platform Control. The Platform does not operate, control, or maintain security protocols for these Payment Processors.',
                '11.2. Disclaimer of Processor Errors. The Platform shall not be liable for any transaction failures, delayed payouts, erroneous transfers, account suspensions, freezes, or fund security breaches caused directly or indirectly by the Payment Processors or their intermediary banking partners.',
                '11.3. Processor Terms Apply. Your receipt of payouts is strictly subject to the terms of service, processing fees, and compliance frameworks of the respective Payment Processor.',
            ]],
            ['heading' => '12. Cross-Border Transactions and Foreign Exchange Disclaimer', 'body' => [
                'For all cross-border or foreign currency (FX) denominated payouts, you explicitly acknowledge and agree that:',
                '12.1. Exchange Rate Volatility. For Reels advertising revenue released to an NGN balance, Pelevo converts the releasable USD amount once at release using the approved rate, fees or spread, and timestamp shown in Creator Studio. Other foreign-currency conversions may be performed by the relevant Payment Processor using its prevailing rate at processing. Exchange rates may change, and the Platform does not guarantee a future rate.',
                '12.2. Exclusion of FX Losses. The Platform disclaims all liability for financial shortfalls, discrepancies, or losses resulting from currency conversion, processing delays, or fluctuating exchange rates.',
                '12.3. Compliance Holds. In compliance with Central Bank of Nigeria (CBN) regulations and international Anti-Money Laundering (AML) / Counter-Terrorist Financing (CFT) laws, payouts may be flagged or withheld. The Platform incurs zero liability for payouts delayed or canceled due to a user\'s failure to complete mandatory identity verification (KYC).',
            ]],
            ['heading' => '13. Notifications', 'body' => [
                '13.1. By using the Service, you consent to receive push notifications regarding new episodes from podcasts you follow, replies to your comments, and other service-related communications. You may adjust notification preferences within the app or your device settings.',
            ]],
            ['heading' => '14. Suspension and Termination', 'body' => [
                '14.1. We may suspend or terminate your access to the Service, with or without notice, if we reasonably believe you have violated these Terms, engaged in fraudulent activity, or for any other reason at our discretion, including discontinuation of the Service itself.',
                '14.2. Coin balances upon termination. Upon termination of a user\'s account, that user\'s coin balance is withheld and forfeited as part of the termination.',
                '14.3. Upon termination, your right to use the Service ceases immediately. Sections that by their nature should survive termination (including but not limited to Sections 6.7, 7, 9, 11, 12, 15, and 16) will survive.',
            ]],
            ['heading' => '15. Disclaimers and Limitation of Liability', 'body' => [
                '15.1. THE SERVICE IS PROVIDED "AS IS" AND "AS AVAILABLE," WITHOUT WARRANTIES OF ANY KIND, WHETHER EXPRESS OR IMPLIED, INCLUDING WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, OR NON-INFRINGEMENT.',
                '15.2. WE DO NOT WARRANT THAT THE SERVICE WILL BE UNINTERRUPTED, ERROR-FREE, OR SECURE, OR THAT PODCAST CONTENT SOURCED FROM THIRD PARTIES WILL BE ACCURATE, CURRENT, OR AVAILABLE AT ALL TIMES.',
                '15.3. Exclusion of Consequential Damages. To the maximum extent permitted under applicable Nigerian law, the Platform, its affiliates, directors, or employees shall not be liable to you or any third party for any indirect, incidental, special, consequential, or exemplary damages. This includes, without limitation, damages for loss of profits, loss of sponsor or advertising revenue, goodwill, data loss, or reputational damage arising out of your use of the platform, even if advised of the possibility of such damages.',
                '15.4. Maximum Aggregate Liability Cap. Subject to Section 15.5 below, the total aggregate liability of the Platform to you for all claims arising out of or relating to this Agreement, whether in contract, tort (including negligence), or otherwise, is strictly capped as follows:',
                ['list' => [
                    'For Creators: the total net fees actually retained by the Platform from that specific Creator\'s account during the three (3) months immediately preceding the event giving rise to the liability, calculated in the currency of the disputed transaction.',
                    'For Listeners: the undisputed, verified cashable balance remaining in the Listener\'s Earnings Wallet at the exact time of the error or, for a Listener with no cashable balance, the total amount the Listener paid for Coin purchases during the twelve (12) months immediately preceding the event giving rise to the liability; in each case not exceeding ₦25,000 (Twenty-Five Thousand Naira) (or its equivalent in your local payout currency).',
                ]],
                '15.5. Legal Exceptions and Carve-Outs. Nothing in this Agreement shall limit or exclude the Platform\'s liability for fraud, fraudulent misrepresentation, willful misconduct, death or personal injury caused by gross negligence, or any other liability that cannot be lawfully limited or excluded under the Federal Competition and Consumer Protection Act (FCCPA) 2018 or other mandatory Nigerian statutes.',
            ]],
            ['heading' => '16. Governing Law and Dispute Resolution', 'body' => [
                '16.1. Governing Law. This Agreement, and any dispute, controversy, or claim arising out of or in connection with it (including non-contractual disputes or claims), shall be governed by, and construed in accordance with, the laws of the Federal Republic of Nigeria.',
                '16.2. Informal Internal Resolution First. Before initiating any formal legal or regulatory action, you agree to first notify the Platform of the dispute by emailing info@podemeralds.com or legal@podemeralds.com with a detailed description of your grievance and your requested remedy. The Platform and you shall attempt in good faith to resolve the dispute amicably through informal negotiations within thirty (30) business days from the date the email notice is received.',
                '16.3. Mediation. If the dispute cannot be resolved through informal negotiations within the 30-day period specified in Section 16.2, either party may refer the dispute to neutral mediation.',
                ['list' => [
                    'Forum: the mediation shall be conducted under the auspices of the Lagos Multi-Door Courthouse (LMDC) or any other independent dispute resolution center mutually agreed upon by both parties.',
                    'Virtual Proceedings: to minimize costs for both parties, the mediation shall be conducted virtually via secure video conferencing (e.g., Zoom, Microsoft Teams) unless otherwise required by the mediator.',
                    'Costs: each party shall bear its own legal fees, and the administrative costs of the mediation shall be shared equally, unless the mediator determines otherwise.',
                ]],
                '16.4. Jurisdiction and Statutory Consumer Carve-Outs. If mediation fails to resolve the dispute within forty-five (45) days of its commencement, the dispute may be escalated to formal adjudication subject to the following rules:',
                ['list' => [
                    'Commercial Claims: subject to the consumer protections below, both parties submit to the non-exclusive jurisdiction of the courts of the Federal Republic of Nigeria (with the preferred seat being Abuja FCT).',
                    'Consumer Protection Rights: in strict compliance with the Federal Competition and Consumer Protection Act (FCCPA) 2018, nothing in this Section shall prevent a consumer (Creator or Listener) from escalating a consumer-rights complaint directly to the Federal Competition and Consumer Protection Commission (FCCPC) or any other competent regulatory authority or small claims court in Nigeria.',
                ]],
            ]],
            ['heading' => '17. Changes to These Terms', 'body' => [
                '17.1. We may update these Terms from time to time. Material changes will be communicated through the app or by email to your registered address. Continued use of the Service after a change takes effect constitutes acceptance of the updated Terms.',
            ]],
            ['heading' => '18. Contact', 'body' => [
                'For general questions about these Terms: info@podemeralds.com. For account or app support: support@podemeralds.com. To raise a dispute under Section 16.2, or for other legal matters: legal@podemeralds.com. For privacy-related matters, see our [Privacy Policy](/privacy).',
            ]],
        ];
    }

    /**
     * Same Terms and Privacy copy as the public website, in the mobile CMS shape.
     *
     * Used when no published CMS row exists. A published CMS page still wins.
     *
     * @return array<string, mixed>
     */
    public static function apiPayload(string $slug): array
    {
        $terms = $slug === 'terms';
        $sections = $terms ? self::terms() : self::privacy();

        return [
            'id' => 'public-'.$slug,
            'slug' => $slug,
            'title' => $terms ? 'Terms and Conditions of Use' : 'Privacy Policy',
            'body' => '',
            'version' => 1,
            'published_at' => '2026-09-30T00:00:00Z',
            'updated_at' => '2026-09-30T00:00:00Z',
            'body_format' => 'plain_text',
            'intro' => $terms
                ? "Last updated: 30th September 2026\n\n".implode("\n\n", self::termsIntro())
                : 'Last updated: 30th September 2026',
            'sections' => array_map(
                static fn (array $section): array => [
                    'title' => $section['heading'],
                    'body' => self::privacySectionPlain($section['body']),
                ],
                $sections,
            ),
        ];
    }
}
