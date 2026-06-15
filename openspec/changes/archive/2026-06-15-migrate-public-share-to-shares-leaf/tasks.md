# Tasks: migrate-public-share-to-shares-leaf

All tasks are in the `procest` repo. Estimates: S = half-day, M = 1–2 days, L = 3+ days.
Implemented through Hydra; the OR foundation (PR #151) is the prerequisite that unblocked it.

## [procest] Pre-migration Verification

### P0. Confirm shares leaf contract (S)

- [x] P0.1 Confirmed the OR shares leaf `id` (`shares`), its public-access resolution route shape
  (`GET /apps/openregister/api/public/case-tokens/{token}`, `#[PublicPage]`), and the mint/revoke
  surface (`SharesProvider::create({type:'public-token', label, ttlSeconds})` consumed via
  `POST /api/objects/{register}/{schema}/{id}/integrations/shares`; revoke via `delete(token:<id>)`).
  The leaf is a BACKEND HTTP surface (OR PR #151) — the pinned `@conduction/nextcloud-vue` (beta.108)
  does NOT yet ship a Vue client for it, so procest consumes the OR endpoints directly (in-process
  `CaseTokenService` for mint/revoke, the public endpoint for resolve), exactly as the consumer
  contract intends.
- [x] P0.2 DECISION: legacy `PublicShareController` tokens are DROPPED at cutover (no sunset window).
  The bespoke tokens were SHA-256 hashes in a `caseShare` object store with a different shape than
  the leaf's 256-bit handles; there is no faithful migration path and the citizen links are
  short-lived / re-issuable. No legacy-compat read path is required.

## [procest] Wire the leaf

### P1. Shares leaf on the case (M)

- [x] P1.1 Whitelisted the shares leaf on the `case` schema `configuration.linkedTypes`
  (`lib/Settings/procest_register.json`: added `"shares"`).
- [x] P1.2 Replaced the token "Share link" path: `CaseSharingService::createTokenShare()` now mints
  through OR's `CaseTokenService` (the shares-leaf case-token surface); the citizen public status
  page (`PublicStatusPage.vue`) resolves via the OR `#[PublicPage]` endpoint.
- [x] P1.3 Create / revoke flow through the leaf: `CaseSharingController::createShare` (token branch)
  → `createTokenShare()` → leaf `mint()`; `revokeShare` (token branch, caseId-scoped) →
  `tokenBelongsToCase()` + `revokeTokenShare()` → leaf `revoke()`.

## [procest] Remove in-app token path

### P2. Delete superseded code (M)

- [x] P2.1 Removed `lib/Controller/PublicShareController.php` entirely (bespoke token-resolution +
  password gate + comment/upload contribute surface) and its routes (`/api/public/share/*`,
  `/api/public/status/*`). The citizen status page now resolves via the OR `#[PublicPage]`
  case-token endpoint. Removed `CaseSharingService::validateToken()`, `generateToken()`,
  `getFilteredCaseData()`, the `DEFAULT_EXCLUDED_FIELDS` set, and the APCu brute-force cache — all
  superseded by the leaf + OR RBAC public-read field-masking.
- [x] P2.2 Reduced `CreateShareDialog.vue` to the "Partner organization" handover concern only (token
  tab + permission/expiry/password fields removed); reduced `ShareTab.vue` to partner-share listing.
  Partner-organisation handover + case transfer stay in-app (zaak-domain). Removed the bespoke
  `PublicCaseView.vue` (password/comment/contribute view) + its registry/manifest/customComponents
  entries.

## [procest] Quality gates

### P3. Verify (S)

- [x] P3.1 `openspec validate migrate-public-share-to-shares-leaf --strict` exits 0.
- [x] P3.2 `composer check:strict` / `npm run lint` clean for touched files; route-auth gate clean
  (no remaining bespoke public route — the only public surface is OR's audited `#[PublicPage]`).

## RESOLVED (built 2026-06-15)

The earlier "REAL BLOCKER" is now resolved. OpenRegister PR #151 landed the two prerequisites on
`openregister` development:

1. `SharesProvider::create()` with the `type:'public-token'` path (backed by `CaseTokenService::mint`)
   — the app mints a share through the leaf instead of its own controller, AND
2. A **public case-token status-link** surface: `CaseTokenService` (mint/resolve/revoke) +
   `CaseTokenController` exposing `GET /api/public/case-tokens/{token}` (`#[PublicPage]`,
   RBAC-respecting, uniform 404 on invalid/expired/revoked).

procest now consumes both. The bespoke public-share / case-token status-link is removed; the OR
audited surface is the single source of truth. NOTE: the foundation is BACKEND (HTTP routes); the
nc-vue lib does not yet ship a `CnSharesTab` that mints case-tokens, so procest calls the OR HTTP
endpoints directly (in-process service for the authenticated mint/revoke, the public endpoint for
anonymous resolve) — which is consumption per ADR-022, not re-implementation.
