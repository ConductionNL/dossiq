# Tasks: migrate-public-share-to-shares-leaf

All tasks are in the `procest` repo. Estimates: S = half-day, M = 1–2 days, L = 3+ days.
Implementation runs through Hydra; this change is specs-only.

## [procest] Pre-migration Verification

### P0. Confirm shares leaf contract (S)

- [ ] P0.1 Confirm the OR shares leaf `id`, its public-access resolution route shape, and the
  pinned `@conduction/nextcloud-vue` version. Record in design.md DEFERRED_QUESTIONS.
- [ ] P0.2 DECIDE whether legacy `PublicShareController` tokens must keep resolving during a sunset
  window; open a GH issue if a legacy-compat read path is required.

## [procest] Wire the leaf

### P1. Shares leaf on the case (M)

- [ ] P1.1 Whitelist the shares leaf on the `case` schema `configuration.linkedTypes`.
- [ ] P1.2 Replace the token "Share link" path with the shares leaf tab/widget on the case detail page.
- [ ] P1.3 Verify create / list / revoke through the leaf.

## [procest] Remove in-app token path

### P2. Delete superseded code (M)

- [ ] P2.1 Remove the token-resolution path from `lib/Controller/PublicShareController.php`
  (or thin to a delegation to the leaf's public route).
- [ ] P2.2 Remove the token path from `CreateShareDialog.vue`; reduce/remove `ShareTab.vue`. Keep
  the "Partner organization" handover path if it is zaak-domain logic.

## [procest] Quality gates

### P3. Verify (S)

- [ ] P3.1 `openspec validate migrate-public-share-to-shares-leaf --strict` exits 0.
- [ ] P3.2 `composer check:strict` and `npm run lint` pass; route-auth gate clean for any remaining
  public route.

## REAL BLOCKER (re-spec 2026-06-15)

The boilerplate deferral note below ("target leaf not yet released") is STALE
and was a misdiagnosis. OR's `SharesProvider` **is** present and DI-registered
on openregister development — but it does **not** satisfy this migration:

> `SharesProvider` is **read / unlink-only over Nextcloud FILE shares**. It
> lists and revokes existing `\OCP\Share\IShare` file/folder shares attached to
> an object; it has **no `create()`**, and it has **no public CASE-TOKEN
> status-link surface** (the citizen-facing "track my case by token" link
> procest's `PublicStatusController` / `caseShare` provides).

This migration therefore CANNOT proceed against the leaf as it stands. The real
prerequisite is OR work being built separately:

1. A `create()` on the shares leaf (so the app can mint a share through the leaf
   instead of its own controller), AND
2. A **public case-token status-link** surface in OR (a tokenised, anonymous,
   read-only case-status view) — distinct from NC file shares.

Until that OR surface lands, procest's bespoke public-share / case-token
status-link stays the source of truth. NOT buildable today.

## Deferral block (final-77 sweep, 2026-06-11)

All open tasks above were converted from `[ ]` to `[~]` in one mechanical
pass. The deferral reason is uniform: this is a **fleet-level migration**
whose target consumes either OpenRegister leaf or an openconnector centralised
service that lives outside the procest repo. Per ADR-019 (integration leaves)
and ADR-022 (apps consume OR abstractions):

- The migration requires the target leaf to be released, versioned, and
  tested in the central library (e.g. `@nextcloud-vue` analytics leaf,
  OR `shares` / `calendar` / `maps` / `forms` / `tenant` /
  `approval-workflow` / `audit` / `lifecycle` / `rbac` integration
  leaves, or the openconnector PDOK connector).
- Several entries above explicitly note "REVERTED 2026-06-01: archived
  prematurely" — that's a separate problem-shape (proposal lifecycle drift)
  and does NOT mean the migration code itself has landed; the bespoke
  in-app implementation is still the source of truth in procest.
- Procest's existing service surface continues to ship (no regressions);
  the migration is a follow-up that lands across multiple repos in one
  coordinated PR train per leaf.

Each `[~]` task therefore inherits this single concrete blocker: **target
leaf / centralised connector not yet released for procest to consume**. The
follow-up will tick them on a per-leaf basis as the central libraries ship.
