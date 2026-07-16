# authz-bypass-fixes Specification

Status: done

## Purpose
Close three live authorization bypasses in procest — advice IDOR on the
unguarded live transition path, the WOO per-case guard that fails open on an
absent group, and the belangenconflict check that unconditionally reports "no
conflict". Every requirement below is stated so that the BAD path is the thing
under test: a guard that cannot be proven to reject is not a guard.

## Requirements

### Requirement: Advice status transitions are authorized on the live path
The system MUST authorize every advice status transition performed through the
live HTTP path (`POST /api/advice/{id}/transition` →
`AdviceService::transitionStatus()`). Authorization MUST fail closed: any
transition whose caller relationship cannot be established MUST be rejected.

#### Scenario: Unrelated user cannot mark another user's advice as received
- **GIVEN** an advice request whose `adviseur` is `alice` and whose `case` is
  assigned to `bob`
- **AND** an authenticated non-admin user `mallory`
- **WHEN** `AdviceService::transitionStatus(adviceId, 'ontvangen')` is called as
  `mallory`
- **THEN** the system MUST reject the transition
- **AND** it MUST NOT write any update to the advice request

#### Scenario: The assigned adviseur may mark their advice received
- **GIVEN** an advice request whose `adviseur` is `alice`
- **WHEN** `AdviceService::transitionStatus(adviceId, 'ontvangen')` is called as
  `alice`
- **THEN** the system MUST allow the transition

#### Scenario: The expiry transition is not reachable over HTTP
- **GIVEN** any authenticated non-admin user
- **WHEN** `AdviceService::transitionStatus(adviceId, 'verlopen')` is called
- **THEN** the system MUST reject the transition, because `verlopen` is a
  system-only transition owned by the deadline cron

#### Scenario: The deadline cron can still expire advice without a session
- **GIVEN** no authenticated user session (background job context)
- **WHEN** `AdviceService::expireAdvice(adviceId)` is called
- **THEN** the advice request MUST be updated to status `verlopen`
- **AND** the absence of a user session MUST NOT cause a rejection

#### Scenario: An unknown target status is rejected
- **GIVEN** an authenticated user
- **WHEN** `transitionStatus()` is called with a status outside
  `aangevraagd|ontvangen|verlopen`
- **THEN** the system MUST reject the transition

### Requirement: The dead advice-guard code path is removed
The system MUST NOT retain zero-caller advice mutation methods that carry a
duplicate authorization guard, because a guard on unreachable code is
indistinguishable from an implemented control and conceals the unguarded live
path.

#### Scenario: submitAdvice and cancelAdvice no longer exist
- **GIVEN** the procest codebase after this change
- **WHEN** `AdviceService` is inspected
- **THEN** `submitAdvice()` and `cancelAdvice()` MUST NOT be present
- **AND** the live `transitionStatus()` path MUST carry the authorization guard
  instead

### Requirement: WOO case mutation requires per-case authorization that fails closed
The system MUST authorize every WOO case mutation endpoint against the caller's
actual relationship to the case. Authorization MUST NOT depend on the existence
of any Nextcloud group, and MUST deny when the case or OpenRegister cannot be
resolved.

#### Scenario: Authenticated non-assignee is rejected from every WOO mutation endpoint
- **GIVEN** a WOO case whose `assignee` is `alice`
- **AND** an authenticated non-admin user `mallory`
- **WHEN** `mallory` calls `bulkAssess`, `extendDeadline`, `createDecision`,
  `publishDecision`, or `withdrawPublication` for that case
- **THEN** each call MUST be rejected with a forbidden response

#### Scenario: Statutory deadline extension is rejected for an unrelated user
- **GIVEN** a WOO case whose `assignee` is `alice`
- **AND** an authenticated non-admin user `mallory`
- **WHEN** `mallory` calls `extendDeadline` for that case
- **THEN** the system MUST reject the call, because extending a statutory WOO
  term is a case-worker action

