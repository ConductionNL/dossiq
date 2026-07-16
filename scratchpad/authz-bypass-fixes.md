# procest: authz-bypass-fixes

Tracked on procest#223 (done-spec audit umbrella). Base: `origin/development` @ eeba95e63.
Worktree: /home/rubenlinde/wave2-worktrees/procest-authz

## Verify-first verdicts (against HEAD, not the audit's word)

### Hole 1 — AdviceService IDOR — **CONFIRMED LIVE (audit correct)**
- `submitAdvice` @ lib/Service/AdviceService.php:426 — **ZERO callers**. Grep across php/vue/js:
  only its own definition (426, 487) + a `procest_register.json:4887` reference. Not routed.
  (`AdviceController` has no `submitAdvice` method; `appinfo/routes.php` has no `advice#submitAdvice`.)
- Guard `assertAdviceCallerIsAuthorized` @ :565 is called ONLY from :444 (submitAdvice) and :516 (cancelAdvice).
- **LIVE path**: `appinfo/routes.php:314` `advice#transitionStatus` → `AdviceController::transitionStatus:89`
  → `AdviceService::transitionStatus:103`. Body read in full: **no authorization check whatsoever**.
- Extra drift found: dead `submitAdvice` writes `status => 'received'`, which is not in
  `VALID_STATUSES` (`aangevraagd|ontvangen|verlopen`) — further proof it never ran.
- ⇒ procest#17's IDOR fix landed on dead code. The IDOR is **still open**. PORT the guard first.

### Hole 2 — WOO authz short-circuit — **CONFIRMED LIVE + WORSE THAN TRIAGED**
- `requireCaseMutationAccess` @ lib/Controller/WOOAssessmentController.php:283.
  Guard body: `if (groupExists('procest-gebruikers') === true && isInGroup(...) === false) throw`.
  Group absent ⇒ `&&` short-circuits ⇒ **no throw ⇒ access granted**. Fails OPEN.
- `procest-gebruikers` appears NOWHERE else in code — only this file (294/297/298) + two spec .md
  files. No group creation, no register.d, no Application.php, no info.xml. Group does not exist.
- **Audit said 3 endpoints; there are 5** `#[NoAdminRequired]` endpoints all calling the broken guard:
  `bulkAssess:98`, `extendDeadline:135` (statutory deadline), `createDecision:168`,
  `publishDecision:211`, `withdrawPublication:245`. All at :105/:142/:175/:218/:252.
- Second defect: even with the group present, the check is group-membership only — **not per-case**.
  Any case worker could mutate ANY case. Needs real per-object RBAC (ADR-022 → OpenRegister).

### Hole 3 — ConflictOfInterestService — **CONFIRMED LIVE + WORSE THAN TRIAGED**
- `checkConflict` @ lib/Service/ConflictOfInterestService.php:105 gates on
  `$caseProperties['userBsn']`; `if ($userBsn === '' || $applicantBsn === '') return ['conflict' => false];`
  ⇒ absent identity reports **"no conflict"**. Fails OPEN.
- `userBsn` grep: ONLY tests + this service. **No production code populates it.**
- Live chain: `appinfo/routes.php:586` `mandaatMatrix#probe` → `MandaatMatrixController::probe:117`
  → `MandaatCheckService::isAuthorized:82` → `checkConflict:93`.
- **NEW finding (worse):** `MandaatMatrixController:110` does
  `$caseProps = (array) ($body['caseProperties'] ?? [])` — **client-supplied request body**.
  So `userBsn` is attacker-controlled: a caller simply omits it to force `conflict=false`.
  Fix must resolve identity SERVER-SIDE, never from the body, and fail CLOSED when indeterminate.
- BSN = special-category data (AVG art. 9). Existing convention in-file @ :180 already hashes:
  `substr(hash('sha256', $applicantBsn), 0, 16)`. Team convention = BSN hash-only ⇒ compare hashes.
- Note: `MandaatCheckService::__construct` takes `?ConflictOfInterestService $conflictService=null`
  and `isAuthorized:91` guards `if ($this->conflictService !== null)` — null service = check skipped
  entirely (a second fail-open). No explicit DI registration in Application.php (NC autowires).

## Baseline
(pending)
