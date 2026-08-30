## ADDED Requirements

### Requirement: A status move performed by a flow names the node that performed it @e2e exclude attribution is asserted in openregister; this requirement fixes dossiq's side of the contract

When a case's status is moved by a flow, the resulting audit record SHALL carry the run and node that moved it, so "who moved this case, and why" is answerable without inferring it from timing.

Each status move in a case flow SHALL be its own step rather than a side effect of another step. A status that changes as a by-product of an unrelated node cannot be attributed to an intention, and it is the applicant-facing signal — it is the thing the case's progress is read from.

#### Scenario: A flow-driven status move is attributed
- **WHEN** a flow node moves a case's status
- **THEN** the audit record for that change names the run and the node

#### Scenario: A status move is a step of its own
- **WHEN** a case flow moves a case between stages
- **THEN** the move is performed by a node whose purpose is the move
- **AND** the run's history shows it as a step

#### Scenario: A status move outside a flow is unattributed, not mis-attributed
- **WHEN** a person changes a case's status directly
- **THEN** the audit record names the person
- **AND** it names no flow run
