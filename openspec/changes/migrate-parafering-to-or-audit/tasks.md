# Tasks: migrate-parafering-to-or-audit

> **Build status (hydra audit 2026-06-10).** No ParaferingAuditListener, validator, or paraferingAuditEntry schema exists per the spec's investigation block. Migration is gated on the OR audit listener + append-only validator landing. Tasks deferred.

All tasks are `[procest]`. Estimates: S = half-day, M = 1–2 days, L = 3+ days.

> **Scope adjustment (2026-05-11):** investigation found that procest does NOT
> have a `ParaferingAuditListener.php`, a `ParaferingAuditAppendOnlyValidator.php`,
> or a `paraferingAuditEntry` schema. The Listener directory contains only
> `DeepLinkRegistrationListener` and `KpiCacheInvalidationListener`, and
> `Application.php` has no audit-listener registrations.
>
> Parafering audit currently lives inside `lib/Service/ParaferingService.php` —
> actions append entries to an in-object `auditTrail` array on the voorstel.
> The spec's framing (separate listener + parallel schema + append-only validator)
> does not match the code. This PR records the umbrella rule going forward so
> any future parafering audit work routes through OR's `AuditTrailMapper`. The
> in-object `auditTrail` array migration is a follow-up — it needs concurrent
> updates to readers in views and a controller-side ObjectEntity resolution
> step that the current ParaferingService array-only API does not provide.

---

## [procest] Audit Listener Migration

### P-1. Inject AuditTrailMapper into ParaferingAuditListener (M)

- [~] P-1.1 Update the constructor of `lib/Listener/ParaferingAuditListener.php` to inject
  `OCA\OpenRegister\Db\AuditTrailMapper` (replacing `ObjectService` and `IAppConfig` for
  audit writes). The public DI class is `OCA\OpenRegister\Db\AuditTrailMapper`; the method
  to call is `createAuditTrailEntry(ObjectEntity $object, string $action, array $context = [])`.
  No OR-side changes are needed — the `$context` array is already supported and persisted in
  the `changed` JSON column.
  - **Acceptance:** Constructor updated; `composer check:strict` passes with no new errors.

### P-2. Rewrite ParaferingAuditListener body to emit OR audit events (M)

- [~] P-2.1 Implement the `handle()` method to:
  (a) extract the transition name from `ParafeerTransitionEvent`,
  (b) build action type `procest.parafering.{transitionName}`,
  (c) build `$context` array (`parafeerrouteId`, `paraffeerstapId`, `fromState`, `toState`,
      `actorUuid`, `comment`) — this is persisted in the `changed` JSON column,
  (d) call `AuditTrailMapper::createAuditTrailEntry($object, $actionType, $context)` where
      `$object` is the `ObjectEntity` for the parafeerroute.
  - **Acceptance:** All named transition types from the design.md table produce correctly
    namespaced action strings. PHPUnit unit test with mocked mapper confirms the call is made.

- [~] P-2.2 Remove the old `ObjectService::saveObject()` call and any `paraferingAuditEntry`
  config reads (`paraferingAuditEntry_register`, `paraferingAuditEntry_schema`) from
  `ParaferingAuditListener`.
  - **Acceptance:** No reference to `paraferingAuditEntry_register` or
    `paraferingAuditEntry_schema` remains in the listener file.

### P-3. Remove ParaferingAuditAppendOnlyValidator (S)

- [~] P-3.1 Delete `lib/Validator/ParaferingAuditAppendOnlyValidator.php`.
  - **Acceptance:** File does not exist in the repo after this task.

- [~] P-3.2 Remove the three event listener registrations for `ParaferingAuditAppendOnlyValidator`
  (`ObjectCreatingEvent`, `ObjectUpdatingEvent`, `ObjectDeletingEvent`) from
  `lib/AppInfo/Application.php`.
  - **Acceptance:** No reference to `ParaferingAuditAppendOnlyValidator` remains in
    `Application.php`; `composer check:strict` passes.

### P-4. Update lib/Settings/procest_register.json (S)

- [~] P-4.1 Add `"deprecated": true` and `"deprecationNote"` to the `paraferingAuditEntry`
  schema entry in `procest_register.json`. Do NOT remove the schema object — existing rows
  must remain readable.
  - **Acceptance:** `paraferingAuditEntry` schema has `"deprecated": true`; existing
    `paraferingAuditEntry` objects remain queryable via OR API.

### P-5. Tests (M)

- [~] P-5.1 Write a PHPUnit unit test for `ParaferingAuditListener::handle()`:
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

### P-6. Update openspec/specs/parafering-audit-trail/spec.md (S)

- [~] P-6.1 Update the existing `parafering-audit-trail` spec to reference the new consumer
  contract: add a note that the audit trail is discoverable via OR's audit-trail-immutable API
  (`GET /api/audit-trails?objectUuid={parafeerrouteId}`). The existing requirements for
  immutability, delegation audit, and export remain — only the discovery mechanism reference
  changes.
  - **Acceptance:** `parafering-audit-trail/spec.md` contains a reference to
    `parafering-audit-via-or` and mentions the OR audit-trail API endpoint.

### P-7. Document deprecation and sunset in CHANGELOG (S)

- [~] P-7.1 Add an entry to `CHANGELOG.md` (or equivalent) noting:
  - `paraferingAuditEntry` schema is deprecated as of this release.
  - New parafering transitions are audited via OR's audit-trail API.
  - Sunset: existing records remain readable for one major release;
    schema will be removed in the following major release.
  - **Acceptance:** CHANGELOG entry exists; it names the deprecated schema and the sunset policy.
