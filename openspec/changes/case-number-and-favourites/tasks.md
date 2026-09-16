# Tasks: case-number-and-favourites

Tier: MVP. Kind: config. Size M. Rows 2.1 and 2.19 of
`competitor-parity-2026-09`. Both owner changes are merged:
openregister#3785 `generated-identifier` (`e7ac79d1`) and openregister#3766
`favourites-and-recent` (`77bdb190`).

## 1. The case number

- [ ] 1.1 `lib/Settings/dossiq_register.json`, schema `case`: declare
  `x-openregister-generated` on `identifier` (sequence `case`, format
  `{year}-{seq:4}`, `resetOn: year`) per design D-1, and remove the
  `x-openregister-calculations` entry for `identifier` per D-3. Move the
  schema version.
  - `@spec openspec/changes/case-number-and-favourites/specs/case-management/spec.md`
  - `tests/Unit/Settings/CaseIdentitySchemaTest.php`
- [ ] 1.2 `lib/Settings/dossiq_register.json`, schema `complaint`: the same
  annotation on `complaintNumber` with its own counter per D-5, and move that
  schema's version too.
- [ ] 1.3 Retire `ComplaintService::generateComplaintNumber`. The number comes
  back off the saved object, so the log line says what was issued rather than
  what was guessed.
  - `tests/Unit/Service/ComplaintServiceTest.php`
- [ ] 1.4 `lib/Service/CaseNumberService.php`: the same backfill, its docblock
  naming the annotation it now hedges against per D-4.
- [ ] 1.5 The case page shows the number as issued and refuses to take a new
  one: `identifier` stays `editable: false` in `case-core`, and a refused
  update surfaces OpenRegister's sentence per D-6.

## 2. The star

- [ ] 2.1 `src/services/favouriteApi.js`: `PUT` and `DELETE` on
  `/apps/openregister/api/objects/{register}/{schema}/{id}/favourite`, and
  `isFavourite(row)` reading `@self.favourite`. A thin client, no store, for
  the reason `readStateApi.js` is one.
  - `tests/vitest/caseFavourite.spec.js`
- [ ] 2.2 `src/components/case/CaseFavouriteStrip.vue` plus the
  `case-favourite` widget type in `src/registry.js` and its cell on
  `#CaseDetail`, per D-8 and D-9.
- [ ] 2.3 `src/utils/caseFavourite.js` and the `favourite` row action on
  `#Cases` and `#Queue`, registered in `src/customComponents.js`.
- [ ] 2.4 `src/manifest.json`: the Favourites and Recently opened chips on
  `#Cases`, and the two tiles on `#Dashboard`, per D-10.
- [ ] 2.5 `l10n/en.json` and `l10n/nl.json`: the new labels, sentence case.

## 3. Verification

- [ ] 3.1 `tests/e2e/case-number-and-favourites.spec.ts`: written and tagged,
  not run here. No Playwright in this lane.
- [ ] 3.2 The diff check, one `composer check:strict`, one `npm run lint`,
  `npm run test:l10n`. Exit codes read from `$?`, not from summaries.

## 4. Named back to openregister

- [ ] 4.1 A format per case type. `x-openregister-generated` has no
  placeholder that reads a property of the object, so `{caseTypeCode}` cannot
  be declared. Row 2.1's candidate C-case-core-30 stays open until it can be.
  Asked of the `generated-identifier` lane; one mask on the `case` schema is
  what ships here.
- [ ] 4.2 A second, human number beside the system one (C-case-core-20) and a
  foreign identifier that names its issuer (C-case-core-39). Both are
  REQ-GID-005 and REQ-GID-006 of openregister's own change and neither has
  landed, so there is nothing for dossiq to place.
- [ ] 4.3 A star on a list ROW needs no library support today because dossiq
  renders it as a row action. A real star column, sortable and visible without
  opening a menu, waits on `@conduction/nextcloud-vue`. Named here so the row
  action is not mistaken for the finished affordance.
