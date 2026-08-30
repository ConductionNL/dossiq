# Design: authz-bypass-fixes

## Verify-first verdicts (per hole, against origin/development @ eeba95e63)

The procest#223 audit self-reported two triage errors, both biased toward
"dead". Every claim below was therefore re-derived from HEAD with the
`->method(` caller-grep signal (`class-injected ≠ method-called`), and each
indirect seam (routes, `register.d`, `Application.php`, `info.xml` jobs) was
checked before anything was declared dead or live.

| Hole | Audit said | Verified verdict | Evidence (file:line) |
|---|---|---|---|
| 1 — advice IDOR | guard on zero-caller `submitAdvice`; live path unguarded | **CONFIRMED — and the guard subsystem is entirely dead** | `submitAdvice` defined `AdviceService.php:426`, zero callers (only :426/:487 + `procest_register.json:4887`); not in `appinfo/routes.php`; no such controller method. Guard `assertAdviceCallerIsAuthorized:565` called ONLY from :444 (`submitAdvice`) + :516 (`cancelAdvice`) — **`cancelAdvice:500` is also zero-caller/unrouted**. Live path: `routes.php:314` → `AdviceController::transitionStatus:89` → `AdviceService::transitionStatus:103` — **no check in body**. |
| 2 — WOO short-circuit | 3 `#[NoAdminRequired]` endpoints fail open | **CONFIRMED — 5 endpoints, not 3** | Guard `WOOAssessmentController.php:283`; short-circuit at :297-298. `procest-gebruikers` referenced nowhere else in code (only :294/:297/:298 + 2 spec `.md`). Callers: :105 `bulkAssess`, :142 `extendDeadline`, :175 `createDecision`, :218 `publishDecision`, :252 `withdrawPublication`. |
| 3 — belangenconflict | gates on unpopulated `userBsn` | **CONFIRMED — and the input is client-controlled** | `ConflictOfInterestService.php:114-118` returns `['conflict'=>false]` when `userBsn` empty; `userBsn` grep = this service + tests only. Live: `routes.php:586` → `MandaatMatrixController::probe:117` → `MandaatCheckService::isAuthorized:82` → `checkConflict:93`. `MandaatMatrixController:110`: `$caseProps = (array) ($body['caseProperties'] ?? [])` → **request body**. |

### Additional defects found while verifying (not in the audit)

- **Dead guard targeted the wrong schema.** `assertAdviceCallerIsAuthorized` reads
  `requestedBy`, which does not exist on the live `adviesAanvraag` schema
  (`case, adviseur, type, onderwerp, deadline, status, adviesDocument,
  requestedAt, receivedAt, questions`); it exists only on the unused
  `adviceRequest` schema. Independent proof the guard never executed.
- **Dead `submitAdvice` writes an invalid status** (`'received'` ∉
  `VALID_STATUSES = aangevraagd|ontvangen|verlopen`) — it could never have
  round-tripped.
- **`MandaatCheckService::isAuthorized:91`** guards `if ($this->conflictService !== null)`
  with a `?ConflictOfInterestService $conflictService = null` constructor default —
  a second latent fail-open if the service is ever unresolvable.

## Decisions

### D1 — Guard the live path BEFORE deleting dead code
The dead `submitAdvice` is the only reference implementation of the intended
check. Order is load-bearing: port → prove rejection on `transitionStatus` →
only then delete. Tasks enforce this ordering.

### D2 — Advice transition authorization matrix (fail closed by default)
Keyed on the **live** `adviesAanvraag` schema. There is no `requestedBy`; the
requester relationship is expressed through `case` → `case.assignee`.

| `to` | Who may transition | Rationale |
|---|---|---|
| `ontvangen` | `advice.adviseur` or admin | The advisor marks their own advice received. **This is the open IDOR.** |
| `aangevraagd` | `case.assignee` (of `advice.case`), `advice.adviseur`, or admin | Requesting/notifying is the case handler's action. |
| `verlopen` | **nobody over HTTP** — system/cron only | Expiry is a system transition (`AdviceDeadlineJob:112`). |
| unknown | denied | Fail closed. |

`verlopen` must keep working from `AdviceDeadlineJob` → `expireAdvice()`, which
runs with **no user session** — a naive guard would throw `Not authenticated`
and silently break the cron. Split the method:
`transitionStatus()` (public/HTTP) = assert + `applyTransition()`;
`expireAdvice()` (system/cron) = `applyTransition()` directly. The trust
boundary becomes explicit and `verlopen` is unreachable from the controller.

