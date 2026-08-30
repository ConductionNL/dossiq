---
status: retired
retired_in: dossiq-adopt-or-abstractions
canonical_home: case-management/spec.md
retrofit_extensions:
  - REQ-101
  - REQ-102
  - REQ-103
---

> **RETIRED — see `case-management/spec.md`.**
>
> Action-specific guards and audit recording moved to the consolidated
> `x-openregister-lifecycle` annotation on the case schema. Per-transition
> role-based authorization (advies, parafering, accordering) lives in the
> annotation's `transitions[].roles` array. Audit recording flows through
> OR's `audit-trail-immutable` capability. See ADR-022, ADR-023.
>
> This file is preserved as a historical appendix. Refer to
> `case-management/spec.md` for the canonical lifecycle annotation.

## Purpose

@e2e exclude RETIRED spec; requirements consolidated into case-management/spec.md.

## Requirements

### Requirement: Parafeeractie Schema Registration

The system SHALL register a `parafeeractie` schema in the Dossiq OpenRegister configuration with properties: voorstel (reference), step (integer), actor (string, user UID), actorType (enum: user, delegate), onBehalfOf (string, optional user UID), action (enum: parafered, returned, advised, skipped), comment (string, optional), advice (string, optional for advisory steps), timestamp (datetime), mandate (string, optional mandate reference).

**Feature tier**: V1
**Schema.org type**: `schema:Action`
**ZGW mapping**: No direct equivalent; contributes to besluit audit trail
**CMMN concept**: HumanTask completion event

#### Scenario: Schema is available after app install

- **WHEN** the Dossiq app is installed or updated
- **THEN** the `parafeeractie` schema SHALL be registered in the Dossiq register via the repair step
- **AND** the schema SHALL enforce required properties: voorstel, step, actor, action, timestamp

### Requirement: Paraferen Action (Approve)

The system SHALL allow the active actor at the current step to paraferen (endorse) the voorstel, advancing it to the next step.

**Feature tier**: V1

#### Scenario: Successful parafering

- **WHEN** the parafeerder "Jan de Vries" clicks "Paraferen" on a voorstel at step "Teamleider"
- **THEN** the system SHALL create a parafeeractie with action "parafered", actor "jan.devries", timestamp now
- **AND** the voorstel SHALL advance to the next step
- **AND** the next actor SHALL receive a Nextcloud notification
- **AND** Jan SHALL NOT be able to paraferen again on this voorstel at this step

#### Scenario: Only active actor can paraferen

- **WHEN** a user who is NOT the active actor at the current step attempts to paraferen
- **THEN** the system SHALL reject the action
- **AND** the "Paraferen" button SHALL NOT be visible to non-active users

### Requirement: Terugsturen Action (Return with Comments)

The system SHALL allow the active actor to return the voorstel to the steller with a mandatory comment explaining the reason.

**Feature tier**: V1

#### Scenario: Return voorstel with comment

- **WHEN** the afdelingshoofd clicks "Terugsturen" with comment "Financiele paragraaf ontbreekt"
- **THEN** the system SHALL create a parafeeractie with action "returned" and the comment
- **AND** the voorstel status SHALL change to "teruggestuurd"
- **AND** the steller SHALL receive a notification: "Voorstel teruggestuurd door [actor]: [comment]"

#### Scenario: Comment is mandatory for return

- **WHEN** the actor clicks "Terugsturen" without entering a comment
- **THEN** the system SHALL prevent the submission
- **AND** the comment field SHALL show a validation error: "Reden is verplicht bij terugsturen"

#### Scenario: Resubmit after return

- **WHEN** the steller edits the document on a returned voorstel and clicks "Opnieuw indienen"
- **THEN** the voorstel status SHALL change back to "in_parafering"
- **AND** the currentStep SHALL be set to the step that returned it (resume from that step)
- **AND** the returning actor SHALL be notified of the resubmission

### Requirement: Adviseren Action (Non-binding Opinion)

The system SHALL allow actors at advisory steps to submit non-binding advice. Advisory steps advance automatically after advice is submitted.

**Feature tier**: V1

#### Scenario: Submit advice

- **WHEN** the adviseur submits advice: "Akkoord, mits bouwkosten worden gecontroleerd"
- **THEN** the system SHALL create a parafeeractie with action "advised" and the advice text
- **AND** the voorstel SHALL advance to the next step
- **AND** the advice SHALL be visible to the steller and subsequent parafeerders on the voorstel detail

#### Scenario: Advisory step button label

- **WHEN** the current step type is "advies"
- **THEN** the action button SHALL display "Adviseren" instead of "Paraferen"

### Requirement: Paraferen Namens (On Behalf Of)

