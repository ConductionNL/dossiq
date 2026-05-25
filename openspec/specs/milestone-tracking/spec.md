---
retrofit: true
---

# Milestone Tracking Specification

## Purpose

Translate technical workflow state into business-friendly milestone markers per case: configurable milestone definitions per case type, per-case progress aggregation (reached/total/percentage), explicit marking (with origin), reversal (with audit reason), and a placeholder hook for cross-case duration analytics.

## Requirements

### REQ-001: Milestone REST endpoints (progress / mark / reverse)

The system SHALL expose three `@NoAdminRequired` JSON endpoints on `MilestoneController`: `progress(caseId, caseTypeId)`, `mark(caseId, milestoneId)`, and `reverse(caseId, milestoneId)`. All three SHALL wrap service `\RuntimeException` as JSON envelopes; `progress` returns HTTP 500 on failure; `mark` and `reverse` return HTTP 400.

#### Scenario: Progress endpoint

- WHEN `progress(caseId, caseTypeId)` is called
- THEN it SHALL return the service's progress payload as JSON, or HTTP 500 `{error: <message>}` on `\RuntimeException`

#### Scenario: Reverse requires reason

- WHEN `reverse` is called with empty or whitespace-only `reason`
- THEN the controller SHALL return HTTP 400 `{error: 'Reason is required for milestone reversal'}` BEFORE calling the service

#### Scenario: User attribution

- WHEN `mark` or `reverse` is called
- THEN the controller SHALL resolve the user via `IUserSession::getUser()?->getUID()`, falling back to `'system'` for anonymous calls

### REQ-002: Milestone-progress aggregation by case type

The system SHALL fetch ordered milestone definitions for the case type, fetch all milestone records for the case, and return a payload of `{milestones: [...], reached: <int>, total: <int>, percentage: <int 0-100>}` where each milestone item carries `{identifier, label, order, description, reached: bool, reachedAt: ?datetime, reachedBy: ?userId}`.

#### Scenario: Empty milestone set

- WHEN the case type has no milestone definitions
- THEN the service SHALL return `{milestones: [], reached: 0, total: 0, percentage: 0}` and NOT attempt to fetch records

#### Scenario: Percentage rounding

- WHEN `total > 0`
- THEN `percentage` SHALL be `(int) round((reached / total) * 100)`

#### Scenario: Definition fields fallback

- WHEN a definition lacks `label`
- THEN the payload SHALL fall back to `name`, then empty string

### REQ-003: Mark milestone with trigger origin (manual/workflow/auto)

The system SHALL persist a milestone-record object with `{case, milestoneDefinition, reachedAt, reachedBy, trigger}` where `trigger` defaults to `'manual'` and accepts arbitrary origin strings (the controller currently always passes `'manual'`; service callers can pass `'workflow'` or `'auto'`).

#### Scenario: Persistence shape

- WHEN `markMilestone(caseId, milestoneDefinitionId, userId, trigger)` is called
- THEN the service SHALL persist `{case, milestoneDefinition, reachedAt: now, reachedBy: userId, trigger}` via OpenRegister and return `{id: <uuid>, reachedAt, reachedBy}`

#### Scenario: Schema-unconfigured guard

- WHEN OpenRegister is unavailable
- THEN the service SHALL throw `\RuntimeException('OpenRegister is not available')`
- AND when the milestone-record schema is unconfigured it SHALL throw `\RuntimeException('Milestone record schema not configured')`

### REQ-004: Reverse milestone with mandatory reason

The system SHALL allow reversing a previously-marked milestone by deleting every matching milestone-record (`case` + `milestoneDefinition`) and logging the reversal with the user id and reason. The controller layer SHALL guarantee `reason` is non-empty (REQ-001); the service SHALL accept it as a parameter for the audit log.

#### Scenario: No matching record

- WHEN no milestone records match `(case, milestoneDefinition)`
- THEN `reverseMilestone` SHALL return `false` without touching OpenRegister or logging a reversal

#### Scenario: Successful reversal

- WHEN one or more matching records exist
- THEN the service SHALL delete each, log `'Milestone reversed: <defId> on case <caseId> by <userId> reason: <reason>'`, and return `true`

#### Notes

- The audit reason is captured only in the log line; there is no per-reversal audit object persisted. Future hardening could persist a `MilestoneReversal` record alongside deletion.

### REQ-005: Duration analytics placeholder for case-type aggregations

The system SHALL expose a `getDurationAnalytics(caseTypeId)` method that, in the current implementation, returns a placeholder shape `{caseTypeId, phases: [], message: 'Duration analytics requires sufficient historical data'}` and emits a `debug`-level log entry.

#### Scenario: Placeholder shape

- WHEN `getDurationAnalytics(caseTypeId)` is called
- THEN the service SHALL log at debug and return the placeholder payload above

#### Notes

- The real aggregation is observed-but-stubbed (`// Placeholder: in production, this would aggregate milestone records across all cases of this type and calculate averages`). The signature is locked so future implementations don't break callers.
