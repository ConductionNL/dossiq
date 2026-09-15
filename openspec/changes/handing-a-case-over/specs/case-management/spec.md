## ADDED Requirements

### Requirement: A case is handed to another team as a recorded act (REQ-HAND-01)

A case SHALL be handed to another team inside the organisation as a
first-class act, writing the same transfer record the federated transfer
writes, with the team in place of the target organisation. The case SHALL
keep its number, its history, its documents and its running terms. The act
SHALL carry a reason and SHALL record who performed it. A handover to a
team that cannot be resolved SHALL be refused rather than leaving the case
unowned.

#### Scenario: Vergunningen hands a case to Toezicht
@e2e tests/e2e/handing-a-case-over.spec.ts

- **GIVEN** an open case owned by Vergunningen
- **WHEN** a handler hands it to Toezicht with a reason
- **THEN** the case SHALL be owned by Toezicht
- **AND** its number, history and terms SHALL be unchanged

#### Scenario: the handover joins the custody trail
@e2e tests/e2e/handing-a-case-over.spec.ts

- **GIVEN** a case handed internally and previously transferred from another organisation
- **WHEN** its custody trail is read
- **THEN** both moves SHALL be on the one trail

#### Scenario: an unresolvable team refuses the handover

- **GIVEN** a handover naming a team that does not exist
- **WHEN** it is performed
- **THEN** it SHALL be refused
- **AND** the case SHALL keep its current owner

### Requirement: The receiving team can refuse a handover back (REQ-HAND-02)

A receiving team SHALL be able to refuse a handover with a reason, which
SHALL return the case to the sending team and SHALL be recorded on the
custody trail. A handover nobody has accepted SHALL stay visible to the
sending team as outstanding.

#### Scenario: the wrong team sends it back
@e2e tests/e2e/handing-a-case-over.spec.ts

- **GIVEN** a case handed to Toezicht
- **WHEN** Toezicht refuses it with a reason
- **THEN** the case SHALL return to the sending team
- **AND** the reason SHALL be on the custody trail

#### Scenario: an unaccepted handover does not vanish
@e2e tests/e2e/handing-a-case-over.spec.ts

- **GIVEN** a handover nobody has accepted
- **WHEN** the sending team reads its cases
- **THEN** the case SHALL be listed as an outstanding handover

### Requirement: A doorzending tells the applicant where the case went (REQ-HAND-03)

A handover SHALL declare whether it is a doorzending under Awb 2:3. Where
it is, the applicant SHALL be told that the case moved and to whom,
through the case type's declared automatic moments. Where it is an
internal move within the same bestuursorgaan, no message SHALL be sent to
the applicant.

#### Scenario: the sender is told, per Awb 2:3
@e2e tests/e2e/handing-a-case-over.spec.ts

- **GIVEN** a case handed on as a doorzending
- **WHEN** the handover completes
- **THEN** the applicant SHALL be told the case moved and to whom

#### Scenario: an internal move is not announced
@e2e tests/e2e/handing-a-case-over.spec.ts

- **GIVEN** a case handed from one internal team to another, not marked a doorzending
- **WHEN** the handover completes
- **THEN** no message SHALL be sent to the applicant

### Requirement: A case may be homed in another application (REQ-HAND-04)

A case SHALL be able to declare that it is homed in another application,
naming the application, the identifier there and a link to it. Such a case
SHALL still carry its type, status, terms and parties, and SHALL appear in
the central list beside the cases dossiq handles. dossiq SHALL NOT offer
to perform work on an externally homed case, and SHALL show where the work
is instead.

#### Scenario: one list answers for both kinds
@e2e tests/e2e/handing-a-case-over.spec.ts

- **GIVEN** a generic case and a case homed in a specialist application
- **WHEN** a teamleider reads the case list
- **THEN** both SHALL be listed with their type, status and term

#### Scenario: the externally homed case points at its home
@e2e tests/e2e/handing-a-case-over.spec.ts

- **GIVEN** an externally homed case
- **WHEN** a handler opens it
- **THEN** it SHALL name the application and the identifier there
- **AND** it SHALL offer the link

#### Scenario: dossiq does not pretend to hold the work

- **GIVEN** an externally homed case
- **WHEN** a handler opens the lifecycle menu
- **THEN** the acts that perform the work SHALL be disabled
- **AND** they SHALL say where the work is done