#### Scenario: An absent authorization group does not grant access
- **GIVEN** the `procest-gebruikers` group does not exist on the instance
- **AND** an authenticated non-admin user who is not the case assignee
- **WHEN** that user calls any WOO case mutation endpoint
- **THEN** the system MUST reject the call
- **AND** the absence of the group MUST NOT be treated as authorization

#### Scenario: OpenRegister unavailable denies rather than skips the check
- **GIVEN** OpenRegister is not available
- **WHEN** an authenticated non-admin user calls a WOO case mutation endpoint
- **THEN** the system MUST reject the call rather than proceed unchecked

#### Scenario: The case assignee is authorized
- **GIVEN** a WOO case whose `assignee` is `alice`
- **WHEN** `alice` calls a WOO case mutation endpoint for that case
- **THEN** the system MUST allow the call

### Requirement: Conflict-of-interest detection fails closed and never trusts client identity
The system MUST NOT determine belangenconflict identity from client-supplied
request data, and MUST NOT report "no conflict" when the check cannot be
performed.

#### Scenario: A genuine conflict is detected
- **GIVEN** a case whose applicant is a natural person with a BSN
- **AND** a case worker whose resolved identity hash equals the applicant's
- **WHEN** `ConflictOfInterestService::checkConflict()` is called
- **THEN** it MUST return `conflict = true` with reason `self`

#### Scenario: Indeterminate identity blocks rather than passes
- **GIVEN** a case whose applicant is a natural person with a BSN
- **AND** no bound identity resolver, so the case worker's identity cannot be
  resolved
- **WHEN** `ConflictOfInterestService::checkConflict()` is called
- **THEN** it MUST return `conflict = true` with reason `identiteit_onbepaald`
- **AND** it MUST NOT return `conflict = false`

#### Scenario: Client-supplied identity cannot influence the outcome
- **GIVEN** a request body containing `caseProperties.userBsn`
- **WHEN** `MandaatMatrixController::probe()` handles the request
- **THEN** the client-supplied identity MUST be discarded
- **AND** the applicant identity MUST be sourced from the case object

#### Scenario: A case with no natural-person applicant has no conflict
- **GIVEN** a case whose `initiatorType` is not `person`
- **WHEN** `checkConflict()` is called
- **THEN** it MUST return `conflict = false`, because there is no applicant
  identity to conflict with

#### Scenario: A missing conflict service denies rather than skips
- **GIVEN** `MandaatCheckService` has no bound `ConflictOfInterestService`
- **WHEN** `isAuthorized()` is called
- **THEN** the conflict check MUST be treated as indeterminate and deny, rather
  than silently skipped

#### Scenario: BSN values are never logged or returned
- **GIVEN** any `checkConflict()` invocation
- **WHEN** the check completes
- **THEN** no raw BSN MUST appear in the returned payload or in any log record
- **AND** identity comparison MUST use SHA-256 hashes

## MODIFIED Requirements

### Requirement: Publish action authorization
(modifies `openspec/specs/woo-publication-via-opencatalogi/spec.md`)

The publish and withdraw endpoints MUST enforce per-case mutation authorization
that fails closed. The previous wording required rejection only for a non-member
of the `procest-gebruikers` group **"(when that group exists)"** — which
specified a fail-open control: the group never existed, so the guard
short-circuited and every authenticated user was authorized. Group existence
MUST play no part in the authorization decision.

#### Scenario: Unauthenticated request is rejected
- **GIVEN** no authenticated user session
- **WHEN** `POST /api/cases/{id}/woo/publish` is called
- **THEN** the system MUST return 401 Unauthorized

#### Scenario: Authenticated non-authorized user is rejected
- **GIVEN** an authenticated user who is not an admin and is not the case
  assignee
- **WHEN** `POST /api/cases/{id}/woo/publish` is called
- **THEN** the system MUST reject with a forbidden response, via
  `CaseAccessGuard::assertCaseMutationAccess()`
- **AND** rejection MUST NOT depend on whether any group exists
