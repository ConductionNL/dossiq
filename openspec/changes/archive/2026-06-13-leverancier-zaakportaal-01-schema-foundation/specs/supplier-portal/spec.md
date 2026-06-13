# supplier-portal Specification — Member 01: Schema Foundation

---
status: proposed
---

## Purpose

Declare the seven OpenRegister schemas, four Procest case types, and seed reference data the
supplier portal depends on. This is the declare-first config member of the chain; all behaviour
consumes these schemas in later members.

## ADDED Requirements

### Requirement: Supplier-Portal Schemas Are Registered

The system SHALL register seven OpenRegister schemas — `Supplier`, `SupplierUser`,
`SupplierTender`, `SupplierContract`, `SupplierInvoice`, `SupplierMessage`, and `SupplierKPI` —
through the procest register on app install or upgrade, including the relations between them.

#### Scenario: All seven schemas exist after install

- GIVEN a fresh procest install with OpenRegister available
- WHEN the schema-registration repair step runs
- THEN each of the seven schemas SHALL be retrievable from the procest register with a schema UUID
- AND `SupplierUser` SHALL declare a `supplierRef` reference to `Supplier`
- AND `SupplierTender`, `SupplierContract`, and `SupplierInvoice` SHALL each declare a
  `supplierRef` reference to `Supplier`
- AND each Supplier* schema SHALL declare an index on `supplierRef`, `status`, and its primary
  date field

#### Scenario: SupplierMessage is declared write-once

- GIVEN the `SupplierMessage` schema is registered
- WHEN its schema definition is inspected
- THEN it SHALL declare `direction`, `body`, `attachmentRefs`, `sentBy`, and `sentAt` fields
- AND it SHALL be marked for write-once handling so that immutability can be enforced at the API
  layer by a later chain member

### Requirement: Supplier Procest Case Types Are Declared

The system SHALL declare four Procest zaaktypes used by the portal mutation and renewal flows:
`Leverancier-contractverlenging-verzoek`, `Leverancier-IBAN-wijziging`,
`Leverancier-accreditatie-verificatie`, and `Leverancier-mutatie`.

#### Scenario: All four case types exist after install

- GIVEN a fresh procest install
- WHEN the case-type registration repair step runs
- THEN each of the four supplier zaaktypes SHALL be retrievable by its identifier
- AND `Leverancier-IBAN-wijziging` SHALL declare a 4-eyes approval workflow posture

### Requirement: Reference Seed Data Is Created Idempotently

The system SHALL seed reference data — 3 Supplier records, 5 SupplierUser records (covering the
admin, finance, contracts, sales, and read_only roles), 5 SupplierTender, 4 SupplierContract,
5 SupplierInvoice, and 1 SupplierMessage thread — through an idempotent repair step that creates
no duplicates on re-run.

#### Scenario: Seed data materialises with correct references

- GIVEN the supplier-portal schemas are registered
- WHEN the seed repair step runs for the first time
- THEN 3 Supplier records SHALL exist
- AND 5 SupplierUser records SHALL exist, one per role
- AND 5 SupplierTender records SHALL exist, one per tender status
- AND at least one SupplierContract SHALL fall within the 90-day expiry window
- AND at least one SupplierInvoice SHALL be more than 90 days overdue
- AND every Supplier* record's `supplierRef` SHALL resolve to an existing Supplier

#### Scenario: Re-running the seed step creates no duplicates

- GIVEN the seed repair step has already run once
- WHEN it runs a second time
- THEN the record counts SHALL remain unchanged (3 / 5 / 5 / 4 / 5 / 1)
- AND no duplicate Supplier, SupplierUser, SupplierTender, SupplierContract, SupplierInvoice, or
  SupplierMessage record SHALL be created
