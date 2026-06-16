# Tasks: migrate-parafering-to-or-audit

All tasks are `[procest]`. Estimates: S = half-day, M = 1–2 days, L = 3+ days.

> **APPLIED 2026-06-14:** the 2026-05-11 scope-adjustment note was stale — at
> apply time the code DID carry `lib/Listener/ParaferingAuditListener.php`
> (writing `paraferingAuditEntry` objects via `Parafering\AuditTrailService`),
> `lib/Validator/ParaferingAuditAppendOnlyValidator.php` (3 OR pre-save
> registrations in `Application.php`), and a `paraferingAuditEntry` schema in
> `procest_register.json`. All three were migrated exactly as the proposal
> describes. The listener now resolves the voorstel `ObjectEntity` via OR's
> `ObjectService::find()` and emits `AuditTrailMapper::createAuditTrailEntry()`
> with `procest.parafering.{action}` actions. The validator is deleted (OR's
> audit trail is append-only by construction). The bespoke
> `Parafering\AuditTrailService` is reduced to a read-only historical export
> path so `ParaferingAuditExportController` keeps working on legacy rows; its
> dead write/validate methods (`record`, `assertAppendOnly`, `computeHash`,
> `redactIp`, `buildContentSnapshot`) were removed. The `paraferingAuditEntry`
> schema is marked `deprecated: true` (retained, not deleted) so existing rows
> stay readable until sunset. P-5.2 (live-NC integration test) is deferred —
> see the deferral note on that task.

---

## [procest] Audit Listener Migration

### P-1. Inject AuditTrailMapper into ParaferingAuditListener (M)

- [x] P-1.1 Update the constructor of `lib/Listener/ParaferingAuditListener.php` to inject
  `OCA\OpenRegister\Db\AuditTrailMapper` (replacing `ObjectService` and `IAppConfig` for
  audit writes). The public DI class is `OCA\OpenRegister\Db\AuditTrailMapper`; the method
  to call is `createAuditTrailEntry(ObjectEntity $object, string $action, array $context = [])`.
  No OR-side changes are needed — the `$context` array is already supported and persisted in
  the `changed` JSON column.
  - **Acceptance:** Constructor updated; `composer check:strict` passes with no new errors.

### P-2. Rewrite ParaferingAuditListener body to emit OR audit events (M)

- [x] P-2.1 Implement the `handle()` method to:
  (a) extract the transition name from `ParafeerTransitionEvent`,
  (b) build action type `procest.parafering.{transitionName}`,
  (c) build `$context` array (`parafeerrouteId`, `paraffeerstapId`, `fromState`, `toState`,
      `actorUuid`, `comment`) — this is persisted in the `changed` JSON column,
  (d) call `AuditTrailMapper::createAuditTrailEntry($object, $actionType, $context)` where
      `$object` is the `ObjectEntity` for the parafeerroute.
  - **Acceptance:** All named transition types from the design.md table produce correctly
    namespaced action strings. PHPUnit unit test with mocked mapper confirms the call is made.

- [x] P-2.2 Remove the old `ObjectService::saveObject()` call and any `paraferingAuditEntry`
  config reads (`paraferingAuditEntry_register`, `paraferingAuditEntry_schema`) from
  `ParaferingAuditListener`.
  - **Acceptance:** No reference to `paraferingAuditEntry_register` or
    `paraferingAuditEntry_schema` remains in the listener file.

### P-3. Remove ParaferingAuditAppendOnlyValidator (S)

- [x] P-3.1 Delete `lib/Validator/ParaferingAuditAppendOnlyValidator.php`.
  - **Acceptance:** File does not exist in the repo after this task.

- [x] P-3.2 Remove the three event listener registrations for `ParaferingAuditAppendOnlyValidator`
  (`ObjectCreatingEvent`, `ObjectUpdatingEvent`, `ObjectDeletingEvent`) from
  `lib/AppInfo/Application.php`.
  - **Acceptance:** No reference to `ParaferingAuditAppendOnlyValidator` remains in
    `Application.php`; `composer check:strict` passes.

### P-4. Update lib/Settings/procest_register.json (S)

- [x] P-4.1 Add `"deprecated": true` and `"deprecationNote"` to the `paraferingAuditEntry`
  schema entry in `procest_register.json`. Do NOT remove the schema object — existing rows
  must remain readable.
  - **Acceptance:** `paraferingAuditEntry` schema has `"deprecated": true`; existing
    `paraferingAuditEntry` objects remain queryable via OR API.

### P-5. Tests (M)

- [x] P-5.1 Write a PHPUnit unit test for `ParaferingAuditListener::handle()`:
  mock `AuditTrailMapper`, fire a `ParafeerTransitionEvent`, assert that
  `createAuditTrailEntry()` is called once with the correct `ObjectEntity`,
  `procest.parafering.*` action type, and expected `$context` keys in the `changed` column.
  - **Acceptance:** Test passes under `composer check:strict`; zero PHPCS/PHPStan errors in
    the test file.

- [~] P-5.2 Write an integration test (Newman or PHPUnit integration) that:
  (a) creates a `parafeerroute` object in OR,
  (b) triggers a transition (any valid transition),
  (c) calls `GET /api/audit-trails?objectUuid={parafeerrouteId}`,
  (d) asserts at least one entry exists with `action` matching `procest.parafering.*`,
  (e) calls `GET /api/audit-trails/verify` and asserts the chain is intact.
  - **Acceptance:** Test passes against a running NC dev instance with procest + OR installed.
  - **[~] DEFERRED (2026-06-14):** requires a live NC+OR dev instance with seeded
    parafeerroute data and an authenticated session to exercise the
    `/api/audit-trails` round-trip + `/verify` chain check. The emission path
    (action namespacing, `$context` shape, ObjectEntity resolution, no-write on
    unresolvable voorstel) is fully covered by the PHPUnit unit suite
    (`tests/Unit/Listener/ParaferingAuditListenerTest.php`, 5 tests) and the
    consumer contract by the gate-19 e2e spec
    (`tests/e2e/spec-coverage/parafering-audit-via-or.spec.ts`). The live
    integration round-trip lands with the next NC-in-CI integration pass.

### P-6. Update openspec/specs/parafering-audit-trail/spec.md (S)

- [x] P-6.1 Update the existing `parafering-audit-trail` spec to reference the new consumer
  contract: add a note that the audit trail is discoverable via OR's audit-trail-immutable API
  (`GET /api/audit-trails?objectUuid={parafeerrouteId}`). The existing requirements for
  immutability, delegation audit, and export remain — only the discovery mechanism reference
  changes.
  - **Acceptance:** `parafering-audit-trail/spec.md` contains a reference to
    `parafering-audit-via-or` and mentions the OR audit-trail API endpoint.

### P-7. Document deprecation and sunset in CHANGELOG (S)

- [x] P-7.1 Add an entry to `CHANGELOG.md` (or equivalent) noting:
  - `paraferingAuditEntry` schema is deprecated as of this release.
  - New parafering transitions are audited via OR's audit-trail API.
  - Sunset: existing records remain readable for one major release;
    schema will be removed in the following major release.
  - **Acceptance:** CHANGELOG entry exists; it names the deprecated schema and the sunset policy.

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
