# Design: case-identity

## Context

The `case` schema (`lib/Settings/dossiq_register.json`) carries `identifier`
(string, free), `archiveNomination`, `archiveActionDate`, `archiveStatus`,
`paymentIndication` and `lastPaymentDate`. The `caseType` schema carries
`processingDeadline`. `CaseDetail` (`src/manifest.json`, type `detail`) shows
`identifier` in the `case-core` data widget and hides the archive and payment
fields; its sidebar has tabs History, Version history, Notes, Sharing, Email
and Besluitvorming. `Cases` (type `index`) has a sidebar with filters over the
columns. `Dashboard`'s `new-case` header action includes `identifier` in no
field list today, but the edit form on the case shows it as editable text.
`@conduction/nextcloud-vue` 2.40.0 ships `CnTagsCard`.

ADR-032 kind: **config**. Every change is a schema property or a manifest
entry.

## Goals / Non-Goals

**Goals:**

- Every new case gets a number without anyone typing one.
- A case carries tags you can filter on.
- The case page shows the lead time, legal basis, archive and payment data
  the schema already holds.

**Non-Goals:**

- Per-type tag vocabularies with colours (GZAC). Free tags first.
- A masked BSN and protected addresses (`requester-on-the-case`, A18).
- Renumbering existing cases. Cases with a number keep it.

## Decisions

### D1: the number is an OpenRegister calculation

`case.identifier` gains
`x-openregister-calculations: concat(year(startDate), "-", sequence(scope: "yearly", pad: 4))`
and `readOnly: true`. `email-case-matching` design point 1 spells the same
thing. The sequence counts per register and per year, so the first case of
2027 is `2027-0001`. A case that already has an identifier keeps it: the
calculation only runs when the field is empty. On the forms `identifier`
leaves `new-case`'s `includeFields` and gets `editable: false` in
`case-core`'s overrides. If OpenRegister has no `sequence()` yet, the task is
blocked and the field stays as it is.

### D2: tags are strings on the case

`case.tags` is `{"type": "array", "items": {"type": "string"}}` with
`x-openregister-facet: true` so the index can filter on it. The sidebar of
`CaseDetail` gains a tab `tags` (label Tags, icon TagMultiple) whose widget is
`data` over the single field `tags` with `overrides.tags.widget = "tags"`, the
form widget `CnTagsCard` backs. The `Cases` sidebar gains a filter on `tags`.
The alternative, a `tags` widget type in the page body, was rejected: the
sidebar is where the History tab already lives, and tags are metadata.

### D3: a Terms and archive block on the case

A `data` widget `case-terms` (title Terms and archive, icon Archive) with
`include: ["legalBasis", "archiveNomination", "archiveActionDate", "archiveStatus", "paymentIndication", "lastPaymentDate"]`
and `overrides` marking `archiveActionDate` read-only. The type's
`processingDeadline` renders from `caseType` through
`extend: ["caseType"]` and the include path `caseType.processingDeadline`,
labelled Statutory lead time. The widget takes the layout cell to the right of
`case-core`, under the KPI row, so the page stays inside its cell budget
(ADR-062). `case.legalBasis` is a plain string; the AVG vocabulary lives on the
case type (`case-type-authoring-extras`, A31), and the case carries the
statutory article in prose.

### D4: the dashboard row reads the number

The `Dashboard` tables and the `Cases` columns already render `identifier`.
Nothing changes there; the "-" disappears because the field is filled.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | reason |
|---|---|---|
| Case number | declarative, `x-openregister-calculations` | OpenRegister owns sequences; dossiq declares the format. |
| Tags | declarative, a schema property and a facet | Data, no behaviour. |
| Terms and archive | declarative, a `data` widget over existing fields | Presentation. |

## Seed Data

- The demo cases in `46-demo-cases-english.json` get `identifier` values in
  the `YYYY-NNNN` shape so the dashboard reads the same before and after the
  calculation lands, plus one or two `tags` per case (`spoed`, `wijk-noord`).
- `legalBasis` is filled on the bezwaar and subsidie demo cases.

## Risks / Trade-offs

- A yearly sequence needs `startDate` at write time. `new-case` sets
  `startDate` from the form; the API path in `ZrcController` sets it to today
  when absent. The E2E asserts the API path too.
- A free-text tag field invites variants (`Spoed`, `spoed`). Accepted for
  now; a vocabulary is the next step, not this one.
- Adding `format` to an existing property is breaking in OpenRegister
  (memory: or-gotchas). `identifier` gets a calculation and `readOnly`, not
  a `format`.
