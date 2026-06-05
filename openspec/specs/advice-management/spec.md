---
retrofit_extensions:
  - REQ-001
  - REQ-002
  - REQ-003
---

## Purpose

## Advice Page Render (UI surface)

### Requirement: Advice index page render

The Advice (adviezen) index page (`CnIndexPage`, route `/advice`) SHALL mount and
render its stable list shell on navigation — the Cards/Table view toggle, an "Add"
create button, a per-row "Actions" control, and an empty-state message when no
advice requests are visible — independently of whether the OpenRegister collection
returns rows.

#### Scenario: Advice index page renders list shell
- **GIVEN** an authenticated user on the Procest app
- **WHEN** they navigate to the Advice page
- **THEN** the Cards/Table view-mode toggle MUST be visible
- **AND** an "Add" create button MUST be visible
- **AND** the page MUST NOT show an Internal Server Error

## ADDED Requirements

### Requirement: Advice request schema

@e2e exclude Advice request schema is V1 backend; covered by PHPUnit + OpenRegister schema validation, not a UI surface.

The system SHALL store advice requests as `adviesAanvraag` objects in OpenRegister supporting internal and external advice lifecycle with deadline tracking.

**Feature tier**: V1
**ZGW mapping**: Custom extension (extends Zaak with advice tracking)
**Schema.org**: schema:AskAction (request), schema:InformAction (response)

#### Scenario: Create internal advice request

- **WHEN** the behandelaar clicks "Advies aanvragen" on a case and selects an internal adviseur (e.g., welstandscommissie)
- **THEN** the system SHALL create an `adviesAanvraag` with: case (reference), adviseur (user UID), type="intern", onderwerp (string), deadline (date), status="aangevraagd", requestedAt (datetime)
- **THEN** a task SHALL be created for the adviseur: "Advies uitbrengen voor [case identifier]"
- **THEN** the case timeline SHALL record: "Advies aangevraagd bij [adviseur]"

#### Scenario: Create external advice request

- **WHEN** the behandelaar requests advice from an external party (e.g., Veiligheidsregio)
- **THEN** the system SHALL create an `adviesAanvraag` with type="extern" and the external organization name as adviseur
- **THEN** a reminder notification SHALL be generated 3 days before the deadline
- **THEN** overdue advice SHALL trigger an escalation notification to behandelaar and teamleider

#### Scenario: Receive and process advice

- **WHEN** the adviseur uploads an advies document and marks the request as completed
- **THEN** the adviesAanvraag status SHALL change to "ontvangen" and receivedAt SHALL be set
- **THEN** the advies document SHALL be linked to the case via a document link
- **THEN** the behandelaar SHALL be notified: "Advies ontvangen voor [case identifier]"

#### Scenario: Advice timeout

- **WHEN** an adviesAanvraag deadline passes without response
- **THEN** the status SHALL change to "verlopen"
- **THEN** a task SHALL be created for the behandelaar: "Advies verlopen: beoordeel of vergunningprocedure kan doorgaan zonder dit advies"

### Requirement: Advice panel on case dashboard

@e2e exclude In-case-detail panel requires a seeded case with advice requests; data-dependent panel not testable without pre-seeded case context.

The system SHALL display an advice panel on the case dashboard showing all advice requests with their status and deadlines.

**Feature tier**: V1

#### Scenario: Display advice overview

- **WHEN** a user views the case dashboard for a case with 3 adviesAanvragen
- **THEN** the "Adviezen" panel SHALL show all 3 with: adviseur name, type badge (intern/extern), status badge (aangevraagd=blue, ontvangen=green, verlopen=red), deadline date
- **THEN** overdue advice SHALL be highlighted in red with days overdue count

#### Scenario: Quick actions on advice panel

- **WHEN** the behandelaar views the advice panel
- **THEN** each request SHALL have quick actions: "Herinnering sturen" (for pending), "Bekijk advies" (for received), "Markeer als ontvangen" (for pending with document uploaded)

### Requirement: Advice request form

