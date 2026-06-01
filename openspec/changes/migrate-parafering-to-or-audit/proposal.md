# Proposal: migrate-parafering-to-or-audit

> REVERTED 2026-06-01: archived prematurely; implementation not present on development — re-opened for real apply. (`ParaferingAuditListener` never injects `OCA\OpenRegister\Db\AuditTrailMapper`; `ParaferingAuditAppendOnlyValidator` still exists; no OR audit delegation in place.)

## Why

ADR-022 (Apps Consume OpenRegister Abstractions) explicitly prohibits "home-grown audit
trails — an app writing to a private events table instead of OR's audit trail for actions on
OR-owned objects."

Procest currently violates this rule:

- `lib/Listener/ParaferingAuditListener.php` listens to `ParafeerTransitionEvent` and writes
  records to a `paraferingAuditEntry` schema in OR, creating a parallel audit store instead
  of using OR's built-in audit trail.
- `lib/Validator/ParaferingAuditAppendOnlyValidator.php` registers on
  `ObjectCreatingEvent` / `ObjectUpdatingEvent` / `ObjectDeletingEvent` for
  `paraferingAuditEntry` objects, re-implementing immutability guards that OR's hash-chained
  audit trail already provides natively.
- `lib/AppInfo/Application.php` wires both listeners (lines 127–147).
- `lib/Settings/procest_register.json` defines the `paraferingAuditEntry` schema.

The umbrella spec `consume-or-audit-trail-fleet-wide` (hydra) mandates per-app migration specs
for each violating app within 90 days of umbrella acceptance.

## What

Replace the parallel audit pipeline with direct OR audit-trail emissions:

1. Rewrite `ParaferingAuditListener` to inject `OCA\OpenRegister\Db\AuditTrailMapper`
   and emit OR audit events with namespaced action type
   `procest.parafering.{transitionName}` and domain context in the `$context` payload
   (persisted in the `changed` JSON column).
2. Remove `ParaferingAuditAppendOnlyValidator` (OR audit trail is append-only by construction).
3. Remove the validator's event listener registrations from `Application.php`.
4. Mark `paraferingAuditEntry` schema deprecated in `procest_register.json` with a sunset
   date (one major procest release after this spec ships).
5. Preserve the consumer contract: callers asking "what happened to parafeerroute X" get the
   same event history via OR's audit-trail API.

## Capabilities

### New Capabilities

- `parafering-audit-via-or`: Parafering transition events are discoverable via
  `GET /api/audit-trails?objectUuid={parafeerrouteId}` with action types matching
  `procest.parafering.*`.

### Modified Capabilities

- `parafering-audit-trail` (existing spec) — consumer contract updated to reference OR
  audit-trail API as the discovery endpoint. The spec body update happens during apply phase.

### Removed Capabilities

- In-app `paraferingAuditEntry` write path — removed. Existing records remain readable via
  deprecated endpoint until sunset date. No new records are written after this spec ships.

## Affected Projects

- [x] Project: `procest` — all implementation work is in this repo
- Reference: `hydra/openspec/changes/consume-or-audit-trail-fleet-wide/` (umbrella policy)
- Reference: `openregister/openspec/specs/audit-trail-immutable/spec.md` (OR contract)

## Scope

### In Scope

- Rewriting `ParaferingAuditListener` to emit via OR audit trail
- Removing `ParaferingAuditAppendOnlyValidator`
- Removing listener registrations from `Application.php`
- Marking `paraferingAuditEntry` deprecated in the register JSON
- Tests verifying discoverability via OR audit trail API

### Out of Scope

- The umbrella policy itself (separate spec)
- Modifying OR's audit-trail API (already shipped; consumed, not changed)
- Backfilling historical `paraferingAuditEntry` rows into OR audit trail (out of scope —
  see rationale below)
- Changing the parafering domain logic or transition rules

## Sunset Date

The `paraferingAuditEntry` schema deprecation sunset date is one major procest release
after this spec is accepted. Existing rows remain queryable (read-only) until that date.

## Historical Backfill: Out of Scope

Per ADR-022 + Archiefwet retention, historical rows remain in the deprecated
`paraferingAuditEntry` store in read-only mode; new events emit via OR. Backfilling into
OR's hash chain would risk integrity since chronological ordering of legacy rows is not
guaranteed, and determining the correct `objectUuid` mapping for pre-existing rows is
error-prone. Historical records remain queryable via the deprecated schema endpoint until
the sunset date.

## Success Criteria

- `openspec validate --strict migrate-parafering-to-or-audit` exits 0.
- `ParaferingAuditAppendOnlyValidator.php` is removed.
- `ParaferingAuditListener.php` emits via OR audit trail (no direct `paraferingAuditEntry` writes).
- `GET /api/audit-trails?objectUuid={parafeerrouteId}` returns parafering transition events.
- `composer check:strict` passes.
