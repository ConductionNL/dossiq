# dossiq-sociaal-domein-participatiewet (delta)

## ADDED Requirements

### Requirement: A benefit application is a case of the Bijstandsaanvraag case type (REQ-PW-CT-001)

dossiq SHALL ship the case type `bijstandsaanvraag` as an automatically installed package in the
category Werk en inkomen (the Participatiewet domain), handled by `dossiq-sociaal-domein`,
confidential, with a status for every status of the old `participatiewetZaak` lifecycle. The
household situation and the asset and income tests SHALL be case-type fields flagged `sensitive`.

#### Scenario: Restricted data stays restricted
- **GIVEN** the old record restricted `bsn`, `householdsSituation`, `equityAssessment` and `incomeAssessment` to dossiq-sensitive
- **THEN** on the case they SHALL be `subjectBsn` and `sensitiveProperties`, restricted to the same group

### Requirement: Stored applications move onto cases without loss or duplicates (REQ-PW-CT-002)

On upgrade every stored `participatiewetZaak` SHALL become a case of the type on its mapped status:
the BSN as `subjectBsn`, the klantmanager as assignee, the request date as start, the case number
as a former reference, every other field answered on the case. Two records with one case number
SHALL become one case, the second reported as a duplicate. Consents (`toestemming.caseId`) and
re-integration trajectories (`reIntegratieTraject.caseId`) pointing at the record SHALL point at
the case. The record SHALL be left as it was apart from `migratedTo` and `migratedAt`
(`participatiewetZaak` 1.3.0), and SHALL answer the cross-domain lookup only through its case.

#### Scenario: A shipped application maps onto a case
- **GIVEN** each of the three shipped applications
- **WHEN** the import maps it
- **THEN** its case SHALL carry the subject BSN, the klantmanager, the former case number, the sensitive answers apart and the ordinary ones in `properties`

### Requirement: A benefit application runs on an eight-week decision term (REQ-PW-CT-003)

The Bijstandsaanvraag case type SHALL declare an eight-week processing deadline, and a statutory
term definition (Awb 4:13 lid 2, the Participatiewet setting no term of its own) SHALL bind to it,
so a new application is tracked like any case with a statutory term. On import, an application
still before its decision SHALL carry its term onto the case from its request date; an application
past its decision SHALL have its term completed. The term definitions SHALL be seeded before the
import runs.

#### Scenario: A migrated application before its decision is tracked
- **GIVEN** a stored application in decision preparation, requested on 2 February
- **WHEN** it moves onto a case
- **THEN** the case SHALL carry a running statutory term ending eight weeks after 2 February
