---
status: done
---

# parafering-audit-via-or Specification

## Purpose
Routes every parafering (sign-off route) transition through OpenRegister's immutable audit trail instead of a dedicated procest audit schema. Each transition emits an OR audit entry tagged `procest.parafering.{transition}` carrying full route, actor, state-change, and delegation context, so the complete approval history is discoverable and hash-chain-verifiable via the OR audit API. Existing legacy records remain readable, and OR's native immutability removes the need for an app-specific append-only validator.
## Requirements
### Requirement: Parafeer Transition Emits OR Audit Event

Every `ParafeerTransitionEvent` SHALL trigger an OR audit trail entry with action type
`procest.parafering.{transitionName}` (e.g. `procest.parafering.approved`,
`procest.parafering.returned`, `procest.parafering.skipped`).

#### Scenario: Approved transition creates OR audit entry

- GIVEN a parafeerroute with UUID `route-001` stored in OR
- WHEN a ParafeerTransitionEvent fires with transition `approved` on `route-001`
- THEN an OR audit entry SHALL be created with `objectUuid = route-001`
- AND the entry's `action` field SHALL equal `procest.parafering.approved`
- AND the entry SHALL be retrievable via `GET /api/audit-trails?objectUuid=route-001`

#### Scenario: Returned transition creates OR audit entry

- GIVEN a parafeerroute with UUID `route-002` stored in OR
- WHEN a ParafeerTransitionEvent fires with transition `returned` on `route-002`
- THEN an OR audit entry SHALL be created with action `procest.parafering.returned`
- AND the hash chain SHALL remain intact across all entries for `route-002`

---

### Requirement: Audit Event Carries Parafeer Context

The OR audit event `$context` payload (stored in the `changed` JSON column) MUST include
the following fields for every parafering transition: `parafeerrouteId`, `paraffeerstapId`
(if applicable), `fromState`, `toState`, `actorUuid`, `comment` (if present on the event).

#### Scenario: Changed column contains route and actor context

- GIVEN an OR audit entry for `procest.parafering.approved` on `route-001`
- WHEN the entry is retrieved via the audit trail API
- THEN the `changed` field MUST contain `parafeerrouteId` equal to `route-001`
- AND the `changed` field MUST contain `fromState` and `toState` strings
- AND the `changed` field MUST contain `actorUuid` identifying the approving user

#### Scenario: Comment field carried in context when present

- GIVEN a transition event with a non-empty `comment` field
- WHEN the OR audit entry is created
- THEN the `changed` field's `comment` key MUST equal the comment string from the transition event

---

### Requirement: No Direct Writes To paraferingAuditEntry Schema

Application code MUST NOT write new `paraferingAuditEntry` objects after this spec ships.
The `ParaferingAuditListener` MUST route all new events through OR's audit trail instead.

#### Scenario: No new paraferingAuditEntry objects created after migration

- GIVEN the migration is applied
- WHEN a ParafeerTransitionEvent fires
- THEN the count of `paraferingAuditEntry` objects in OR SHALL NOT increase
- AND a new OR audit trail entry SHALL exist for the transition

#### Scenario: Existing paraferingAuditEntry objects remain

- GIVEN `paraferingAuditEntry` objects exist from before the migration
- WHEN an administrator queries `paraferingAuditEntry` objects via the OR API
- THEN those objects SHALL remain readable (the schema is deprecated, not deleted)

---

### Requirement: Existing paraferingAuditEntry Records Remain Readable

Until the sunset date documented in `proposal.md`, existing `paraferingAuditEntry` rows MUST
remain queryable via the deprecated schema endpoint. The sunset date is defined as one major
procest release after this spec's acceptance.

#### Scenario: Historical audit records readable after migration

- GIVEN `paraferingAuditEntry` objects exist from before the migration was applied
- WHEN an administrator queries `GET /api/registers/{register}/schemas/paraferingAuditEntry/objects`
- THEN the response SHALL return the existing historical records
- AND the response SHALL NOT include a 404 or schema-not-found error

---

### Requirement: Test Audit Discoverable Via OR

Given a parafeerroute UUID, querying OR's audit-trail-immutable API SHALL return all
parafering transitions in chronological order, including hash-chain integrity.

#### Scenario: Full parafering history discoverable via OR

- GIVEN a parafeerroute `route-003` that has gone through three transitions: submitted → under_review → approved
- WHEN `GET /api/audit-trails?objectUuid=route-003` is called
- THEN the response SHALL include three entries in chronological order
- AND each entry SHALL have an `action` field matching `procest.parafering.*`
- AND `GET /api/audit-trails/verify` SHALL return a passing integrity check for the chain

#### Scenario: Cross-actor delegation audit is preserved

- GIVEN a transition delegated by user A on behalf of user B
- WHEN the OR audit entry is created
- THEN the `changed` field MUST contain both the delegate actor UUID and the principal UUID (onBehalfOf)

---

### Requirement: Append-Only Validator Removed

The `ParaferingAuditAppendOnlyValidator` class MUST be removed from the codebase. OR's
audit trail provides immutability natively via HTTP 405 on PUT/DELETE. No replacement
validator is needed.

#### Scenario: Validator file absent after migration

- GIVEN the migration is applied
- THEN `lib/Validator/ParaferingAuditAppendOnlyValidator.php` SHALL NOT exist in the repo
- AND no `ObjectCreatingEvent` / `ObjectUpdatingEvent` / `ObjectDeletingEvent` registrations
  for `paraferingAuditEntry` objects SHALL remain in `Application.php`

#### Scenario: OR enforces immutability natively

- GIVEN an OR audit trail entry for a parafering transition
- WHEN an API call attempts `PUT /api/audit-trails/{id}` with modified data
- THEN OR SHALL return HTTP 405 Method Not Allowed
- AND no procest-specific validator SHALL be required to enforce this

