# Pelevo implementation status

Last audited: 2026-09-08  
Source of truth: `C:\Users\BELOVED\Downloads\PELEVO_LARAVEL_API_ADMIN_CODEX_MASTER (2).md`

## Status definitions

- **Complete**: the document requirement has schema, executable behavior, authorization, relevant UI where required, and meaningful passing tests.
- **Partial**: useful behavior exists, but one or more required routes, states, controls, integrations, operational safeguards, or tests remain.
- **Missing**: no meaningful implementation was found.
- **External gate**: code cannot prove completion without credentials, infrastructure, provider sandbox access, compliance review, or production-like load.

## Verified repository baseline

- Laravel application, Sanctum, Horizon, Inertia, React, TypeScript, Tailwind CSS 4, migrations, queues, and WAMP/MySQL local execution are present.
- Current application inventory includes the existing product APIs plus the Phase 0 identity lifecycle and administrator security routes added in this pass.
- Latest completed verification: 100 tests and 967 assertions passed; Pint and the production Vite build passed.
- `openapi.yaml` documents only a subset of the current route surface.
- Local MySQL proves local behavior only. The document's production database authority remains Supabase PostgreSQL.

## Phase status

### Phase 0 — Foundation: PARTIAL

Implemented:

- Laravel bootstrap, versioned `/api/v1`, standard API envelopes/request IDs, Sanctum tokens, device-bound refresh rotation, admin guard, TOTP MFA challenge, RBAC middleware, audit tables, versioned configuration, readiness route, Horizon installation, and a basic command-center page.
- Mobile OTP/password recovery, profile/password update, onboarding interests, optimistic-concurrency preferences, connected-account listing/removal, privacy export, delayed account anonymization, and accurate device logout revocation.
- Admin first-time MFA enrolment, encrypted TOTP secrets, one-use hashed recovery codes, tracked/revocable sessions, profile password changes, and notification preferences.
- Server-verified social identity boundary for Google/Apple, verified-email first sign-in, exclusive provider-subject ownership, account linking, and invitation creation/rotation/redemption controls.
- Non-enumerating administrator password recovery with expiring single-use hashed tokens, full session revocation, a dedicated five-minute MFA step-up flow, and immediate rejection of remotely revoked tracked sessions.
- Command Center now exposes real audience, listening, catalog/feed, creator/claim, moderation/live, finance, queue/upload, incident, and audit signals without placeholder navigation links.
- Phase 0 mobile identity routes are documented in OpenAPI and protected by automated route/method parity assertions. A CI workflow runs MySQL and Redis, migrations, Pint, the complete test suite, the production frontend build, and Composer's security audit.
- Structured request logging includes request ID, trace ID, route, status, duration, and actor type; request/trace IDs are returned in response headers. Scheduler and Horizon metric heartbeats are configured.
- Tests cover core token rotation/reuse, OTP attempt limits, device revoke, profile/privacy lifecycles, admin authentication/MFA enrolment/recovery/session tracking, configuration ETags, and permission denial.

Remaining or partial:

- Remaining mobile identity surface: export retrieval, deletion cancellation, and explicit email/phone verification policy.
- Remaining admin account surface: dedicated permission-denied and maintenance/degraded pages, recovery-code regeneration, idle-session activity updates, and maker-checker approval infrastructure.
- Admin MFA freshness is enforced, but dedicated step-up interaction and maker-checker approval are absent.
- Command Center core operational panels are implemented; historical trend charts, task ownership, external provider health, SLO burn rates, and production telemetry remain partial.
- Phase 0 identity OpenAPI parity is enforced, but the complete Phase 1–6 route inventory and schema/example validation remain incomplete.
- CI and structured JSON request telemetry are implemented. Sentry transport, production deployment proof, backup/restore proof, and production Supabase PostgreSQL/Redis/Horizon verification remain unproven.

### Phase 1 — Fast listening core: COMPLETE (local gate)

Implemented:

