---
kind: code
depends_on: [documents-live-on-the-case]
---

# Proposal: approval-chain-on-the-document

Parity ledger row 3.13, "document review or approval workflow". decidiq owns
the engine and shipped the surface: `document-approval-chain-leaf`, nine of
its ten tasks done on `parity/round2`, the tenth being screenshots. What is
left is the half `competitor-parity-2026-09` names in one line: place the
leaf on the case's documents and read the outcome.

## Why

A concept letter goes round three people before it is sent. Today dossiq can
hold that route for exactly one kind of document, a beschikking, through
`PATCH /api/beschikkingen/{id}/akkoord` and `/onderteken`. Every other
document goes round by e-mail, and the case keeps no record of who agreed to
what.

dossiq used to have a general one. Parafering was retired on the rule that
sign-off belongs to decidiq, which was right, and it left a hole nobody
filled: the engine moved and no surface took its place.

## What is actually there

Read against `parity/round2` at `c3bdf65d`.

- Parafering is gone. Two mentions survive, one in a menu-layout note and one
  in a template, and no route, service or component.
- decidiq registers `decidiq-approval-chain`, a render-surface leaf with a
  tab (the timeline of every action and its reason) and a widget (the current
  step, its due date, and approve and reject for the current actor). Start,
  approve and reject run through decidiq's own controller, and the leaf
  invokes nothing in the consuming app.
- dossiq already consumes decidiq's other leaf the same way:
  `src/components/tabs/BesluitvormingLeafTab.vue` resolves
  `decidesk-decisions` from `window.OCA.OpenRegister.integrations` at render
  time and forwards the case context, handling both the component and the
  mount render modes.
- A document on a case is an `informatieobject` record
  (`lib/Settings/register.d/70-document-zaakdossier.json`), reached from the
  file row on the Files tab, and it carries the ZGW lifecycle
  `concept`, `definitief`, `gearchiveerd`.

So the wrapper pattern, the object to hang the leaf on and the status to read
into all exist. Nothing places the leaf.

## What changes

- A leaf wrapper for `decidiq-approval-chain`, built the way
  `BesluitvormingLeafTab` is built, so one pattern covers both decidiq leaves
  and neither drifts.
- The leaf is placed on the document record, not on the file row: the leaf
  takes a register, a schema and an object id, and a file is not an object.
  It renders as a section of the document properties surface, so the route
  sits next to the metadata the same person maintains.
- The case's Files tab shows, per row, that a document is in a route and at
  which step, so a handler does not have to open each document to find out
  what is waiting on them.
- dossiq reads the outcome and does not act on it by itself. A completed
  approved route SHALL make the `definitief` transition available on the
  document and name the route as its ground. A document with an open route
  SHALL NOT be moved to `definitief`.

## The one decision in here

Making a document `definitief` locks it. dossiq offers that act once the
route says yes, and a person makes it. An automatic transition would mean
the app locks a document because a third approver clicked a button in a
widget, and the person who has to defend the lock is not that approver. The
route is the ground for the act, not the act.

## Capabilities

### Modified Capabilities

- `besluitvorming-leaf`: the capability that already governs how dossiq
  consumes decidiq's surfaces gains the approval chain beside the decisions
  leaf.

## Impact

- **Frontend**: one wrapper component under `src/components/tabs/`, its
  registry key in `src/registry.js`, and its placement plus the row marker in
  `src/manifest.json`.
- **PHP**: a read of the route state for the file-row marker, and the
  guard that refuses `definitief` while a route is open, in
  `lib/Service/Zaakdossier/InformatieobjectStatusLifecycle.php`.
- **Schemas**: none. The route lives in decidiq.
- **Risk, measured before it bites**: a cross-app leaf is dark when nothing
  loads the registering bundle on the page. The first task checks the network
  log on a real case page rather than trusting the registration, because a
  leaf that never registers renders the same quiet unavailable notice as a
  decidiq that is not installed.
