# approval-routes-are-flows Specification

## Purpose

An approval route stops being an entity and becomes a flow: a sequence of manual
sign-offs ending in the decision they gate. This change projects the existing
routes; it does not yet retire them.

## ADDED Requirements

### Requirement: REQ-ARF-001 A route projects onto a flow of manual steps

The system SHALL project each stored approval route onto an OpenRegister flow
whose nodes are the route's steps, in `order`, followed by the decision the
steps gate.

Each step SHALL be a `dossiq.askPerson` node, so the step reaches its actor as a
task in the work queue they already read, and the run waits for their answer in
the same node.

#### Scenario: Steps become askPerson nodes in order

- **GIVEN** a route whose steps declare `order` 2 then 1
- **WHEN** it is projected
- **THEN** the flow's nodes MUST be the step declaring `order` 1 first, then the
  one declaring 2, then `dossiq.requestDecision`

#### Scenario: The steps are chained, not parallel

- **GIVEN** a route with two steps
- **THEN** an edge MUST run from the first to the second, because step two is
  not asked until step one has answered

#### Scenario: The chain ends in the decision it gates

- **GIVEN** any projected route
- **THEN** the final node MUST be `dossiq.requestDecision`

### Requirement: REQ-ARF-002 A step with no actor refuses the whole route

The system SHALL NOT project a route in which any step names no actor, and SHALL
report it as skipped with the reason.

`dossiq.askPerson` rejects an empty assignee. Projecting the remaining steps
would produce a flow that silently omits a sign-off somebody is expecting, which
is worse than projecting nothing.

#### Scenario: One actorless step skips the route

- **GIVEN** a route whose second step has an empty `actor`
- **WHEN** the migration runs
- **THEN** no flow MUST be written for that route, and the summary MUST count it
  as skipped

### Requirement: REQ-ARF-003 The projection arrives disabled

The projected flow SHALL be created disabled.

The route still drives parafering through `BesluitvormingParafeerService`. A
projection that also ran would ask every approver twice.

#### Scenario: A freshly projected route is disabled

- **GIVEN** any route
- **WHEN** it is projected
- **THEN** the flow document MUST carry `enabled: false`

### Requirement: REQ-ARF-004 The projection runs as a named owner

The migration SHALL run as the user named on the command, and SHALL refuse to
run when the object service cannot scope to a user.

A flow inherits its owner and organisation from whoever created it, and keeps
them. Projecting outside that scope would hand every migrated route to whichever
identity the CLI happened to carry.

#### Scenario: No runAs means no projection

- **GIVEN** an object service exposing no `runAs()`
- **WHEN** the migration runs
- **THEN** nothing MUST be written, and the summary MUST carry a note saying why

#### Scenario: A re-run updates rather than duplicating

- **GIVEN** a route already projected once
- **WHEN** the migration runs again
- **THEN** it MUST update the flow carrying that route's provenance marker,
  resolved by marker and never by name
