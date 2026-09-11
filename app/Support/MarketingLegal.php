<?php

namespace App\Support;

final class MarketingLegal
{
    /**
     * @return list<array{heading: string, body: list<string>}>
     */
    public static function privacy(): array
    {
        return [
            ['heading' => 'Who we are', 'body' => [
                'Pelevo is a Nigeria-first podcast, reels, and creator platform. This policy explains how the Pelevo mobile applications and this website handle personal data. Pelevo is the business authority for accounts, wallets, claims, and entitlements. Money, Earn awards, withdrawals, gifts, and Premium status are decided on our servers, not on your device.',
            ]],
            ['heading' => 'Data we collect', 'body' => [
                'Account data such as name, email, phone number, authentication credentials, and verification status.',
                'Device and session metadata including a stable device identifier, app version, locale, and security events needed to refresh sessions and revoke compromised devices.',
                'Listening and library activity such as playback progress, queues, playlists, collections, downloads metadata, likes, follows, and search queries submitted to Pelevo.',
                'Creator and studio data including claim evidence, show membership, reel uploads, live sessions, and payout destination verification status.',
                'Financial records for gift wallets, Earn wallets, creator revenue, Premium entitlements, and withdrawal requests. We store the minimum receipt reference required to verify in-app purchases with Apple or Google. We do not store CVV, full raw bank secrets, PayPal passwords, or unmasked provider payloads in ordinary logs.',
                'Support messages, contact forms, reports, and consent records for Terms, Privacy, notifications, analytics, and AI processing where those controls are offered.',
            ]],
            ['heading' => 'How we use data', 'body' => [
                'To create and secure your account, restore sessions, and honour device revoke and sign-out-all requests.',
                'To personalise discovery, continue listening, recommendations, and feature availability through /me/config flags and regional policy.',
                'To operate playback, downloads, offline library use, comments, reels, live, gifts, Earn, withdrawals, Premium, and Creator Studio.',
                'To detect fraud, abuse, claim disputes, replayed receipts, and unsafe content, and to meet accounting and regulatory retention duties in Nigeria and other markets we serve.',
                'To send operational and preference-aware notifications. Quiet hours and lock-screen privacy rules follow the settings you choose in the app.',
            ]],
            ['heading' => 'Legal bases', 'body' => [
                'We process data to perform our contract with you, to meet legal obligations (including financial record keeping), and where required on legitimate interests such as security, integrity of Earn and claims, and product improvement. Where consent is required, we capture a versioned consent and let you change it in the app.',
            ]],
            ['heading' => 'Sharing', 'body' => [
                'We do not sell personal information.',
                'Infrastructure processors host media, send messages, and provide crash or analytics services under contract. Analytics and crash breadcrumbs are sanitised so search content, tokens, receipts, NUBAN, notes, and support messages are not treated as telemetry.',
                'Payment and store providers (for example Paystack, Flutterwave, Apple, and Google) receive only what is required to complete a purchase, payout, or entitlement refresh.',
                'Podcast discovery is performed by Pelevo servers. The mobile app never stores Podcast Index credentials and never talks to Podcast Index directly.',
                'We may disclose information if required by law, to protect users, or to resolve a verified creator claim or financial dispute.',
            ]],
            ['heading' => 'Retention, export, and deletion', 'body' => [
                'You may request a data export and an account deletion from the app where those flows are enabled. Deletion explains effects on downloads, creator shows, comments, wallets, pending payouts, and records we must retain for law or dispute resolution. We will not promise immediate erasure of legally retained financial records.',
                'Access and refresh tokens live in secure device storage. Logging out or revoking a device clears secrets on that device. Public downloads may remain until you choose to delete them, according to the in-app policy.',
            ]],
            ['heading' => 'Children and sensitive content', 'body' => [
                'Pelevo is not directed at children. Age and explicit-content controls are enforced as product and legal policy require. Report and block tools update your local feeds and then reconcile with the server.',
            ]],
            ['heading' => 'International users', 'body' => [
                'Pelevo is Nigeria-first and globally aware. We display currency using server-returned units and ISO codes, store timestamps in UTC, and render times in your timezone for live schedules, payout windows, and quiet hours.',
            ]],
            ['heading' => 'Contact', 'body' => [
                'Privacy questions can be sent through the Contact page. Include enough detail for us to locate your account without pasting passwords, OTP codes, or full payout credentials.',
            ]],
        ];
    }

    /**
     * @return list<array{heading: string, body: list<string>}>
     */
    public static function terms(): array
    {
        return [
            ['heading' => 'The service', 'body' => [
                'Pelevo provides podcast listening, short-form reels, creator tools, optional Premium features, gifts, and an Earn programme. Feature availability can change through server configuration without an app-store update. A disabled feature shows an informative state rather than failing silently.',
            ]],
            ['heading' => 'Accounts', 'body' => [
                'You must provide accurate registration details and keep verification current where required. You are responsible for devices signed into your account. Pelevo may revoke sessions when token reuse or other security events are detected.',
            ]],
            ['heading' => 'Listening and content', 'body' => [
                'Shows and episodes may originate from creators, licensed catalogues, or discovery partners. Availability can change when a feed moves, an episode is removed, or a region restriction applies. Do not scrape, redistribute, or reverse-engineer the service.',
            ]],
            ['heading' => 'Creators', 'body' => [
                'Publishing, monetisation, live, and payout tools require a verified claim in a claimed state. Claim evidence, eligibility, and studio membership are server-authoritative. You warrant that you have the right to claim and publish the shows and media you submit, and that reels respect duration and community rules (including the 60-second reel limit).',
            ]],
            ['heading' => 'Money', 'body' => [
                'Gift balances, Earn awards, withdrawals, creator revenue, and Premium entitlements are determined by Pelevo after server verification. Client displays are not proof of payment. Duplicate taps, receipt replay, and checkout return pages do not credit value. Earn is an online programme with session heartbeats, locks, and moderation holds. Withdrawals require verified payout destinations and remain subject to thresholds, reviews, and applicable tax or compliance checks.',
            ]],
            ['heading' => 'Acceptable use', 'body' => [
                'Do not harass others, upload unlawful or infringing media, manipulate Earn evidence, submit fraudulent claims or receipts, or attempt unauthorised access. We may hide, remove, or suspend accounts according to our moderation and sanctions process, with appeal paths where offered.',
            ]],
            ['heading' => 'Liability', 'body' => [
                'Pelevo is provided as available. Catalogue, discovery, payout rails, and third-party stores can be interrupted. To the extent permitted by applicable law, Pelevo is not liable for indirect or consequential loss. Nothing in these terms limits liability that cannot legally be limited.',
            ]],
            ['heading' => 'Changes', 'body' => [
                'We may update these terms. Material changes will be reflected on this page and, where required, through in-app consent. Continued use after the effective date constitutes acceptance.',
            ]],
        ];
    }
}
