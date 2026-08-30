# Proposal: authz-bypass-fixes

kind: code — security fix. Closes three LIVE authorization bypasses in procest,
each of which is currently exploitable by any authenticated user. All three were
surfaced by the procest#223 done-spec audit; all three were **re-verified against
`origin/development` HEAD (eeba95e63) before any code was written**, and two of
them proved *worse* than the audit's triage.

## Why

Procest handles Dutch statutory government processes (WOO/FOI decisions, advice
requests, mandated decision-making). An authorization bypass here is not an
abstract risk: it lets an arbitrary authenticated user extend a statutory WOO
deadline, publish or withdraw a government decision, or submit advice on a case
they have no relationship to.

The unifying defect is the same in all three holes, and it is the reason every
one of them survived a green test suite and a `status: done` spec: **the check
exists, but it does not run on the live path** — either because it hangs off a
zero-caller method, because it short-circuits on an absent precondition, or
because it gates on an input nothing populates. A guard that cannot execute is
identical to no guard at all (OWASP A01:2021).

### Hole 1 — advice IDOR is still open; procest#17 "fixed" dead code

`AdviceService::submitAdvice()` (lib/Service/AdviceService.php:426) carries the
procest#17 / Wilco #6 IDOR guard. **`submitAdvice` has zero callers** — it is not
routed (`appinfo/routes.php` has no `advice#submitAdvice`) and no controller
method of that name exists. The guard's only other caller, `cancelAdvice()`
(:500), is **also dead**. So the entire guard subsystem
(`assertAdviceCallerIsAuthorized`, :565) is unreachable.

The live path is `appinfo/routes.php:314` → `AdviceController::transitionStatus()`
(:89) → `AdviceService::transitionStatus()` (:103), which performs **no
authorization check whatsoever**. Any authenticated user can POST
`/api/advice/{id}/transition {"to":"ontvangen"}` against **any** advice UUID and
mark someone else's advice request as received. The IDOR procest#17 claimed to
close has been open the entire time.

Two independent proofs the dead code never ran: it writes `status => 'received'`,
which is not a member of the live `VALID_STATUSES`
(`aangevraagd|ontvangen|verlopen`); and its guard reads a `requestedBy` field that
**does not exist** on the live `adviesAanvraag` schema (it exists only on the
unused `adviceRequest` schema). The guard was written against the wrong schema.

### Hole 2 — WOO authorization fails OPEN on an absent group

`WOOAssessmentController::requireCaseMutationAccess()` (:283) reads:

```php
if ($this->groupManager->groupExists('procest-gebruikers') === true
    && $this->groupManager->isInGroup($uid, 'procest-gebruikers') === false
) { throw new OCSForbiddenException(...); }
```

`procest-gebruikers` is referenced **nowhere else in the codebase** — no group
creation, no `register.d`, no `Application.php`, no `info.xml`, no migration. The
group does not exist, so `groupExists()` returns false, the `&&` short-circuits,
no exception is thrown, and **every authenticated user is authorized**.

The audit reported 3 affected endpoints; there are **5**, all `#[NoAdminRequired]`
and all calling this guard: `bulkAssess` (:98), `extendDeadline` (:135 — statutory
deadline extension), `createDecision` (:168), `publishDecision` (:211),
`withdrawPublication` (:245).

Worse, the check is group-membership-shaped, not per-case: even with the group
present, any case worker could mutate **any** case. Creating the group is
therefore explicitly *not* the fix.

The canonical spec itself blessed the bug — `openspec/specs/woo-publication-via-opencatalogi/spec.md:152`
requires rejection only for a non-member *"(when that group exists)"*. That
requirement is corrected by this change's spec delta.

### Hole 3 — the belangenconflict check always returns "no conflict"

`ConflictOfInterestService::checkConflict()` (:105) gates on
`$caseProperties['userBsn']` and returns `['conflict' => false]` when it is
absent. **No production code populates `userBsn`** — it appears only in this
service and its unit tests. The conflict-of-interest check therefore reports "no
conflict" unconditionally on every live call.

Live chain: `appinfo/routes.php:586` → `MandaatMatrixController::probe()` (:117)
→ `MandaatCheckService::isAuthorized()` (:82) → `checkConflict()` (:93).

**Worse than triaged:** `MandaatMatrixController` (:110) builds
`$caseProps = (array) ($body['caseProperties'] ?? [])` — straight from the
**client-supplied request body**. Identity is attacker-controlled, so even a
deployment that populated `userBsn` would be trivially bypassed by omitting it.
An authorization input read from the requester is not an authorization input.

## What changes

1. **Advice (hole 1).** Port the guard to the LIVE path first. `transitionStatus()`
   gains a per-transition, fail-closed authorization gate keyed on the live
   `adviesAanvraag` schema's real `adviseur`/`case.assignee` fields. The cron
   (`expireAdvice` → `verlopen`) keeps working via an explicit, documented
   system-only seam that is unreachable over HTTP. Only after the live path is
   guarded and proven do we remove the dead `submitAdvice`/`cancelAdvice`.
2. **WOO (hole 2).** Replace the short-circuiting group check with a real
   per-case, fail-closed guard (`CaseAccessGuard`) consuming OpenRegister for the
   case read (ADR-022 — OR owns RBAC; procest does not grow a parallel RBAC
   stack). Absent group, absent case, and unavailable OR all DENY.
3. **Conflict of interest (hole 3).** Identity is never read from the request
   body. The applicant identity is resolved server-side from the case object;
   the case-worker identity is resolved through a dormant, server-side resolver
   seam. When the applicant is known but the worker's identity cannot be
   resolved, the check is **indeterminate and BLOCKS** — it never reports "no
   conflict". BSNs are compared as SHA-256 hashes and never stored or logged.

## Non-goals

- Creating the `procest-gebruikers` group (would not fix the fail-open).
- Building an app-local RBAC engine (ADR-022 forbids it; OR owns RBAC).
- Adding a raw-BSN store for civil servants (AVG art. 9 — hash-only, and no such
  source exists in Nextcloud today).
