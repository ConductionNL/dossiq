# Audit: procest specs marked `done` that are not live

**Date:** 2026-07-16
**procest HEAD:** `d1ebe418b` (development)
**openregister ref audited:** `origin/development` = `6fc3a5372` (working tree is on `wip/public-audit-query-endpoint` `ebedbdd5a` and diverges — NOT used)
**Scope:** analysis + issue-filing only. No app code modified, nothing committed.

---

## 1. Bottom line

Of procest's **136 `done` specs**, the audit found the "healthy app, dead feature" pattern is **real and systemic**, but it is **not** uniformly a third of the surface. What we can defend:

- **22/22** gate-flagged write capabilities triaged → **19 genuinely orphaned**, **3 superseded** (delete-candidates, not dead features). Of the 19, 2 are benign (no behaviour lost).
- **2 confirmed fabricated passes** + 2 UNSURE — including an authorization guard that lets **any authenticated user** mutate any WOO case.
- **4 inert declarations** — declared `x-openregister-*` config that OR silently drops, incl. the **AVG read-log** and the **statutory AWB 7:10 bezwaar deadline**.
- **2 phantom handler/seam refs** — a docblock and a deleted listener that name seams which do not exist.
- **Phantom-ticks confirmed**, but the mechanism is the *opposite* of the team-memory precedent (see §5) — and **the seed claim handed to this audit was itself wrong** (§6).

**Honest verdict on "how many of 136 are live":** we cannot responsibly give a single number. We audited capability-level evidence, not a 1:1 spec→code map, and one finding often spans several specs while many specs have no flagged capability at all. What is defensible:

| Bucket | Count | Basis |
|---|---|---|
| Specs with a **confirmed dead/partial** capability | **~14–18** | traced from the 19 orphans + 4 inert + 2 fabricated, de-duplicated by owning spec |
| Specs **confirmed live** by this audit | **~12** | the LIVE rows in §4 + superseded-by-a-live-path rows |
| Specs **not examined** | **~105** | see coverage caveat §7 |

So: **a defensible floor of ~14 `done` specs are not live**, roughly **10%** of the 136 — with ~105 specs unexamined, meaning the true figure could be materially higher. This is a floor, **not** an estimate of the total.

---

## 2. Verdict table — the 22 gate findings

