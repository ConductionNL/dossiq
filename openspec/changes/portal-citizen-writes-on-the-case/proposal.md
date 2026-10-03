---
kind: code
depends_on: [portal-case-list-declarations]
---

# Proposal: portal-citizen-writes-on-the-case

Owner-moves pass of 2026-09-28. One half that portaliq's OpenSpec-pass lane
handed to dossiq after dossiq's own lane had finished: the `citizenWrite`
update action, with the case type declarations it points at. No matrix row of
its own; three open portaliq changes depend on it.

| requesting change (ConductionNL/portaliq) | what it asks of dossiq |
| --- | --- |
| `what-the-citizen-may-write-on-their-own-case` | "dossiq declares which fields a citizen may change and until when, raises a citizen task through the contribution, and listens for the portal write event to run its own rules." |
| `case-actions-withdraw-screen` (rows `act-withdraw-case`, `sib-dossiq-2-47`) | "**ConductionNL/dossiq owes** a `citizenWrite` update action on its case collection and `portalCaseType` records with `portalWithdrawal` for the case types a resident may withdraw." |
| `withdrawing-your-own-case-from-the-portal` | "dossiq declares on the case type whether a citizen may withdraw, until when, [...]" |

dossiq#3152 records it as a live defect: "The citizen case screen refuses every
dossiq case, because dossiq declares no `type: update` action with a
`citizenWrite` block on `case`." portaliq's `CitizenCaseController::context()`
answers 403 `portal-writes-not-declared` for every dossiq case, so `show()`,
`amend()`, `addDocument()` and `withdraw()` all stop there.

The listener half is built: `lib/Listener/PortalClientWriteListener.php`
(dossiq#3144) records a resident's amendment, document, task answer and
withdrawal on the timeline and tells the assignee. It hears nothing yet,
because portaliq refuses the writes before it raises the event.

## Why

A resident cannot open their dossiq case in the portal's case screen, cannot
correct an answer they gave, cannot add a document the handler asked for, and
cannot withdraw a request they no longer want. The screen, the rules and the
event are portaliq's and are built; the declaration that opens them is
dossiq's and is missing.

Decision `build`: a half that merged portaliq changes depend on.

## What changes

- The citizen contribution declares an update action on `case` that carries
  `citizenWrite`, naming where the case type lives and which case fields hold
  the status, the record of the resident's writes and their documents.
- A case type says, per field, whether a resident may change it and until
  which status, when documents are still accepted, and whether and until when
  a resident may withdraw, onto which status.
- A case type that says nothing offers nothing, as today.

## Capabilities

- Modified: `portal-contribution`.

## Out of scope

- The case screen, the writable set, the windows and the withdraw button:
  portaliq's.
- Recording the writes for the handler: built (dossiq#3144).
- Tasks published to the resident: `partner-tasks-in-the-portal` and the task
  delivery are portaliq's; raising one from a case is a separate change.
