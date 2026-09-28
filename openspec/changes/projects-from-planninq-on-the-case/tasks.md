# Tasks: projects-from-planninq-on-the-case

Tier: V1. Kind: config. Half: planninq `integration-case-bridge`.

## 1. The placement

- [ ] 1.1 `lib/Settings/register.d/79-planninq-projects-leaf.json` appends `planninq-projects` to `case.linkedTypes`, and `CaseDetail` places the `case-projects` integration widget (design D1).
  - unit: a schema test asserts the linked type; `npm run check:manifest`

## 2. Live check

- [ ] 2.1 With planninq's case scope landed and checking the `dossiq` app id: the case page shows "Projects" with the case's projects, "New project" opens planninq's dialog with the case title, and on an instance without planninq the panel is absent (design D2).

## 3. Validation

- [ ] 3.1 `openspec validate projects-from-planninq-on-the-case --strict`, `npm run lint`, `composer check:strict` once before push.
