---
kind: code
depends_on: []
---

# Proposal: requester-on-the-case

Round 2 competitor analysis, rows A04, A18 and A35 of
`concurrentie-analyse/procest/_round2/compare/placement.md`. Rung 1 on the
placement ladder for A04 and A18 (a property on an existing schema, bound to
the form and the card), rung 3 for A35 (a column and a filter on an existing
index). One noun: the person who asked.

## Why

You cannot name the citizen or company when you file a case. The New case
form on `Dashboard` lists nine fields and none of them is the requester
(`_round2/dossiq-baseline/journeys.md` J2, step 1). Edit on `CaseDetail`
shows Requester as a disabled text field whose placeholder says it is set
when the case arrives via an integration (J2, step 5). The cause is in
`dossiq-defect-triage.md` #6: `case.requester` is a semantic reference to
`https://openregister.app/ns#Requester`, and no schema in the fleet declares
itself a provider of that type, so `CnFormDialog` renders the field disabled
by design. `InitiatorPicker.vue` is registered as a form field and bound to
no field. The `initiator` widget on `CaseDetail` renders nothing because the
projection fields are never written. Nothing on a person says its address is
protected. The case list has no requester column, because no requester is
set.

Every competitor names the requester at creation and shows them on the case:

- OpenCase: `opencase/round2/create-case-anatomy.md` (Select citizen; the
  party line in the header) and `opencase/round2/pages/Search-Citizen.md`
  (masked CPR with an audited reveal; protected address; the citizen 360
  lists the cases).
- GZAC: `valtimo/round2/pages/CaseCreate.md` (step 1 Aanvrager, prefilled
  from the login), `valtimo/round2/pages/CaseList.md` (Aanvrager column) and
  `valtimo/round2/code-census.md` (field-level PBAC conditions).
- Zaaksysteem: `xxllnc-zaken/round2/pages/Zaak-aanmaken-dialog.md` (Aanvrager
  type and finder), `xxllnc-zaken/round2/case-detail-anatomy.md` (Aanvrager
  link in the info card), `xxllnc-zaken/round2/search-anatomy.md` (default
  column Aanvrager; filters Aanvrager, Indicatie geheim) and
  `xxllnc-zaken/round2/pages/ContactBeeld-persoon.md`.
- Dossiq baseline: `_round2/dossiq-baseline/journeys.md` J2 and J6, and
  `_round2/dossiq-baseline/case-detail-anatomy.md` (no party on the page).

## What changes

- `brpPerson` and `kvkCompany` declare `implements:
  ["https://openregister.app/ns#Requester"]`, so `case.requester` has a
  provider and the form enables it.
- The New case form on `Dashboard` and the edit form on `CaseDetail` carry
  `requester`, rendered by `InitiatorPicker` through `fieldOverrides`. The
  picker writes the chosen row's uuid to `requester` and fills
  `initiatorType`, `initiatorSourceId` and `initiatorDisplayName` from it.
  One write path, as `semantic-case-intake` requires.
- The `initiator` slot on `CaseDetail` renders a person card: name, type,
  identifying number, address, and a link to the source record.
- `brpPerson` gains `indicatieGeheim`. A protected person's card carries a
  marker and renders the BSN masked. Reveal fetches the person row through
  OpenRegister, which logs the read against the case's processing activity.
- `Cases` gains a Requester column over `initiatorDisplayName` and a sidebar
  text filter on it.
- **BREAKING** for nothing: cases that arrive through the semantic handoff keep
  writing `requester` the way they do today.

## Interim and durable route

The durable route for the list column is a `$ref` column that renders a label
field of the referenced object (`placement.md` section 3, triage #8, Tier
D06). Until `CnIndexPage` ships that, the column reads the denormalised
`initiatorDisplayName`, which the picker keeps in step with `requester`. The
masked BSN is a display rule on the card; field-level read masking belongs in
OpenRegister and is listed in tasks as blocked.

## Capabilities

### New capabilities

None.

### Modified capabilities

- `initiator-selection`: the picker is bound to `requester` on both forms;
  the register sets provide `ns#Requester`; the picker writes the canonical
  uuid and the projection in one save.
- `initiator-display`: the initiator slot renders a person card; the list
  names the requester and filters on it; a protected person's BSN stays masked
  until you reveal it.
- `brp-register`: `brpPerson` carries `indicatieGeheim`; at least one seeded
  persona is protected.

## Impact

- `lib/Settings/register.d/25-brp-kvk.json`: `implements` on `brpPerson` and
  `kvkCompany`; `indicatieGeheim` and `logReads` on `brpPerson`; one seeded
  persona flagged.
- `lib/Settings/dossiq_register.json` and `lib/Settings/dossiq_mock_register.json`:
  the `case.requester` description drops the integration-only wording.
- `src/manifest.json`: page `Dashboard` (`new-case` `includeFields` and
  `fieldOverrides`), page `CaseDetail` (`case-core` overrides, the `initiator`
  widget), page `Cases` (`columns`, sidebar filter).
- `src/components/initiator/InitiatorPicker.vue` (emits the uuid plus the
  projection; `appliesTo` on the registry entry), `InitiatorSection.vue` (the
  card, the mask, the reveal).
- `lib/Service/External/Brp/HaalCentraalBrpAdapter.php` and
  `LogBrpHaalCentraalAdapter.php`: map `geheimhoudingPersoonsgegevens` to
  `indicatieGeheim`.
- E2E: `tests/e2e/case-requester.spec.ts` (new).
