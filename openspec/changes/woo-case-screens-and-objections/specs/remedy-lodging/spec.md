## ADDED Requirements

### Requirement: A remedy is lodged against a decided case (REQ-RML-001)

Any case type MAY name, in its `remedy` declaration, the case type a remedy against its decisions
opens (`remedy.caseType`). A case of such a case type SHALL accept POST `/api/cases/{id}/remedy`
with the received date, the channel and the grounds. The caller SHALL need mutation access to the
case. In one request the endpoint SHALL:

1. refuse with 409 when the case has no decision, or when its case type names no remedy case type;
2. refuse with 422, before anything is opened, a lodging without grounds or with a channel other
   than `brief`, `email`, `form` or `balie`;
3. open a case of the named case type, started on the received date;
4. record the objection against the latest decision through the code `bezwaarObjection#open` runs,
   so an objection is recorded one way, and link the two cases through the case relation;
5. answer `{caseId, objectionId, deadline, isTimely}`.

When step 4 fails, the case of step 3 SHALL be deleted again and the endpoint SHALL answer the
error. No remedy case SHALL exist without its objection record.

Nothing in this capability names a procedure. Which remedy a case type offers, its term and the
case type it opens are configuration on the case type.

#### Scenario: A lodged remedy opens its own case
- **GIVEN** a decided case whose case type names a remedy case type
- **WHEN** a remedy received on 2026-12-01 is lodged with grounds and channel `brief`
- **THEN** a case of the remedy case type SHALL exist, started on 2026-12-01
- **AND** an objection record SHALL link that case and the decision

#### Scenario: No decision, nothing to lodge against
- **GIVEN** a case without a decision
- **WHEN** POST `/api/cases/{id}/remedy` is called
- **THEN** it SHALL answer 409 and no case SHALL be opened

#### Scenario: A case type without a remedy case type
- **GIVEN** a decided case whose case type names no `remedy.caseType`
- **WHEN** POST `/api/cases/{id}/remedy` is called
- **THEN** it SHALL answer 409 and no case SHALL be opened

#### Scenario: A failed objection record leaves no empty case
- **GIVEN** a lodging whose objection record cannot be saved
- **WHEN** POST `/api/cases/{id}/remedy` is called
- **THEN** it SHALL answer the error and the opened case SHALL be deleted again

### Requirement: A lodged remedy is judged timely against the declared term (REQ-RML-002)

The endpoint SHALL set `isTimely` from the term the case type declares (`remedy.termDays`). The term
counts from the day the decision was made known: the publication date, else the delivery date, else
the decision date. The end of the term SHALL go through the engine calendar, so a term that ends on
a Saturday, a Sunday or a public holiday runs to the next working day (Algemene termijnenwet
art. 1). A remedy received on or before that day is timely. An untimely remedy SHALL still be
registered, marked `isTimely: false`, for the jurist to judge (Algemene wet bestuursrecht (Awb)
art. 6:11). A decision without any of the three dates, or a case type without a term, SHALL count
as timely.

#### Scenario: A timely remedy
- **GIVEN** a decision made known on 2026-11-02 and a term of 42 days
- **WHEN** a remedy received on 2026-12-14 is lodged
- **THEN** `isTimely` SHALL be true

#### Scenario: A late remedy is registered and marked
- **GIVEN** a decision made known on 2026-11-02 and a term of 42 days
- **WHEN** a remedy received on 2026-12-15 is lodged
- **THEN** the case SHALL be opened and `isTimely` SHALL be false

#### Scenario: A term that ends in a weekend
- **GIVEN** a decision made known on 2026-11-07 and a term of 42 days, which ends on Saturday 2026-12-19
- **WHEN** a remedy received on Monday 2026-12-21 is lodged
- **THEN** `isTimely` SHALL be true

### Requirement: A remedy is lodged from the case page (REQ-RML-003)

A case page header action of type `open-modal` MAY open `RemedyLodgeDialog`. The action's `props`
SHALL configure it: `title`, `lodgeUrl` (with `{caseId}`), `channels` (each `{value, label}`, the
values the route accepts) and `openRoute` (`{name, idField}`: the page that opens the new case and
the answer field holding its id).

The dialog SHALL ask for the day the remedy was received, the channel, the grounds and, optionally,
what is asked for, and SHALL post them once. The received day SHALL be sent as the local calendar
day. On success the dialog SHALL say the remedy is registered, SHALL say so when the answer carries
`isTimely: false`, and SHALL offer to open the new case. A refusal SHALL be shown with the server's
sentence, and the dialog SHALL stay open.

The dialog decides nothing. Whether the case has a decision, whether its case type offers a remedy
and whether the lodging is on time are the route's answers (REQ-RML-001, REQ-RML-002).

#### Scenario: A handler registers an objection and opens its case
- **GIVEN** a decided case whose case type names a remedy case type
- **WHEN** the handler fills the dialog and saves, then chooses to open the new case
- **THEN** POST `/api/cases/{id}/remedy` SHALL be called once
- **AND** the page of the new case SHALL open

#### Scenario: A late objection is registered and the handler is told
- **GIVEN** the route answers `isTimely: false`
- **WHEN** the handler saves
- **THEN** the dialog SHALL say the remedy was received after the term and is registered anyway

#### Scenario: No decision yet
- **GIVEN** a case without a decision
- **WHEN** the handler saves
- **THEN** the dialog SHALL show the server's sentence and SHALL NOT read as registered
