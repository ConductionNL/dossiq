---
retrofit_extensions:
  - REQ-001
  - REQ-002
  - REQ-003
---

# Advice Management — controller + service + deadline job (retrofit)

## Requirements

### REQ-001: AdviceController SHALL expose advice transition + reminder endpoints

`OCA\Procest\Controller\AdviceController` SHALL provide `POST /api/advice/{id}/transition` (transition the advice between statuses with optional payload) and `POST /api/advice/{id}/reminder` (manually dispatch a reminder to the assigned adviseur). Each endpoint SHALL delegate to `AdviceService` and SHALL enforce that the calling user has authority on the parent case.

#### Scenario: Manual reminder dispatch
- **WHEN** a behandelaar calls `POST /api/advice/{id}/reminder`
- **THEN** the controller SHALL call `AdviceService::dispatchReminder($id)` and respond with the dispatch outcome

### REQ-002: AdviceService SHALL implement the full advice lifecycle + workflow guard

`OCA\Procest\Service\AdviceService` SHALL provide `transitionStatus()`, `dispatchReminder()`, `getAdviceForCase()`, `getOpenAdvice()`, `expireAdvice()`, and `applyWorkflowGuard()`. The workflow guard SHALL block parent-case status transitions while open advice requests are still pending for that case — releasing only when all advice is `received`, `withdrawn`, or `expired`. Status transitions SHALL be append-only audit-trailed.

#### Scenario: Workflow guard blocks case completion while advice pending
- **GIVEN** a case with one advice request at status `requested`
- **WHEN** `AdviceService::applyWorkflowGuard($caseId)` is evaluated for a "complete case" transition
- **THEN** the guard SHALL block the transition with the reason "Open advice request — awaiting reply"

#### Scenario: Expire overdue advice
- **WHEN** `AdviceService::expireAdvice($adviceId)` is called for an advice whose deadline has passed
- **THEN** the advice status SHALL transition to `expired` and the parent case workflow guard SHALL release this dependency

### REQ-003: AdviceDeadlineJob SHALL send reminders and auto-expire overdue advice

`OCA\Procest\BackgroundJob\AdviceDeadlineJob` SHALL run on the Nextcloud BackgroundJob schedule and: (a) dispatch reminders to assigned adviseurs at the configured thresholds before the deadline, (b) call `AdviceService::expireAdvice()` on requests whose deadline has passed without response. The job SHALL be idempotent — duplicate runs SHALL NOT send duplicate reminders for the same threshold.

#### Scenario: Reminder is sent once per threshold
- **GIVEN** an advice with deadline 3 days away and a 3-day reminder configured
- **WHEN** `AdviceDeadlineJob::run()` runs twice within the same threshold window
- **THEN** the reminder SHALL be dispatched only once