| # | Capability | Shape | What silently breaks | Evidence | Sev |
|---|---|---|---|---|---|
| 1 | `TenantAuditTrailService::emit` | **orphan** | Tenant-stamped audit log **never written**. Class has zero refs outside its own file | `lib/Service/TenantAuditTrailService.php:65`; only ref `:126` (its own self-certification) | **TOP** |
| 2 | `TenantBillingService::emitEvent` | **orphan** | No billing event ever inserted → every aggregation/invoice is €0. Revenue silently unbilled | def `:83`; sole injector `lib/Service/TenantLifecycleControlService.php:49` calls only `fetchEventsForMonth` `:161` | **TOP** |
| 3 | `ShillinqIntegrationService::exportInvoice` | **orphan** | No invoice ever POSTs to Shillinq; whole group→build→export chain dead | `lib/Service/ShillinqIntegrationService.php:128`; only test caller | High |
| 4 | `TenantJwtService::createTokenFromSaml` | **orphan** (injected≠called) | eHerkenning/SAML→tenant JWT minting does not exist; `createToken` also zero-caller → middleware validates tokens nobody mints | class live at `lib/Middleware/TenantClaimValidationMiddleware.php:81`, calls only `validate()` `:108`; def `lib/Service/TenantJwtService.php:113` | High |
| 5 | `ZgwExternalAdapterInterface::submitZaak` | **orphan** (documented-dormant) | No external ZGW zaak push | DI alias `lib/AppInfo/Application.php:408-411`; impl `LogZgwExternalAdapter.php:41` logs `PUSH_DEFERRED`; **zero consumers inject the interface** | Med |
| 6 | `ZgwExternalAdapterInterface::submitDocument` | **orphan** (documented-dormant) | No external document push | def `:115`; impl `LogZgwExternalAdapter.php:105` | Med |
| 7 | `DoorverbindingService::createContextSnapshot` | **orphan** | Warm transfers carry no context; live sibling reads snapshot from the *client* param, defaults `'{}'`, no frontend sends it | def `:67`; live `initiateWarmTransfer` `:109` ← `ContactMomentController.php:301` | High |
| 8 | `AdvisoryBodyService::issueSecureToken` | **orphan + already broken** | External advisory-body response flow unreachable; would TypeError if wired | `lib/Service/AdvisoryBodyService.php:281`, arg drift `:297` | High |
| 9 | `MandaatEscalatieService::createEscalatie` | **orphan** | Denied decisions never escalate to next-higher mandate holder; `escalateApprove/Reject` (routes 505/506) act on rows nothing creates | def `:68`; `MandaatCheckService.php:93` | compliance/money |
| 10 | `BeroepService::recordJudgment` | **orphan** | Court uitspraak never recorded → `executeCascade` never fires. Sole writer of `judgmentOutcome` | `lib/Service/Bezwaar/BeroepService.php:321` | compliance |
| 11 | `AdvisoryCommitteeService::recordCouncilDeviation` | **orphan** | Awb 7:13 deviation-from-advice leaves no audit row | `lib/Service/Bezwaar/AdvisoryCommitteeService.php:449` | compliance |
| 12 | `Bezwaar\HearingService::recordAttendance` | **orphan** (namespace trap) | Awb 7:7 attendance + late-correction audit gate never runs | def `:319`. Trap: `ComplaintController.php:29` imports the *other* `Service\HearingService` | compliance |
| 13 | `ConflictOfInterestService::clearConflict` | **orphan — vacuous** | Nothing; its writer `registerConflict` is also dead | `:239`, `:50` | Low |
| 14 | `Inspection\ChecklistService::createRun` | **orphan** | No run created → no `templateSnapshot`/version freeze → template edits retroactively alter historic runs | FQCN appears only in own file `lib/Service/Inspection/ChecklistService.php:37`; `@psalm-suppress UnusedClass` `:52` | High |
| 15 | `Inspection\ChecklistService::submitRun` | **orphan** | REQ-IC-7 follow-up dispatch + REQ-IC-8 append-only locking never run | same; no route: `grep -i checklistrun appinfo/routes.php` → 0 | High |
| 16 | `Subsidie\TussenrapportageService::createExpected` | **orphan** | Nothing ever creates a `tussenrapportage` → routed `approveTussenrapportage` (`routes.php:82`) can never fire | def `:134`; sole schema writer `:256`; no cadence job | High |
| 17 | `VergaderingCaseService::createForVergadering` | **orphan** (test-only) | ORI vergaderingen never get a linked case → agenda-publication deadline never set. `VergaderingDeadlineJob:80` scans for cases this would have created | def `:92`; test-only callers `tests/Unit/Service/VergaderingCaseServiceTest.php:132` | Med-High |
| 18 | `ParaferingNotificationService::notifyParaferingReminder` | **orphan — WIRE** | Handlers never reminded of overdue parafering; steps stall silently. **Not** superseded declaratively | def `:154`; no notif dialect on voorstel/parafering; spec wants a job `openspec/specs/parafering-actions/spec.md:174` | Med |
| 19 | `WorkflowTemplateLoader::clearCache` | **orphan — benign** | Nothing; cache is per-request `:46` | def `:180` | Info |
| 20 | `AdviceService::submitAdvice` | **superseded + 🔴 security inversion** | See §3 — the IDOR fix landed on a zero-caller method | live path `appinfo/routes.php:258` → `AdviceController.php:106` → `transitionStatus:103` | **SECURITY** |
| 21 | `DsoIntakeService::createCase` | **superseded** (+broken) | Nothing — `processAanvraag` covers it | live `routes.php:344` → `DSOIntakeController.php:141` → `:130` | Low-Med |
| 22 | `EmailTemplateService::seedDefaultTemplates` | **superseded** (delete-candidate) | Nothing for the shipped caseType; **caveat**: register.d fragment hardcodes `"caseType":"omgevingsvergunning"`, PHP method was generic → other caseTypes get no defaults | `lib/Settings/register.d/35-email-templates.json:11,24,37` vs `DEFAULT_TEMPLATES` `lib/Service/EmailTemplateService.php:48` | Low |

---

## 3. Fabricated passes

