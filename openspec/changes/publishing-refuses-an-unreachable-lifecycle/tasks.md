# Tasks: publishing-refuses-an-unreachable-lifecycle

Tier: V1. Kind: code. Competitor map row
`dossiq/specs/case-type-publish-validation`.

## 1. The walk

- [x] 1.1 `lib/Service/CaseType/CaseTypeReachability.php`: a pure class taking
  the declared statuses, the status a new case starts in, and the moves, and
  answering the findings (D-1).
  - `brokenMoves()` names a move whose `fromStatus` is empty, is `*`, or is a
    status this type does not declare, and a move whose `toStatus` is not
    declared. Each entry carries the status it was trying to lead to (D-3).
  - `reachedFrom()` walks the sound moves from the initial status.
  - `orphanFindings()` names a declared status nothing leads to, skipping the
    initial one and any status already blamed on a broken move (D-3).
  - `closureFindings()` names the initial status when no final status is
    reachable from it.
  - `@spec openspec/specs/case-type-publish-validation/spec.md`

## 2. Ask it where the write is

- [x] 2.1 `CaseTypePublishService::reachabilityFindings()`, merged into
  `validate()` beside `handlingFindings()` (D-2). Extracted rather than inlined
  for the reason that one was: `validate()` has a complexity ceiling.
  - It returns early when the case type has no active workflow template, so the
    case types that drive their lifecycle from statuses alone are untouched.
  - `moves()` decodes `transitions` when the store handed back the JSON string
    the authoring page wrote. Treating the string as an empty list would make
    every finding disappear on exactly the case types with the most moves.
- [x] 2.2 `CaseTypeReachability` joins the constructor as the eleventh
  collaborator. It has no constructor of its own, so Nextcloud's container
  autowires it and no registration is needed.

## 3. Pin it

- [x] 3.1 `tests/Unit/Service/CaseType/CaseTypeReachabilityTest.php`, ten cases,
  every refusal paired with an acceptance.
  - Mutation-checked twice, both times reading which line reddened. Dropping
    the `*` branch from `brokenMoves()` reddened the `starts from no status`
    assertion at `CaseTypeReachabilityTest.php:126`, not a setup line. Dropping
    the `blamed` skip from `orphanFindings()` reddened the `assertCount(1)` at
    `CaseTypeReachabilityTest.php:259` with an actual size of 2.
- [x] 3.2 Both existing publish suites take the new collaborator.
  `CaseTypePublishServiceTest` and `CaseTypePublishValidationTest` pass
  unchanged otherwise: 40 tests, 76 assertions, exit code 0.
- [x] 3.3 `tests/e2e/publishing-refuses-an-unreachable-lifecycle.spec.ts`, tagged
  to every scenario in the delta. Written, not run: the nightly runs it.

## 4. Verify

- [x] 4.1 `php -l` on all five changed PHP files, exit code 0.
- [x] 4.2 `phpunit --filter 'CaseTypeReachabilityTest|CaseTypePublishValidationTest|CaseTypePublishServiceTest'`:
  OK, 40 tests, 76 assertions.
- [x] 4.3 `phpcs` on the changed PHP files, 0 errors.
- [x] 4.4 `openspec validate publishing-refuses-an-unreachable-lifecycle --strict`.

## 5. Deliberately not built here

- [ ] 5.1 NOT BUILT: honouring `*` as a real wildcard in
  `StatusTransitionService` and `Transitions\OfferedTransitions`. That changes
  what every stored template means, including on live instances, so a move that
  has never been offered would start being offered on upgrade. It needs a
  migration and a decision about which of those moves were meant. See design
  D-5. It belongs in `status-transition-engine`, not here.
