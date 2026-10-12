# zaakportaal-mijngemeente Delta: a-request-form-opens-the-case-at-once

**Status**: draft
**Scope**: the portal's bezwaar and klacht filings open a case directly (decision 179).

## MODIFIED Requirements

### Requirement: Bezwaar (objection) filing within legal deadline

REQ-POR-008: When a decision is issued, the citizen SHALL be able to file a formal objection (bezwaarschrift) if the deadline (typically 6 weeks after decision) has not passed. The submit SHALL create a bezwaar case in dossiq in the same request, with the contested case and decision as cross-references. No intermediate object SHALL be written.

#### Scenario: Bezwaar form appears when deadline is open
- **GIVEN** case Z/2026/09128 has a decision "Beschikking omgevingsvergunning" issued on 2026-04-02
- **AND** the bezwaarschrifttermijn is 6 weeks, so deadline is 2026-05-14
- **AND** current date is 2026-04-12 (32 days before deadline)
- **WHEN** the citizen opens the case detail
- **THEN** the "Mogelijke acties" section shows a button: "Bezwaar indienen"
- **AND** when clicked, a BezwaarForm opens with:
  - Pre-filled: `tegenZaakId`, decision title, decision date
  - Fields: Motiveringveld (text area), file upload area for supporting documents
  - A checkbox: "Ik ben het ermee eens dat mijn gegevens voor deze procedure worden gebruikt"

#### Scenario: Bezwaar is submitted within deadline
- **GIVEN** case Z/2026/09128 has a decision issued on 2026-04-02 with a bezwaar term until 2026-05-14
- **AND** the citizen fills in the motivering and attaches one document on 2026-04-12
- **WHEN** the citizen sends the bezwaar
- **THEN** a bezwaar case exists before the response returns, with `tegenZaak` Z/2026/09128 and the attachment as a case document
- **AND** the confirmation names the bezwaar case number, the received moment, the term start and the deadline
- **AND** the case detail shows "Bezwaar ingediend op 12 april 2026" with the bezwaar case number

#### Scenario: Bezwaar deadline has expired
- **GIVEN** current date is 2026-05-20 (6 days after the 2026-05-14 deadline)
- **WHEN** the citizen opens the case detail
- **THEN** the "Bezwaar indienen" button is hidden
- **AND** a message says the bezwaar term ended on 14 May, with a link to "Vraag om uitleg"

### Requirement: Klacht (complaint) filing and optional subsidie aanvragen

REQ-POR-009: Citizens SHALL be able to file formal complaints (klacht) independently of any case. The submit SHALL create a case of case type `klacht` in the same request and return its number. Subsidie-aanvragen SHALL be directed to the appropriate application system.

#### Scenario: Klacht intake form
- **GIVEN** a citizen navigates to a standalone "Klacht indienen" form (accessible from the main menu or homepage)
- **WHEN** she selects "Klacht indienen"
- **THEN** KlachtForm opens with fields:
  - `categorie`: dropdown (Bejegening, Doorlooptijd, Communicatie, Medische/Zorgkwaliteit, Andere)
  - `omschrijving`: text area ("Beschrijf je klacht")
  - `betrokkenMedewerker`: optional (name or department of the employee)
  - `aanvullingenVerzenden`: optional checkbox to allow handler to request more info

#### Scenario: Klacht is submitted
- **GIVEN** the klacht form is filled with categorie "Bejegening" and an omschrijving
- **WHEN** the citizen sends it
- **THEN** a klacht case with status "ontvangen" exists before the response returns
- **AND** the confirmation and the receipt mail name that case number
- **AND** the case is routed to the klachtencoördinator

#### Scenario: Subsidie-aanvraag selection
- **GIVEN** a citizen navigates to "Subsidie aanvragen"
- **WHEN** the SubsidieForm loads
- **THEN** it queries opencatalogi for available subsidies in the municipality
- **AND** displays a list: "Dakisolatie huiseigenaren 2026", "Monument gevelrenovatie", etc.
- **AND** for each subsidy, a button "Aanvragen" links to either:
  - An external application system URL (gemeente website)
  - An embedded form if available (future phase)
