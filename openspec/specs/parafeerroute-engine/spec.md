---
status: retired
retired_in: dossiq-adopt-or-abstractions
canonical_home: case-management/spec.md
---

> **RETIRED — route modification expressed as `requires` guards on
> lifecycle transitions.**
>
> Skip-step and ad-hoc-step semantics live in the consolidated
> `x-openregister-lifecycle` annotation on the case schema. Routes
> become a data attribute on case-types, not a separate engine. See
> ADR-022 and `case-management/spec.md`.
>
> This file is preserved as a historical appendix. Refer to
> `case-management/spec.md` for canonical route semantics.

## Purpose

@e2e exclude RETIRED spec; requirements consolidated into case-management/spec.md.

## Requirements

### Requirement: Parafeerroute Schema Registration

The system SHALL register a `parafeerroute` schema in the Dossiq OpenRegister configuration with properties: name (string), caseType (reference), voorstelType (enum: dt_advies, collegeadvies, raadsvoorstel), steps (array of parafeerstap objects). Each parafeerstap SHALL have: order (integer), type (enum: advies, parafering, accordering), actor (string, user UID or group/role name), actorType (enum: user, group, role), mandatory (boolean).

**Feature tier**: V1
**Schema.org type**: `schema:HowTo`
**CMMN concept**: PlanItemDefinition with ordered HumanTasks

#### Scenario: Schema is available after app install

- **WHEN** the Dossiq app is installed or updated
- **THEN** the `parafeerroute` schema SHALL be registered in the Dossiq register via the repair step
- **AND** the schema SHALL enforce required properties: name, steps

### Requirement: Sequential Step Routing

The system SHALL execute parafeerroute steps in sequential order. Each step SHALL complete before the next step is activated.

**Feature tier**: V1

#### Scenario: Sequential step execution

- **WHEN** a voorstel is submitted for parafering with a 5-step route
- **THEN** the system SHALL activate step 1 first
- **AND** step 2 SHALL NOT be activated until step 1 is completed
- **AND** each actor SHALL receive a Nextcloud notification when their step is activated

#### Scenario: Step completion advances to next

- **WHEN** the actor at step 3 completes their action (paraferen or adviseren)
- **THEN** the voorstel currentStep SHALL advance to 4
- **AND** the step 4 actor SHALL receive a Nextcloud notification
- **AND** the voorstel updatedAt SHALL be refreshed

### Requirement: Admin parafeerroute configuration

Approval routes SHALL be authored in the decision app. dossiq raises a voorstel's
chain there and records the outcome, and SHALL NOT offer a local authoring surface.

AMENDED 2026-09-02. dossiq#1666 moved the parafering RUNTIME to decidiq and retired
the local engine with no facade, including the dossiq-side flow projection that
dossiq#1632 had introduced. So there is no dossiq authoring surface left to keep,
and the `/settings/parafeerroutes` index page is retired with it: editing a route
object here would reach nothing that runs. `/settings/parafeerroutes/:id` stays
registered so a reader can still open a legacy route object, and so the frozen
`procest.parafering.*` audit trail that names `parafeerrouteId` keeps resolving.

**Feature tier**: V1

#### Scenario: dossiq offers no route editor

- **GIVEN** a beheerder in dossiq's settings
- **WHEN** they look for a way to create or edit an approval route
- **THEN** dossiq SHALL offer no page to create, link, reorder or edit a route
- **AND** the route SHALL be authored in the decision app
- **AND** `/settings/parafeerroutes/:id` SHALL still open a legacy route object read-only

### Requirement: Override Route on Specific Voorstel

Skipping a step or adding an ad-hoc step on a specific voorstel SHALL happen in
the decision app, which runs the route. dossiq SHALL NOT offer to change a
running route.

AMENDED 2026-11-04 (decision 332). The route editor and the per-voorstel
override are not drawn on any dossiq board, because dossiq no longer runs the
route.

**Feature tier**: V1

#### Scenario: A step is skipped where the route runs

- **GIVEN** a voorstel whose route runs in the decision app
- **WHEN** an authorized manager wants to skip a step with a reason
- **THEN** they SHALL do so in the decision app
- **AND** dossiq SHALL show the outcome the decision app records
