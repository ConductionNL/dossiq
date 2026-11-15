# Tasks: projects-from-planninq-on-the-case

Tier: V1. Kind: config. Half: planninq `integration-case-bridge`.

## 1. The placement

- [x] 1.1 `lib/Settings/register.d/79-planninq-projects-leaf.json` appends `planninq-projects` to `case.linkedTypes`, and `CaseDetail` places the `case-projects` integration widget (design D1).
  - unit: `tests/Unit/Service/Planninq/PlanninqProjectsLeafDeclarationTest.php` (through the real fragment merger), `tests/vitest/siblingLeavesOnTheCase.spec.js`, `tests/Unit/LeafIntegrationDeclarationsTest.php::testEveryLinkedTypeResolves`; `npm run check:manifest`

## 2. Live check

- [ ] 2.1 (live pass, decision 139) With planninq's case scope landed and checking the `dossiq` app id: the case page shows "Projects" with the case's projects, "New project" opens planninq's dialog with the case title, and on an instance without planninq the panel is absent (design D2).

## 3. Validation

- [x] 3.1 `openspec validate projects-from-planninq-on-the-case --strict`, `npm run lint`, `composer check:strict` once before push. (validate exits 0, 2026-10-10; lint and check:strict ran in the L4 checkpoint chain, see the PR body)
