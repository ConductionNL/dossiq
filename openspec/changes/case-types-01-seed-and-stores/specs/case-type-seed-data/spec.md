# case-type-seed-data Specification

## ADDED Requirements

### Requirement: Sub-entity object stores MUST be registered
The system SHALL register object stores for the five case-type sub-entities so the
admin tab UI (members 03, 04) can query them by `caseType`.

#### Scenario: Sub-entity stores are queryable
- GIVEN the Procest frontend has loaded
- WHEN `objectStore.getObjects({ caseType: uuid })` is called for each of
  `result-type`, `role-type`, `property-definition`, `document-type`,
  `decision-type`
- THEN each store MUST return the objects linked to that case type
- AND no console errors MUST occur on app load
- AND all five type names MUST be kebab-case (ADR-015)
- AND no duplicate registrations MUST exist across `OBJECT_TYPES` or `ENTITY_STORES`

### Requirement: Case type seed data MUST be imported via the repair step
The system SHALL provide pre-seeded realistic Dutch case types imported via the
repair step. Seed data enables automated browser testing and manual QA without
requiring manual setup.

#### Scenario: Seed data imported on first install
- GIVEN a fresh Procest installation running the repair step for the first time
- WHEN `ConfigurationService::importFromApp('procest', data, version, false)` is called
- THEN the following case types MUST exist in the `procest` register:
  - "Omgevingsvergunning" (processingDeadline: P56D, isDraft: false)
  - "Subsidieaanvraag" (processingDeadline: P13W, isDraft: false)
  - "Klachtbehandeling" (processingDeadline: P6W, isDraft: false)
  - "Bezwaarschrift" (processingDeadline: P6W, isDraft: false)
- AND each case type MUST have at least 3 linked status types (one with `isFinal = true`)
- AND each case type MUST have at least 3 linked result types

#### Scenario: Seed data is idempotent
- GIVEN an installation where seed data was already imported
- WHEN the repair step runs again (e.g., after an update)
- THEN the system MUST NOT create duplicate case types
- AND existing customisations to the seeded case types MUST be preserved
- AND no errors MUST be thrown

#### Scenario: Seed case types are publishable
- GIVEN the 4 seeded case types
- WHEN the backend publish validation runs against each (member 02, REQ-CT-02b)
- THEN all 4 MUST pass validation without errors
  (each has: ≥1 status type, ≥1 isFinal status type, validFrom set)

### Requirement: Translation keys MUST be declared for both languages
The system SHALL declare all user-visible string keys consumed by the case-types
tab UI in both English and Dutch, with no key gaps.

#### Scenario: en/nl key sets match
- GIVEN the new case-types translation keys (tab labels, form field labels,
  archivalAction options)
- WHEN comparing the key sets of `l10n/en.json` and `l10n/nl.json`
- THEN the two key sets MUST be identical (no gaps)
- AND `en.json` values MUST equal their keys (English source)
- AND `nl.json` MUST provide Dutch translations