- Podcast Index client/authentication, safe RSS fetching and hydration, show search/detail/episodes, episode detail, chapters/transcript reads, playback progress, basic queue replacement, library, playlists, collections, saved episodes, signed download authorization, follows, ratings, notifications, and preferences.
- Display-ready personalized home rails, discovery, charts, editorial playlists, browse/categories, recent and voice search, related shows, public reviews, show notification controls, and followed-show listing.
- SSRF-guarded episode media proxy with valid byte-range forwarding, 206 response preservation, 416 handling, upstream failure handling, and a 16 MiB response ceiling.
- Versioned player session, episode notes, episode reactions, granular queue add/remove/reorder/clear, saved/liked/followed/recent library views, library/download settings, download listing, and listener statistics.
- RSS safety, malformed XML, playback, home isolation, discovery, browse, search, Range streaming, listener state, library, queue, episode content, and new-episode notification tests exist.
- Media proxying now uses a disk-backed streaming sink with fixed 64 KiB output chunks, validates every absolute or relative redirect destination, caps redirect depth, and has range-ignoring/AAC publisher compatibility coverage.
- Personalized home rails are durably materialized, refreshed on schedule, and invalidated after follow, playback, rating, review, editorial, and RSS publication changes. Public discovery caches are invalidated with the corresponding mutations.
- Phase 1 read-path indexes and a repeatable MySQL query-plan/warm-load gate are present; the local representative test executes 25 warm home requests and enforces p95 at or below 300 ms.
- Catalog operations now include a permission-protected, filterable/paginated show and RSS-health workspace, show/episode diagnostics APIs, audited live refresh, taxonomy and editorial management, search synonyms, and zero-result evidence.
- Secondary episode like/download aliases, note deletion, review editing/deletion, and cursor pagination controls are implemented.

External production evidence still required: CDN/provider end-to-end certification and the same p95 run against production-like PostgreSQL/Redis data and topology. Those do not block the verified local Phase 1 implementation gate.

### Phase 2 — Creators and claims: COMPLETE (local gate)

Implemented:

- RSS owner-email extraction/masking, queued expiring email codes, attempt limits, cache-bypassing description verification, manual review, first-winner unique ownership, duplicate disputes, expiry/live verification jobs, studios and member roles, immediate `/me` creator capability, and claim admin queue/detail/evidence/history.
- Focused email, RSS, expiry, ownership, dispute, studio, authorization, and admin tests exist.
- Claimants can privately inspect claim status and request a replacement email challenge; prior codes are consumed, new codes retain expiry/attempt limits, and neither API nor admin responses expose the owner address.
- Verified creators and role-bearing studio members receive scoped, cursor-paginated show, episode, and reel reads plus audience, monetization-readiness, and creator-owned transaction views. Outsiders receive no workspace access.
- Creator/studio administration now includes a filterable directory, Creator 360 evidence, studio membership counts, open disputes, governed dispute transitions, and complete claim evidence/audit views.
- Duplicate catalog merge provides conflict-aware preview, blocks verified-owner and episode-identity conflicts, requires typed confirmation, reason, catalog permission, and fresh MFA, then executes asynchronously and idempotently while preserving aggregates and recording canonical redirects/audits.
- The Phase 2 mobile surface is documented in OpenAPI and protected by route/method parity coverage.

Creator payout settings, tax/currency mutation, schedules, revenue eligibility, strikes, and creator-live authoring remain assigned to their document phases (3 and 5); they are not Phase 2 gate requirements. Production multi-process race certification remains deployment evidence, while database uniqueness, transactions, and replay tests enforce the local first-valid-claim invariant.

### Phase 3 — Social, reels, and moderation: COMPLETE (local gate)

Implemented:

- Comment CRUD, edit expiry, likes, one-pin behavior, creator hide, reports, show follow/rating, reel drafts, upload reservation/processing, FFmpeg probe/transcode/thumbnail lifecycle, publication/moderation states, qualified-view caps, following/trending feeds, episode links, live sessions/events/public status, moderation APIs/UI, and visibility invalidation tests.
- The narrow Phase 3 gate behavior is covered: valid processed reels publish, rejected/removed content is hidden, and moderation actions are audited.
- Explicit reel like/save/not-interested transitions, related-show lookup, creator following, configuration-versioned reel eligibility, and creator opt-in are implemented.
- Appeals, duplicate-report prevention, report decisions, evidence-preserving sanctions, strike/escalation actions, and sanction enforcement across publishing entry points are implemented with ownership isolation and audit references.
- Creator live aliases, verified-show authorization, health snapshots, public visibility rules, administrative live detail, takedown, events, and takedown history are implemented.
- Moderation operations expose reel/media probe/safety/engagement/report/timeline detail, full comment thread context, report detail/history, appeal decisions, sanctions, broken episode links, live health, and governed takedowns.
- The Phase 3 mobile surface is documented in OpenAPI and protected by route/method parity tests.

