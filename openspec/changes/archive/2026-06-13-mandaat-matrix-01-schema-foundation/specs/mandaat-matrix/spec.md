# mandaat-matrix Specification — Member 01: Schema Foundation

---
status: proposed
---

## Purpose

Declare the six OpenRegister schemas and seed reference data the mandate-matrix feature depends
on. This is the declare-first config member of the chain; all behaviour consumes these schemas
in later members.

## ADDED Requirements

### Requirement: Mandate-Matrix Schemas Are Registered

The system SHALL register six OpenRegister schemas — `MandateringsBesluit`, `Mandaat`,
`OrganisatieRol`, `MedewerkerRolToewijzing`, `MandaatGebruik`, and `MandaatEscalatie` — through
the procest register on app install or upgrade, including the relations between them.

#### Scenario: All six schemas exist after install

- GIVEN a fresh procest install with OpenRegister available
- WHEN the schema-registration repair step runs
- THEN each of the six schemas SHALL be retrievable from the procest register with a schema UUID
- AND the `Mandaat` schema SHALL declare a `besluitId` reference to `MandateringsBesluit`
- AND the `Mandaat` schema SHALL declare a `gemandateerdeRol` reference to `OrganisatieRol`
- AND the `MedewerkerRolToewijzing` schema SHALL declare a `rolId` reference to `OrganisatieRol`

#### Scenario: MandaatGebruik is declared immutable

- GIVEN the `MandaatGebruik` schema is registered
- WHEN its schema definition is inspected
- THEN it SHALL declare the audit-snapshot fields `rolOpMomentVanBesluit` and
  `gebruikteVoorwaarden` as JSON
- AND it SHALL be marked for write-once handling so that immutability can be enforced at the API
  layer by a later chain member

### Requirement: Reference Seed Data Is Created Idempotently

The system SHALL seed reference data — 7 OrganisatieRol records, 5 MedewerkerRolToewijzing
records (including one waarnemer), 2 MandateringsBesluit records, and 4 Mandaat records — through
an idempotent repair step that creates no duplicates on re-run.

#### Scenario: Seed data materialises with correct references

- GIVEN the mandate-matrix schemas are registered
- WHEN the seed repair step runs for the first time
- THEN 7 OrganisatieRol records SHALL exist (incl. Vergunningverlener, Senior Vergunningverlener,
  Hoofd Vergunningverlening, Handhaver)
- AND 5 MedewerkerRolToewijzing records SHALL exist, one with `toewijzingType` = "waarnemer"
- AND 2 MandateringsBesluit records SHALL exist (CR 2026-001 vastgesteld, CR 2025-099 vervallen)
- AND 4 Mandaat records SHALL exist, each with `besluitId` and `gemandateerdeRol` resolving to an
  existing MandateringsBesluit and OrganisatieRol respectively

#### Scenario: Re-running the seed step creates no duplicates

- GIVEN the seed repair step has already run once
- WHEN it runs a second time
- THEN the record counts SHALL remain unchanged (7 / 5 / 2 / 4)
- AND no duplicate OrganisatieRol, Mandaat, MedewerkerRolToewijzing, or MandateringsBesluit record
  SHALL be created
