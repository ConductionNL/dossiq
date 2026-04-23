---
status: draft
---
# parafeerroute-engine Specification

## Purpose

Implement the parafeerroute configuration and execution engine within Procest. This enables beheerders to define named approval routes (parafeerroutes) with ordered steps, link them to case types and voorstel types, and execute these routes sequentially when a voorstel is submitted for parafering. Authorized managers can override routes on in-flight voorstellen (skip steps, add ad-hoc steps) with mandatory audit trail recording.

## Context

Dutch municipal B&W decision-making relies on structured approval chains (parafeerroutes) where proposals pass sequentially through adviseurs, parafeerders, and accorderende partijen before reaching the college. The `bw-parafering` spec established the data model (`voorstel`, `parafeerroute`, `parafeeractie` in ADR-000). This change implements the engine: schema registration, sequential execution, admin configuration UI, and runtime override capabilities. The `routeSnapshot` pattern ensures that edits to a parafeerroute template do not affect voorstellen already in flight.

## Requirements

---

### REQ-PRE-001: Parafeerroute Schema Registration

The system SHALL register a `parafeerroute` schema in the Procest OpenRegister configuration with properties: `name` (string, required), `caseType` (reference), `voorstelType` (enum: `dt_advies`, `collegeadvies`, `raadsvoorstel`), `steps` (array of `parafeerstap` objects, required), `isDefault` (boolean), `description` (string). Each `parafeerstap` SHALL have: `order` (integer), `type` (enum: `advies`, `parafering`, `accordering`), `actor` (string — user UID or group/role name), `actorType` (enum: `user`, `group`, `role`), `mandatory` (boolean).

**Feature tier**: V1

#### REQ-PRE-001-001: Schema available after app install

- **GIVEN** the Procest app is installed or updated
- **WHEN** the app repair step runs
- **THEN** the `parafeerroute` schema SHALL be registered in the Procest register via the repair step
- **AND** the schema SHALL enforce required properties: `name` and `steps`
- **AND** previously registered seed routes SHALL be importable idempotently — no duplicates on re-import (verified by slug match)

---

### REQ-PRE-002: Sequential Step Routing

The system SHALL execute parafeerroute steps in sequential order. Each step SHALL complete before the next step is activated. The `routeSnapshot` SHALL be captured on the voorstel at submission time so that subsequent edits to the parafeerroute template do not affect in-flight voorstellen.

**Feature tier**: V1

#### REQ-PRE-002-001: Sequential step execution

