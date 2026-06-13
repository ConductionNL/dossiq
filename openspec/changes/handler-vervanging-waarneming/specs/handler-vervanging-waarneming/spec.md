---
status: done
---

# Spec: handler-vervanging-waarneming

**Status:** done
**Scope:** procest
**Depends on:** my-work, case-management, task-management, openregister (RBAC + notification engine, per ADR-022)
**Related:** mandaat-matrix (decision-authority waarneming — explicitly out of scope here)

## Purpose

Workload continuity when a case handler is absent (vervanging/waarneming) and when a handler
permanently leaves (bulk reassignment). A waarnemer sees and works the absent handler's cases,
tasks, and deadline signals for a bounded period, every action taken in that capacity is
audit-trailed as "namens", and a coordinator can transfer all open work of a departing handler
in one previewed, audited batch. Substitution is domain logic on top of OpenRegister RBAC and
grants no permissions of its own.

> **Implementation residue (2026-06-13):** the substitution record/schema,
> resolution + RBAC-scoped routing, capacity-stamp service + query, bulk
> reassignment (preview/execute/audit/digest), all controllers/routes, and the
> full UI (user settings, coordinator admin, bulk-reassign modal, My Work badge
> + filter) are built and tested. Two cross-app items are deferred: (a) fanning
> deadline/signalering notifications out to the active waarnemer for the
> duration — the substitute is notified on registration and the resolution
> primitive exists, but wiring the termijnbewaking dispatch to the substitute
> is owned by the OR notification engine (notification-leaf follow-up); and
> (b) threading `stampIfSubstituted()` into every existing case/task mutation
> call-site — the stamp service + endpoints exist and are tested, but the
> retrofit across all mutation paths is a cross-cutting follow-up.

## ADDED Requirements

### Requirement: Substitution records MUST be first-class OpenRegister objects

The system SHALL store vervanging/waarneming registrations as objects of a dedicated `substitution` schema in OpenRegister (no app-local tables), capturing absentee, substitute, period, scope, reason, and status.

#### Scenario: Handler registers their own substitution

- **GIVEN** handler Jan is logged in and opens the Vervanging section of his user settings
- **WHEN** he registers Marieke as waarnemer from 2026-07-01 through 2026-07-21 with scope `all` and reason `verlof`
- **THEN** a `substitution` object MUST be created with `absentee = jan`, `substitute = marieke`, `status = active`, `createdBy = jan`
- **AND** Marieke MUST receive a notification that she has been registered as waarnemer for Jan for that period

#### Scenario: Coordinator registers a substitution on behalf of an absent handler

- **GIVEN** handler Jan is unexpectedly ill and a user with the procest coordinator role opens the substitution admin view
- **WHEN** the coordinator registers Marieke as Jan's waarnemer starting today with reason `ziekte`
- **THEN** the substitution MUST be created with `createdBy` set to the coordinator's user id
- **AND** both Jan and Marieke MUST be notified of the registration

#### Scenario: Self-substitution is rejected

- **WHEN** a substitution is submitted with the same user as absentee and substitute
- **THEN** the request MUST be rejected with a validation error and no object created

#### Scenario: Overlapping full-scope substitutions are rejected

- **GIVEN** an active substitution covering Jan with scope `all` from 2026-07-01 through 2026-07-21
- **WHEN** a second substitution for Jan with scope `all` and an overlapping period is submitted
- **THEN** the request MUST be rejected with a validation error naming the conflicting substitution
- **AND** a second substitution with a disjoint scope (e.g. `caseTypes` limited to bezwaar) for the same period MUST be accepted

#### Scenario: Period validation

- **WHEN** a substitution is submitted with `endDate` before `startDate`, or without an `endDate`
- **THEN** the request MUST be rejected with a validation error

### Requirement: Active substitution MUST route the absent handler's workload to the waarnemer

While a substitution is active (status `active` and today within the period), the substitute's My Work view SHALL additionally contain the absentee's open cases and tasks within the substitution scope, visually marked as substituted, and deadline/signalering notifications for those items SHALL additionally be delivered to the substitute.

#### Scenario: Waarnemer sees substituted work in My Work

- **GIVEN** an active substitution where Marieke covers Jan with scope `all`
- **WHEN** Marieke opens My Work
- **THEN** she MUST see her own cases and tasks unchanged
- **AND** additionally Jan's open cases and tasks, each marked "waargenomen voor Jan"
- **AND** the substituted items MUST be filterable (show/hide substituted work)

#### Scenario: Scope-limited substitution only routes matching items

- **GIVEN** an active substitution where Marieke covers Jan with scope `caseTypes` limited to the bezwaar case type
- **WHEN** Marieke opens My Work
- **THEN** she MUST see Jan's open bezwaar cases and their tasks
- **AND** she MUST NOT see Jan's cases of other case types

#### Scenario: Deadline signals fan out to the waarnemer

