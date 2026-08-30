# authz-bypass-fixes Specification

Status: done

## Purpose
Close three live authorization bypasses in dossiq — advice IDOR on the
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

@e2e exclude Backend authorisation boundary with no browser surface; covered by `AdviceServiceAuthorizationTest::testUnrelatedUserIsRejectedFromMarkingAdviceReceived`.

- **GIVEN** an advice request whose `adviseur` is `alice` and whose `case` is
  assigned to `bob`
- **AND** an authenticated non-admin user `mallory`
- **WHEN** `AdviceService::transitionStatus(adviceId, 'ontvangen')` is called as
  `mallory`
- **THEN** the system MUST reject the transition
- **AND** it MUST NOT write any update to the advice request

#### Scenario: The assigned adviseur may mark their advice received

@e2e exclude Backend authorisation boundary with no browser surface; covered by `AdviceServiceAuthorizationTest::testAssignedAdviseurMayMarkAdviceReceived`.

- **GIVEN** an advice request whose `adviseur` is `alice`
- **WHEN** `AdviceService::transitionStatus(adviceId, 'ontvangen')` is called as
  `alice`
- **THEN** the system MUST allow the transition

#### Scenario: The expiry transition is not reachable over HTTP

@e2e exclude Backend authorisation boundary with no browser surface; covered by `AdviceServiceAuthorizationTest::testExpiryTransitionIsRejectedOverTheHttpPath`.

- **GIVEN** any authenticated non-admin user
- **WHEN** `AdviceService::transitionStatus(adviceId, 'verlopen')` is called
- **THEN** the system MUST reject the transition, because `verlopen` is a
  system-only transition owned by the deadline cron

#### Scenario: The deadline cron can still expire advice without a session

@e2e exclude Background-job path with no user session; not reachable from a browser at all. Covered by `AdviceServiceAuthorizationTest::testExpireAdviceStillWorksWithoutASession`.

- **GIVEN** no authenticated user session (background job context)
- **WHEN** `AdviceService::expireAdvice(adviceId)` is called
- **THEN** the advice request MUST be updated to status `verlopen`
- **AND** the absence of a user session MUST NOT cause a rejection

#### Scenario: An unknown target status is rejected

@e2e exclude Input-validation boundary the UI cannot express (the browser only ever offers valid statuses); covered by `AdviceServiceAuthorizationTest::testInvalidStatusIsRejected`.

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

@e2e exclude Code-absence requirement: there is no runtime surface to exercise, because the assertion IS that the methods do not exist. Verified by grep over `lib/` (0 hits; the search is live — it still matches the schema key in `lib/Settings/dossiq_register.json` and the docblock in `AdviceServiceAuthorizationTest`).

- **GIVEN** the dossiq codebase after this change
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

@e2e exclude Backend authorisation boundary; expressing it in a browser needs a second logged-in session per endpoint. Covered by `WOOAssessmentControllerAuthorizationTest::testAuthenticatedNonAssigneeIsRejectedFromEveryMutationEndpoint`.

- **GIVEN** a WOO case whose `assignee` is `alice`
- **AND** an authenticated non-admin user `mallory`
- **WHEN** `mallory` calls `bulkAssess`, `extendDeadline`, `createDecision`,
  `publishDecision`, or `withdrawPublication` for that case
- **THEN** each call MUST be rejected with a forbidden response

#### Scenario: Statutory deadline extension is rejected for an unrelated user

@e2e exclude Backend authorisation boundary; covered by `WOOAssessmentControllerAuthorizationTest::testStatutoryDeadlineExtensionIsRejectedForUnrelatedUser`.

- **GIVEN** a WOO case whose `assignee` is `alice`
- **AND** an authenticated non-admin user `mallory`
- **WHEN** `mallory` calls `extendDeadline` for that case
- **THEN** the system MUST reject the call, because extending a statutory WOO
  term is a case-worker action

#### Scenario: An absent authorization group does not grant access

@e2e exclude The precondition is the ABSENCE of a Nextcloud group, which a browser test cannot establish without provisioning the instance. Covered by `WOOAssessmentControllerAuthorizationTest::testAbsentAuthorizationGroupDoesNotGrantAccess`.

