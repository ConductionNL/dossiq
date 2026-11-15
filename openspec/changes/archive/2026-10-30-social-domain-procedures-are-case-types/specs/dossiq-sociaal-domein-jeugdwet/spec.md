# dossiq-sociaal-domein-jeugdwet (delta)

## ADDED Requirements

### Requirement: A Jeugdwet report is a case of the Melding Jeugdwet case type (REQ-JW-CT-001)

The `jeugdwet` package SHALL install on every instance and its case type `melding-jeugdwet` SHALL be
the one Jeugdwet case type (no second one): category Jeugdhulp (the Jeugdwet domain, and still a
category the family-plan panel shows for), handled by `dossiq-sociaal-domein`, with statuses for
every status of the old `jeugdwetZaak` lifecycle (Jeugdhulp loopt, Evaluatie and Verlenging
aangevraagd added) and the record's data as fields. The support question, the family plan and
case meeting references and a supervision order SHALL be fields flagged `sensitive`.

#### Scenario: The young person's BSN stays restricted
- **GIVEN** the old record restricted `jeugdigeBsn` to dossiq-sensitive
- **THEN** on the case it SHALL be `subjectBsn`, restricted to the same group

### Requirement: Stored Jeugdwet reports move onto cases without loss or duplicates (REQ-JW-CT-002)

On upgrade every stored `jeugdwetZaak` SHALL become one case per case number on its mapped status,
with the young person's BSN as `subjectBsn`, the referrer as initiator and the youth worker as
assignee. Family plans (`gezinsplan.caseId`), consents (`toestemming.caseId`) and case meetings
(`mdoOverleg.caseIds`, a list) pointing at the record SHALL point at the case, so the family plan
shows on the case. The record SHALL be left as it was apart from `migratedTo` and `migratedAt`
(`jeugdwetZaak` 1.3.0).

#### Scenario: The family plan follows the report
- **GIVEN** a family plan whose caseId is a stored Jeugdwet report
- **WHEN** the report moves
- **THEN** the plan's caseId SHALL be the new case

### Requirement: A Jeugdwet report follows a flow (REQ-JW-CT-003)

The Melding Jeugdwet case type SHALL name a published flow from Melding ontvangen through Onderzoek,
Indicatie, Overleg and Besluit to Jeugdhulp loopt, Evaluatie, Verlenging aangevraagd and Afgehandeld.
The move to Indicatie SHALL require the support question and the move to Besluit the family plan,
both checked as the system so the mover need not read them.

#### Scenario: Every status can be reached
- **GIVEN** the shipped flow
- **THEN** every status of the case type SHALL be reachable from Melding ontvangen

### Requirement: A Jeugdwet report runs on an eight-week decision term (REQ-JW-CT-004)

Ruben (23 Oct 2026): a statutory term definition (Awb 4:13 lid 2, the Jeugdwet setting no decision
term of its own) SHALL bind to Melding Jeugdwet for eight weeks, without extension, and the record
import SHALL carry the term of a report still before its decision onto its case.

#### Scenario: A migrated report before its decision is tracked
- **GIVEN** a stored report in the family-plan stage
- **WHEN** it moves onto a case
- **THEN** the case SHALL carry a running statutory term ending eight weeks after the request date
