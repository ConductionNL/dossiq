# Tasks: what-a-transition-declares

Tier: V1. Kind: code. Size M. Rows 2.39, 3.30, 11.41 and 13.26.

- [ ] 1.1 `lib/Settings/dossiq_register.json`: `transition.requiresSettled`,
  the dependencies that must be settled before a transition is available
  (D-1, D-2).
  - `@spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md`
- [ ] 1.2 Withhold the transition while a dependency is open, and render the
  reason where the transition would have been (D-1).
  - `tests/unit/Service/TransitionPreconditionTest.php`
  - `tests/vitest/transitionWithheldReason.spec.js`
- [ ] 1.3 Withhold every closing status, not only the one the case type calls
  closed.
  - `tests/unit/Service/ClosingWithheldTest.php`
- [ ] 2.1 Declare the obligation: what is placed, on whom, what settles it,
  what it blocks and what happens when it is met (D-3).
  - `@spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md`
  - `tests/unit/Service/ObligationServiceTest.php`
- [ ] 2.2 Place the obligation as an engine task and arm its term where it
  has one; meeting it releases what it blocked.
  - `tests/unit/Service/ObligationReleaseTest.php`
- [ ] 2.3 Re-express the advice request as the first declared obligation,
  keeping `ConsultationService`'s behaviour identical from the outside (D-4).
  - `tests/unit/Service/ConsultationServiceTest.php`
- [ ] 3.1 Render `statusType.description` on the case, and add
  `transition.explanation` rendered in the transition list (D-5).
  - `tests/vitest/transitionExplanation.spec.js`
- [ ] 3.2 The case-type authoring surface edits both texts on the row they
  belong to.
- [ ] 4.1 `transition.notPerformedBy`, naming the earlier act whose performer
  may not make this transition (D-6).
  - `tests/unit/Service/FourEyesTransitionTest.php`
- [ ] 4.2 The refusal names the act, its date and the person, and the case
  type may name who may be asked instead (D-7).
- [ ] 4.3 Declare the rule as an action mapping under ADR-023 so an
  administrator can read it.
- [ ] 5.1 Dutch and English strings for the withheld reasons, the obligation
  states, the two explanation fields and the four-eyes refusal.
- [ ] 5.2 `tests/e2e/what-a-transition-declares.spec.ts`: a withheld closing
  status with its reason, an obligation placed and met, an explanation read
  at the moment of choosing, an author refused their own approval;
  `openspec validate what-a-transition-declares --type change --strict`.