- **GIVEN** the `procest-gebruikers` group does not exist on the instance
- **AND** an authenticated non-admin user who is not the case assignee
- **WHEN** that user calls any WOO case mutation endpoint
- **THEN** the system MUST reject the call
- **AND** the absence of the group MUST NOT be treated as authorization

#### Scenario: OpenRegister unavailable denies rather than skips the check

@e2e exclude The precondition is an absent collaborator app; not expressible in a browser against a working instance. Covered by `WOOAssessmentControllerAuthorizationTest::testOpenRegisterUnavailableDeniesRatherThanSkips`.

- **GIVEN** OpenRegister is not available
- **WHEN** an authenticated non-admin user calls a WOO case mutation endpoint
- **THEN** the system MUST reject the call rather than proceed unchecked

#### Scenario: The case assignee is authorized

@e2e exclude Positive arm of the same backend boundary; covered by `WOOAssessmentControllerAuthorizationTest::testCaseAssigneeIsAuthorized`.

- **GIVEN** a WOO case whose `assignee` is `alice`
- **WHEN** `alice` calls a WOO case mutation endpoint for that case
- **THEN** the system MUST allow the call

### Requirement: Conflict-of-interest detection fails closed and never trusts client identity
The system MUST NOT determine belangenconflict identity from client-supplied
request data, and MUST NOT report "no conflict" when the check cannot be
performed.

#### Scenario: A genuine conflict is detected

@e2e exclude Belangenconflict detection is a backend decision over BRP identity data; the browser only ever sees the resulting refusal. Covered by `ConflictOfInterestServiceTest::testSelfDetected` and `::testRelationshipDetected`.

- **GIVEN** a case whose applicant is a natural person with a BSN
- **AND** a case worker whose resolved identity hash equals the applicant's
- **WHEN** `ConflictOfInterestService::checkConflict()` is called
- **THEN** it MUST return `conflict = true` with reason `self`

#### Scenario: Indeterminate identity blocks rather than passes

@e2e exclude Fail-closed branch reached only when identity resolution is unavailable; not reproducible from a browser. Covered by `ConflictOfInterestServiceTest::testIndeterminateIdentityBlocksRatherThanPasses`, `::testUnresolvableIdentityBlocks` and `::testThrowingResolverBlocks`.

- **GIVEN** a case whose applicant is a natural person with a BSN
- **AND** no bound identity resolver, so the case worker's identity cannot be
  resolved
- **WHEN** `ConflictOfInterestService::checkConflict()` is called
- **THEN** it MUST return `conflict = true` with reason `identiteit_onbepaald`
- **AND** it MUST NOT return `conflict = false`

#### Scenario: Client-supplied identity cannot influence the outcome

@e2e exclude The scenario requires forging a request field the UI never sends, so a browser test cannot construct it. Covered by `ConflictOfInterestServiceTest::testClientSuppliedUserBsnIsIgnored`.

- **GIVEN** a request body containing `caseProperties.userBsn`
- **WHEN** `MandaatMatrixController::probe()` handles the request
- **THEN** the client-supplied identity MUST be discarded
- **AND** the applicant identity MUST be sourced from the case object

#### Scenario: A case with no natural-person applicant has no conflict

@e2e exclude Negative arm of the backend conflict decision; covered by `ConflictOfInterestServiceTest::testNoConflictWithoutApplicantIdentity`.

- **GIVEN** a case whose `initiatorType` is not `person`
- **WHEN** `checkConflict()` is called
- **THEN** it MUST return `conflict = false`, because there is no applicant
  identity to conflict with

#### Scenario: A missing conflict service denies rather than skips

@e2e exclude The precondition is an unbound collaborator, which a browser cannot create. Covered by `MandaatCheckServiceTest::testMissingConflictServiceDeniesRatherThanSkips` — added 2026-08-12, because this was the one requirement in this spec with no coverage to point at, and it is covered rather than waived. That test carries its own positive control: the identical call authorizes with the service bound.

- **GIVEN** `MandaatCheckService` has no bound `ConflictOfInterestService`
- **WHEN** `isAuthorized()` is called
- **THEN** the conflict check MUST be treated as indeterminate and deny, rather
  than silently skipped

