---
status: proposed
---
# bezwaar-lifecycle Specification

## Purpose

Implement backend enforcement and proactive notification for the 6-week statutory bezwaar processing deadline (Awb art. 7:10). A `BezwaarDeadlineService` automatically sets `case.deadline` from `objection.receivedDate`, applies extension (verdaging) and suspension (opschorting) logic per Awb, and a daily background job sends Nextcloud notifications to case handlers before deadlines expire.

## Context

Dutch municipalities are legally required (Awb art. 7:10) to decide on bezwaar within 6 weeks of receipt (12 weeks when an advisory committee is involved), with a one-time extension of up to 6 weeks. Deadline calculation was previously performed only in frontend store logic, leaving `case.deadline` unpopulated unless set manually. 49 tender documents (demand score 147) explicitly require automated deadline tracking. This change moves deadline enforcement entirely to the backend, making it reliable, auditable, and capable of sending proactive notifications.

All entities used here are defined in ADR-000 and already present in `procest_register.json`: `case`, `caseType`, `objection`, `caseProperty`.

## Requirements

### REQ-BZL-001: Automatic Deadline Calculation

When an objection is created or updated, the system MUST automatically calculate and set the bezwaar processing deadline on the linked case.

#### Scenario 1.1: Deadline set on objection creation

- GIVEN a bezwaar case `zaak-bz-001` linked to caseType `bezwaar` (processingDeadline: `P6W`)
- AND the case has `extensionCount: 0`
- WHEN a new `objection` is saved with `case: zaak-bz-001` and `receivedDate: 2026-04-01`
- THEN `case.deadline` MUST be set to `2026-05-13` (6 weeks after receivedDate)
- AND an OpenRegister audit trail entry MUST be created recording the deadline calculation

#### Scenario 1.2: Deadline derived from caseType processingDeadline (12-week committee track)

- GIVEN a bezwaar case linked to caseType `bezwaar-commissie` (processingDeadline: `P12W`)
- WHEN an objection with `receivedDate: 2026-04-01` is saved
- THEN `case.deadline` MUST be set to `2026-06-24` (12 weeks after receivedDate)

#### Scenario 1.3: Extended deadline is not overwritten on objection update

- GIVEN bezwaar case `zaak-bz-001` with `extensionCount: 1` and deadline already extended
- WHEN the `objection` record for that case is updated (e.g. attachments added)
- THEN `case.deadline` MUST NOT be recalculated — the extended deadline takes precedence
- AND the response MUST confirm deadline was unchanged

#### Scenario 1.4: Timeliness of objection assessed (Awb art. 6:7)

- GIVEN a contested decision with `decisionDate: 2026-03-01`
- WHEN an objection with `receivedDate: 2026-04-15` is saved (45 days after decision — beyond 6-week term)
- THEN `objection.isTimely` MUST be set to `false`
- AND `objection.timelinessAssessment` MUST contain the calculated interval and legal basis (Awb art. 6:7)

#### Scenario 1.5: Timely objection assessment

- GIVEN a decision with `decisionDate: 2026-03-01`
- WHEN an objection with `receivedDate: 2026-03-20` is saved (19 days — within 6-week term)
- THEN `objection.isTimely` MUST be set to `true`
- AND `objection.timelinessAssessment` MUST record the interval

### REQ-BZL-002: Verdaging (Deadline Extension)

Authorized users MUST be able to extend the bezwaar deadline once, by up to the period defined in `caseType.extensionPeriod`, per Awb art. 7:10 lid 3.

#### Scenario 2.1: Apply extension to an on-track case

- GIVEN bezwaar case `zaak-bz-001` with `deadline: 2026-05-13`, `extensionCount: 0`
- AND caseType `bezwaar` with `extensionAllowed: true` and `extensionPeriod: P6W`
- WHEN an authenticated admin POSTs `/api/bezwaar/zaak-bz-001/deadline/extend` with body `{ "reason": "Complexe zaak vereist nader onderzoek" }`
- THEN `case.deadline` MUST be updated to `2026-06-24` (original deadline + 6 weeks)
- AND `case.extensionCount` MUST be incremented to 1
- AND a note MUST be appended to the case notes: "Verdaging toegepast: Complexe zaak vereist nader onderzoek (2026-04-16)"
- AND the response body MUST return the updated case with the new deadline