Production realtime transport, multi-worker cache/presence certification, real-device media failure injection, and browser automation remain deployment/acceptance evidence. Durable polling/read APIs remain the correctness fallback required by the document and the local Phase 3 gate is closed.

### Phase 4 — Financial core: COMPLETE (local gate)

Implemented:

- Double-entry transaction posting with balance checks and metadata-aware idempotency hashes, gift catalog/wallet/packs, IAP receipt records and server verification boundary, Earn eligibility snapshots/sessions/heartbeats/review/locks, encrypted payout methods, payout webhooks, ledger reversals, reconciliation runs/items, anomaly scoring, finance RBAC/MFA, finance desk UI, and focused replay/invariant tests.
- MySQL triggers prohibit ledger-entry updates and deletes. Corrections use linked, idempotent reversal transactions and all financial entries remain zero-sum.
- Gift fee decisions use immutable effective-dated basis-point versions attached to both the gift and ledger metadata. FX-rate versions provide immutable historical rate storage for payout conversion.
- Earn includes campaign/version snapshots, eligible-show and eligible-episode APIs, a database-enforced one-active-session invariant, expiring per-session nonces, and session/sequence/device/nonce-bound attestation requests.
- Withdrawal reservation and record creation are one database transaction with payload-bound replay detection. Provider dispatch attempts are recorded, provider failures reverse atomically, and governed state transitions require permissions, fresh MFA, reasons, and audit records.
- Provider settlement imports are replay-safe and payload-bound. Reconciliation creates actionable finance exceptions for drift, and administrators can run reconciliation and resolve exceptions with immutable audit history.
- The finance workspace now includes balance-sheet accounts, ledger/reversals, receipts, gift fees, Earn fraud review, payout queues, provider settlements, reconciliation, and open exceptions.
- Public financial writes default to disabled pending finance sign-off.

The configurable Apple/Google, device-attestation, bank, and PayPal boundaries validate provider identity, replay keys, application identity, revocation, quantities, and destination evidence. Real-provider credentials, sandbox certification, production concurrency/load runs, key rotation ceremonies, and finance/compliance approval remain external production evidence; the feature flags stay closed until those gates are signed off. Creator payout batches and Premium money flows remain Phase 5 scope.

### Phase 5 — Creator money and Premium: COMPLETE (local gate)

Implemented:

- Creator eligibility/opt-in, gift and qualified-reel-view revenue events, immutable fee/FX attachment, creator balances and transaction detail, encrypted tax data, currency/schedule/minimum settings, and administrator compliance decisions.
- Monthly idempotent creator payout batches, fixed historical FX, separate maker/checker approval, atomic ledger reservation, provider dispatch, governed failure reversal, payout states, audit history, and finance workspace coverage.
- Provider-initialized Paystack/Flutterwave Premium checkout with payload-bound idempotency. A checkout never grants access; only an authenticated, amount/currency-matched provider webhook creates or renews a subscription, invoice, and time-bounded entitlement.
- Premium plan, current entitlement/subscription, private invoice history, plan-change checkout, live-provider restore, exclusive episode access, cancellation, expiry, refunds/revocation, webhook replay protection, and exception quarantine are implemented.
- Premium remains an entitlement rather than a wallet. Public checkout remains disabled by default pending payment sign-off.
- Phase 5 mobile routes are documented and protected by automated OpenAPI route/method parity coverage. Focused webhook/replay/mismatch tests and the complete regression suite pass.

Real Paystack/Flutterwave sandbox credentials, provider certification, payout compliance review, and end-to-end settlement against provider exports remain external deployment evidence required before enabling real volume; these external controls do not block the verified local Phase 5 implementation gate.

### Phase 6 — Advanced platform: COMPLETE (local gate)

Implemented:

