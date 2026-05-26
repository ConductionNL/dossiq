# Tasks: Procest Legacy Quality Cleanup

## Phase 1 — Inventory + Planning

- [ ] Run `composer phpcs`, `composer phpmd`, `composer phpstan` for the first time as unified gates; capture baseline counts and violation categories per gate (starting from 3 exclude-patterns in `phpcs.xml`)
- [ ] Per gate, decide: fix-outright (if <50 violations) or capture a fresh baseline.xml
- [ ] Confirm CI runs `composer check:strict` on every PR before starting burn-down work

## Phase 2 — PHPCS Burn-Down

For each excluded file: fix sniffs, remove `<exclude-pattern>` from phpcs.xml, verify gate stays green.

- [ ] Excluded file 1 — fix sniffs + drop exclude
- [ ] Excluded file 2 — fix sniffs + drop exclude
- [ ] Excluded file 3 — fix sniffs + drop exclude
- [ ] Drop the legacy-debt block from `phpcs.xml` entirely once all excludes are removed

## Phase 3 — PHPMD Burn-Down

Contingent on Phase 1 findings. If <50 violations, collapse to single fix-outright PR; otherwise manage via baseline.xml.

- [ ] If baseline captured: Resolve ElseExpression violations (reshape `if/else` → early-return)
- [ ] If baseline captured: Resolve CyclomaticComplexity / NPathComplexity violations (extract methods)
- [ ] If baseline captured: Resolve MissingImport violations (add `use` statements)
- [ ] If baseline captured: Resolve StaticAccess violations (replace with DI)
- [ ] If baseline captured: Resolve variable-naming violations (Long, Short, Undefined, UnusedFormalParameter)
- [ ] Once baseline reaches 0 lines: delete `phpmd.baseline.xml` and drop `--baseline-file` from composer.json's phpmd script

## Phase 4 — PHPStan Burn-Down

Contingent on Phase 1 findings. If <50 violations, collapse to single fix-outright PR; otherwise manage via baseline-neon.

- [ ] Inventory phpstan errors by file and type
- [ ] Fix missing return-type and param-type declarations
- [ ] Fix mixed-type violations (specify generic types or unions)
- [ ] Fix possibly-null dereference violations
- [ ] Once baseline reaches 0 lines (or never created): confirm gate runs clean against current code

## Phase 5 — CI Integration

- [ ] Verify `composer check:strict` runs in CI on every PR as a required check
- [ ] Delete `phpmd.baseline.xml` and `phpstan-baseline.neon` (if created) once all baselines are empty
- [ ] Add a weekly smoke-test cron that runs `composer check:strict` on `development` branch

## Phase 6 — Documentation

- [ ] Update README quality-gates section to reflect cleared legacy debt
- [ ] Note in `app-config.json` that legacy quality cleanup is complete
- [ ] Close the burn-down tracking issue once the last baseline line is removed
