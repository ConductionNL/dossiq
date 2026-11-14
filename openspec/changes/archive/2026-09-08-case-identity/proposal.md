---
kind: config
depends_on: []
---

# Proposal: case-identity

Round 2 competitor analysis, rows A13, A28 and A32 of
`concurrentie-analyse/procest/_round2/compare/placement.md`. Rung 1 on the
placement ladder: schema properties, read by widgets that already exist. One
noun: what identifies a case. Its number, its tags and its statutory fields.

## Why

`case.identifier` is free text. Five of the seven demo cases on the dashboard
show "-" where a number should be, and `ComplaintService::generateComplaintNumber`
numbers complaints only. The open change `email-case-matching` already assumes
a generated `YYYY-NNNN` number that nothing generates. A case has no tags, so
nothing groups cases across types. `archiveNomination`, `archiveActionDate`
and `paymentIndication` exist on the schema and are hidden on the page, the
type's `processingDeadline` is not shown on the case, and there is no legal
basis field.

Every competitor numbers the case for you and shows its statutory data:

- OpenCase: `opencase/round2/case-detail-anatomy.md` (a chip 2026-00002
  from the mask yyyy-#####).
- GZAC: `valtimo/round2/code-census.md`
  (`JsonSchemaDocumentDefinitionSequenceRecord`) and
  `valtimo/round2/pages/CaseDefinition-Tags.md` (tags with a colour).
- Zaaksysteem: `xxllnc-zaken/round2/case-detail-anatomy.md` (Zaak 2 in the
  top bar), `xxllnc-zaken/round2/search-anatomy.md` (filter Trefwoord) and
  `xxllnc-zaken/round2/pages/Case-MeerInformatie.md` (Afhandeltermijn,
  Archiefnominatie, Uiterste vernietigingsdatum, Wettelijke grondslag,
  Betaalstatus).
- Dossiq baseline: `_round2/dossiq-baseline/dashboard-anatomy.md` (tile 8)
  and `_round2/dossiq-baseline/case-detail-anatomy.md` (Core case data).

## What Changes

- `case.identifier` is declared as generated: a per-year sequence in the
  format `YYYY-NNNN`, read-only on every form and no longer on the New case
  form.
- `case.tags`, an array of strings, shown as a tags card in the case sidebar
  and offered as a filter on the Cases index.
- `case.legalBasis`, a string, and a Terms and archive block on `CaseDetail`
  showing the type's processing deadline, the legal basis, the archive
  nomination, the destruction date and the payment indication.

## Interim and durable route

The sequence is an OpenRegister feature: `x-openregister-calculations` with a
`sequence()` function scoped per year, the spelling `email-case-matching`
already documents. Until OpenRegister ships it, the number stays free text
and the New case form keeps the field. No dossiq code is written for it.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `case-management`: every new case gets a number; a case carries tags; the
  case shows its statutory lead time, archive and payment data.

## Impact

- `lib/Settings/dossiq_register.json`: `identifier` gains the calculation,
  `tags` and `legalBasis` are new properties on `case`.
- `src/manifest.json`: page `CaseDetail` (widget `case-terms`, sidebar tab
  `tags`, `identifier` read-only in `case-core`), page `Dashboard` (`identifier`
  leaves `new-case`'s `includeFields`), page `Cases` (a Tags filter).
- `l10n/en.json`, `l10n/nl.json`: the new labels.
- E2E: `tests/e2e/case-identity.spec.ts` (new).
- `email-case-matching` gains the generator it assumed. No effect on Pipelinq.