| file:line | Verdict | Evidence |
|---|---|---|
| `lib/Controller/WOOAssessmentController.php:221` `requireCaseMutationAccess()` | **FABRICATED — HIGH** | Guard hangs off `groupManager->groupExists('procest-gebruikers') === true && isInGroup(...) === false`. **`procest-gebruikers` is referenced nowhere else in the app** (verified: only lines 218/221/222). Group absent → `&&` short-circuits → **every authenticated user passes**. `$caseId` is used *only* to interpolate the error string — no per-object check, despite the docblock claiming "OWASP A01:2021 per-object authorization (ADR-005 Rule 3)". Exposes 3 `#[NoAdminRequired]` endpoints: `bulkAssess():97`, `extendDeadline():134`, `createDecision():167` — statutory WOO deadline extension + formal besluit assembly |
| `lib/Service/ConflictOfInterestService.php:114-118` `checkConflict()` | **FABRICATED — HIGH** | Auto-detection gated on `userBsn`/`applicantBsn`; grep shows **no caller anywhere populates them** (keys hit only this file). Empty → `return ['conflict' => false]` unconditionally. `MandaatCheckService::REDEN_BELANGENCONFLICT:97` unreachable. Manual escape hatch also dead (`registerConflict` zero callers, writes request-scoped `private array $registered`). BRP fallback `:140` sits behind the same return → unreachable |
| `lib/Service/TenantAuthenticationService.php:200-204` `loadActiveMatrix()` | **UNSURE — leans finding, MED** | Missing `matrix` → hardcoded `'tenant_admin' => ['*' => true]`. Contradicts class docblock ("fail-closed") and its own sibling: malformed `:195` → `DEFAULT_DENY_MATRIX` (`[]`). **Malformed → deny, missing → allow** |
| `lib/Service/ZgwJwtValidator.php:167` `validate()` | **UNSURE — adjacent, MED** | `$secret = $authConf['publicKey'] ?? '';` no non-empty guard → blank key makes `hash_hmac(..., '')` forgeable by anyone knowing the issuer name. Not hardcoded-pass, but trivially satisfiable |

**Explicitly cleared** (precision matters): `lib/Service/Transitions/*` all fail closed — `GuardRegistry::evaluateAll:109-118` marks an **unknown guard type `passed => false`**, the exact opposite of the shillinq `default => true` precedent. `MandaatValidationService` fails closed (`:76-81`, `:133`). `InformatieobjectAccessGuard` maps unknown → most restrictive. `TenantAuthenticationService::isAllowed` deliberately **re-throws** rather than returning null (`:241`). `BezwaarDeadlineGuard:72` fail-open is documented and reasoned (cannot block a statutory decision) — legitimate. **No `default => true` arms and no `catch { return true; }` exist anywhere in `lib/`.**

---

## 4. Inert declarations (declared config no engine reads)

| Key | procest | OR consumer | Verdict | Breaks | Sev |
|---|---|---|---|---|---|
| `x-openregister-processing` | `procest_register.json:793`, `:1659`, `:2467`, `register.d/40-kcc-werkplek.json:21` | read at `ProcessingLogService.php:344` — but **stripped at save** by `Schema.php:1940` against `ANNOTATION_VOCABULARY` `Schema.php:2094-2114`, which lists only the *legacy* `x-openregister-processing-activity` | **INERT** | **AVG/GDPR read-logging never happens**; no verwerkingsregister attribution | **Critical** |
| `x-openregister-calculations` **as ARRAY** | `procest_register.json:3394` (bezwaar) | engine expects a **map**: `CalculationAnnotationValidator.php:129-175`; runtime `CalculationOnSaveListener.php:300` | **INERT ×2** | **AWB 7:10 statutory bezwaar `decisionDeadline`** + **`dwangsom`** never computed. Fails twice: int keys → validator error → warn-and-ignore (`SchemaMapper.php:791-820`); entries also lack `materialise:true` → `CalculationOnSaveListener.php:167-169` `continue`s | **Critical** |
| `x-openregister-lifecycle` (complaint) | `/components/schemas/complaint` | `LifecycleAnnotationValidator.php:80` requires `field`,`initial`,`transitions` | **INERT** | Block declares only `transitions` → early return `lifecycle-missing-key` → `SchemaMapper.php:731-742` degrades to warning. 7 transitions unenforced | High |
| `x-openregister-notifications` (consultation) | `procest_register.json:4880` | `AnnotationNotificationDispatcher.php:237` reads `$spec['trigger']`; `matches()` returns false without `type` | **INERT** (fail-closed) | Never dispatches. Foreign dialect: no `trigger`/`enabled`/`channels`; bare-string `subject` (canonical `{nl,en}`) and bare-string `recipients` (canonical `{kind,field}`) | Med |

**LIVE and confirmed** (do not touch): lifecycle dict-transitions ×10 (`:995`,`:1606`,`:2091`,`:2772`); `transitions.*.requires` FQCN guards → `LifecycleGuardRegistry.php:83-115` resolves via `IServerContainer` autowiring; canonical notifications ×3 (`:629`,`:1367`, `register.d/62-handler-vervanging.json:31`); `-archival` → `Cron/ArchivalRetentionTask.php:237`; `-quality`; `-dedup`; `-references`; `-calculations` as MAP (`:812`,`:1489`).

