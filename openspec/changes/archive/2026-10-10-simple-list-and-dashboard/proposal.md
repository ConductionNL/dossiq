---
kind: code
depends_on: [simple-structure-profile, simple-case-page]
---

# Proposal: simple-list-and-dashboard

## Why

The cases list shows 16 columns and 19 quick filters. The dashboard answers how
the team is doing, and a handler who opens it wants to know what to do first.
The Zuiddrecht design (`DqZaken`, `DqDashboard`, `DqWerkbord`, 4 October 2026)
gives the list five views with counts and six columns, marks late cards on the
board, and opens the dashboard with the handler's own day.

This change does that in the simple structure. The full structure keeps the
three pages as they are.

## What changes

1. **Cases list.** Five views lead the strip, each with a count: All, Mine, Due
   this week, Waiting on the applicant, Woo requests. The other fifteen lenses
   stay, behind the overflow chip. Six columns: number, title, type, status,
   handler (with a picture) and deadline (in colour).
2. **Board.** A deadline that ends today counts as late. The card reads a
   `dueRule` from the board page.
3. **Dashboard.** A greeting, a card "First today", four counts of your own
   cases, the deadlines of this week, your cases per step and your tasks. What
   the dashboard held before follows underneath.
4. **Overlays** can now patch list items by `key` or `label`, move named items
   to the front (`configOrder`) and add page `slots`.

## What the design shows and dossiq has no data for

Not invented:

- **The one case on "First today"** and its button "Suspend the term". The card
  counts cases. It is not bound to a record.
- **The notes under the four counts** (new this week, longest wait, last
  month). No endpoint gives them.
- **A "Publish" step in the bar.** No status role means publishing. Cases whose
  status type has no role fall outside the four named steps.
- **Recent activity.** dossiq has no activity feed per person.
- **The switch "My work / My team".** The team widgets follow below instead.
- **The handler's name in the list.** The case holds the user id of its handler
  and no display name, so the id stands beside the picture.
- **Requester under the title** in one cell. A column shows one field.
- **The board per case type, as the design draws it.** The board is dossiq's own
  component. Only the late marking changes.

## Impact

- The dashboard lets a handler arrange it (`userLayout`). Whether an
  arrangement stored before this change hides the new default was not checked.
- The ten columns that leave the simple list stay in the full structure.