#### Scenario 2.2: Extension rejected when already applied once

- GIVEN bezwaar case `zaak-bz-002` with `extensionCount: 1`
- WHEN an admin POSTs `/api/bezwaar/zaak-bz-002/deadline/extend`
- THEN the response MUST return HTTP 422 Unprocessable Entity
- AND the response body MUST contain: `{ "message": "Verdaging kan slechts eenmaal worden toegepast (Awb art. 7:10 lid 3)" }`
- AND `case.deadline` and `case.extensionCount` MUST remain unchanged

#### Scenario 2.3: Extension rejected when caseType does not allow it

- GIVEN a bezwaar case linked to caseType with `extensionAllowed: false`
- WHEN an admin POSTs the extend endpoint
- THEN the response MUST return HTTP 422
- AND the response body MUST state that extension is not permitted for this case type

#### Scenario 2.4: Extension requires admin authorization

- GIVEN any bezwaar case
- WHEN a non-admin Nextcloud user POSTs `/api/bezwaar/{caseId}/deadline/extend`
- THEN the response MUST return HTTP 403 Forbidden
- AND no changes MUST be made to the case

#### Scenario 2.5: Extension rejected during active suspension

- GIVEN bezwaar case `zaak-bz-001` with an active suspension (bezwaar_suspension_start set, bezwaar_suspension_end not set)
- WHEN an admin attempts to extend the deadline
- THEN the response MUST return HTTP 422
- AND the response body MUST state: `{ "message": "Verdaging kan niet worden toegepast tijdens een actieve opschorting" }`

### REQ-BZL-003: Opschorting (Suspension)

The system MUST support suspension of the deadline clock during periods awaiting information from the bezwaarmaker, per Awb art. 7:10 lid 4.

#### Scenario 3.1: Start suspension

- GIVEN bezwaar case `zaak-bz-001` with `deadline: 2026-05-13` and no active suspension
- WHEN an admin POSTs `/api/bezwaar/zaak-bz-001/deadline/suspend` with body `{ "reason": "Wacht op nadere stukken van bezwaarmaker", "startDate": "2026-04-16" }`
- THEN a `caseProperty` record MUST be created with propertyDefinition slug `bezwaar_suspension_start` and value `2026-04-16`
- AND a case note MUST be appended: "Opschorting gestart: Wacht op nadere stukken van bezwaarmaker (2026-04-16)"
- AND `case.deadline` MUST NOT change until the suspension is resumed
- AND the response MUST confirm the suspension started

#### Scenario 3.2: Resume suspension and recalculate deadline

- GIVEN bezwaar case `zaak-bz-001` with active suspension from `2026-04-16` and original `deadline: 2026-05-13`
- WHEN an admin POSTs `/api/bezwaar/zaak-bz-001/deadline/resume` with body `{ "endDate": "2026-04-25" }`
- THEN a `caseProperty` record MUST be created with slug `bezwaar_suspension_end` and value `2026-04-25`
- AND the suspension duration MUST be calculated as 9 calendar days
- AND `case.deadline` MUST be updated to `2026-05-22` (original deadline + 9 suspended days)
- AND a case note MUST be appended: "Opschorting beëindigd, deadline herberekend naar 2026-05-22"

#### Scenario 3.3: Cannot resume without active suspension

- GIVEN bezwaar case `zaak-bz-001` with no active suspension
- WHEN an admin POSTs `/api/bezwaar/zaak-bz-001/deadline/resume`
- THEN the response MUST return HTTP 422
- AND the response body MUST state: `{ "message": "Geen actieve opschorting gevonden voor deze zaak" }`