- Private device inventory, activity/trust state, expiring one-use pairing codes, account-scoped pairing/revocation, refresh-token revocation, and sign-out-all.
- Allow-listed versioned sync resources, durable cursors, conflict detection and account-isolated resolution, encrypted checksummed backups/restores, storage reporting, and offline-cache cleanup.
- Versioned share-card templates, owned card creation/list/detail, public deep-link tokens, scheduling fields, and analytics events.
- Daily listening statistics, streaks, achievements, affinity/content-feature foundations, materialized explainable recommendations, trending snapshots, experiments, editorial overrides, deterministic fallbacks, and scheduled refresh.
- Versioned referral programs, qualifying-listen evidence, feature switch, caps/fraud evidence, and balanced idempotent rewards into separate Earn wallets.
- Quiet hours, lock-screen privacy, versioned notification templates, audience-filtered scheduled broadcasts, deduplicated inbox delivery, delivery attempts/health, and operator evidence.
- Versioned AI prompts, quotas, provider processing, token/cost accounting, failure and safety-review states, owned job reads, operator controls, and a default-closed kill switch.
- Published CMS help/about, audited CMS mutations, SLA support tickets, feedback, scheduled-export foundations, and a permission-protected advanced-operations workspace.
- Existing durable live scheduling, health, public status, moderation takedown, and history complete mature-live correctness.
- Phase 6 routes are documented and protected by OpenAPI route/method parity tests; independent subsystem switches and durable operational evidence satisfy the local gate.

External deployment evidence remains for real push and AI providers, multi-node Redis coordination, deployed deep-link association files, production backup/restore drills, representative analytics performance, accessibility/browser/visual regression, and cost/security dashboard integration.

## Admin implementation status

Current Inertia pages:

- Dashboard
- Login
- MFA challenge
- Claims
- Moderation
- Finance
- Advanced operations

The document requires more than 140 pages/workspaces across 15 navigation groups. Most are missing. The current navigation contains placeholder `#` destinations for unimplemented groups, so the non-negotiable admin shell and “no dead controls” requirement are not complete.

Shared admin infrastructure still missing:

- Reusable `AdminShell`, searchable/collapsible permission-filtered navigation, sticky top bar, breadcrumbs, command palette, remembered navigation state, and typed component library.
- Server-driven tables with URL filters, pagination, sorting, saved views, columns, density, exports, and safe bulk actions.
- Universal loading, empty-filter, degraded, retry, forbidden, stale, mutation, validation, confirmation, and concurrent-edit states.
- Accessible confirmation/reason/step-up/approval components and immutable timeline/audit-diff components.
- Browser coverage for the ten required operator journeys.

## Cross-cutting gaps

- Many controllers use inline validation and query-builder business logic; the document's thin controllers, Form Requests, policies, application actions, events/listeners, and domain state/value objects are only partially followed.
- Several list endpoints return bounded arrays rather than the required cursor envelope and stable tie-break ordering.
- OpenAPI coverage is partial and has no automated route/contract parity check.
- No complete authorization matrix tests across every user, creator, admin role, and ownership boundary.
- No production-like concurrency, load, migration, backup/restore, chaos/failure-injection, accessibility, or real browser suite.
- No proof of queue workers, Redis locks, scheduler, mail, FFmpeg, external providers, and PostgreSQL operating together in a production-like environment.
- Privacy export and delayed anonymization now exist, but retention policy coverage, export download authorization/expiry cleanup, legal holds, and operator privacy workflows remain incomplete.

## Prioritized remaining implementation queue

1. **Finish the complete admin acceptance gate**: shared shell/design system, remaining page-level inventories and guided journeys, advanced table/state contracts, realtime invalidation, and browser/accessibility/visual testing.
2. **Production-readiness gate**: Supabase PostgreSQL, Redis/Horizon workers, scheduler, provider credentials/sandboxes, observability destination, backup drills, representative load/failure tests, security review, and finance/compliance approval.

## External gates requiring owner/infrastructure input

- Supabase PostgreSQL connection and production-like representative data.
- Redis/Horizon and scheduler deployment configuration.
- Apple, Google, Paystack, Flutterwave, PayPal, mail, CDN/storage, Podcast Index, notification, deep-link, and AI provider credentials/sandboxes.
- Finance and compliance decisions: fee/FX policy, payout KYC/AML rules, withdrawal/payout approval thresholds, tax handling, refunds/chargebacks, and production financial sign-off.
- Production SLO/load targets, deployment topology, observability destination, retention policy, and backup/restore environment.