**Two brief assumptions corrected:** procest declares **no `actions[]` and no `guards`** anywhere — or#433's `LifecycleActionExecutor` exists in OR but has nothing to execute here; guard-like config is spelled `requires`. And the only multi-field `groupBy` (`src/manifest.json:2438`) is the **AppHost metrics engine** (`MetricDescriptor.php:230-241`), which has always accepted a field array — **not** or#435's aggregation API. No defect.

**Root cause of the inert class:** OR's `SchemaMapper` deliberately warns-and-imports on malformed lifecycle/calculations rather than failing. Combined with warn-only `logDroppedAnnotationKeys`, **all four defects surface as nothing but a log line** — the register imports clean and the JSON reads as authoritative. The `-processing` bug is an **OR-side vocabulary gap affecting every consuming app**, masked by test-fake drift: `tests/Unit/Service/ProcessingLogServiceTest.php:142` stubs `getConfiguration()` with the key pre-injected, bypassing the filter.

---

## 5. Phantom refs & phantom-ticks

- **`ChecklistRunImmutabilityListener` — phantom seam.** A fully implemented `IEventListener` (`lib/Listener/ChecklistRunImmutabilityListener.php:50`) that is **never registered**. `lib/Service/Inspection/ChecklistService.php:16` docblocks that "append-only enforcement (REQ-IC-8) lives in ChecklistRunImmutabilityListener" — pointing at a seam that does not exist. Both paths for REQ-IC-8 are dead.
- **`StatusChangeDispatcherListener` — deleted by a commit that said it changed no source.** `e050e4a69` ("test(integration): add Newman API-contract suite", body: *"Test files only; no app source modified"*) is **136 files / 1827 deletions** and removed `lib/Listener/StatusChangeDispatcherListener.php`. At HEAD `VergunningStatusChangedEvent` still exists and is constructed at `lib/Service/DsoCaseService.php:283` — with **zero consumers**. Permit status changes fire into the void; DSO/Omgevingsloket is never notified. **No grep gate catches a commit that lies about its own scope.**
- **Mandaat admin UI 404s entirely.** `src/views/settings/tabs/MandaatMatrixTab.vue:132,144,156,194,198` call `/api/mandate/besluiten|rollen|toewijzingen|mandaten`; `appinfo/routes.php:502-508` registers **none** of them (only `probe`, `import`, `escalations`, `audit-trail`, `applicable`). Verified.
- **`MandaatCheckService::isAuthorized` gates nothing.** Exactly one caller — `MandaatMatrixController::probe()`, an advisory endpoint returning the decision as JSON. Nothing *enforces* it server-side. Compounds §3's belangenconflict finding.
- **`vth-05` beschikking generation fails open.** `BeschikkingGenerationService` has **zero `throw` statements**; ticked `BeschikkingValidationException` / `renderTemplate` / `SigningAdapterInterface` don't exist. Docudesk unavailable → returns `'success' => true` with a text stub; a legally-binding permit decision is a placeholder and the workflow proceeds. Its own test asserts the fail-open as correct.
- **"emits X / listens to Y" is fiction ~14×.** `IEventDispatcher` is imported into **none** of the 17 termijn/mandaat/dwangsom services. Burger notifications render but never send; deadline escalation is a log line that marks itself sent so it never retries.
- **Terminal deferral (systemic).** All 16 leverancier chain members are 100% `[x]`, zero unticked; ticks defer to "chain member 16", which itself ticks `[x]` on tasks it also defers. The chain closes on a dangling promise, then archives complete.

**The mechanism is the inverse of the team-memory precedent.** Not "code never written" — procest **builds bottom-up, ticks the connective tissue it never wrote, archives, then deletes the orphans**, leaving stale docs and dead config that make live code look dead and dead capability look live.

---

## 6. 🔴 Two corrections — this audit's own triage was wrong before it was right

**(a) The seed handed to this audit was FALSE.** Cluster B reported `SupplierAuthService.issueSessionToken` as phantom-ticked ("neither class nor method exists in `lib/`"). **Refuted and independently verified by me:** `git show f0d68a3c4:lib/Service/SupplierAuthService.php` has `issueSessionToken()` at **line 196** calling `$this->jwt->createToken(` at **line 203** — exactly as ticked. The class was later **deliberately deleted** by `062d9dede` ("remove orphaned supplier/citizen-portal backend", ADR-046 — portals moved to external Portaliq). **Absent-at-HEAD ≠ never-written.** The trap: `issueSessionToken`'s only HEAD hits are in `docs/` (`docs/openapi/leverancier-zaakportaal.yaml:8`), stale docs for a removed feature. The `saas-05` half was **misattributed** — that file never mentions `SupplierAuthService`; every saas-05 artifact verifies. **saas-05 is clean.**