#### Scenario: BSN values are never logged or returned

@e2e exclude Asserts the ABSENCE of a value from a payload and from log records; a browser assertion over a response it never receives proves nothing. Covered by `ConflictOfInterestServiceTest::testNoRawBsnIsReturned`.

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

@e2e exclude Authentication boundary enforced by Nextcloud middleware ahead of the controller; covered by `AdviceServiceAuthorizationTest::testUnauthenticatedCallerIsRejected` and, live, by the unauthenticated arm of the two-account probe recorded on PR #805 (HTTP 401).

- **GIVEN** no authenticated user session
- **WHEN** `POST /api/cases/{id}/woo/publish` is called
- **THEN** the system MUST return 401 Unauthorized

#### Scenario: Authenticated non-authorized user is rejected

@e2e exclude Backend authorisation boundary needing a second logged-in session; covered by `WOOAssessmentControllerAuthorizationTest::testAuthenticatedNonAssigneeIsRejectedFromEveryMutationEndpoint`, which drives the publish and withdraw endpoints named by this requirement.

- **GIVEN** an authenticated user who is not an admin and is not the case
  assignee
- **WHEN** `POST /api/cases/{id}/woo/publish` is called
- **THEN** the system MUST reject with a forbidden response, via
  `CaseAccessGuard::assertCaseMutationAccess()`
- **AND** rejection MUST NOT depend on whether any group exists

## ADDED Requirements (2026-08-11, gate-7 re-audit)

The `.github#365` re-audit established that gate-7 accepts a `401` authentication
preamble as an authorisation guard, so its `0` for dossiq was never a verdict.
Hand-verification found 33 `#[NoAdminRequired]` endpoints that take an object or
subject identifier off the request and act on it with no per-object
authorisation. The requirements below are stated, as in the rest of this spec, so
that the BAD path is the thing under test.

### Requirement: Citizen-identifier lookups require a klantcontactcentrum role

The system MUST authorise every endpoint that resolves a caller-supplied citizen
identifier (`burgerId`) into that citizen's data. Authentication MUST NOT be
accepted as authorisation. Authorisation MUST fail closed: the absence of every
recognised role grants nothing.

These endpoints have no per-object owner — a KCC handler legitimately answers a
call from a citizen they have never handled — so the control is a role, not a
per-case relationship. `CaseAccessGuard` therefore does not apply and
`CitizenLookupGuard` MUST be used instead.

#### Scenario: An authenticated account with no KCC role cannot read a citizen's voorblad

@e2e exclude Backend authorisation boundary; the browser surface renders the same
service through the same controller and cannot express "a second account with no
role" without a second logged-in session. Covered by
`ContactMomentControllerAuthorizationTest` (7 cases, proven able to fail by
inverting the guard predicate) and by a two-account live probe printing status
codes.

- **GIVEN** a citizen identified as `BSN-999999999` with an open case and a
  logged contactmoment
- **AND** an authenticated non-admin user who is in none of the KCC groups
- **WHEN** that user calls `GET /api/kcc/voorblad?burgerId=BSN-999999999`
- **THEN** the system MUST return a forbidden response
- **AND** `CaseVoorbladService::getCaseVoorblad()` MUST NOT be called

#### Scenario: A KCC handler still gets the voorblad

@e2e exclude Same boundary as above; the positive arm exists so the guard cannot
be satisfied by refusing everyone.

- **GIVEN** an authenticated user who is a member of a KCC group
- **WHEN** that user calls `GET /api/kcc/voorblad?burgerId=BSN-999999999`
- **THEN** the system MUST return the voorblad for that citizen

#### Scenario: The same exposure through the contact-history door is refused

@e2e exclude Backend authorisation boundary; covered by
`ContactMomentControllerAuthorizationTest::testContactHistoryIsRefusedWithoutKccRole`.

- **GIVEN** an authenticated non-admin user who is in none of the KCC groups
- **WHEN** that user calls `GET /api/contactmomenten?burgerId=…`
- **THEN** the system MUST return a forbidden response
- **AND** `ContactMomentService::listForBurger()` MUST NOT be called

#### Scenario: Writing against an arbitrary citizen is refused