- **GIVEN** a voorstel is submitted for parafering with a linked parafeerroute containing 5 steps
- **WHEN** the steller submits the voorstel (status transitions to `in_parafering`)
- **THEN** the system SHALL capture a `routeSnapshot` on the voorstel (JSON-encoded copy of the route's `steps` array at that moment)
- **AND** `voorstel.currentStep` SHALL be set to `1`
- **AND** the system SHALL activate step 1: create a Nextcloud task for the step 1 actor linked to the case, and send a notification: "Voorstel '[onderwerp]' wacht op uw [type] (stap 1 van 5)"
- **AND** step 2 SHALL NOT be activated until step 1 is completed

#### REQ-PRE-002-002: Step completion advances to next

- **GIVEN** the actor at step 3 completes their action (paraferen or adviseren) on a 5-step voorstel
- **WHEN** the `parafeeractie` is recorded with `action` = `parafered` or `advised`
- **THEN** `voorstel.currentStep` SHALL advance to `4`
- **AND** the step 4 actor SHALL receive a Nextcloud notification: "Voorstel '[onderwerp]' wacht op uw [type] (stap 4 van 5)"
- **AND** a task SHALL be created for the step 4 actor
- **AND** `voorstel.updatedAt` SHALL be refreshed

#### REQ-PRE-002-003: Final step completion marks voorstel geaccordeerd

- **GIVEN** a voorstel at its final step (e.g. step 5 of 5) of type `accordering`
- **WHEN** the accordering actor records their action
- **THEN** `voorstel.status` SHALL change to `geaccordeerd`
- **AND** the steller SHALL receive a Nextcloud notification: "Voorstel '[onderwerp]' is volledig geaccordeerd"
- **AND** no further routing steps SHALL be activated

---

### REQ-PRE-003: Admin Parafeerroute Configuration

The system SHALL provide an admin UI for creating and managing parafeerroutes. Routes SHALL be linkable to case types and voorstel types. Each caseType + voorstelType combination SHALL support at most one default route.

**Feature tier**: V1

#### REQ-PRE-003-001: Create new parafeerroute

- **GIVEN** the beheerder navigates to admin settings and opens the "Parafeerroutes" tab
- **WHEN** the beheerder clicks "Nieuwe route", fills in: name, voorstelType, optional caseType, optional isDefault flag, optional description, and adds steps via the step editor
- **AND** each step has: step type (`advies`/`parafering`/`accordering`), actor type (`user`/`group`/`role`), actor selection, and mandatory flag
- **WHEN** the beheerder saves the route
- **THEN** the system SHALL create a `parafeerroute` object in OpenRegister
- **AND** the route SHALL appear in the list under the "Parafeerroutes" tab
- **AND** if `isDefault` is true and another route with the same caseType + voorstelType is already the default, the previous default's `isDefault` SHALL be set to false

#### REQ-PRE-003-002: Link route to case type and voorstel type

- **GIVEN** the beheerder has created a parafeerroute with `voorstelType` = `collegeadvies` and `caseType` linked to "Omgevingsvergunning", with `isDefault` = true
- **WHEN** a steller creates a voorstel of type `collegeadvies` on a case of that case type
- **THEN** the linked route SHALL be pre-loaded as the default parafeerroute on the voorstel
- **AND** the steller SHALL be able to change the route before submission

#### REQ-PRE-003-003: Edit existing parafeerroute

- **GIVEN** the beheerder edits an existing parafeerroute that has no active voorstellen currently using it (no voorstel with status `in_parafering` referencing this route)
- **WHEN** the beheerder adds, removes, or reorders steps and saves
- **THEN** the route definition SHALL be updated in OpenRegister
- **AND** voorstellen already using this route (with a captured `routeSnapshot`) SHALL NOT be affected — they execute against their frozen snapshot
- **AND** new voorstellen created after the edit SHALL use the updated route definition

---

### REQ-PRE-004: Override Route on Specific Voorstel

The system SHALL allow authorized users (managers, secretariaat) to modify the parafeerroute on a specific in-flight voorstel: skip steps or add ad-hoc steps. A mandatory reason SHALL be recorded for skip actions. All overrides SHALL append entries to the voorstel's `auditTrail` and update the voorstel's `routeSnapshot`.

**Feature tier**: V1

#### REQ-PRE-004-001: Skip a step

- **GIVEN** an authorized manager views a voorstel currently at step 2, and step 3 has `mandatory` = false ("Adviseur vakinhoud")
- **WHEN** the manager clicks "Stap overslaan" on step 3 and provides the reason: "Vakinhoudelijk advies niet vereist voor dit type omgevingsvergunning"
- **THEN** step 3 SHALL be marked as `skipped` in the voorstel's `routeSnapshot`
- **AND** a `parafeeractie` SHALL be recorded: `step` = 3, `action` = `skipped`, `comment` = the reason, `actor` = manager's user UID
- **AND** the voorstel `auditTrail` SHALL include: "Stap overgeslagen: 'Adviseur vakinhoud' door [manager], reden: Vakinhoudelijk advies niet vereist voor dit type omgevingsvergunning"
- **AND** the skip action SHALL be blocked (with an error message) if the step has `mandatory` = true

#### REQ-PRE-004-002: Add ad-hoc step

- **GIVEN** the steller adds an ad-hoc advisory step "Financieel adviseur" between step 2 and step 3 on a specific in-flight voorstel
- **WHEN** the steller confirms the insertion with actor UID and step type `advies`
- **THEN** the voorstel `routeSnapshot` SHALL be updated: existing steps 3, 4, 5 become steps 4, 5, 6; new step 3 is the ad-hoc "Financieel adviseur" step
- **AND** the voorstel `auditTrail` SHALL include: "Stap toegevoegd: 'Financieel adviseur' door [user] na stap 2"
- **AND** if `currentStep` is already at or past the insertion point, the ad-hoc step SHALL be inserted immediately after the current step
- **AND** when the routing engine reaches the ad-hoc step, the actor SHALL receive a Nextcloud notification and task as for any regular step

---

## Dependencies

- OpenRegister: `parafeerroute`, `parafeeractie`, and `voorstel` object storage, relation management, audit trail
- NotificatieService (platform): Nextcloud in-app notifications per step activation, skip event, and geaccordeerd event
- TasksController (platform): Auto-create tasks for step actors linked to the parent case
- Admin Settings UI (existing): Embed `ParafeerRoutesTab` in the existing admin settings page

## Standards & References

- **ADR-000 (Data Model)**: `parafeerroute`, `parafeeractie`, `voorstel` entity definitions — properties MUST match exactly
- **ADR-001 (Data Layer)**: All persistence via `ObjectService` (3-arg API), no custom Entity/Mapper
- **ADR-004 (Frontend)**: Vue 2 Options API, `createObjectStore`, `@conduction/nextcloud-vue` imports only
- **ADR-015 (Common Patterns)**: SPDX headers, error handling, authorization checks, translation keys
- **CMMN 1.1**: HumanTask concept maps to parafering steps; sequential plan item execution with completion rules
- **BPMN 2.0**: Process model reference for sequential routing
- **Archiefwet**: Immutable audit trail required for skip and override actions on official voorstellen (legal accountability requirement)
