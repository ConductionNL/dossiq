# mandaat-matrix Specification — Member 04: Decidesk Import

---
status: proposed
---

## Purpose

Import a mandateringsbesluit and its mandate table from decidesk, validate roles, create concept
records, present a diff, and finalise on approval.

## ADDED Requirements

### Requirement: Decidesk Mandate Import

The `DecideskImportService` SHALL fetch a besluit and its attached mandate table from decidesk,
parse the table, and create concept `MandateringsBesluit` + `Mandaat` records.

#### Scenario: Import creates concept records from the besluit table

- GIVEN a collegebesluit "Algemene mandaatregeling gemeente 2026" in decidesk with an Excel mandate table
- WHEN a juridisch medewerker calls `POST /api/mandate/import` with `{decidesk_uuid}`
- THEN the service SHALL retrieve the besluit metadata and attachment from decidesk
- AND SHALL parse the table rows into mandate fields (mandaatNummer, omschrijving, rolNaam, plafond, …)
- AND SHALL create one MandateringsBesluit with `status: "concept"`
- AND SHALL create one Mandaat with `status: "concept"` per table row, each referencing the besluit

### Requirement: Role Validation and Diff View

The import SHALL validate that every referenced role exists and SHALL present a NEW/CHANGED/
REMOVED/UNCHANGED diff against the prior mandateringsbesluit.

#### Scenario: Missing role aborts with an error

- GIVEN an import where a row references role "Wethouder RO" not present in OrganisatieRol
- WHEN the import is parsed
- THEN the service SHALL report an error "Role 'Wethouder RO' not found in registry"

#### Scenario: Diff classifies rows against the prior version

- GIVEN a prior mandateringsbesluit exists
- WHEN a new import is parsed
- THEN the result SHALL classify each mandaatNummer as NEW, CHANGED, REMOVED, or UNCHANGED
- AND the import preview SHALL include `newCount`, `changedCount`, and `removedCount`

### Requirement: Import Approval Finalisation

On approval the import SHALL activate the new besluit and supersede the prior one.

#### Scenario: Approval activates the new besluit and vervalt the prior

- GIVEN a concept MandateringsBesluit with concept Mandaat records and a prior active besluit
- WHEN the user calls `POST /api/mandate/import/{importId}/approve`
- THEN the new MandateringsBesluit status SHALL become "vastgesteld" and its Mandaat records "active"
- AND the prior MandateringsBesluit status SHALL become "vervallen" with `vervalDatum` set to the
  day before the new inwerkingtreding
