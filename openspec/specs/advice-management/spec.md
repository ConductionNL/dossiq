## ADDED Requirements

### Requirement: Advice request schema

The system SHALL store advice requests as `adviesAanvraag` objects in OpenRegister supporting internal and external advice lifecycle with deadline tracking.

**Feature tier**: V1
**ZGW mapping**: Custom extension (extends Zaak with advice tracking)
**Schema.org**: schema:AskAction (request), schema:InformAction (response)

#### Scenario: Create internal advice request

- **WHEN** the behandelaar clicks "Advies aanvragen" on a case and selects an internal adviseur (e.g., welstandscommissie)
- **THEN** the system SHALL create an `adviesAanvraag` with: case (reference), adviseur (user UID), type="intern", onderwerp (string), deadline (date), status="aangevraagd", requestedAt (datetime)
- **THEN** a task SHALL be created for the adviseur: "Advies uitbrengen voor [case identifier]"
- **THEN** the case timeline SHALL record: "Advies aangevraagd bij [adviseur]"

#### Scenario: Create external advice request

- **WHEN** the behandelaar requests advice from an external party (e.g., Veiligheidsregio)
- **THEN** the system SHALL create an `adviesAanvraag` with type="extern" and the external organization name as adviseur
- **THEN** a reminder notification SHALL be generated 3 days before the deadline
- **THEN** overdue advice SHALL trigger an escalation notification to behandelaar and teamleider

#### Scenario: Receive and process advice

- **WHEN** the adviseur uploads an advies document and marks the request as completed
- **THEN** the adviesAanvraag status SHALL change to "ontvangen" and receivedAt SHALL be set
- **THEN** the advies document SHALL be linked to the case via a document link
- **THEN** the behandelaar SHALL be notified: "Advies ontvangen voor [case identifier]"

#### Scenario: Advice timeout

- **WHEN** an adviesAanvraag deadline passes without response
- **THEN** the status SHALL change to "verlopen"
- **THEN** a task SHALL be created for the behandelaar: "Advies verlopen: beoordeel of vergunningprocedure kan doorgaan zonder dit advies"

### Requirement: Advice panel on case dashboard

The system SHALL display an advice panel on the case dashboard showing all advice requests with their status and deadlines.

**Feature tier**: V1

#### Scenario: Display advice overview

- **WHEN** a user views the case dashboard for a case with 3 adviesAanvragen
- **THEN** the "Adviezen" panel SHALL show all 3 with: adviseur name, type badge (intern/extern), status badge (aangevraagd=blue, ontvangen=green, verlopen=red), deadline date
- **THEN** overdue advice SHALL be highlighted in red with days overdue count

#### Scenario: Quick actions on advice panel

- **WHEN** the behandelaar views the advice panel
- **THEN** each request SHALL have quick actions: "Herinnering sturen" (for pending), "Bekijk advies" (for received), "Markeer als ontvangen" (for pending with document uploaded)

### Requirement: Advice request form

The system SHALL provide a form for creating advice requests from the case dashboard.

**Feature tier**: V1

#### Scenario: Create advice request form

- **WHEN** the behandelaar clicks "Advies aanvragen" on the case dashboard
- **THEN** a dialog SHALL appear with fields: adviseur (user selector for intern, text input for extern), type (intern/extern toggle), onderwerp (text), deadline (date picker, default: 2 weeks from now), specific questions (text area)
- **THEN** the form SHALL validate that adviseur and deadline are filled before submission

#### Scenario: Advice guard on workflow transition

- **WHEN** a workflow transition has a guard requiring "all advice received"
- **THEN** the transition SHALL be blocked if any adviesAanvraag has status "aangevraagd"
- **THEN** the guard violation message SHALL list the pending advice requests: "[adviseur]: advies verwacht voor [deadline]"
