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

## Additional defects found while verifying (NOT in the audit)
- **Dead guard targeted the WRONG SCHEMA.** `assertAdviceCallerIsAuthorized` reads `requestedBy`,
  which does not exist on the live `adviesAanvraag` schema (`case, adviseur, type, onderwerp,
  deadline, status, adviesDocument, requestedAt, receivedAt, questions`). It exists only on the
  unused `adviceRequest` schema. Independent proof the guard never executed.
- **Dead `submitAdvice` writes an invalid status** (`'received'` ∉ `VALID_STATUSES`).
- **`cancelAdvice:500` is ALSO dead** (zero callers, unrouted) — so BOTH callers of the procest#17
  guard were dead. The entire guard subsystem was unreachable.
- **`procest_register.json:4887` `submitAdvice` is a FALSE POSITIVE** — a declarative
  `x-openregister-lifecycle` transition key on the unrelated `consultation` schema, no guard/class
  binding. Left untouched.
- **`MandaatCheckService:91`** `if ($this->conflictService !== null)` — a second latent fail-open
  (null service = check skipped). Fixed (design D5).
- **No AdviceService tests existed at all** — which is exactly why procest#17's dead fix went
  unnoticed. The 6 pre-existing `WOOAssessmentControllerTest` tests only ever stubbed
  `isAdmin => true`, so the WOO fail-open was never exercised either.
- **`composer phpstan` ends in `|| echo 'skipping'`** → PHPStan never fails the build. 135
  pre-existing errors on development. Out of scope; noted.

## Baseline + delta (REAL output, php:8.3-cli container `procest-phpunit-83:local`)
- Fresh `composer install` in a clean worktree; `vendor/nextcloud/ocp/OCP` resolves (no dangling-
  symlink trap). Pre-existing composer.json quirk: `config.platform.php = 8.2.22` vs `require
  php ^8.3` → installed with `--ignore-platform-req=php` (env workaround, not a code change).
- **BASELINE (origin/development eeba95e63): `Tests: 1551, Assertions: 5210, Skipped: 5` — 0 failures.**
- **FINAL (branch): `Tests: 1583, Assertions: 5288, Skipped: 5` — 0 failures.**
- Delta = **+32 tests**, 0 regressions. Baseline test-NAME list captured (1551) for diffing.

## Quality (proven against a pristine origin/development worktree, not asserted)
- PHPCS (7 changed files): **0 errors, 0 warnings**. Also fixed 3 pre-existing class-level `@spec`
  warnings encountered (ConflictOfInterestService, MandaatCheckService, MandaatMatrixController).
- PHPStan: 135 errors on branch vs 135 on pristine — **byte-identical finding sets after
  normalising line numbers: 0 introduced, 0 fixed.** All pre-existing.
- PHPMD: **0 introduced**; **3 pre-existing findings REMOVED** (`checkConflict` CC 11→ok,
  NPath 252→ok, and the deleted dead guard's ElseExpression).
- Hydra gates (--scope-to-diff): 37/39 PASS. 2 FAIL, both **proven pre-existing**:
  - gate-46 spec-anchor-existence: 38 unresolved `@spec` — **0 are mine** (all point at archived
    change dirs: `mandaat-matrix-*`, `woo-case-type`). Repo-wide debt = 2817 on pristine.
    ⚠️ My own `@spec` tags were retargeted from the change dir to the canonical
    `openspec/specs/authz-bypass-fixes/spec.md` so they survive archive.
  - gate-52 orphaned-write-capability: `ConflictOfInterestService::clearConflict` — test-only
    callers on pristine too; untouched by my diff. Flagged only because my change pulled the file
    into diff scope. → deferred, issue filed.
