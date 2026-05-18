# Tasks: Procest Legacy Quality Cleanup

## Phase 1 — Inventory + planning

- [ ] Run `composer phpcs`, `composer phpmd`, `composer phpstan` for the first time as unified gates; capture baseline counts + violation categories per gate (starting from 3 exclude-patterns in `phpcs.xml`).
- [ ] Per gate decide: fix-outright (if <50 violations) or capture a fresh baseline. Confirm CI runs `composer check:strict` on every PR before starting burn-down work.

## Phase 2 — PHPCS burn-down (per excluded file)

For each file: fix errors, remove the `phpcs.xml` `<exclude-pattern>` entry, verify gate stays green.

- [ ] Excluded file 1 — fix sniffs + drop exclude.
- [ ] Excluded file 2 — fix sniffs + drop exclude.
- [ ] Excluded file 3 — fix sniffs + drop exclude.
- [ ] Once all excludes are gone, drop the legacy-debt block from `phpcs.xml` entirely.

## Phase 3 — PHPMD burn-down

Contingent on Phase 1 output. If volume is small, collapses to a single fix-outright PR.

- [ ] Resolve PHPMD findings by category as baseline dictates: ElseExpression (reshape `if/else` → early-return), CyclomaticComplexity / NPathComplexity (extract methods), MissingImport (`use` statements), StaticAccess (replace with DI), variable-naming (Long/Short/Undefined/UnusedFormalParameter).
- [ ] Once baseline reaches 0 lines: delete `phpmd.baseline.xml` and drop `--baseline-file` from composer.json's phpmd script.

## Phase 4 — PHPStan burn-down

Contingent on Phase 1 output. If volume is small, collapses to a single fix-outright PR.

- [ ] Inventory phpstan errors by file/type and fix the common patterns: missing return-type / param-type declarations, mixed types (specify generic / union), possibly-null dereferences.
- [ ] Once baseline reaches 0 lines (or never created): confirm gate runs clean against current code.

## Phase 5 — CI integration

- [ ] Verify `composer check:strict` runs in CI on every PR; once all baselines are empty, delete `phpmd.baseline.xml` and `phpstan-baseline.neon` (if either was created) and drop the legacy-debt section from `phpcs.xml`.
- [ ] Add a smoke-test cron that runs `composer check:strict` weekly on `development`.

## Phase 6 — Documentation

- [ ] Update README quality-gates section, note in `app-config.json` that legacy quality cleanup is done, and close the burn-down tracking issue once the last baseline line is removed.
