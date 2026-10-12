## ADDED Requirements

### Requirement: An objection against a Woo decision is registered from the Woo case (REQ-WCS-004)

Amended per decision 182: the capability is generic and lives in `specs/remedy-lodging/spec.md`
(REQ-RML-001). This requirement is the configuration that makes it serve the Wet open overheid (Woo)
case type.

The Woo request case type SHALL declare a `remedy` of kind `bezwaar` whose `caseType` is the Bezwaar
case type. A Woo case with a stored decision SHALL offer a `Register objection` header action. Its
dialog SHALL ask for the received date, the channel and the grounds, and SHALL post them to POST
`/api/cases/{id}/remedy`. There is no Woo-specific endpoint and no Woo-specific class: the same
action serves any case type that declares a remedy case type.

#### Scenario: An objection opens its own case
- **GIVEN** a Woo case with a decision made known on 2026-11-02
- **WHEN** the handler registers an objection received on 2026-12-01
- **THEN** a Bezwaar case SHALL exist that is related to the Woo case
- **AND** an objection record SHALL link that case and the Woo decision
- **AND** the Woo case page SHALL link to the Bezwaar case, and the Bezwaar case page SHALL link back

#### Scenario: No decision, no objection
- **GIVEN** a Woo case without a decision
- **WHEN** POST `/api/cases/{id}/remedy` is called
- **THEN** it SHALL answer 409 and no case SHALL be created

### Requirement: The objection has its own term and timeliness (REQ-WCS-005)

Amended per decision 182: timeliness is generic and lives in `specs/remedy-lodging/spec.md`
(REQ-RML-002). This requirement is the configuration.

The Woo request case type's `remedy` SHALL declare a term of 42 days, the six weeks of Algemene wet
bestuursrecht (Awb) art. 6:7. The Bezwaar case SHALL get its statutory decision term from the
Bezwaar case type: six weeks (Awb art. 7:10 lid 1), rolled to the next working day, with one
extension of six weeks.

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
