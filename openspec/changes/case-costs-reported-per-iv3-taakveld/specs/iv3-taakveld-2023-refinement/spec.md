## ADDED Requirements

### Requirement: A case type carries its Iv3 taakveld as a code from Shillinq's catalogue (REQ-IV3-CLASS-01)

A case type SHALL carry at most one Iv3 taakveld code in `iv3TaskField`. Dossiq SHALL NOT ship a taakveld list of its own: when Shillinq is installed the code SHALL be picked from Shillinq's `BbvTaakveld` catalogue, showing the code and its translated label; without Shillinq the field SHALL accept a code as typed and say that Shillinq provides the list. The code SHALL be stored verbatim, a 2023 refinement code (for example `6.71a`) included; rolling it up to its parent is Shillinq's job.

#### Scenario: The beheerder picks a refinement code from Shillinq's list

- **GIVEN** Shillinq is installed and its catalogue holds `6.71a` "Hulp bij het huishouden (Wmo)"
- **WHEN** the beheerder sets the taakveld of case type "Wmo huishoudelijke hulp"
- **THEN** the picker SHALL offer `6.71a` with its label
- **AND** the case type SHALL store `iv3TaskField = '6.71a'`
- @e2e tests/e2e/iv3-link-to-shillinq.spec.ts

#### Scenario: Without Shillinq the code is typed

- **GIVEN** Shillinq is not installed
- **WHEN** the beheerder opens the taakveld field of a case type
- **THEN** the field SHALL accept a code as text and show "Installeer Shillinq om uit de BBV-lijst te kiezen"
- @e2e tests/e2e/iv3-link-to-shillinq.spec.ts

### Requirement: Dossiq exposes classification and hours, never a sum of money (REQ-IV3-CLASS-02)

Dossiq SHALL expose what Shillinq needs to cost cases per taakveld through its OpenRegister objects only: the case id, the case's `caseType` reference and that case type's `iv3TaskField`. Hours on a case SHALL be humaniq `TimeEntry` records with `domainObjectRef` set to the case id and `domainObjectType` `dossiq:case`. Dossiq SHALL NOT hold a cost amount per case, sum money per taakveld, case type or period, or serve an Iv3 report or export.

#### Scenario: Shillinq reads the taakveld of a case

- **GIVEN** case Z-2026-0042 of case type "Wmo huishoudelijke hulp" with `iv3TaskField = '6.71a'`
- **WHEN** Shillinq reads the case and its case type from dossiq's register
- **THEN** it SHALL get the taakveld `6.71a` without calling a dossiq endpoint
- @e2e exclude register contract, read by Shillinq's own tests

#### Scenario: No money sum in dossiq

- **GIVEN** the dossiq code and manifest
- **WHEN** they are searched for an Iv3 or taakveld cost aggregation, a cost field on the case or an Iv3 route
- **THEN** none SHALL exist
- @e2e exclude source check, asserted by a unit test over routes and register

### Requirement: The reports page links to Shillinq for costs per taakveld (REQ-IV3-CLASS-03)

The reports page SHALL show a card "Kosten per taakveld staat in Shillinq" in place of a cost report. With Shillinq installed the card SHALL open Shillinq's Iv3 report. Without Shillinq the card SHALL say that the report needs Shillinq and SHALL show no link and no figure. The reports page SHALL show no money figure per taakveld or per case type.

#### Scenario: The card opens Shillinq

- **GIVEN** Shillinq is installed
- **WHEN** the controller opens Rapportages and chooses "Kosten per taakveld staat in Shillinq"
- **THEN** Shillinq's Iv3 report SHALL open
- @e2e tests/e2e/iv3-link-to-shillinq.spec.ts

#### Scenario: Without Shillinq the card explains itself

- **GIVEN** Shillinq is not installed
- **WHEN** the controller opens Rapportages
- **THEN** the card SHALL say "Dit rapport staat in Shillinq. Shillinq is niet geïnstalleerd."
- **AND** the page SHALL show no cost figure
- @e2e tests/e2e/iv3-link-to-shillinq.spec.ts

## REMOVED Requirements

### Requirement: The shipped taakveld list SHALL include the 18 official 2023 Wmo/Jeugd refinement codes and SHALL flag their 4 pre-2023 parent codes as deprecated

**Reason**: ADR-107 decision 1 and decision 261: Shillinq's `BbvTaakveld` catalogue is the fleet's single taakveld authority; dossiq ships no list.
**Migration**: The 22 codes move to Shillinq's catalogue (Shillinq change `iv3-report-takes-case-costs-by-case-type`); `lib/Settings/iv3_taakvelden.json` is deleted after that lands.

### Requirement: Iv3TaakveldList::aggregationKeyFor() SHALL resolve every 2023-refinement code to its single pre-2023 parent code, and SHALL pass through every other code unchanged

**Reason**: The roll-up is a reporting rule and the report is Shillinq's (ADR-107, decision 261).
**Migration**: Shillinq's catalogue carries `aggregatesUnder` per refinement code and its Iv3 report rolls up through it.

### Requirement: Iv3ReportService quarterly aggregation SHALL bucket cases by aggregation key, so old- and new-code-classified cases land in the same bucket

**Reason**: A domain app does not sum money (ADR-107 decision 2); `Iv3ReportService` was already deleted.
**Migration**: Shillinq's `Iv3ReportGenerator` reports case costs per taakveld from the case type classification (Shillinq change `iv3-report-takes-case-costs-by-case-type`).