@e2e exclude Backend authorisation boundary; covered by
`ContactMomentControllerAuthorizationTest` (create, nieuweZaak,
klachtRegistreren), each asserting the write collaborator is never reached.

- **GIVEN** an authenticated non-admin user who is in none of the KCC groups
- **WHEN** that user calls `POST /api/contactmomenten`,
  `POST /api/kcc/quick-actions/nieuwe-zaak`, or
  `POST /api/kcc/quick-actions/klacht-registreren`
- **THEN** each call MUST return a forbidden response
- **AND** no contactmoment, case, or klacht MUST be written

### Requirement: The AI decision trail is scoped to a case the caller works on

The system MUST NOT serve an unscoped listing of AI decision records, and MUST
authorise the scoped listing against the caller's relationship to the case.
Omitting the scope MUST be rejected rather than silently widened.

#### Scenario: Omitting caseId no longer dumps every AI decision on the instance

@e2e exclude Backend authorisation boundary; covered by
`AiControllerAuditIndexTest::testAuditIndexRefusesUnscopedDump`, which asserts
`AiAuditService::listAuditEntries()` is never called.

- **GIVEN** an authenticated user
- **WHEN** `GET /api/ai/audit` is called with no `caseId`
- **THEN** the system MUST reject the request
- **AND** MUST NOT query the audit store

#### Scenario: A user who does not work on the case cannot read its AI decisions

@e2e exclude Backend authorisation boundary; covered by
`AiControllerAuditIndexTest::testAuditIndexRejectsUserWithoutCaseAccess`.

- **GIVEN** a case whose `assignee` is `alice`
- **AND** an authenticated non-admin user `mallory`
- **WHEN** `mallory` calls `GET /api/ai/audit?caseId=<that case>`
- **THEN** the system MUST return a forbidden response
- **AND** MUST NOT query the audit store

### Requirement: Case read access is authorised by a guard that fails closed

The system MUST authorise per-case READ endpoints against the caller's real
relationship to the case (`assignee`, membership of `assignees`, or Nextcloud
admin). It MUST NOT delegate that decision to
`Sharing\CaseAccessPolicy::canUserAccessCase()`, which returns TRUE when
OpenRegister is absent, when the case schema is unconfigured, and when the
lookup throws — three fail-open branches that would make the guard grant access
precisely when it cannot evaluate it.

#### Scenario: An unresolvable case denies rather than skips

@e2e exclude Failure-mode of a backend collaborator; not expressible in a
browser. Covered by `CaseAccessGuardReadAccessTest`.

- **GIVEN** OpenRegister is unavailable, or the case schema is unconfigured, or
  the case cannot be loaded
- **WHEN** `CaseAccessGuard::hasCaseReadAccess()` is called
- **THEN** it MUST return false

#### Scenario: A member of the assignees array may read the case

@e2e exclude Backend predicate; covered by `CaseAccessGuardReadAccessTest`.

- **GIVEN** a case whose `assignee` is `alice` and whose `assignees` array
  contains `bob`
- **WHEN** `CaseAccessGuard::hasCaseReadAccess()` is called as `bob`
- **THEN** it MUST return true
- **AND** for an unrelated user `mallory` it MUST return false

### Requirement: Tenant middleware must not fatal on the multi-tenant path

The system MUST NOT call methods that do not exist on the Nextcloud request
object. `TenantMiddleware::beforeController()` called
`IRequest::setParameter()`, which exists on no Nextcloud request class, so every
request from a user who HAD a tenant died as an unhandled `Error` and Nextcloud
answered HTTP 500 with an HTML page. Single-tenant installs returned earlier and
never reached it, so no test and no e2e rig exercised the failing path.

#### Scenario: A request from a user with an active tenant reaches its controller

@e2e exclude Requires a provisioned OR Organisation bound to a user, which the
e2e rig does not seed. Verified live on a rig where the tenant existed: the same
request returned 500 before the fix and 200/403 after, with the status code
printed.

- **GIVEN** an authenticated user bound to an active tenant
- **WHEN** any non-exempt Dossiq endpoint is called
- **THEN** the middleware MUST NOT raise
- **AND** the controller's own response MUST be returned