#### Scenario 3.4: Cannot start second suspension while one is active

- GIVEN bezwaar case `zaak-bz-001` with an active suspension
- WHEN an admin POSTs `/api/bezwaar/zaak-bz-001/deadline/suspend`
- THEN the response MUST return HTTP 422
- AND the response body MUST state: `{ "message": "Er is al een actieve opschorting voor deze zaak" }`

### REQ-BZL-004: Proactive Deadline Notifications

Case handlers MUST receive Nextcloud in-app notifications when a bezwaar deadline is approaching or already overdue.

#### Scenario 4.1: Notification for approaching deadline

- GIVEN open bezwaar case `zaak-bz-atrisk` assigned to user `behandelaar` with `deadline: 2026-04-23`
- AND today is `2026-04-16` (7 days before deadline)
- AND no notification was sent for this case today
- WHEN `BezwaarDeadlineJob` runs
- THEN a Nextcloud notification MUST be sent to the case assignee (`behandelaar`)
- AND the notification subject MUST include the case title and deadline date
- AND the notification link MUST point to the case detail page

#### Scenario 4.2: Notification for overdue case

- GIVEN open bezwaar case `zaak-bz-overdue` with `deadline: 2026-04-01` and status not final
- AND today is `2026-04-16`
- AND no notification was sent today
- WHEN `BezwaarDeadlineJob` runs
- THEN a Nextcloud notification MUST be sent to the assignee
- AND the notification MUST indicate the case is overdue and by how many days (15 days)

#### Scenario 4.3: No duplicate notifications within same day

- GIVEN `BezwaarDeadlineJob` already ran and notified the handler for `zaak-bz-atrisk` today
- WHEN the job runs again the same day
- THEN NO additional notification MUST be sent for `zaak-bz-atrisk`

#### Scenario 4.4: No notification for closed (final-status) cases

- GIVEN bezwaar case `zaak-bz-closed` with a statusType where `isFinal: true` and past deadline
- WHEN `BezwaarDeadlineJob` runs
- THEN NO notification MUST be sent for this case

#### Scenario 4.5: No notification when case has no assignee

- GIVEN bezwaar case `zaak-bz-unassigned` with `assignee: null` and approaching deadline
- WHEN `BezwaarDeadlineJob` runs
- THEN NO notification MUST be sent (no recipient can be determined)
- AND the case MUST still appear in the overdue/at-risk API response

### REQ-BZL-005: Overdue Case Dashboard Feed

The system MUST provide a REST endpoint listing overdue and at-risk bezwaar cases for dashboard widget consumption.

#### Scenario 5.1: List overdue and at-risk cases

- GIVEN multiple open bezwaar cases with varied deadline states
- WHEN an authenticated user GETs `/api/bezwaar/overdue?withinDays=7`
- THEN the response MUST include all open bezwaar cases where:
  - `case.deadline < today` (overdue), OR
  - `case.deadline` is within 7 days of today (at-risk)
- AND cases with an active suspension (bezwaar_suspension_end is null) MUST be excluded
- AND cases with a final-status statusType MUST be excluded
- AND each entry MUST include: case UUID, title, deadline, assignee, days overdue or remaining
- AND cases MUST be sorted: overdue first (most-overdue first), then at-risk (soonest deadline first)

#### Scenario 5.2: Pagination

- GIVEN 50 overdue bezwaar cases
- WHEN an authenticated user GETs `/api/bezwaar/overdue?limit=10&page=2`
- THEN the response MUST contain exactly 10 cases
- AND the response MUST include `total`, `page`, and `pages` fields per ADR-002

#### Scenario 5.3: withinDays parameter controls the at-risk window

- GIVEN 3 cases with deadlines: 3 days away, 8 days away, 30 days away
- WHEN the user GETs `/api/bezwaar/overdue?withinDays=7`
- THEN only the case with 3 days remaining MUST appear in the response (8-day and 30-day are outside the window)