### D3 — WOO: real per-case guard, consuming OR (ADR-022)
ADR-022 lists **Authorization RBAC** as an OR-owned abstraction, and gate-23
flags app-local `*Permission*Service` / `*Authorization*Service`. So procest does
**not** grow a parallel RBAC engine. Instead a thin `CaseAccessGuard`
(naming mirrors the existing `InformatieobjectAccessGuard`, and avoids the
gate-23 pattern) resolves the case **through OpenRegister's `ObjectService`**
(OR's own RBAC applies to that read) and enforces the per-case relationship:

```
no session            → deny
admin                 → allow
OR unavailable        → deny   (fail closed — never "skip the check")
case not found        → deny   (collapsed with deny: no existence oracle)
uid === case.assignee → allow
otherwise             → deny
```

`case.assignee` is the schema's documented "Nextcloud user ID of the primary
handler". This mirrors the already-live, already-fail-closed
`DsoCaseService::authorizeZaakMutation` (:348) rather than inventing a new idiom.
**Absence of the `procest-gebruikers` group can never grant access** — the group
plays no part in the new guard at all.

### D4 — Conflict of interest: fail closed on indeterminate identity, hash-only
Two rules, both required:

1. **Identity is never read from the request body.** `checkConflict` no longer
   reads `userBsn` from `$caseProperties`. `MandaatMatrixController::probe`
   strips client-supplied identity keys and repopulates the applicant identity
   **server-side from the case object** (`initiatorType === 'person'` ⇒
   `initiatorSourceId` is the applicant BSN).
2. **Indeterminate ⇒ BLOCK.** The case-worker BSN is resolved through a
   `MedewerkerIdentityResolverInterface` seam that ships **dormant** (Nextcloud
   holds no civil-servant BSN; `BurgerIdentificationService::resolveFromDigiD` is
   the *citizen* path and cannot serve this). Therefore:

```
manual registered conflict         → conflict = true
applicant identity absent          → conflict = false   (nothing to compare — sound)
applicant present, worker absent   → conflict = true, reason = identiteit_onbepaald   ← BLOCKS
worker === applicant               → conflict = true, reason = self
relationship found (lookup/BRP)    → conflict = true, reason = <relation>
otherwise                          → conflict = false
```

The `applicant present + worker unresolvable ⇒ block` rule is the fail-closed
core: an unresolvable check **never** reports "no conflict". Scoping
"indeterminate" to *cases that actually have an applicant* keeps the app usable
(cases with no natural-person initiator have no conflict to detect) without
reintroducing a fail-open.

**BSN handling (AVG art. 9).** BSNs are special-category personal data. Per the
team's hash-only convention — already present in-file at
`ConflictOfInterestService.php:180`
(`substr(hash('sha256', $applicantBsn), 0, 16)`) — comparison is on **SHA-256
hashes**. No raw BSN is stored on the service, logged, or returned. The
resolver seam returns a hash, not a BSN, so a binding never has to hand raw
BSNs to procest. This is the "hash/pseudonym rather than raw BSN" fix the brief
asks us to call out explicitly.

### D5 — `MandaatCheckService` null-service fail-open (D-adjacent, in scope)
`isAuthorized` skipping the conflict check entirely when `$conflictService === null`
is the same defect class as the three holes. The conflict check becomes
non-optional on the live path: a null service is treated as **indeterminate**
(deny) rather than "skip", consistent with D4.

## Seed Data

**No seed data is added, changed, or required by this change.**

Specifically, the `procest-gebruikers` group is **deliberately not seeded**.
Creating it would satisfy the old guard's `groupExists()` precondition and make
the fail-open look fixed while leaving the real defect (group-shaped check, not
per-case) in place — and would mean absence of a group still governs access.
D3 removes the group from the authorization decision entirely, so no group,
register fragment, or `register.d` entry needs to exist for the guard to fail
closed. No register schema changes: the guard reads existing fields
(`case.assignee`, `adviesAanvraag.adviseur`, `case.initiatorType`,
`case.initiatorSourceId`).

## ADR-031 (declarative extensions) — considered, not applicable

ADR-031 requires behaviour expressible as OR schema metadata
(`x-openregister-{lifecycle,aggregations,calculations,notifications,relations,widgets}`)
to be **declared in the register rather than written as service classes**, and
gate-18 enforces the canonical notification dialect.

Assessed per hole:

- **Hole 1 (advice transitions).** Advice status *is* a lifecycle, and
  `x-openregister-lifecycle` can express transition guards. But the ADR-031
  lifecycle dialect resolves guards by class name via
  `LifecycleGuardRegistry` — and the fleet-wide finding recorded on procest#223
  is that **17 `register.d` guards name classes that do not exist**, with OR's
  `LifecycleGuardRegistry::resolve()` throwing. Introducing this fix as a
  declarative guard would route a live security control through exactly the
  orphaned-capability seam this change exists to close. The guard therefore
  stays in PHP on the live call path, where a test can prove it rejects.
  Migrating advice status to a declarative lifecycle is legitimate follow-up
  work, but it must not be bundled with a security fix.
- **Holes 2 & 3.** Per-case mutation authorization and belangenconflict
  detection are not expressible in any ADR-031 dialect (no authorization dialect
  exists; RBAC is an OR runtime abstraction per ADR-022, consumed here via
  `ObjectService`).
- **Notifications.** This change dispatches no new notifications and touches no
  `lib/Settings/*register*.json` notification block, so gate-18's dialect rule is
  not engaged. The existing `fireTransitionNotification` behaviour is preserved
  byte-for-byte and simply moves inside `applyTransition()`.

## Risks

- **Cron regression (advice `verlopen`).** Mitigated by D2's explicit system seam
  plus a test asserting `expireAdvice()` still succeeds with no user session.
- **Behaviour change for `probe`.** Cases with a natural-person initiator now
  block when no identity resolver is bound. This is intended fail-closed
  behaviour and is called out in the spec delta; the alternative is a
  conflict-of-interest control that is decorative.
- **Deleting dead code.** `submitAdvice`/`cancelAdvice` removal happens only
  after the live path is guarded and green (D1), and both are provably
  zero-caller and unrouted.
