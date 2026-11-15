---
kind: config
depends_on: [documents-on-the-case, gemachtigde-role-on-every-case-type]
---

# Proposal: document-correspondents

Competitor gap register, row 5.12 "Document senders and recipients"
(`procest/_gaps/gap-register.md` in ConductionNL/market-intelligence,
2026-09-13). Rated partial, owner dossiq, size S.

## Why

A letter on a case does not say who sent it or who received it. The
`dispatch` schema in `lib/Settings/dossiq_register.json` declares an
involved party with a relationship type "afzender/geadresseerde", and
`zaaktypeInformatieobjecttype.direction` says inbound or outbound per type.
Both are schema only: nothing writes a dispatch when a beschikking goes
out, nothing writes a sender when mail intake files an attachment, and the
Files tab (`#CaseDetail/case-files`, the `files` leaf) shows name, size and
date.

The best competitor in the register: xxllnc Zaken,
`backend/zaken/src/zsnl_domains/document/entities/document.py` (direction)
(`_round2/compare/M1-functionality.md`).

## What changes

- `sender` and `recipients` on the `informatieobject` schema, each holding
  a party OF THE CASE in the role `afzender` or `geadresseerde`. Never a
  typed name. `direction` is already there and keeps its three values.
- Every write that files an outgoing document sets `recipients` and
  `direction: outgoing`; the mail intake sets `sender` and
  `direction: incoming`, matching the From address against the parties of
  the case.
- The document properties dialog edits both with a party picker, offering
  exactly the parties the case has.
- The dossier listing carries both, resolved to names, so a row can show
  them and the tab can filter on a correspondent.
- The People tab shows, per party, the documents they sent and received.
- A `dispatch` record is written per correspondent, which is the first
  writer that schema has had.

## Ownership

dossiq builds the two fields, the writers and the surfaces. It consumes
the party model from openregister#3761 (`GET /api/objects/{r}/{s}/{id}/parties`
and `ContactService::getContactsForObject`) and the six generic link roles
the `gemachtigde-role-on-every-case-type` change put on the case schema. The
Files tab's own extra columns wait on nextcloud-vue `files-browser-columns`
(row 4.8); until that lands the manifest declares them and the properties
dialog and the dossier listing carry the values.

## ADRs

- Company ADR-031: correspondents are declared fields, not a service.
- Company ADR-037: the schema change ships as a `register.d/` fragment.
- Company ADR-022: the party model is read from OpenRegister, not wrapped.

## Capabilities

- Modified: `document-zaakdossier`: a document knows its sender and its
  recipients, and both are parties.

## Impact

`lib/Settings/register.d/71-document-correspondents.json` (new);
`lib/Service/Zaakdossier/` (the rules and the writer);
`lib/Service/ZaakdossierService.php` (upload and metadata);
`lib/Service/Actions/MergeTemplateHandler.php` (the outgoing letter);
`lib/Service/Email/InboundMailIntake.php` (the incoming message);
`src/modals/DocumentMetadataDialog.vue`;
`src/components/case/CasePartiesWidget.vue`; `src/manifest.json`.

## Superseded within this change

The first draft of 2026-09-13 modelled each correspondent as
`{party?: ref, name: string}` and retired the `dispatch` schema. Both are
reversed here. Free text was dropped because a typed name cannot be
counted, cannot be filtered, and goes stale the day the party is
corrected. `dispatch` is kept because it is the ZGW Verzending record and
the natural home for the per-send date, and because the parties change that
landed in between (dossiq#2849) made a real party the thing to point at.
