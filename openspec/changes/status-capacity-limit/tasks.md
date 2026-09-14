# Tasks: status-capacity-limit

Tier: V1. Kind: code. Row Q3.22. Depends on nothing.

- [ ] 1.1 `lib/Settings/dossiq_register.json`: `statusType.capacity`, a
  whole number, absent or zero meaning no limit (D-1).
- [ ] 1.2 `lib/Service/Transitions/CapacityGuard.php` counting cases in
  the status within the case type, excluding final statuses (D-2), and
  registered in `GuardRegistry` as `statusCapacity`.
  - `tests/Unit/Service/Transitions/CapacityGuardTest.php`
  - `@spec openspec/changes/status-capacity-limit/specs/status-transition-engine/spec.md`
- [ ] 1.3 The guard runs on every transition into the status and never on
  a transition out of it (D-3).
- [ ] 1.4 The refusal names the status, the limit and the count, as a
  status and a message (D-4).
- [ ] 2.1 Bulk transition: move what fits, report each refusal (D-5).
  - `tests/Unit/Service/CaseBulkStatusTransitionServiceTest.php`
- [ ] 2.2 `src/manifest.json`: the board column header and the status chip
  show count against capacity; a drag into a full column is refused before
  the card lands (D-6).
  - `tests/vitest/statusCapacity.spec.js`
- [ ] 3.1 `tests/e2e/status-capacity-limit.spec.ts`; `openspec validate
  status-capacity-limit --strict`.
