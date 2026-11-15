## ADDED Requirements

### Requirement: A hand-off across organisations needs recorded consent (REQ-CST-01)

A hand-off or share of a case to another organisation SHALL be refused
unless a `toestemming` record covers this case, this receiving organisation
and this moment. The refusal SHALL name what is missing. A case type SHALL
be able to declare that consent is required for moves inside the
organisation as well.

#### Scenario: A Wmo case cannot leave without consent
@e2e tests/e2e/custody-and-handover-of-a-case.spec.ts

- **GIVEN** a Wmo case with no recorded consent
- **WHEN** a handler tries to hand it to a partner organisation
- **THEN** the hand-off SHALL be refused
- **AND** the refusal SHALL say that consent for this receiver is missing

#### Scenario: Consent that has lapsed does not cover the hand-off
@e2e exclude unit; CaseTransferConsentGateTest

- **GIVEN** a consent whose period ended last month
- **WHEN** the same hand-off is attempted
- **THEN** it SHALL be refused
- **AND** the refusal SHALL name the period that ended

#### Scenario: A move between teams of one organisation is not blocked
@e2e exclude unit; CaseTransferConsentGateTest

- **GIVEN** a case type that does not require consent inside the organisation
- **WHEN** the case moves from one team to another in the same organisation
- **THEN** the move SHALL proceed with no consent record

### Requirement: The recorded scope travels with the share (REQ-CST-02)

Consent SHALL name the scope it grants: which categories of the file the
receiving organisation may read, and for how long. The share created by the
hand-off SHALL carry that scope. A share SHALL NOT be created without one.

#### Scenario: The partner sees only what the consent names
@e2e tests/e2e/custody-and-handover-of-a-case.spec.ts

- **GIVEN** a consent granting the receiver the care plan and not the medical history
- **WHEN** the hand-off creates the share
- **THEN** the share SHALL carry that scope
- **AND** a share with no scope SHALL NOT be written

#### Scenario: The scope is readable afterwards
@e2e exclude unit; PartnerShareScopeTest

- **GIVEN** a case shared with a partner organisation
- **WHEN** the case's sharing is read
- **THEN** it SHALL name the consent, the scope and the period it runs for
