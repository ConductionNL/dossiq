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

## The party model OpenRegister shipped (rows 5.1, 5.8, 5.12)

openregister#3761 made a party a row rather than a property. dossiq consumes
it here rather than in a change of its own, because the roles it adds are the
roles this change was already about.

- [x] 3.1 `lib/Service/People/PartyVocabulary.php`: the three kinds and the
  six generic roles, translated.
  - unit: `tests/Unit/Service/People/CaseRoleVocabularyTest.php`
  - `@spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md`
- [x] 3.2 `CaseRoleVocabulary::sync()` writes `partyKinds` and appends the
  generic roles behind the instance's role types.
  - unit: role types first, a claimed key not listed twice
- [x] 3.3 `lib/Settings/dossiq_register.json`: the case declares `partyKinds`
  and the generic `linkRoles`; `case-location` declares
  `x-openregister-party`. `dossiq_mock_register.json`: `brpPerson` and
  `kvkCompany` gain addresses, indicators, a parent and the declaration.
- [x] 3.4 `lib/Service/People/PartyIndicatorReader.php` plus the two acts:
  the file request refuses a send the indicator refuses, and says so in the
  dialog; the publication panel refuses and names the indicator.
  - unit: `FileRequestServiceTest`, `FileRequestControllerTest`
- [x] 3.5 `src/components/case/CasePartiesWidget.vue` and the Roles section
  of the People tab: `byRole`, primary party first, verdicts on screen.
  - unit: `tests/vitest/caseParties.spec.js`
- [x] 3.6 `InitiatorPicker` asks `/api/parties/resolve` before a second
  record for the same address is created.
  - unit: `tests/vitest/initiatorPicker.spec.js`
- [ ] 3.7 Tasks 1.1 to 1.3 above are NOT done by this pass: the generic
  Gemachtigde ROLE TYPE and its Represented by column stay open. The role is
  offered as a party role on every case type, which is what row 5.8 asked
  for; a `role` row naming `delegateFrom` is the seat half and needs the
  repair step task 1.1 describes.
