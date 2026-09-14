# Tasks: case-priority-impact-urgency

Tier: V1. Kind: code. Size M. Round 4 discovery clusters 15 and 42,
candidates C-search-6 and C-deadlines-15. Decision D14, answered as
recommended: store impact and urgency, derive the priority, let a rule
raise it. The rule engine is openregister's under D3, to be specified in
openregister, wave 1, as `field-rules-by-state` and
`lifecycle-declarative-conditions`.

- [x] 1.1 `lib/Settings/dossiq_register.json`: `case.impact` and
  `case.urgency` as administered values with an order, and the case-type
  matrix that derives priority from them (D-1, D-2).
  - `tests/Unit/Service/PriorityDerivationTest.php` (the repo's suite is
    `tests/Unit`, not `tests/unit`)
  - `@spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md`
- [x] 1.2 An instance default matrix, so a case type that declares none
  still derives a priority (D-2).
- [x] 1.3 Write the derived value into the existing `case.priority`,
  keeping its `low`, `normal`, `high`, `urgent` values and its
  `facetable` flag, so every current reader keeps working (D-3).
- [x] 2.1 The override: stored beside the derived value, with who, when
  and why, winning until cleared, and clearing returns to the derived
  answer (D-4).
  - `tests/Unit/Service/PriorityOverrideTest.php`
- [x] 3.1 Declare the term rule on openregister's rules engine, raising
  only, recording the rule that raised it (D-5). Name the openregister
  slug once that lane opens it.
  - `tests/Unit/Service/PriorityRaiseRuleTest.php`
  - Declared in `lib/Settings/priority_raise_rule.json`. It names
    `openregister` as the engine and says in its own text that dossiq
    evaluates nothing: the rungs are OpenRegister flow timers, dossiq is
    handed the threshold that fired and looks up a floor. **The
    openregister capability slug is still unnamed**, because that lane has
    opened no artefact for `field-rules-by-state` or
    `lifecycle-declarative-conditions` on its `development`. The
    declaration records that, and the thresholds move there unchanged when
    it lands.
- [x] 4.1 Declare an order and an NL Design System colour token per
  priority value; add the sortable column to `src/manifest.json` (D-7).
  - `tests/vitest/caseListPriority.spec.js`
- [x] 4.2 Consume nextcloud-vue's list rendering for the order and the
  colour; ship no colour in a dossiq component (D-7).
- [x] 5.1 `lib/Service/DeadlineEscalationService.php`: read the case's
  priority; rename its own four-value constant to a notification urgency
  (D-6).
  - `tests/Unit/Service/DeadlineEscalationServiceTest.php`
- [x] 6.1 Dutch and English strings for impact, urgency, priority and the
  override reason.
- [ ] 6.2 Ask the corpus lane for the missing row, in D14's own words:
  priority derived from impact and urgency, ordering the working list, and
  raised by a rule as the term approaches; rate every driven column.
  - **NOT DONE HERE, and deliberately not ticked.** The corpus lives in
    ConductionNL/market-intelligence, not in this repo, and this lane owns
    no branch there. The ask is recorded verbatim in the PR body so it can
    be carried by whoever runs the promotion pass. Ticking it from here
    would claim a row exists that does not.
- [x] 6.3 `tests/e2e/case-priority.spec.ts`: derive, override, clear,
  raise on an approaching term, sort the queue;
  `openspec validate case-priority-impact-urgency --strict`.
