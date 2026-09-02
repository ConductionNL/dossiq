# parafering-runtime-to-decidiq Specification

**Status**: in progress
**Scope**: dossiq

## Purpose

dossiq raises a voorstel's parafering in the decision app and records the
outcome it concludes; the local sign-off runtime retires with no facade.

## ADDED Requirements

### Requirement: REQ-PRTD-001 Entering parafering is a raise, and it fails closed

When a voorstel enters parafering the system SHALL resolve its route locally,
refuse a voorstel whose case type has no route, and hand the route and the
voorstel to the decision app. The system SHALL NOT advance a sign-off chain
locally.

The raise SHALL fail closed: with the local runtime retired there is no engine
to fall back to, so a voorstel is never put into parafering when the decision
app is absent or will not hold the route.

#### Scenario: A routed voorstel is raised and records the route id

- **GIVEN** a case type with a default route and the decision app installed
- **WHEN** a voorstel of that type is activated
- **THEN** the voorstel enters `in_parafering` and records its `approvalRouteId`

#### Scenario: An unroutable voorstel is refused

- **GIVEN** a voorstel whose case type has no route
- **WHEN** activation is attempted
- **THEN** it is refused and nothing is written

`@e2e changed-surfaces.spec.ts` asserts activation of an unroutable voorstel
does not answer 200.

#### Scenario: The raise fails closed without the decision app

- **GIVEN** an install whose decision app will not hold the route
- **WHEN** a voorstel is activated
- **THEN** the raise is refused and the voorstel is not parked in parafering

`@e2e exclude` requires an instance without the decision app; the fail-closed
raise is unit-pinned (ParaferingRaiseServiceTest).

### Requirement: REQ-PRTD-002 A concluded chain is recorded as case data

When the decision app announces `ApprovalRouteConcludedEvent` for a chain it
raised (`sourceApp: dossiq`), the system SHALL project the outcome onto the
voorstel: one `parafeeractie` per sign-off preserving actor, onBehalfOf,
mandate, comment and advice; the final status (`geaccordeerd` or
`teruggestuurd`); the steller's notification; and the accordering signature.

The system SHALL record and never decide, SHALL be idempotent against a
replayed conclusion and a re-recorded sign-off, and SHALL keep raising the
`procest.parafering.*` audit transition per sign-off so the legal trail does
not split.

Events for any other consuming app SHALL be ignored.

#### Scenario: The sign-off record survives the move

- **GIVEN** a concluded chain whose record carries a mandated delegate sign-off
- **WHEN** the conclusion is recorded
- **THEN** the parafeeractie carries `actorType: delegate`, the onBehalfOf
  principal and the mandate reference

`@e2e exclude` a typed cross-app event is invisible to a browser; preservation,
dedup, terminal-status idempotency and the audit transition are unit-pinned and
mutation-checked (ParaferingConclusionServiceTest).

#### Scenario: Another app's conclusion is ignored

- **GIVEN** an `ApprovalRouteConcludedEvent` with a different sourceApp
- **THEN** nothing is recorded on any dossiq case

`@e2e exclude` unit-pinned (ParaferingConcludedListenerTest).

### Requirement: REQ-PRTD-003 No local parafering runtime returns

The system SHALL NOT contain a local parafering engine: no file under `lib/`
may advance a sign-off chain (write a `currentStep` or a terminal voorstel
status) outside the sanctioned raise and conclusion recorder, and none of the
retired runtime classes may exist again.

#### Scenario: A reintroduced local engine fails the build

- **GIVEN** a new file under `lib/` that writes `currentStep` or a terminal
  voorstel status while performing storage
- **THEN** the structural test fails naming it, unless it is the raise or the
  conclusion recorder

`@e2e exclude` a source-structure invariant, asserted by
LocalParaferingRuntimeTest over every file under `lib/`.

### Requirement: REQ-PRTD-004 In-flight paraferingen are re-raised on upgrade

On upgrade the system SHALL re-raise every voorstel still in parafering in the
decision app, after the routes are held, so the migrated engine has a chain to
finish. The step SHALL fail rather than run without a system identity, SHALL be
idempotent, and SHALL report a voorstel it cannot re-raise rather than strand
it silently.

#### Scenario: An in-flight voorstel is re-raised

- **GIVEN** a voorstel `in_parafering` whose route the decision app holds
- **WHEN** the migration runs
- **THEN** its chain is raised in the decision app

`@e2e exclude` a repair step runs under `occ upgrade`, not a browser;
re-raise selection, the skip cases and the no-identity failure are unit-pinned
(RaiseInFlightParaferingenInDecidiqTest).