This is the shillinq lesson reproducing exactly: **the error runs toward "dead", and it propagates between agents.** Had this gone into the issue unchecked, it would have sent someone to rebuild a feature that was deliberately retired.

**(b) `submitAdvice` — the "superseded" story was a red herring, and the truth is worse.** The `submitAdvice` transition at `procest_register.json:4855` is on the **`consultation`** schema; `AdviceService::submitAdvice` writes the **`adviesAanvraag`** schema. Different schema — **that transition supersedes nothing.** The real superseder is `transitionStatus`, live via `routes.php:258`. Worse: **the procest#17 IDOR fix was applied to a method with zero callers.** `submitAdvice:444` calls `assertAdviceCallerIsAuthorized`; the live `transitionStatus:103-150` has **no such check** (`AdviceController:92` only verifies *authenticated*). `POST /api/advice/{id}/transition {"to":"ontvangen"}` still lets any authed user mark advice received on any UUID. **The vulnerability the ticket claims to have closed is open on the live path.** Deleting `submitAdvice` without first porting the guard erases the only record the fix was intended.

---

## 7. Bonus — live runtime break (not a "done-spec" defect, but found en route)

**`saveObject` positional-arg drift on a LIVE path.** OR's real signature (verified `openregister/lib/Service/ObjectService.php:1066`) is `saveObject(array|ObjectEntity $object, ?array $extend, Register|string|int|null $register, ...)`. Two sites pass `($register, $schema, $data)` positionally → binds `string` to `array|ObjectEntity $object` → **TypeError**:
- `lib/Service/DoorverbindingService.php:115` — 🔥 **on the live `initiateWarmTransfer` path**, reached from route `contactMoment#doorverbinden` (`routes.php:457`). Suggests the doorverbinden endpoint **500s outright**. No Doorverbinding unit test exists to have caught it. *UNSURE pending a runtime hit — but the signature evidence is strong.*
- `lib/Service/DsoIntakeService.php:289` — dead *and* broken (inside the orphaned `createCase`), so it is **not a usable fallback**.
- `AdvisoryBodyService.php:297` / `:161` / `:163` — same drift; green only because `tests/Unit/Service/AdvisoryBodyServiceTest.php:80` hand-rolls a `saveObject(string $register, ...)` signature **that does not exist**. Textbook test-fake drift.

Live paths that work use **named args** (`DsoIntakeService.php:130`, `TenantBillingService.php:105`).

---

## 8. Coverage caveat (read this before quoting any number)

**Audited exhaustively:**
- All **22/22** gate findings from `check_orphaned_write_capability.py`, each read in full with caller + supersession analysis.
- `check_register_handler_resolution.py` → **0 findings, verified** (procest has no `handler` entries; guard config is spelled `requires` and all 3 FQCNs resolve).
- All **8 distinct `x-openregister-*` keys** + sub-keys across `procest_register.json`, `ori_register.json`, `register.d/*.json`, each traced to an OR consumer or proven to have none.
- Fabricated-pass sweep across **all of `lib/`** (validators/guards/policy/authz), with an explicit cleared-list.

**Sampled, NOT exhaustive:**
- **73 of 294 archived changes (~25%)** read for phantom ticks — prioritised by blast radius: leverancier (16), saas (12), termijn (11), mandaat (9), vth (11), archief (8), dso (1), + leges/subsidie/AVG/WOO/beschikking spot-checks. **Untouched:** the 2026-02/03/05 generations (largely superseded), `retrofit-*` (~35), `migrate-*` leaves.
- **~105 of 136 `done` specs were not individually examined.** The gate only flags *write* capabilities in `lib/Service/` — read paths, controllers, frontend, and jobs are outside its reach, so orphans of other shapes would not have surfaced.

**Method limits:** static analysis only (read-only constraint). No register import was executed and no live `oc_openregister_schemas.configuration` row was inspected — a runtime confirmation of the `-processing` drop and the Doorverbinding 500 are the two cheap checks worth adding. The bezwaar/complaint/consultation inert blocks *may* be intentional forward-declarations for dialects not yet built; they are inert **today** either way, but that changes whether it's a bug or unshipped work.

**Do not read "10%" as the answer.** It is a floor derived from a gate with a narrow aperture plus a 25% archive sample. The honest statement is: *every place we looked hard, we found dead capability.*
