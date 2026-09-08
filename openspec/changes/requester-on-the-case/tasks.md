# Tasks: requester-on-the-case

Tier: V1. Kind: code. Every checkbox below is one implementation task; the
criteria under a task are plain bullets.

## 1. The register sets provide the requester

- [x] 1.1 `lib/Settings/register.d/25-brp-kvk.json`: add
  `implements: ["https://openregister.app/ns#Requester"]` to `brpPerson` and
  `kvkCompany`; add property `indicatieGeheim` (boolean, default false) and
  `logReads: true` to `brpPerson`; flag one seeded persona that carries
  `geheimhoudingPersoonsgegevens` in the personen-mock.
  - unit test in `tests/Unit/Settings/BrpKvkRegisterSetsTest.php`: both
    schemas implement the URI, `indicatieGeheim` is optional, exactly one
    seed is protected
  - `@spec openspec/specs/brp-register/spec.md`
- [x] 1.2 `lib/Settings/dossiq_register.json` and
  `lib/Settings/dossiq_mock_register.json`: reword the `case.requester`
  description so it no longer says the field is set only by an integration.
- [x] 1.3 `lib/Service/External/Brp/HaalCentraalBrpAdapter.php` and
  `LogBrpHaalCentraalAdapter.php`: map `geheimhoudingPersoonsgegevens` to
  `indicatieGeheim`.
  - unit test in `tests/Unit/Service/External/Brp/HaalCentraalBrpAdapterTest.php`
    covering 0, 1 and absent

## 2. The picker on both forms

- [ ] 2.1 `src/components/initiator/InitiatorPicker.vue`: emit
  `{requester, initiatorType, initiatorSourceId, initiatorDisplayName}` on
  select, with `requester` the uuid of the chosen register row and empty for
  a contact; accept the form's `value` for `requester` and show the current
  choice. `src/modals/InitiatorPickerModal.vue` passes the same payload.
  - vitest in `src/components/initiator/InitiatorPicker.spec.js`
  - `@spec openspec/specs/initiator-selection/spec.md`
- [ ] 2.2 `src/registry.js`: `appliesTo: ["case.requester"]` on
  `InitiatorPicker`; no boot warning for a missing `appliesTo`.
- [ ] 2.3 `src/manifest.json` page `Dashboard`, action `new-case`: add
  `requester` to `includeFields` after `title`;
  `fieldOverrides.requester.widget = "InitiatorPicker"`.
- [ ] 2.4 `src/manifest.json` page `CaseDetail`, widget `case-core`:
  `overrides.requester.widget = "InitiatorPicker"` so the edit form uses it.
  - `npm run check:manifest` exits 0

## 3. The card

- [ ] 3.1 `src/components/initiator/InitiatorSection.vue`: render the person
  card (name, type, identifying number, address, link to the source record);
  resolve the source row for the address and `indicatieGeheim`; fill the
  projection from `requester` when it is set and the projection is empty.
  - vitest in `src/components/initiator/InitiatorSection.spec.js`
  - `@spec openspec/specs/initiator-display/spec.md`
- [ ] 3.2 `InitiatorSection.vue`: the Protected marker; the BSN masked to
  its last four digits when `indicatieGeheim` is true; a Reveal button that
  fetches the `brpPerson` row through the object store with
  `_reason: "bsn-reveal"` and then shows the full number.
  - vitest: masked by default, full after reveal, one object-store read with
    the reason
- [ ] 3.3 `src/manifest.json` page `CaseDetail`: move widget `initiator` to
  the first row beside `case-core`; keep the layout cell budget (ADR-062).

## 4. The list

- [ ] 4.1 `src/manifest.json` page `Cases`: column
  `{"key": "initiatorDisplayName", "label": "Requester"}` after `title`, and a
  sidebar text filter on `initiatorDisplayName`.
- [ ] 4.2 [blocked: nextcloud-vue a `$ref` column on `CnIndexPage` that
  renders a label field of the referenced row (triage #8, Tier D06)] Replace
  the projection column with a `$ref` column over `requester`; 4.1 is the
  interim.

## 5. Verification

- [ ] 5.1 Add `tests/e2e/case-requester.spec.ts` covering every scenario of
  the three delta specs that names it: the picker on the New case form, the
  picker on the edit form, the uuid and projection on the saved case, the
  card, the empty card, the Requester column and filter, the masked BSN, the
  reveal, the unprotected person. Seed through `seedCase`, `createObject` and
  `ensureCaseType` from `tests/e2e/helpers/fixtures.ts`.
- [ ] 5.2 Update `tests/e2e/case-create-form.spec.ts`: `CREATE_FIELDS` grows
  to ten with `requester`.
- [ ] 5.3 Run `composer check:strict`, `npm run check:manifest`, the hydra
  gates, vitest and the unit suite locally; read the exit codes, not the
  summaries.
- [ ] 5.4 [blocked: openregister field-level read masking, so a protected
  person's BSN never reaches a reader who has not revealed it] Store a masked
  `initiatorSourceId` for protected persons and read the full number only
  through the reveal; 3.2 is the interim.
