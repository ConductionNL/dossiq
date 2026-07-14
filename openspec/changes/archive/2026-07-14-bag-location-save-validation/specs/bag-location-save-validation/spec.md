# bag-location-save-validation Specification

## Purpose

Enforce the `location` schema's documented `source: bag` ⇒ `nummeraanduidingId` contract at
save time, closing the follow-up filed by `bag-register-adapter` (tasks.md item 4.1). Locations
are saved through OpenRegister's generic object-save API (no dedicated procest `LocationService`
exists at HEAD — see `design.md` §2); this spec hooks the same pre-persist event pipeline every
other procest schema save goes through.

## ADDED Requirements

### Requirement: LocationBagValidationListener MUST reject a `source=bag` location that lacks a syntactically valid `nummeraanduidingId`

For any `location`-schema object being created or updated with `source = bag`, `nummeraanduidingId` SHALL be present and SHALL match `^\d{16}$`, else the save SHALL be rejected.

A payload that fails either check SHALL be rejected pre-persist (`stopPropagation()` on the OpenRegister `ObjectCreatingEvent`/`ObjectUpdatingEvent`) with a translated error message; the row SHALL NOT be written to the database. Objects on schemas other than `location`, and `location` payloads whose `source` is not `bag`, SHALL NOT be inspected by this rule.

#### Scenario: Non-location schema is ignored

- **GIVEN** an OpenRegister object being created on any schema other than `location`
- **WHEN** the pre-persist event fires
- **THEN** `LocationBagValidationListener` MUST NOT stop propagation, regardless of the payload
  contents

#### Scenario: source absent or not bag is ignored

- **GIVEN** a `location` payload with no `source` field, or `source` set to `pdok-reverse` / `gps`
  / `free`
- **WHEN** the pre-persist event fires
- **THEN** `LocationBagValidationListener` MUST NOT stop propagation

#### Scenario: source=bag with missing nummeraanduidingId is rejected

- **GIVEN** a `location` payload `{source: 'bag', case: 'uuid-x'}` (no `nummeraanduidingId`)
- **WHEN** the pre-persist event fires
- **THEN** `LocationBagValidationListener` MUST call `stopPropagation()`
- **AND** the event errors MUST include a translated message referencing the missing BAG id

#### Scenario: source=bag with a malformed nummeraanduidingId is rejected

- **GIVEN** a `location` payload `{source: 'bag', nummeraanduidingId: '12345'}` (not 16 digits)
- **WHEN** the pre-persist event fires
- **THEN** `LocationBagValidationListener` MUST call `stopPropagation()`

#### Scenario: source=bag with a well-formed nummeraanduidingId and a dormant adapter is accepted

- **GIVEN** a `location` payload `{source: 'bag', nummeraanduidingId: '0363010000123456'}`
- **AND** `BagAdapterInterface::isDormant()` returns `true` (log-mode, the default)
- **WHEN** the pre-persist event fires
- **THEN** `LocationBagValidationListener` MUST NOT call `stopPropagation()`
- **AND** `BagAdapterInterface::lookupObject()` MUST NOT be called

### Requirement: LocationBagValidationListener SHALL attempt best-effort BAG existence verification when the adapter is non-dormant, and SHALL fail open on any inconclusive outcome

When `nummeraanduidingId` is well-formed and `BagAdapterInterface::isDormant()` is `false`, the listener SHALL call `lookupObject('nummeraanduiding', $id)` and SHALL reject the save only on a definitive `NOT_FOUND`.

Every other result (`FOUND`, `LOOKUP_ERROR`, `LOOKUP_DEFERRED`, `INVALID_INPUT`) — and any `Throwable` raised by the adapter call — SHALL accept the save, logging a warning for the non-`FOUND` outcomes.

#### Scenario: Non-dormant adapter returns FOUND — accepted

- **GIVEN** a well-formed `nummeraanduidingId`
- **AND** `BagAdapterInterface::isDormant()` returns `false`
- **AND** `lookupObject('nummeraanduiding', $id)` returns a `FOUND` result
- **WHEN** the pre-persist event fires
- **THEN** `LocationBagValidationListener` MUST NOT call `stopPropagation()`

#### Scenario: Non-dormant adapter returns NOT_FOUND — rejected

- **GIVEN** a well-formed `nummeraanduidingId`
- **AND** `BagAdapterInterface::isDormant()` returns `false`
- **AND** `lookupObject('nummeraanduiding', $id)` returns a `NOT_FOUND` result
- **WHEN** the pre-persist event fires
- **THEN** `LocationBagValidationListener` MUST call `stopPropagation()`
- **AND** the event errors MUST include a translated message stating the BAG id could not be
  found

#### Scenario: Adapter unavailable — accepted with a logged warning, not rejected

- **GIVEN** a well-formed `nummeraanduidingId`
- **AND** `BagAdapterInterface::isDormant()` returns `false`
- **AND** `lookupObject('nummeraanduiding', $id)` throws, or returns `LOOKUP_ERROR` /
  `LOOKUP_DEFERRED` / `INVALID_INPUT`
- **WHEN** the pre-persist event fires
- **THEN** `LocationBagValidationListener` MUST NOT call `stopPropagation()`
- **AND** a warning MUST be logged
