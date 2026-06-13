# Design: termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle

## Scope of this member

The binding layer: `TermijnService` and the zaak-creation hook. Consumes the member-01 schemas. No pause/extension/scan/dwangsom logic — those are later members.

## Approach

- **Data access (ADR-001)**: all CRUD goes through the OpenRegister `ObjectService` (find / findAll / saveObject / updateObject). No direct DB access, no bespoke mappers. The audit trail is auto-maintained by OpenRegister.
- **`TermijnService`** exposes `createTermijnInstance(zaakId, zaaktypeKey)`, `getTermijnInstance(zaakId)`, `updateTermijnInstance(...)`, and `getTermijnDefinitie(zaaktype)` with in-memory caching (definitions are rarely updated).
- **Zaak-creation hook**: on the zaak-creation event, resolve the active `TermijnDefinitie` for the zaaktype. If none → reject creation with a clear admin-facing error (REQ-TERM-001-A). If found → compute `einddatumBerekend = startDatum + standaardDuurDagen`, set `status = lopend`, persist the instance, and record a `start` `TermijnGebeurtenis` (grondslag `AWB 4:13`).
- **Versioning (REQ-TERM-001-C)**: `getTermijnDefinitie` selects the version whose `validFrom ≤ now` and (`validUntil` is null or `≥ now`). Existing `TermijnInstance` rows are never recalculated when a definition changes.

## Security (ADR-005)

Creation runs in the zaak-creation flow (authenticated handler context). `TermijnService` performs no privilege escalation; entity access is governed by OpenRegister RBAC (ADR-022). No new public endpoints in this member — the REST surface lands in member 10.

## Tests

Unit tests for `TermijnService` deadline calculation and the missing-definition block; an integration test that a zaak-creation in OpenRegister spawns the `TermijnInstance` + start event.
