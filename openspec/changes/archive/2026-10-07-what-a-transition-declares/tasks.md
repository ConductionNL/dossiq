# Tasks: what-a-transition-declares

Tier: V1. Kind: code. Size M. Rows 2.39, 3.30, 11.41 and 13.26.

The file paths below say where each task LANDED. This repo's PHP suite lives
under `tests/Unit/`, not `tests/unit/`, and the four new unit files sit
together under `tests/Unit/Service/Obligations/` because they are one feature
read from four sides.

- [x] 1.1 `lib/Settings/dossiq_register.json`: `transition.requiresSettled`,
  the dependencies that must be settled before a transition is available
  (D-1, D-2). `workflowTemplate` moves to 1.3.0.
  - `@spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md`
  - Four kinds: `obligationOpen`, and `fieldPresent`, `fieldEquals` and
    `documentPresent`, which are the vocabulary `statusType.derivedWhen`
    already uses. An author who has written one has written the other, and the
    last three are evaluated by the same `DerivedStatusEvaluator`.
- [x] 1.2 Withhold the transition while a dependency is open, and render the
  reason where the transition would have been (D-1).
  - `lib/Service/Transitions/TransitionPreconditions.php`
  - `tests/Unit/Service/Obligations/TransitionPreconditionTest.php`
  - `tests/vitest/transitionWithheldReason.spec.js`
- [x] 1.3 Withhold every closing status, not only the one the case type calls
  closed. The declaration carries the token `closing` and the engine resolves
  it against `statusType.isFinal` at the moment it asks.
  - `tests/Unit/Service/Obligations/ClosingWithheldTest.php`
- [x] 2.1 Declare the obligation: what is placed, on whom, what settles it,
  what it blocks and what happens when it is met (D-3). New `obligation`
  schema, and `caseType.obligationKinds` as the authoring vocabulary.
  - `@spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md`
  - `tests/Unit/Service/Obligations/ObligationServiceTest.php`
- [x] 2.2 Meeting it releases what it blocked, and withdrawing it releases the
  case while staying readable as a withdrawal.
  - `tests/Unit/Service/Obligations/ObligationReleaseTest.php`
- [x] 2.3 Re-express the advice request as the first declared obligation,
  keeping `ConsultationService`'s behaviour identical from the outside (D-4).
  - `tests/Unit/Service/ConsultationServiceTest.php`
- [x] 3.1 Render `statusType.description` on the case, and add
  `transition.explanation` rendered where the handler is choosing (D-5).
  - `src/components/case/CaseStatusDeclarationPanel.vue`
  - `tests/vitest/transitionWithheldReason.spec.js`
- [x] 3.2 The case-type authoring surface edits both texts on the row they
  belong to: the status text on `StatusTypeForm.vue`, which already had it,
  and the transition text on `TransitionConfigPanel.vue`.
- [x] 4.1 `transition.notPerformedBy`, naming the earlier act whose performer
  may not make this transition (D-6). `statusRecord` gains `actor` and moves
  to 1.2.0, because the row's owner is a fact about who wrote the record
  rather than about who took the step.
  - `tests/Unit/Service/Obligations/FourEyesTransitionTest.php`
- [x] 4.2 The refusal names the act, its date and the person, and the case
  type may name who may be asked instead.
- [x] 5.1 Dutch and English strings for the withheld reasons, the two
  explanation fields and the authoring hints. Both catalogues; `npm run
  test:l10n` is the guard.
- [x] 5.2 `tests/e2e/what-a-transition-declares.spec.ts`: a withheld closing
  move with its reason, an obligation met and one withdrawn, an explanation
  read at the moment of choosing, and the actor recorded on the move.

## What was left out, and why

**Task 4.3, the four-eyes rule as an ADR-023 action mapping.** It is NOT
declared in `caseType.rightsMatrix`, and that is the finding rather than the
omission. The matrix is a positive grant of verbs per department, role and
confidentiality. Four eyes is a NEGATIVE rule about an act, and D-6 says why it
cannot be a role: the same person legitimately approves other cases they did
not prepare. Writing it as a matrix row would mean inventing a synthetic role
per case, which is the combinatorial explosion the matrix's own description
warns about. It is declared on the transition, where the case type author reads
it, and an administrator reads it there. Giving it a home in the effective
blueprint alongside `obligationKinds` is the next step and is not taken here.

**The obligation is not placed as an engine task.** `obligation.engineTaskId`
exists and nothing writes it. Placing the ask in OpenRegister's task inbox is
what task 2.2 asks for, and it needs `CreateTaskHandler`'s assignee resolution
and an acting identity, which is a seam worth its own change rather than a
fifth thing in this one. Today an obligation blocks and releases correctly and
nobody is told about it except through the case.

**An obligation's own term arms no timer.** `obligation.dueAt` and the `term`
on a declared kind are stored and read; nothing counts down to them. The shape
to reach for is `StatusDwellTimer`, which `what-a-status-declares` shipped, and
the same divergence applies: the engine has the calendar and dossiq has the
count. An overdue obligation is currently found by reading `dueAt`, not by
being told.

**No Playwright run.** The e2e spec is written and tagged; this lane runs no
browser.