@e2e exclude In-case-detail create form requires a seeded case context to open; data-dependent form not testable without pre-seeded case.

The system SHALL provide a form for creating advice requests from the case dashboard.

**Feature tier**: V1

#### Scenario: Create advice request form

- **WHEN** the behandelaar clicks "Advies aanvragen" on the case dashboard
- **THEN** a dialog SHALL appear with fields: adviseur (user selector for intern, text input for extern), type (intern/extern toggle), onderwerp (text), deadline (date picker, default: 2 weeks from now), specific questions (text area)
- **THEN** the form SHALL validate that adviseur and deadline are filled before submission

#### Scenario: Advice guard on workflow transition

- **WHEN** a workflow transition has a guard requiring "all advice received"
- **THEN** the transition SHALL be blocked if any adviesAanvraag has status "aangevraagd"
- **THEN** the guard violation message SHALL list the pending advice requests: "[adviseur]: advies verwacht voor [deadline]"

<!-- BEGIN retrofit-2026-05-24-advice-management -->

## Controller + Service + Deadline Job (retrofit)

### REQ-001: AdviceController SHALL expose advice transition + reminder endpoints

@e2e exclude Backend PHP controller endpoints; covered by Newman API tests + PHPUnit, not a UI surface.

`OCA\Procest\Controller\AdviceController` SHALL provide `POST /api/advice/{id}/transition` (transition the advice between statuses with optional payload) and `POST /api/advice/{id}/reminder` (manually dispatch a reminder to the assigned adviseur). Each endpoint SHALL delegate to `AdviceService` and SHALL enforce that the calling user has authority on the parent case.

#### Scenario: Manual reminder dispatch
- **WHEN** a behandelaar calls `POST /api/advice/{id}/reminder`
- **THEN** the controller SHALL call `AdviceService::dispatchReminder($id)` and respond with the dispatch outcome

### REQ-002: AdviceService SHALL implement the full advice lifecycle + workflow guard

@e2e exclude Backend PHP service + workflow guard; lifecycle logic covered by PHPUnit, not a UI surface.

`OCA\Procest\Service\AdviceService` SHALL provide `transitionStatus()`, `dispatchReminder()`, `getAdviceForCase()`, `getOpenAdvice()`, `expireAdvice()`, and `applyWorkflowGuard()`. The workflow guard SHALL block parent-case status transitions while open advice requests are still pending for that case — releasing only when all advice is `received`, `withdrawn`, or `expired`. Status transitions SHALL be append-only audit-trailed.

#### Scenario: Workflow guard blocks case completion while advice pending
- **GIVEN** a case with one advice request at status `requested`
- **WHEN** `AdviceService::applyWorkflowGuard($caseId)` is evaluated for a "complete case" transition
- **THEN** the guard SHALL block the transition with the reason "Open advice request — awaiting reply"

#### Scenario: Expire overdue advice
- **WHEN** `AdviceService::expireAdvice($adviceId)` is called for an advice whose deadline has passed
- **THEN** the advice status SHALL transition to `expired` and the parent case workflow guard SHALL release this dependency

### REQ-003: AdviceDeadlineJob SHALL send reminders and auto-expire overdue advice

@e2e exclude Backend BackgroundJob; reminder/expiry idempotency covered by PHPUnit, not a UI surface.

`OCA\Procest\BackgroundJob\AdviceDeadlineJob` SHALL run on the Nextcloud BackgroundJob schedule and: (a) dispatch reminders to assigned adviseurs at the configured thresholds before the deadline, (b) call `AdviceService::expireAdvice()` on requests whose deadline has passed without response. The job SHALL be idempotent — duplicate runs SHALL NOT send duplicate reminders for the same threshold.

#### Scenario: Reminder is sent once per threshold
- **GIVEN** an advice with deadline 3 days away and a 3-day reminder configured
- **WHEN** `AdviceDeadlineJob::run()` runs twice within the same threshold window
- **THEN** the reminder SHALL be dispatched only once

<!-- END retrofit-2026-05-24-advice-management -->