The system SHALL support delegation where a user with a configured mandate can paraferen on behalf of another user.

**Feature tier**: V1

#### Scenario: Delegate parafering

- **WHEN** secretaresse Bakker has a mandate to paraferen on behalf of wethouder Van Dam
- **AND** Bakker opens the voorstel task assigned to Van Dam
- **THEN** Bakker SHALL see an option "Paraferen namens Van Dam"
- **AND** the parafeeractie SHALL record: actorType "delegate", actor "bakker", onBehalfOf "vandam", mandate reference

#### Scenario: Delegation in audit trail

- **WHEN** a delegate parafering is recorded
- **THEN** the audit trail SHALL clearly display: "Geparafeerd door [delegate] namens [principal]"

### Requirement: Besluit Registration from Voorstel

The system SHALL support registering a formal besluit (decision) when the college has decided on a voorstel. This uses the existing `decision` schema and `BrcController`.

**Feature tier**: V1

#### Scenario: Manual besluit registration

- **WHEN** the secretariaat clicks "Besluit registreren" on a voorstel with status "geaccordeerd" or "aangeboden"
- **AND** enters: besluit tekst, ingangsdatum, besluittype
- **THEN** a decision object SHALL be created via the existing decision schema
- **AND** the voorstel status SHALL change to "besloten"
- **AND** the case activity timeline SHALL show: "Besluit vastgesteld: [tekst]"

#### Scenario: No RIS connector configured

- **WHEN** no RIS connector is configured in the system
- **THEN** the "Aanbieden aan RIS" button SHALL NOT be displayed
- **AND** a "Markeer als besloten" button SHALL allow manual besluit registration

<!-- BEGIN retrofit-2026-05-24-parafering-actions-impl -->

**Implementation Surface (retrofit — general controller)**

### Requirement: ParaferingController SHALL expose voorstel CRUD + per-action endpoints + audit trail

`OCA\Dossiq\Controller\ParaferingController` SHALL provide HTTP endpoints for: `createVoorstel()`, `startParafering($id)`, `paraferen($id)`, `terugsturen($id)`, `adviseren($id)`, and `auditTrail($id)`. The controller SHALL delegate all state mutation to `ParaferingService` and SHALL enforce that the calling user holds the role required by the current parafering step before executing an action.

#### Scenario: Adviseren by a user lacking the adviseur role
- **GIVEN** a voorstel in step `advies-juridisch` requiring role `adviseur`
- **WHEN** a user without that role calls `POST /api/paraferingen/{id}/adviseren`
- **THEN** the controller SHALL respond `403 Forbidden`

### Requirement: ParaferingService SHALL implement voorstel lifecycle + action execution + step resolution

`OCA\Dossiq\Service\ParaferingService` SHALL provide the canonical lifecycle: `createVoorstel(...)`, `startParafering(...)`, `executeAction(...)`, `getCurrentStep(...)`, `getAuditTrail(...)`, and `overrideRoute(...)`. The service SHALL persist every action as an audit-trail entry attached to the voorstel and SHALL advance the parafering route after each successful action — except `terugsturen`, which SHALL rewind to the previous handler.

#### Scenario: Successful paraferen advances to next step
- **GIVEN** a voorstel at step S1 with successor S2
- **WHEN** `ParaferingService::executeAction($voorstel, 'paraferen', $user, $context)` succeeds
- **THEN** the voorstel's current step SHALL be S2 and an audit-trail entry SHALL record the paraferen action with user + timestamp

#### Scenario: Terugsturen rewinds to previous handler
- **GIVEN** a voorstel at step S2 returned from S1
- **WHEN** `terugsturen` is invoked with a comment
- **THEN** the voorstel SHALL move back to S1 and the comment SHALL be attached to both the rewind audit entry and the original S1 handler's notification

### Requirement: ParaferingNotificationService SHALL emit step + reminder + completion notifications

`OCA\Dossiq\Service\ParaferingNotificationService` SHALL emit Nextcloud notifications: `notifyStepActivated()` when a new step's assigned handlers become responsible, `notifyVoorstelReturned()` when an upstream handler returns the voorstel for rework, and `notifyParaferingReminder()` on the BackgroundJob cadence for steps approaching their deadline. Notifications SHALL be deduplicated per (voorstel, step, type) so handlers do not see repeat noise within the same step.

#### Scenario: Reminder is sent once per step
- **GIVEN** a step approaching its deadline with no prior reminder
- **WHEN** `notifyParaferingReminder(...)` runs
- **THEN** the assigned handlers SHALL receive a notification once and subsequent runs within the same step SHALL be no-ops

<!-- END retrofit-2026-05-24-parafering-actions-impl -->
