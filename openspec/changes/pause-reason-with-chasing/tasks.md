# Tasks: pause-reason-with-chasing

Tier: V1. Kind: code. Row 2.25. Waits on `termijnbewaking-op-engine-timers`
phase 1 (shipped) and reuses its helper timer.

- [ ] 1.1 `lib/Settings/register.d/60-termijnbewaking.json`: schema
  `pauseReason` (D-1) and `deadlineInstance.pauseReason`; two seeded
  reasons for the demo case type.
  - `@spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md`
- [ ] 1.2 `lib/Service/TermijnTimerService::armHersteltermijn()`: chase
  rungs per D-2 from the reason.
  - fixture pair: offsets for 14 days, interval 5, budget 2; working-day
    unit when `countsWorkingDays`
- [ ] 1.3 `lib/Listener/TermijnTimerFiredListener.php`: `pauze-chase` rung
  (D-3): send through the messaging leaf, record `chased`, last-chase
  handler notification, ignore after resume.
  - unit over messaging and store stubs
- [ ] 1.4 `lib/Service/DeadlinePauseService::registerPauze()`: takes the
  reason id; validates it belongs to the case type.
- [ ] 2.1 The Suspend dialog: reason picker (required), rationale as a note.
- [ ] 2.2 `#CaseDetail` `case-terms`: show the reason and the chases sent.
- [ ] 3.1 `tests/e2e/pause-reason-with-chasing.spec.ts`; `openspec validate
  pause-reason-with-chasing --strict`.
