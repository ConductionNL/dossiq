# Proposal: dialogs-answer-what-the-boards-draw

## Why

The dialog boards (DqVolgendeStap, DqBerichtVersturen, DqZaakBesluiten, DqZaak, DqZaakLocatie, DqDocumentGenereren)
draw things the backend did not answer: the remedy entry in the lifecycle menu, a Berichtenbox refusal by code, the
kind and subject of an advice request and the outcome of a received advice, a BAG refusal by code, and a template's
notes. One defect sat underneath: an advice request was written to the `adviceRequest` schema while the list, the
transitions and the reminders read `adviesAanvraag`, so a new request never showed and could not be received.
Another: `location_schema` was mapped from a schema slug the register does not have, so the BAG check on a location
save never ran.

## What changes

- `GET /api/case/{id}/acts` answers `remedies: [{ id, label, description, available, reason }]` from the remedy the
  case type declares (`remedy.id`, `remedy.label`, `remedy.description`, fragment
  `99-case-type-remedy-menu-entry.json`); available once a decision was made known (`decisionSentOn`). The Woo request
  case type (version 2) names its remedy `objection`.
- The Berichtenbox send answers `code: provider-not-configured` (the adapter's code as `cause`) when no digital post
  provider is set up. The message is still recorded as not sent.
- `POST /api/cases/{id}/advice-requests` writes to `adviesAanvraag` with `type` (intern/extern from `kind`),
  `subject`, `questions` and `requestedBy`; an external advisor is not notified as a user. Receiving stores
  `conclusion`, `adviceText` and `conditionsChecked`, and the notification carries the summary. Fragment
  `99-advice-request-kind-and-outcome.json` (adviesAanvraag 1.3.0).
- A refused location save carries `code` `bag-address-unknown` (with `addressId`) or `bag-check-unavailable`.
  `case-location` now backs `location_schema`.
- The template list forwards each template's `notes`.

## Scope

Generic. The requester reminder on Suspend (DQB-E S1) is not in this change.
