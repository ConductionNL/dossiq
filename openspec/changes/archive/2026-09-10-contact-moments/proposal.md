---
kind: config
depends_on: []
---

# Proposal: contact-moments

Round 2 competitor analysis, row A17 of
`concurrentie-analyse/procest/_round2/compare/placement.md`. Rung 1 on the
placement ladder: a schema property and two manifest entries, read by widgets
that already exist. One noun: the conversations on a case.

## Why

A handler who takes a phone call about a case has nowhere on the case to
write it down. The `contactmoment` schema
(`lib/Settings/register.d/40-kcc-werkplek.json`) and
`ContactMomentController` exist for the KCC workplace and are API only: no
page lists a case's contact moments and no form logs one. The case page
holds Notes and Mail as two separate tabs, so three kinds of conversation
live in three places, one of them missing.

Both competitors put every conversation on the case in one pane:

- Zaaksysteem: `xxllnc-zaken/round2/pages/Case-Communicatie.md` (notes,
  contact moments, e-mail, PIP messages and letters in one thread list) and
  `xxllnc-zaken/round2/pages/Contactmoment-dialog.md` (Contact, Contactkanaal,
  Richting, Zaak, Samenvatting).
- OpenCase: `opencase/round2/pages/DocumentDetail-SendersRecipients.md`
  (direction, on a document only).
- Dossiq baseline: `_round2/dossiq-baseline/case-detail-anatomy.md` (Notes
  dead, Mail its own tab, no contact moments; findings.md row A17).

## What Changes

- `contactmoment.case`: a reference to the case the contact is about. The
  schema has `relatedCases`, a list of ids the KCC workplace fills; a list
  cannot be an `object-list` filter, and decisions D13 asks for an English
  name. `case` is that name.
- `CaseDetail` gains a Communication tab in `case-panels`: an `object-list`
  over `contactmoment` where `case` is the open case, with the columns date,
  channel, direction and summary.
- A header action Log contact of type `open-form` on `CaseDetail`, with the
  case prefilled, so you log a call or a visit without leaving the case.
- The Notes and Mail tabs move inside the Communication tab as sections once
  nextcloud-vue can render more than one widget in a tab. Until then they
  keep their tabs.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `kcc-werkplek-zaaksysteem-bridge`: a contact moment names its case; the
  case page lists its contact moments and logs a new one.

## Impact

- `lib/Settings/register.d/40-kcc-werkplek.json`: property `case` on
  `contactmoment`.
- `lib/Service/ContactMomentService.php`: `create` copies `case` through
  and, when `case` is set and `relatedCases` is empty, seeds `relatedCases`
  with it so the KCC voorblad keeps working.
- `src/manifest.json`: page `CaseDetail`, widget `case-communication` and a
  tab in `case-panels`, header action `log-contact`.
- `l10n/en.json`, `l10n/nl.json`: the new labels.
- E2E: `tests/e2e/case-communication.spec.ts` (new).
- Adjacent: `parties-on-the-case` (A03) and `custom-objects-on-the-case`
  (A27) wait on the same nextcloud-vue create-with-initial-data need;
  `case-actions-menu` (A33) orders the tab row this tab joins.
