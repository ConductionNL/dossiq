---
retrofit_extensions:
  - REQ-101
  - REQ-102
  - REQ-103
---

# Parafering Actions — general-controller surface (retrofit)

## Requirements

### REQ-101: ParaferingController SHALL expose voorstel CRUD + per-action endpoints + audit trail

`OCA\Procest\Controller\ParaferingController` SHALL provide HTTP endpoints for: `createVoorstel()`, `startParafering($id)`, `paraferen($id)`, `terugsturen($id)`, `adviseren($id)`, and `auditTrail($id)`. The controller SHALL delegate all state mutation to `ParaferingService` and SHALL enforce that the calling user holds the role required by the current parafering step before executing an action.

#### Scenario: Adviseren by a user lacking the adviseur role
- **GIVEN** a voorstel in step `advies-juridisch` requiring role `adviseur`
- **WHEN** a user without that role calls `POST /api/paraferingen/{id}/adviseren`
- **THEN** the controller SHALL respond `403 Forbidden`

### REQ-102: ParaferingService SHALL implement voorstel lifecycle + action execution + step resolution

`OCA\Procest\Service\ParaferingService` SHALL provide the canonical lifecycle: `createVoorstel(...)`, `startParafering(...)`, `executeAction(...)`, `getCurrentStep(...)`, `getAuditTrail(...)`, and `overrideRoute(...)`. The service SHALL persist every action as an audit-trail entry attached to the voorstel and SHALL advance the parafering route after each successful action — except `terugsturen`, which SHALL rewind to the previous handler.

#### Scenario: Successful paraferen advances to next step
- **GIVEN** a voorstel at step S1 with successor S2
- **WHEN** `ParaferingService::executeAction($voorstel, 'paraferen', $user, $context)` succeeds
- **THEN** the voorstel's current step SHALL be S2 and an audit-trail entry SHALL record the paraferen action with user + timestamp

#### Scenario: Terugsturen rewinds to previous handler
- **GIVEN** a voorstel at step S2 returned from S1
- **WHEN** `terugsturen` is invoked with a comment
- **THEN** the voorstel SHALL move back to S1 and the comment SHALL be attached to both the rewind audit entry and the original S1 handler's notification

### REQ-103: ParaferingNotificationService SHALL emit step + reminder + completion notifications

`OCA\Procest\Service\ParaferingNotificationService` SHALL emit Nextcloud notifications: `notifyStepActivated()` when a new step's assigned handlers become responsible, `notifyVoorstelReturned()` when an upstream handler returns the voorstel for rework, and `notifyParaferingReminder()` on the BackgroundJob cadence for steps approaching their deadline. Notifications SHALL be deduplicated per (voorstel, step, type) so handlers do not see repeat noise within the same step.

#### Scenario: Reminder is sent once per step
- **GIVEN** a step approaching its deadline with no prior reminder
- **WHEN** `notifyParaferingReminder(...)` runs
- **THEN** the assigned handlers SHALL receive a notification once and subsequent runs within the same step SHALL be no-ops
