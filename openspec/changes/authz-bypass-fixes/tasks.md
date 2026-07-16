# Tasks: authz-bypass-fixes

## 1. Hole 1 — port the advice guard to the LIVE path (before any deletion)
- [x] 1.1 `AdviceService`: add `assertAdviceTransitionAuthorized(array $advice, string $to): void` — fail-closed matrix per design D2 (`ontvangen` ⇒ `adviseur`; `aangevraagd` ⇒ `case.assignee`/`adviseur`; `verlopen` ⇒ system-only; unknown ⇒ deny). Uses the live `adviesAanvraag` fields, not the dead guard's `requestedBy`.
- [x] 1.2 `AdviceService`: split `transitionStatus()` into public guarded path + private `applyTransition()`; `transitionStatus()` asserts then applies.
- [x] 1.3 `AdviceService::expireAdvice()` calls `applyTransition()` directly (documented system/cron seam — no session; keeps `AdviceDeadlineJob` working).
- [x] 1.4 Tests: BAD path — a user who is neither `adviseur` nor case assignee nor admin is REJECTED on `transitionStatus(..., 'ontvangen')`; `verlopen` rejected over the HTTP path; `expireAdvice()` still succeeds with no session; adviseur + admin still allowed.

## 2. Hole 1 — remove the dead code (ONLY after 1.4 is green)
- [x] 2.1 Delete `submitAdvice()` (zero-caller, unrouted, wrong schema, invalid status) and `cancelAdvice()` (zero-caller, unrouted).
- [x] 2.2 Delete the now-unreferenced `assertAdviceCallerIsAuthorized()`. NOTE (verify-first): the `submitAdvice` hit in `lib/Settings/procest_register.json:4887` is a FALSE POSITIVE — it is a declarative `x-openregister-lifecycle` transition key on the unrelated `consultation` schema, not a reference to the PHP method, and carries no guard/class binding. Left untouched.
- [x] 2.3 Re-run suite; confirm no caller/test regression vs the pristine baseline test-name list.

## 3. Hole 2 — real per-case WOO guard, fail closed
- [ ] 3.1 New `lib/Service/CaseAccessGuard.php` (SPDX EUPL-1.2) — `assertCaseMutationAccess(string $caseId, IUser $user): void`; consumes OR `ObjectService` via `SettingsService` + `SearchesObjects` (ADR-022); deny on no-OR / not-found / non-assignee; allow admin + `case.assignee`.
- [ ] 3.2 `WOOAssessmentController`: inject `CaseAccessGuard`; rewrite `requireCaseMutationAccess()` to delegate to it. Remove the `procest-gebruikers` `groupExists()` short-circuit entirely.
- [ ] 3.3 Tests: BAD path — an authenticated non-assignee non-admin is REJECTED from **all 5** endpoints (`bulkAssess`, `extendDeadline`, `createDecision`, `publishDecision`, `withdrawPublication`), incl. statutory deadline extension.
- [ ] 3.4 Test: the **absent-group case does NOT grant access** (no `procest-gebruikers` anywhere ⇒ still rejected) — the exact fail-open being closed.
- [ ] 3.5 Test: assignee and admin are allowed (no functional regression).

## 4. Hole 3 — conflict of interest fails closed, hash-only identity
- [ ] 4.1 New `lib/Service/MedewerkerIdentityResolverInterface.php` (SPDX EUPL-1.2) — `bsnHashFor(string $userId): ?string`; ships dormant/unbound.
- [ ] 4.2 `ConflictOfInterestService`: stop reading `userBsn` from `$caseProperties`; resolve worker identity via the resolver; compare SHA-256 hashes; applicant-present + worker-unresolvable ⇒ `conflict=true, reason=identiteit_onbepaald`.
- [ ] 4.3 `MandaatMatrixController::probe()`: strip client-supplied identity keys from `$caseProperties`; repopulate `applicantBsn` server-side from the case object (`initiatorType === 'person'` ⇒ `initiatorSourceId`).
- [ ] 4.4 `MandaatCheckService`: treat a null `conflictService` as indeterminate (deny), not "skip" (design D5).
- [ ] 4.5 Tests: BAD path — a genuine conflict is DETECTED (not "no conflict"); an indeterminate identity is BLOCKED (not passed); client-supplied `userBsn` in the body cannot influence the outcome; no raw BSN is logged/returned.

## 5. Spec + i18n + quality
- [ ] 5.1 Spec delta: MODIFY `woo-publication-via-opencatalogi` "Publish action authorization" to drop `(when that group exists)` and require per-case fail-closed authorization.
- [ ] 5.2 i18n: EN keys + NL translations for any new user-facing authorization/conflict message.
- [ ] 5.3 `composer check:strict` (PHPCS/PHPMD/Psalm/PHPStan) green; fix any pre-existing issues touched.
- [ ] 5.4 Full unit suite green; report baseline (1551 tests) + delta with real output.
