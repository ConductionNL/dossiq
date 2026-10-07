---
kind: code
depends_on: []
---

# Proposal: substituted-work-reaches-my-work

Competitor gap register, row 13.17 "Out of office with a stand-in who sees
the work" (`procest/_gaps/gap-register.md` in ConductionNL/market-intelligence,
2026-09-13). Rated no, owner dossiq, size S. The register's fifth pick to
build first.

## Why

The substitution rule exists and its list half is dead code.
`openspec/specs/handler-vervanging-waarneming/spec.md` requires that an
active substitution routes the absent handler's workload to the waarnemer,
and its own `@e2e exclude` on that scenario (line 70) says why nothing
proves it: `fetchSubstitutedWork()` in `src/services/substitutionApi.js:59`
is never called, every helper in `src/utils/substitutionHelpers.js` is
imported by nothing, and neither `src/views/MyWorkCards.vue` nor
`src/views/widgets/MyWorkWidget.vue` mentions substitution. A case assigned
to someone on leave is invisible until it breaches, and statutory terms do
not pause for leave.

The best competitor in the register: Zammad 7,
`app/models/user/out_of_office.rb:56-76` with substitution chains and a My
Replacement Tickets overview (`_round3/compare/proposed-rows.md`).

## What changes

- My work (`#MyWorkHome` and `#MyWork`) calls `fetchSubstitutedWork()` and
  merges the rows in, each marked "for <absent handler>", with a toggle to
  hide them. The dead helpers get their call site or go.
- The absence signal comes from humaniq: when a handler has an approved
  leave covering today and a substitution names them as absentee, the
  substitution's period follows the leave. A substitution without leave
  keeps its typed dates.
- The `@e2e exclude` on the spec's scenario is replaced by a citation.

## Ownership

dossiq builds the call site, the marker, the toggle and the period rule.
It consumes humaniq's leave (spec `leave-management` in humaniq, `status:
done`; the approved `LeaveRequest` objects in humaniq's register, read
through OpenRegister), and OpenRegister's object read for that query.
Absence itself stays humaniq's (ownership rules, humaniq paragraph).

## ADRs

- Company ADR-022: dossiq reads the leave object; it keeps no absence
  record.
- Company ADR-099: work done as a substitute stays attributed to the
  substitute; the capacity stamp is the spec's own retrofit and is not
  widened here.
- Company ADR-058: the leave query is bounded to the handlers on the
  substitution.

## Capabilities

- Modified: `handler-vervanging-waarneming`: substituted work is on My
  work, and leave in humaniq sets the period.

## Impact

`src/views/MyWorkCards.vue`, `src/views/widgets/MyWorkWidget.vue`,
`src/utils/substitutionHelpers.js`; `lib/Service/SubstitutionService.php`
(period from leave); `tests/e2e/spec-coverage/handler-vervanging-waarneming.spec.ts`
(the exclusion becomes a test).

Inherited, reported not fixed: `SubstitutionAuditService::stampIfSubstituted()`
has zero callers (spec line 116). It is the same spec's deferred retrofit
and belongs to its own task.
