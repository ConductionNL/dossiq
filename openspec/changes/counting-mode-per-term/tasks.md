# Tasks: counting-mode-per-term

Tier: V1. Kind: code. Row Q8.16.

- [ ] 1.1 `register.d/60-termijnbewaking.json`: `deadlineDefinition.countingMode`.
  - `@spec openspec/changes/counting-mode-per-term/specs/termijnbewaking-schemas/spec.md`
- [ ] 1.2 `TermijnTimerService::armBeslistermijn()`: unit from the mode.
- [ ] 1.3 `TermijnService::createTermijnInstance()`: end date in the mode,
  engine `SlaCalculator` for working days, degraded per D-2.
  - fixture pair D-3
- [ ] 2.1 Termijn settings tab: the field.
- [ ] 3.1 `tests/e2e/termijn-counting-mode.spec.ts`; `openspec validate
  counting-mode-per-term --strict`.
