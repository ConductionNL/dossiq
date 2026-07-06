---
status: proposed
---

# Spec: initiator-display

**Status:** proposed
**Scope:** Initiator details on the case detail view
**Depends on:** `initiator-selection` (this change — the stored initiator reference)
**Standards:** GEMMA Zaakafhandel (initiator visible on the zaak), ZGW ZRC Rol betrokkene
**Feature tier:** MVP

## ADDED Requirements

### Requirement: Case detail shows the initiator

The manifest `CaseDetail` page SHALL display the case's initiator in its overview when the
initiator fields are set: `initiatorDisplayName` with the `initiatorType` (Person / Company /
Contact) and the identifying `initiatorSourceId` (BSN / KvK number / contact reference) as a link
to the source record (the `brpPerson`/`kvkCompany` register object detail, or the contact). Cases
without an initiator SHALL show no empty initiator block.

#### Scenario: Initiator visible on the case

- **GIVEN** a case with `initiatorType: company` and `initiatorSourceId: 69599084`
- **WHEN** a user opens the case detail
- **THEN** the overview MUST show the company's display name, the type Company, and the KvK number
- **AND** the KvK number MUST link to the seeded `kvkCompany` record

#### Scenario: No initiator, no clutter

- **GIVEN** a case without initiator fields
- **WHEN** a user opens the case detail
- **THEN** no initiator section/fields MUST be rendered
