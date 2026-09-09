# Design: requester-on-the-case

## Context

`case.requester` (`lib/Settings/dossiq_register.json:1764`) is
`{"type": "string", "format": "uuid", "referenceSemanticType":
"https://openregister.app/ns#Requester"}`. `CnFormDialog` resolves that URI
against OpenRegister's discovery endpoint and renders the field disabled with
a tooltip when no installed schema implements it (triage #6). A schema says it
implements a semantic type with `implements: ["<uri>"]`, the key
`semantic-case-intake` already uses for `ns#Case` on `case`. Nothing in the
fleet implements `ns#Requester`.

The picker exists. `InitiatorPicker.vue` (`src/registry.js:194`, kind
`form-field`, no `appliesTo`) searches `brpPerson` and `kvkCompany` through
the object store and Nextcloud Contacts, and emits `select` with a unified
result. `InitiatorPickerModal.vue` wraps it for `StartCaseWidget`.
`InitiatorSection.vue` (slot `widget-initiator`) renders name, type and source
id from the projection fields and nothing when they are empty. The three
projection fields `initiatorType`, `initiatorSourceId` and
`initiatorDisplayName` live on `case`. `HaalCentraalBrpAdapter` and
`KvkApiAdapter` (`lib/Service/External/`) are the live adapters behind the
register tier; the `Log*` adapters are their offline stand-ins.

Pages touched, by manifest id: `Dashboard` (header action `new-case`, an
`open-form` over `case`), `CaseDetail` (widget `case-core`, the edit form it
opens, and the `initiator` slot in the overview) and `Cases` (`columns`,
sidebar).

ADR-032 kind: **code**. The centre of mass is the picker binding, the card
with its mask and reveal, and the adapter mapping. The schema and manifest
edits ride along.

## Goals / Non-goals

**Goals:**

- You pick the citizen or company when you file a case, on `Dashboard` and
  on `CaseDetail`.
- The case shows the requester at the top, with a link to the source record.
- You see when a person's data is protected, and the BSN stays masked until
  you reveal it. The reveal is logged by the platform.
- The case list names the requester and filters on the name.

**Non-goals:**

- Everyone else on the case (`parties-on-the-case`, over `role`).
- Prefilling the requester from the signed-in user for a citizen portal
  (`zaakportaal`).
- Field-level read masking in OpenRegister (listed as blocked).
- A citizen 360 page (rung 4, not in this change).

## Decisions

### D1: the register sets implement `ns#Requester`; `requester` stays a uuid

Two ways to enable the field:

1. Replace `referenceSemanticType` with a `$ref` to one party schema.
2. Keep the semantic reference and declare providers.

Option 1 loses the handoff: `semantic-case-intake` writes `requester` from
whatever schema implements the type in the sending app, and a `$ref` binds
the field to one dossiq schema. Option 2 keeps that path and enables the
field. `brpPerson` and `kvkCompany` gain
`implements: ["https://openregister.app/ns#Requester"]` in
`lib/Settings/register.d/25-brp-kvk.json`. A Nextcloud contact has no
register row, so a contact requester fills the projection only and leaves
`requester` empty. That is the same shape a case has today.

### D2: `InitiatorPicker` is the widget for `requester` on both forms

`fieldOverrides.requester.widget = "InitiatorPicker"` on the `new-case`
action of `Dashboard` and on the `case-core` overrides of `CaseDetail`. The
registry entry gains `appliesTo: ["case.requester"]`, which silences the boot
warning triage #6 records. The picker's `select` payload grows to
`{requester, initiatorType, initiatorSourceId, initiatorDisplayName}`; the
form writes all four in one save. `requester` is the uuid of the chosen
`brpPerson` or `kvkCompany` row. `requester` joins `includeFields` on
`new-case` after `title`. `InitiatorPickerModal` keeps working for
`StartCaseWidget` and emits the same payload.

### D3: the card renders from the projection, and resolves the source row for the rest

`InitiatorSection.vue` renders name, type and identifying number from the
projection so the card paints without a second request. It then resolves the
source row by `initiatorSourceId` (the method already exists for the deep
link) to add the address for a person or the vestigingsadres for a company,
and `indicatieGeheim`. The card keeps the `initiator` widget id and slot; it
moves to the first row of the `CaseDetail` layout, beside `case-core`. When
`requester` is set and the projection is empty (a case that arrived through
the handoff before this change), the card resolves the row by uuid and fills
the projection on first render. `CnPeopleWidget` was considered and rejected:
it is a directory of Nextcloud users, not a card for one register row.

### D4: the BSN of a protected person is masked, and the reveal is a logged read

`brpPerson.indicatieGeheim` is a boolean. It maps from Haal Centraal
`geheimhoudingPersoonsgegevens` (any value other than 0 is true) in
`HaalCentraalBrpAdapter` and `LogBrpHaalCentraalAdapter`. When true, the card
shows a Protected marker and renders the BSN as `••••• ` plus the last four
digits. The Reveal button fetches the `brpPerson` row through the object store
with `_reason: "bsn-reveal"`. `brpPerson` gets `logReads: true`, so
OpenRegister records the read, attributed to the case's processing activity
(`avg-verwerkingenlogging`). Dossiq writes no log row itself, as that spec
requires. The mask is a display rule: `initiatorSourceId` still carries the
BSN on the case object. Storing a masked projection for protected persons
needs OpenRegister to mask a field on read; that is the blocked task.

### D5: the list column reads the projection until `$ref` columns render labels

`Cases` gains `{"key": "initiatorDisplayName", "label": "Requester"}` after
`title`, and the sidebar gains a text filter on the same field. The durable
form is a `$ref` column over `requester` that renders the provider row's
label field; `CnIndexPage` cannot do that yet (triage #8). The projection
column needs no nextcloud-vue change and the picker keeps it in step, so
nothing here is thrown away when the `$ref` column lands: the column
definition changes, the data does not.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | reason |
|---|---|---|
| Which schemas may be a requester | declarative, `implements` on the schemas | The platform resolves providers; dossiq lists none in code. |
| Picking a requester | the existing `InitiatorPicker` as a form-field widget | Three sources, one of them outside OpenRegister (Contacts). |
| Writing the uuid and the projection | the picker's payload, one form save | One write path, no service in between. |
| The mask and the reveal | a display rule in the card, a platform-logged read | No dossiq audit table; `logReads` does the logging. |
| The column and the filter | declarative, manifest `columns` and sidebar | A projection field is a plain column. |

## Seed data

`25-brp-kvk.json`: `brpPerson` gains `indicatieGeheim` (boolean, default
false) and `logReads: true`. One of the ten seeded personas gets
`indicatieGeheim: true`, chosen from the personen-mock rows that carry
`geheimhoudingPersoonsgegevens`, never invented. The demo cases in
`46-demo-cases-english.json` stay as they are; the e2e seeds its own case.

## Risks / Trade-offs

- The BSN is on the case object for every reader of `case`. The mask hides
  it in the card and nowhere else. Mitigated by `logReads` on `case`, which is
  already on, and named as a blocked task.
- A schema that implements `ns#Requester` in a sibling app would also appear
  in the picker's provider set. The picker searches its three sources by
  name, so an extra provider adds nothing to the dropdown today.
- `implements` on `brpPerson` makes the register-set import a schema change.
  The import is additive (REQ-BRP-001), so existing rows stay valid.
- Two entry points write the projection: the picker and the handoff. The
  card's first-render fill in D3 covers the handoff's gap.
