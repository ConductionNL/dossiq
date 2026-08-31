# parafering-to-decidiq Specification

**Status**: in progress
**Scope**: dossiq

## Purpose

Hold the parafeerroute — the reusable definition of who signs a voorstel, in
what order — in decidiq's `ApprovalRoute`, and read it from there when a
voorstel enters parafering. The runtime chain state stays in dossiq.

## ADDED Requirements

### Requirement: REQ-PTD-001 Routes are held in the decision app

The system SHALL provide a migration that, for each local `parafeerroute`,
causes a decidiq `ApprovalRoute` to exist carrying the same ordered steps, with
`sourceApp: dossiq` and `externalReference` set to the local route's id, and
SHALL record the resulting id on the local row.

The command SHALL travel as a typed event, not over the REST seam: ADR-041, and
a migration has no session for decidiq's controller to authenticate.

The migration SHALL skip a route that already records an id, and SHALL report a
partial run as partial rather than as a clean one.

#### Scenario: A parafeerroute becomes an approval route

- **GIVEN** a `parafeerroute` with four ordered steps and `isDefault: true`
- **WHEN** the migration runs
- **THEN** a decidiq `ApprovalRoute` exists with those four steps in order,
  `sourceApp: dossiq` and `externalReference` naming the local route
- **AND** the local route records the new route's id

#### Scenario: Re-running mints nothing new

- **GIVEN** a route already carrying a migrated id
- **WHEN** the migration runs again
- **THEN** no second route is created and the run reports it as already mapped

#### Scenario: The decision app is absent

- **GIVEN** an install without the decision app
- **WHEN** the migration runs
- **THEN** it reports a skip, changes nothing, and does not fail the upgrade

#### Scenario: The step order survives the move

- **GIVEN** a route whose steps carry `order` 1..4 with mixed mandatory flags
- **WHEN** it is migrated
- **THEN** the held route's steps carry the same order, actor, actorType,
  mandatory flag and label

### Requirement: REQ-PTD-002 Activating a voorstel sends its route and starts the chain there

When a voorstel enters parafering the system SHALL resolve its route locally,
take the snapshot from those steps, and dispatch the route to the decision app
naming the voorstel as its subject, so the decision app holds the route AND
materialises the sign-off chain.

The system SHALL NOT read the route back from the decision app. dossiq has the
steps in hand at activation; reading them back would need a cross-app read path
that does not exist, and a route resolved from the wrong place is a wrong
signature chain that would look entirely plausible.

The dispatch SHALL NOT decide whether activation succeeds. The decision app is
an optional runtime dependency, so a voorstel must still enter parafering
without it. Whether the chain was mirrored SHALL be RECORDED ON THE VOORSTEL
rather than only logged, so "which voorstellen are mirrored" is a query and not
an archaeology exercise.

#### Scenario: Activation sends the route and records the id

- **GIVEN** a caseType with a default route of four steps, and the decision app installed
- **WHEN** a voorstel of that caseType is activated
- **THEN** the voorstel's `routeSnapshot` carries those four steps
- **AND** the decision app holds an `ApprovalRoute` with `sourceApp: dossiq`
- **AND** the voorstel records the resulting `approvalRouteId`

#### Scenario: The decision app is absent

- **GIVEN** the decision app is not installed
- **WHEN** a voorstel is activated
- **THEN** activation SUCCEEDS with the local snapshot
- **AND** the voorstel's `approvalRouteId` is empty, which is how an unmirrored
  voorstel is found later

#### Scenario: The decision app refuses

- **GIVEN** the decision app is installed but refuses the command
- **WHEN** a voorstel is activated
- **THEN** activation still succeeds and `approvalRouteId` stays empty
- **AND** the refusal is logged with the voorstel and the reason

#### Scenario: Steps are copied, not referenced

- **GIVEN** a voorstel activated against a route
- **WHEN** the route's steps are later changed
- **THEN** that voorstel's snapshot is unchanged

### Requirement: REQ-PTD-003 A voorstel that cannot be routed is not put into parafering

When no route resolves for a voorstel's caseType, the system SHALL refuse to
activate parafering and SHALL leave the voorstel's status and step untouched.

Activating with an empty snapshot parks the voorstel in `in_parafering` at step
1 with nothing to travel. Every later action then fails
`Current step not found in route snapshot` — a message about the snapshot when
the fault is a route nobody configured — and there is no way forward or back.

#### Scenario: Activation without a route is refused

- **GIVEN** a voorstel whose caseType has no default route
- **WHEN** parafering is activated
- **THEN** the request is refused with a message naming the missing route
- **AND** the voorstel's status and `currentStep` are unchanged
- **AND** no `routeSnapshot` is written

#### Scenario: A route with no steps is refused too

- **GIVEN** a default route whose `steps` is empty
- **WHEN** parafering is activated
- **THEN** it is refused for the same reason: there is nothing to travel
