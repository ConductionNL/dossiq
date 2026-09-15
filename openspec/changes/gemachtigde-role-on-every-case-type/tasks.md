# Tasks: gemachtigde-role-on-every-case-type

Tier: V1. Kind: config. Row 5.8.

- [ ] 1.1 `lib/Repair/SeedGemachtigdeRoleType.php`: create the generic row
  once by `genericRole`; register in `appinfo/info.xml`.
  - unit: second run creates nothing
  - `@spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md`
- [ ] 1.2 Add party form: role type source lists the case type's rows then
  the generic rows, skipping a generic row whose `genericRole` the type
  already declares.
- [ ] 1.3 `src/manifest.json` `#CaseDetail` widget `case-roles`: column
  `delegateFrom` labelled Represented by.
- [ ] 2.1 `tests/e2e/case-parties.spec.ts` extended with the three
  scenarios; `openspec validate gemachtigde-role-on-every-case-type --strict`.
