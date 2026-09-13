# Tasks: citizen-status-labels

Tier: V1. Kind: config. Row Q6.19.

- [ ] 1.1 `lib/Settings/dossiq_register.json` `statusType.publicLabel`,
  `publicDescription`; mock register follows.
  - `@spec openspec/changes/citizen-status-labels/specs/case-types/spec.md`
- [ ] 1.2 The helper (D-1) and its unit test (label, fallback).
- [ ] 1.3 `PublicStatusPage` and `PortalContributionProvider` read the
  helper.
- [ ] 1.4 ZGW mapping: `statustekst`.
- [ ] 2.1 Status editor: the two fields (D-3);
  `tests/e2e/case-type-status-authoring.spec.ts` extended.
- [ ] 3.1 `tests/e2e/citizen-status-labels.spec.ts`; `openspec validate
  citizen-status-labels --strict`.
