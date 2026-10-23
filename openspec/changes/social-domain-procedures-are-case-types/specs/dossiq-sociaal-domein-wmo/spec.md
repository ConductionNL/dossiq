# dossiq-sociaal-domein-wmo (delta)

## ADDED Requirements

### Requirement: A Wmo report is a case of the Wmo-melding case type (REQ-WMO-CT-001)

dossiq SHALL complete the shipped `wmo-melding` case type through an automatically installed
package that adopts it by identifier (no second case type): category Wmo, handled by
`dossiq-sociaal-domein`, confidential, a status for every status of the old `wmoZaak` lifecycle,
roles, results, fields and a flow. The support question, the household composition and the needs
assessment reference SHALL be fields flagged `sensitive`; the move to Besluit in voorbereiding SHALL
require the support question, checked as the system.

#### Scenario: Restricted data stays restricted
- **GIVEN** the old record restricted `bsn`, `supportRequest`, `householdsComposition` and `needsAssessmentId` to dossiq-sensitive
- **THEN** on the case they SHALL be `subjectBsn` and `sensitiveProperties`

### Requirement: Stored Wmo reports move onto cases without loss or duplicates (REQ-WMO-CT-002)

On upgrade every stored `wmoZaak` SHALL become one case per case number on its mapped status, with
the BSN as `subjectBsn`, the applicant's name as the initiator, the consulent as assignee and every
other field answered on the case. Needs assessments (`indicatiestelling.caseId`), consents
(`toestemming.caseId`) and case meetings (`mdoOverleg.caseIds`, a list) pointing at the record SHALL
point at the case. The record SHALL be left as it was apart from `migratedTo` and `migratedAt`
(`wmoZaak` 1.3.0), and SHALL answer the cross-domain lookup only through its case.

#### Scenario: A case meeting about two reports keeps both
- **GIVEN** a case meeting listing a moved Wmo report and another case
- **WHEN** the report moves
- **THEN** the meeting SHALL list the new case in its place and the other case unchanged
