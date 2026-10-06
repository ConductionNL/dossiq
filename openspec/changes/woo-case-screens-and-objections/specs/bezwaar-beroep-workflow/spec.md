## ADDED Requirements

### Requirement: An objection against a Woo decision is registered from the Woo case (REQ-WCS-004)

A Woo case with a stored decision SHALL offer a `Register objection` header action. Its dialog SHALL
ask for the received date, the channel and the grounds, and SHALL post them to a new endpoint POST
`/api/cases/{id}/woo/objection`. The endpoint SHALL, in one request:

1. refuse with 409 when the Woo case has no decision;
2. open a case of the Bezwaar case type whose `relatedCases` holds the Woo case;
3. attach the objection record with `contestedDecision` set to the Woo decision, through the same
   service code `bezwaarObjection#open` runs, so there is one way an objection is recorded;
4. answer `{bezwaarCaseId, objectionId, deadline, isTimely}`.

When step 3 fails, the bezwaar case of step 2 SHALL be deleted again and the endpoint SHALL answer
the error. No bezwaar case SHALL exist without its objection record. The caller SHALL need mutation
access to the Woo case (`requireCaseMutationAccess`).

#### Scenario: An objection opens its own case
- **GIVEN** a Woo case with a decision made known on 2026-11-02
- **WHEN** the handler registers an objection received on 2026-12-01
- **THEN** a Bezwaar case SHALL exist whose `relatedCases` holds the Woo case
- **AND** an objection record SHALL link that case and the Woo decision
- **AND** the Woo case page SHALL link to the Bezwaar case, and the Bezwaar case page SHALL link back

#### Scenario: No decision, no objection
- **GIVEN** a Woo case without a decision
- **WHEN** POST `/woo/objection` is called
- **THEN** it SHALL answer 409 and no case SHALL be created

#### Scenario: A failed objection record leaves no empty case
- **GIVEN** an objection payload the objection schema refuses
- **WHEN** POST `/woo/objection` is called
- **THEN** it SHALL answer the error and no Bezwaar case SHALL remain

### Requirement: The objection has its own term and timeliness (REQ-WCS-005)

The Bezwaar case SHALL get its statutory decision term from the Bezwaar case type: six weeks
(Awb art. 7:10 lid 1), rolled under the Awt, starting the day after the objection period ended or
the day it was received, whichever is later. The endpoint SHALL set `isTimely` from Awb art. 6:7
and 6:8: an objection received within six weeks from the day after the decision was made known is
timely. An untimely objection SHALL still be registered, marked `isTimely: false`, for the jurist
to judge (Awb art. 6:11).

#### Scenario: A timely objection
- **GIVEN** a Woo decision made known on 2026-11-02
- **WHEN** an objection received on 2026-12-01 is registered
- **THEN** `isTimely` SHALL be true

#### Scenario: A late objection is registered and marked
- **GIVEN** a Woo decision made known on 2026-11-02
- **WHEN** an objection received on 2026-12-21 is registered
- **THEN** a Bezwaar case SHALL exist and `isTimely` SHALL be false

### Requirement: The Bezwaar case type exists where the Woo case type does (REQ-WCS-006)

The Bezwaar case type SHALL be seeded with the Woo request case type, from a register fragment of
its own, on every install that has the Woo request case type. The Beroep and Subsidie demo case
types SHALL stay parked under `_caseTypes_disabled`. A Bezwaren index page SHALL list the cases of
the Bezwaar case type and SHALL be reachable from the navigation.

#### Scenario: A fresh install can handle an objection
- **GIVEN** a fresh install with the English demo profile
- **WHEN** the register is imported
- **THEN** the Bezwaar case type SHALL exist and the Beroep and Subsidie demo case types SHALL NOT
- **AND** the Bezwaren page SHALL list a newly registered objection
