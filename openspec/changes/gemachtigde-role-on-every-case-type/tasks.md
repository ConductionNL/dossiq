# Tasks: gemachtigde-role-on-every-case-type

Tier: V1. Kind: config. Row 5.8.

- [x] 1.1 `lib/Repair/SeedGemachtigdeRoleType.php`: create the generic row
  once by `genericRole`; register in `appinfo/info.xml`.
  - unit: second run creates nothing
    (`tests/Unit/Service/People/GemachtigdeRoleTypeSeederTest.php`)
  - The work is in `lib/Service/People/GemachtigdeRoleTypeSeeder.php` and the
    repair step delegates to it, the shape `SeedIntakeSources` already uses:
    a repair step cannot be unit-tested without an upgrade harness, and the
    idempotence is the whole requirement.
  - IDENTITY IS `genericRole`, NOT THE NAME AND NOT THE SLUG. An administrator
    may rename the row, and the shipped seed already carries a Gemachtigde at
    `rol-gemachtigde`. That row is ADOPTED (the key is stamped onto it) rather
    than duplicated, so the roles pointing at it keep pointing at it.
  - A role type that NAMES A CASE TYPE never satisfies the generic one, even
    carrying the same `genericRole`: it is offered on that type alone, so an
    instance holding only that one still has no representative anywhere else.
    That is the assertion the mutation check reddened.
  - `roleType.genericRole` gains the value `gemachtigde` and the schema moves
    to 1.2.0 in both register files. The enum did not hold it, and
    OpenRegister fast-skips a schema whose version did not move.
  - Registered BEFORE `SyncCaseRoleVocabulary` in both repair blocks: the sync
    writes the role types onto the case schema, so seeding after it would
    leave the new row out of the vocabulary for a whole upgrade.
  - `@spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md`
- [x] 1.2 Add party form: role type source lists the case type's rows then
  the generic rows, skipping a generic row whose `genericRole` the type
  already declares.
  - `src/services/roleTypeOptions.js` holds the ordering and the
    deduplication as a pure function, so both are testable without mounting
    anything; `src/components/case/RoleTypePicker.vue` only asks and draws,
    and is bound through `fieldOverrides.roleType` on the `add-party` action.
  - AN UNKNOWN CASE TYPE FALLS BACK TO EVERY ROW rather than to the generic
    ones alone. Returning the generic rows for a case whose type could not be
    read would empty the picker on exactly the case where somebody is trying
    to record a party, and an empty picker reads as "this instance declares no
    roles".
  - SAME STANDING LIMITATION AS `InitiatorPicker`: `@conduction/nextcloud-vue`
    3.2.0 VALIDATES a `form-field` registry entry (CnAppRoot requires
    `appliesTo`) but does not MOUNT one into the form dialog. So the manifest
    binding is the declaration, the library's own object picker still renders
    the field, and the ordering is asserted by the unit test rather than by a
    screenshot. Recorded rather than worked around: an app-side form dialog
    would reimplement in dossiq the seam every fleet app needs.
- [x] 1.3 `src/manifest.json` `#CaseDetail` widget `case-roles`: column
  `delegateFrom` labelled Represented by.
  - IT IS NOT `delegateFrom`, AND NOT A COLUMN. Two corrections, both
    measured rather than assumed:
    - `delegateFrom` is the START OF A DELEGATION WINDOW, a date-time that
      `RoleDelegationResolver` compares against now. Writing a party uuid
      into it would have made every routing decision on that case compare a
      uuid against a clock, silently, while the widget rendered the name
      correctly. `role.representedParty` is a new property and the role
      schema moves to 1.2.0 with it. REQ-ROLE-010 is amended to say so.
    - There is no widget `case-roles` and no column: the Roles section of the
      People tab is `case-party-roles`, a registry component
      (`CasePartiesWidget.vue`), because a tab child renders through
      CnTabsWidget which resolves `cnRegistry[widget.type]`. So Represented by
      is a rendered field on the party row, from `representedByMap()` over the
      case's `role` rows.
- [x] 2.1 `tests/e2e/case-parties.spec.ts` extended with the three
  scenarios; `openspec validate gemachtigde-role-on-every-case-type --strict`.
  - NOT YET RUN: there is no Playwright runner on the build host, so the three
    are written and tagged for the nightly, like the two party-model tests
    above them. Each names what would break it.
  - The dedup scenario computes the offer from the instance's REAL rows rather
    than importing `offeredRoleTypes`: that module imports `@nextcloud/axios`
    and `@nextcloud/router`, which do not resolve in a Playwright process, and
    the import would have taken the whole spec file down at load. The rule
    itself is unit-tested in `tests/vitest/gemachtigdeRole.spec.js`.
  - The Represented by scenario reads the stored row back over the API rather
    than off the page, because a property OpenRegister dropped renders exactly
    like a party who represents nobody.

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
