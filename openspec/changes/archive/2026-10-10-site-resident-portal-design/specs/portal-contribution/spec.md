## ADDED Requirements

### Requirement: The resident sees what the organisation still needs from them (REQ-SRPD-001)
An `aanvullingsverzoek` on a case that has a `portalSubject` MUST carry the same
`portalSubject`, written when the request is asked. The citizen contribution, served to
`citizen` and `client`, MUST offer the collection `vragenAanU` on schema `aanvullingsverzoek`,
scoped by `portalSubject` and projected to `case`, `summary`, `missingItems`,
`hersteltermijn`, `state` and `requestedAt`. No other field of the request MAY be projected.
This is a new requirement: today no request reaches the portal.

#### Scenario: A handler asks, the resident sees the question
- **GIVEN** a resident's Woo request case with a `portalSubject`
- **WHEN** the handler asks for a clarification with summary "Bedoelt u alleen de kap van maart 2026?" and a hersteltermijn of 12 October
- **THEN** the resident's `vragenAanU` MUST list one open request for that case with that summary and that date
- **AND** the request MUST NOT expose `rationale`, `requestedBy` or `pauseReason`

#### Scenario: Someone else's request stays invisible
- **GIVEN** an open request on another resident's case
- **WHEN** the resident reads `vragenAanU`
- **THEN** the request MUST NOT be returned

### Requirement: A case says who must act (REQ-SRPD-002)
The case schema MUST carry `portalTurn`, a calculation that is `applicant` when
`waitingOnApplicant` is true or `waitingOn` is `applicant`, `thirdParty` when `waitingOn` is
`thirdParty`, `us` otherwise, and empty when the case has an `endDate`. The citizen case
projection MUST include `portalTurn`, `waitingOnApplicant` and `waitingOn`. The `mijnZaken`
collection MUST declare `turnField: 'portalTurn'`, `dueField: 'deadline'` and value labels on
`portalTurn`, so a case card reads "U bent aan zet", "Wij wachten op informatie van een ander"
or "De gemeente is aan zet". A closed case MUST show no such line. This is a new requirement.

#### Scenario: The case waits on the resident
- **GIVEN** a running case with an open aanvullingsverzoek
- **WHEN** the resident's overview lists the case
- **THEN** the case card MUST read "U bent aan zet"

#### Scenario: The card reads one declared field
- **GIVEN** the `mijnZaken` collection
- **WHEN** portaliq normalises the manifest
- **THEN** `turnField` MUST be `portalTurn` and `portalTurn` MUST be a projected field

#### Scenario: The case waits on the organisation
- **GIVEN** a running case in a status whose `waitingOn` is `us` and no open request
- **WHEN** the overview lists it
- **THEN** the card MUST read "De gemeente is aan zet"

### Requirement: A case hands the portal its steps (REQ-SRPD-003)
The provider MUST answer `caseSteps(caseId)` with the case type's statuses in `order`, folded
so that consecutive statuses sharing a public label form one step. Each step MUST carry the
public label (or the status name when none is declared), the public description, a state
`done`, `current` or `todo`, and for a done or current step the date the case entered it. The
`mijnZaken` collection MUST declare it as `steps` with the label "Waar staat uw aanvraag?".
The steps MUST come from the case type and never from the page. This is a new requirement.

#### Scenario: A Woo request in assessment
- **GIVEN** a case of the seeded Woo type in status "Zoeken documenten"
- **WHEN** portaliq asks `caseSteps` for it
- **THEN** the answer MUST hold five steps: Ontvangen (done), In behandeling (current), Besluit, Besluit genomen and Afgehandeld (todo)

#### Scenario: Another resident's case
- **GIVEN** a case id that is not in the resident's `mijnZaken`
- **WHEN** portaliq asks for its steps on the resident's behalf
- **THEN** portaliq MUST refuse before calling the provider, as it does for `caseTimeline`

### Requirement: A case names its handling team in public words (REQ-SRPD-004)
The case schema MUST carry `assignedGroupPublicName`, a calculation over the handling team's
public name, empty when the team declares none. It MUST be on the citizen detail fields. An
internal team name MUST never reach the portal. This is a new requirement.

#### Scenario: A team without a public name
- **GIVEN** a case handled by a team that declares no public name
- **WHEN** the resident opens the case
- **THEN** "Behandeld door" MUST NOT be shown

### Requirement: The resident pages are declared, and none of them is a menu entry (REQ-SRPD-005)
The citizen contribution MUST declare the pages `overzicht`, `mijnZaken`, `berichten` and
`verzoeken` with the blocks of design D4, using only block types and keys portaliq
`site-mijn-omgeving-components` defines (`tasks`, `inbox`, `cases`, `steps`, `documents`,
`timeline`, `collection`, `detail`, `action`, `cta`, `citizenCase`). `overzicht` MUST declare
`home: true`. `mijnZaken` MUST be a record page on the case collection, so opening a case
lands on it with that case chosen. Its open-question block MUST be a `tasks` block on
`vragenAanU` with `recordField: case`. "Bericht sturen" MUST be a `cta` on `replyToMessage` with
`withRecord: true`, and `replyToMessage` MUST declare `recordField: 'caseId'`. Every resident page MUST
declare `menu: false`, so the site menu shows one "Zaken" and one "Berichten", both portaliq's
own. The case page MUST hold, in this order, the open question for the case, the steps, the
documents, "Wat er is gebeurd", and beside them the facts, "Bericht sturen" and the
withdrawal.

#### Scenario: One Zaken, one Berichten
- **GIVEN** a resident signed in on a site where portaliq honours `menu: false`
- **WHEN** the menu is built
- **THEN** "Zaken" and "Berichten" MUST each appear once
- **AND** no dossiq page MUST appear as a menu entry

#### Scenario: No placeholder block names
- **GIVEN** the declared resident pages
- **WHEN** portaliq normalises them
- **THEN** no block MUST be dropped as an unknown type

#### Scenario: Only this case's question
- **GIVEN** a resident with open questions on cases 2026-0003 and 2026-0004
- **WHEN** case 2026-0003 is open on the case page
- **THEN** the tasks block MUST list only the question on 2026-0003

#### Scenario: A case opens on its page
- **GIVEN** the resident's case 2026-0003 in portaliq's case list
- **WHEN** they open it
- **THEN** the page `mijnZaken` MUST open with the open question first, the steps, "Documenten", "Wat er is gebeurd", "In het kort" and "Verzoek intrekken"

### Requirement: Dossiq offers its start points to the signed-out home (REQ-SRPD-006)
Each action a resident may start without a dossier, `startWooVerzoekAlgemeen`,
`createBezwaar` and `createKlacht`, MUST carry a one-sentence `summary` of at most 200
characters and the `audiences` it serves, a subset of dossiq's audiences, so a site page can list them as
"Wat wilt u regelen?" tiles. A visitor who is not signed in and picks one MUST be asked to
sign in before the form opens.

#### Scenario: The home lists what a resident can arrange
- **GIVEN** a site page with the start points widget and dossiq installed
- **WHEN** a signed-out visitor opens it
- **THEN** it MUST list "Informatie opvragen (Woo-verzoek)", "Bezwaar maken" and "Klacht indienen" with their summaries
