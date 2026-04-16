# Proposal: Bezwaar Lifecycle

## Summary

Implement full backend enforcement and proactive notification for the 6-week statutory bezwaar processing deadline (Awb art. 7:10). A `BezwaarDeadlineService` sets `case.deadline` from `objection.receivedDate`, applies extension (verdaging) and suspension (opschorting) logic, and a background job sends Nextcloud notifications to handlers before the deadline expires.

## Problem

Dutch municipalities that receive bezwaarschriften are legally required (Awb art. 7:10) to decide within 6 weeks of receipt, with one possible extension of up to 6 weeks. Currently the deadline calculation logic exists only in the frontend store (`bezwaar.js`), meaning:

- The `case.deadline` field is never automatically set when an `objection` is recorded — handlers must set it manually.
- No proactive alerts reach case handlers when a deadline is approaching — they must check the dashboard.
- Verdaging and opschorting are not recorded on the `case` object so audit trails are incomplete.
- Overdue bezwaar cases are not surfaced via the Nextcloud notification system.

Municipalities with high bezwaar volumes risk fines, legal liability, and loss of institutional trust when deadlines are missed. 49 tender documents explicitly require automated deadline tracking for bezwaar procedures.

## Scope — MVP

**In scope:**

- `BezwaarDeadlineService`: calculates and sets `case.deadline` from `objection.receivedDate` + `caseType.processingDeadline`
- Verdaging (extension): updates `case.deadline`, increments `case.extensionCount`, records reason in case notes
- Opschorting (suspension): stores suspension start/end in `caseProperty`, excludes suspended days from deadline calculation
- `BezwaarDeadlineController`: REST endpoints for extend, suspend, resume actions
- `BezwaarDeadlineJob`: daily background job that sends Nextcloud notifications to case handlers for deadlines expiring within 7 days and for already-overdue cases
- `GET /api/bezwaar/overdue`: aggregate endpoint for the dashboard widget
- Seed data: 3–5 bezwaar case instances with objections and realistic deadlines (one overdue, one at-risk, one on-track, one extended, one suspended) for dev/test

**Out of scope:**

- Email/SMS notifications (Nextcloud in-app notifications only for MVP)
- Automatic case closure on deadline expiry (V1)
- DSO/ZGW deadline webhook emission (V1)
- Citizen-facing deadline visibility (V1)

## Dependencies

- OpenRegister `ObjectService` — `case`, `objection`, `caseType`, `caseProperty` entities (all already defined in ADR-000)
- `BezwaarDeadlineJob` extends `OCP\BackgroundJob\TimedJob`
- `INotificationManager` for Nextcloud notification dispatch
- `IGroupManager` for admin checks on mutation endpoints
- Existing `SeedBezwaarBeroepData` repair step — seed data added alongside existing case-type seed
