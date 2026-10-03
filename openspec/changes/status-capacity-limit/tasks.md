# Tasks: status-capacity-limit

Tier: V1. Kind: code. Row Q3.22. Depends on nothing.

- [x] 1.1 `lib/Settings/dossiq_register.json`: `statusType.capacity`, a whole
  number, absent or zero meaning no limit (D-1). Register version 0.19.2 →
  0.20.0, or `ImportHandler` skips the import and the property reaches a fresh
  CI install and no existing instance.
- [x] 1.2 `lib/Service/Transitions/CapacityGuard.php`, registered as
  `statusCapacity`. It counts the cases whose `status` is the TARGET, excluding
  the case being moved, and never caps a final status (D-2, corrected — see
  D-7).
  - `tests/Unit/Service/Transitions/CapacityGuardTest.php`
- [x] 1.3 Appended by BOTH assemblers, `StatusTransitionService::evaluateGuards`
  and `OfferedTransitions::guardsFor`, carrying `toStatus` (D-3). One decides
  whether a move is offered and the other whether it runs; a limit on only one
  of them is a button that says it may be pressed and a move that is refused.
  - `tests/Unit/Service/Transitions/CapacityGuardWiringTest.php`
- [x] 1.4 The refusal names the status, the limit and the count, as a message on
  the failed guard (D-4).
- [x] 2.1 Bulk transition: NO CODE NEEDED. `TransitionCasesAction::apply()`
  already takes one object and answers `refused` with the first failed guard's
  message, so ten cases into three free places move three and report seven, each
  with the capacity sentence. Pinned so it stays true.
- [x] 2.2 `src/utils/statusCapacity.js` + `BoardColumn.vue` + `WorkflowBoard.vue`:
  the column header shows count against limit, and a drop onto a full status is
  refused before the card lands (D-6). A MERGED column shows no limit — see D-7.
  - `tests/vitest/statusCapacity.spec.js`
- [x] 3.1 `tests/e2e/status-capacity-limit.spec.ts`; `openspec validate
  status-capacity-limit --strict`.
