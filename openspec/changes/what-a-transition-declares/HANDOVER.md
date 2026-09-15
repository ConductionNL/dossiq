# what-a-transition-declares, picked up where this lane stopped

Rows 2.39, 3.30, 11.41 and 13.26, merged as specs in dossiq#2759. The change
artifacts are already on `development` at
`openspec/changes/what-a-transition-declares/`. Nothing here is wired up yet:
these are four services and one register patch, written and syntax-checked,
with no schema applied, no DI registration, no tests and no frontend.

## What is here

- `lib/Service/Obligations/ObligationDeclaration.php`: what an obligation is,
  read off the case type's `obligationKinds` rather than out of a service. The
  three parts are placed, blocks, and meeting it releases. `blocks` carries the
  token `closing` rather than a list of status ids, because a list has to be
  kept complete by hand and the token cannot go stale: that is what makes
  "every closing status, not the one the case type calls closed" true of a
  case type that grows a fourth one next year.
- `lib/Service/Obligations/ObligationService.php`: place, meet, withdraw, and
  the two read paths the engine needs. Withdrawn is never recorded as met: an
  inspection nobody carried out is not an inspection that passed.
- `lib/Service/Transitions/TransitionPreconditions.php`: reads
  `transition.requiresSettled` and answers whether a transition is available
  and what is in the way. Its field and document kinds delegate to
  `DerivedStatusEvaluator`, which `what-a-status-declares` shipped, so "the
  file is complete" cannot mean two things on the same case.
- `lib/Service/Transitions/FourEyesRule.php`: reads `transition.notPerformedBy`
  and finds who performed the named earlier act, from the status record chain.
  The LAST performance wins, not the first: a decision redrafted twice was
  prepared by whoever wrote the version being approved.
- `patch-register.py`: adds `statusRecord.actor`, moves `statusRecord` and
  `workflowTemplate` a version, and documents the three transition
  declarations. Every edit asserts its anchor is unique before writing. Run it
  from the clone root. ⚠️ Re-check the version numbers first: two lanes already
  collided on `statusType` 1.3.0 and `case` 1.21.0 in this wave.

## What is deliberately not here

The obligation SCHEMA itself, the DI wiring, `CaseStatusStore` writing
`actor`, the withheld list on `/available-transitions`, the re-expression of
`ConsultationService` over `ObligationService`, the authoring surface in
`TransitionConfigPanel.vue`, every test, and the e2e spec.

## Two things this lane learned that will bite here

1. `ServiceCatchReturnsNullTest` counts every `catch (\Throwable)` under
   `lib/Service` that answers `null` or `[]` within its first three
   statements, against a ceiling that only goes down. The ceiling was at its
   limit, so a new one fails even if you allowlist it. `ObligationService` is
   already written to log in the catch and answer at the end of the method
   instead; keep that shape in anything new.
2. `EveryTermOnTheCalendarTest` wants a row in
   `docs/research/date-arithmetic-audit-2026-09-14.md` for any file under
   `lib/` that does date arithmetic, with a verdict of `statutory`, `business`
   or `neither`. An obligation term will need one.

Full plan, including the frontend surface that does not collide with the
`lifecycle-acts-on-the-case` lane's `CaseActionProvider`: the case page's
transition surface is `case-stages` driven by that provider, so the withheld
reasons and the explanations belong on `CaseStatusDeclarationPanel.vue`, the
strip `what-a-status-declares` already owns.
