# Spec delta: archief-edepot-handover-01-schema-config

## ADDED Requirements

### Requirement: procest-archief register declares six archival schemas
The app MUST register a `procest-archief` register hosting six OpenRegister schemas — `BewaarTermijnRegel`, `OverdrachtTrigger`, `SipBundel`, `OverdrachtTransactie`, `ArchiefBewijs`, `OverdrachtAuditLog` — each with the documented fields and relations, importable idempotently on install.

#### Scenario: All six schemas registered on fresh install
- **GIVEN** a fresh procest instance with OpenRegister available
- **WHEN** the register/schema import runs on install
- **THEN** the `procest-archief` register exists
- **AND** all six schemas (`BewaarTermijnRegel`, `OverdrachtTrigger`, `SipBundel`, `OverdrachtTransactie`, `ArchiefBewijs`, `OverdrachtAuditLog`) exist and pass OpenRegister schema validation
- **AND** `OverdrachtTrigger` declares a relation to `case` via `zaakId`
- **AND** `OverdrachtTransactie` declares a relation to `SipBundel` via `sipBundelId`

#### Scenario: Generic REST endpoints available and empty pre-seed
- **GIVEN** the six schemas are registered
- **WHEN** the generic OpenRegister collection endpoint for `SipBundel` is queried before any object is written
- **THEN** the response is an empty collection
- **AND** re-running the schema import does not duplicate any schema

### Requirement: VNG default retention rules are seeded idempotently
The app MUST seed VNG-default `BewaarTermijnRegel` rows on first install so that common zaaktypen have a retention disposition out of the box, and the seed MUST be idempotent.

#### Scenario: Default rules present after first install
- **GIVEN** a fresh procest instance
- **WHEN** the retention-rule seed runs
- **THEN** a `BewaarTermijnRegel` exists for `omgevingsvergunning` with `bewaartermijnJaren` = 5 and `selectielijstCategorie` = "Selectielijst gemeenten 4.1.3"
- **AND** a rule exists for `wmo-aanvraag` with `bewaartermijnJaren` = 10
- **AND** a rule exists for `subsidie-verlening` with `bewaartermijnJaren` = "permanent"

#### Scenario: Re-running the seed does not duplicate rules
- **GIVEN** the default rules are already seeded
- **WHEN** the seed step runs a second time
- **THEN** the total count of seeded default `BewaarTermijnRegel` rows is unchanged
- **AND** a DIV admin can subsequently modify a seeded rule (e.g. 5 → 7 years) without the next seed run re-creating the original
