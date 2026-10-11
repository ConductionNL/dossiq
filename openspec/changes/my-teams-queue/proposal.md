---
kind: code
---

# Proposal: my-teams-queue

Follow-up to one-team-model (dossiq #3533). A team is a Nextcloud group, and
nextcloud-vue now has the three pieces that one-team-model named as library
gaps (nextcloud-vue #1424, change `nextcloud-group-surfaces`): a group picker
in the inline editor, a group cell, and the `@myGroups` filter token.

## Why

"Your team's queue" on the Dashboard and the queue on the My team view of the
landing page show the SHARED queue: every unclaimed case of every team.
REQ-DASH-031 (archived as REQ-DASH-024) asked for the reader's own team and recorded why it could not be
expressed: no token named the reader's groups. The Team column on the Cases
index shows the raw group id.

## What changes

- The `your-teams-queue` preset and its copy on the My team view filter
  `assignedGroup` on `@myGroups` beside the Queue page's own conditions, so
  they show the unclaimed work of the reader's own teams. Their empty text and
  a prompt for the waiting state say so.
- The Team column on the Cases index uses the library's group cell
  (`widget: group`) and shows the team's display name.
- The case page's Data and Seats panels need no manifest change: the library
  now picks a group for a property marked `referenceType: nextcloud-group`.
- `_userWidgetsNote`, `_viewsNote` and `_teamColumnNote` are rewritten.

## Order

Merges after #3533 and after the nextcloud-vue release that carries #1424.
The installed library's manifest schema does not know `@myGroups` until then,
so `check:manifest` and two vitest assertions that read the schema fail until
`@conduction/nextcloud-vue` is bumped to that release and
`tests/schemas/app-manifest-v2.schema.json` is re-vendored from it.

## Out of scope

- A "My teams" quick-filter chip on the Cases index.
- The Queue page itself stays the shared queue.
