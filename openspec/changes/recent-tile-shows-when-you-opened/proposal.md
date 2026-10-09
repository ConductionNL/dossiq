---
kind: config
depends_on: []
---

# Proposal: recent-tile-shows-when-you-opened

Product owner review of the DqDashboard board on 2026-10-09.

## Why

The Recently opened tile lists the last cases a reader opened, but not when.
The drawn board shows a muted relative date on every row, so a handler can
tell this morning's case from last week's at a glance.

## What changes

- The `recent-cases` tile on the Dashboard gets a third column bound to
  `@self.viewedAt`, the moment this reader last opened the case. OpenRegister
  adds it to the metadata envelope of every object the `_recent` lens returns
  (the OpenRegister read-history change). The column uses the library's
  built-in `daysSince` formatter and the muted, end-aligned cell the
  Stalled cases tile uses for `daysSinceActivity`.
- The tile's empty text says why it can be empty: the server does not log
  case views when the audit trail is off.
- REQ-FAV-02 gains two scenarios for both behaviours.
- Capability row 2.19 links to its spec, `openspec/specs/case-management`.

## Out of scope

- Logging reads. OpenRegister owns the read history and the `_recent` lens.
- A new formatter. `daysSince` ships in `@conduction/nextcloud-vue`.

## Dependency

Until the OpenRegister read-history change lands, `@self.viewedAt` is absent
and the date column renders empty. Nothing else on the tile changes.
