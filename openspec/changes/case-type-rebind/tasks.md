# Tasks: case-type-rebind

Tier: V1. Kind: code. Row 2.13. Waits on openregister
`migrate-run-between-versions`.

- [ ] 1.1 `lib/Service/CaseRebindService.php`: validate (D-1), migrate,
  write, re-arm (D-2), group check (D-3); typed refusals.
  - `tests/Unit/Service/CaseRebindServiceTest.php`: missing property
    refused, engine refusal stops all, handler refused, happy path
  - `@spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md`
- [ ] 1.2 `TermijnService::rearmForDefinition()` with the fixture pair.
- [ ] 1.3 Controller method and route, `#[NoAdminRequired]` with the group
  guard in the service (gate 12); ADR-105 translation.
- [ ] 2.1 `src/dialogs/CaseRebindDialog.vue`: target picker (versions from
  `case-type-version-chain`, other types), status mapping, missing
  properties, reason.
- [ ] 2.2 `src/manifest.json` `#CaseDetail` header action
  `case-rebind`, group-gated.
- [ ] 3.1 `tests/e2e/case-type-rebind.spec.ts`; `openspec validate
  case-type-rebind --strict`.