- **GIVEN** an active substitution where Marieke covers Jan
- **WHEN** a deadline warning (streef- or fatale termijn) fires for one of Jan's substituted cases
- **THEN** the notification MUST be delivered to Marieke in addition to Jan
- **AND** Jan's own notification delivery MUST be unchanged

#### Scenario: Routing stops automatically when the period ends

- **GIVEN** a substitution where Marieke covers Jan through 2026-07-21
- **WHEN** Marieke opens My Work on 2026-07-22
- **THEN** Jan's items MUST no longer appear in her werkvoorraad
- **AND** no deadline notifications for Jan's cases are delivered to her from that date
- **AND** the substitution's status MUST resolve to `ended`

#### Scenario: Revocation takes effect immediately

- **GIVEN** an active substitution where Marieke covers Jan
- **WHEN** Jan or a coordinator revokes the substitution
- **THEN** the substitution status MUST become `revoked`
- **AND** Jan's items MUST disappear from Marieke's werkvoorraad on the next load

### Requirement: Actions performed under substitution MUST carry a capacity audit trail

Every mutation a substitute performs on a case or task that is in their werkvoorraad by virtue of an active substitution SHALL record the acting user, the absentee on whose behalf they acted, and the substitution id, and SHALL be rendered in the case timeline as acting "namens".

#### Scenario: Timeline shows the substituted capacity

- **GIVEN** an active substitution where Marieke covers Jan
- **WHEN** Marieke completes a task on one of Jan's substituted cases
- **THEN** the case timeline entry MUST read that Marieke performed the action "namens Jan (waarneming)"
- **AND** the underlying audit metadata MUST contain `actedOnBehalfOf = jan` and the `substitutionId`

#### Scenario: Actions on own work are not capacity-stamped

- **GIVEN** the same active substitution
- **WHEN** Marieke updates one of her own cases
- **THEN** the audit entry MUST NOT contain `actedOnBehalfOf`

#### Scenario: All actions under a substitution are queryable

- **GIVEN** a substitution that was active during July
- **WHEN** a coordinator opens that substitution's detail view
- **THEN** they MUST see a chronological list of every capacity-stamped action performed under it (case, action, timestamp)

### Requirement: Substitution MUST NOT bypass OpenRegister RBAC

Substitution SHALL grant no permissions. Resolved substituted work items SHALL be filtered through the substitute's own OpenRegister RBAC effective permissions, and direct object access SHALL remain enforced by OR RBAC unchanged.

#### Scenario: Items the substitute cannot read are excluded

- **GIVEN** an active substitution where Marieke covers Jan with scope `all`
- **AND** one of Jan's cases is confidential to a group Marieke is not a member of
- **WHEN** Marieke opens My Work
- **THEN** that case MUST NOT appear in her substituted werkvoorraad
- **AND** a direct request for that case by Marieke MUST be denied by OR RBAC exactly as without the substitution

#### Scenario: Substitution confers no write elevation

- **GIVEN** an active substitution where Marieke covers Jan, and Marieke has read-only OR RBAC access to a substituted case
- **WHEN** Marieke attempts to update that case
- **THEN** the update MUST be rejected by OR RBAC

### Requirement: Coordinators MUST be able to bulk-reassign a handler's open work

A user with the procest coordinator role SHALL be able to permanently transfer all open cases and tasks of one handler to another in a single operation, with a mandatory preview, optional case-type filter, per-item audit entries sharing a batch id, and a single digest notification to the receiving handler.

#### Scenario: Preview before execution

- **GIVEN** handler Jan is leaving the organisation with 34 open cases and 12 open tasks
- **WHEN** a coordinator starts a bulk reassignment from Jan and requests the preview
- **THEN** the system MUST list all 34 open cases and 12 open tasks with title, case type, status, and next deadline
- **AND** no data is mutated by the preview

#### Scenario: Execute full reassignment

- **GIVEN** the preview above
- **WHEN** the coordinator confirms reassignment of everything to Pieter
- **THEN** every listed open case and task MUST have its handler/assignee set to Pieter
- **AND** each item MUST receive an audit entry recording previous handler, new handler, the acting coordinator, and a shared batch id
- **AND** Pieter MUST receive a single digest notification summarising the transfer
- **AND** closed and archived cases of Jan MUST be untouched

#### Scenario: Filtered partial reassignment

- **WHEN** the coordinator restricts the reassignment to the VTH case type before executing
- **THEN** only Jan's open VTH cases and their tasks are transferred
- **AND** Jan's other open work is unchanged

#### Scenario: Bulk reassignment is coordinator-only

- **GIVEN** a user without the procest coordinator role
- **WHEN** they attempt to invoke the reassignment preview or execute endpoint
- **THEN** the request MUST be denied

#### Scenario: Partial failure is reported per item

- **GIVEN** a bulk reassignment where one case update fails (e.g. concurrent modification)
- **WHEN** the batch completes
- **THEN** the response MUST report per-item success/failure
- **AND** failed items MUST remain assigned to the original handler and be re-runnable
