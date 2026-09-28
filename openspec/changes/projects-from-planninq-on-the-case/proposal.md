---
kind: config
depends_on: []
---

# Proposal: projects-from-planninq-on-the-case

Owner-moves pass of 2026-09-28. The dossiq half of planninq's open change
`integration-case-bridge`, handed to dossiq after dossiq's own lane had
finished. No matrix row of its own; the planninq rows are
`int-project-from-case` and `int-archive-to-case` in planninq's
`openspec/parity/capabilities.json`, both `specified` with that change.

planninq `integration-case-bridge`, verbatim: "On a case's page in Dossiq,
planninq's Projects panel lists the projects linked to that case and offers
'New project', which opens planninq's New project dialog with the case linked
and the case title filled in", and under cross-project: "ConductionNL/dossiq
adds planninq's leaf to its case schema's linked types." Its task 4.1 opens
the issue for this half.

Demand on the planninq side: `int-archive-to-case` carries a tender row,
TenderNed 365739, gemeente Sittard-Geleen, requirements 4054 and 4126
(<https://www.tenderned.nl/aankondigingen/overzicht/365739>): hand a project's
files and metadata over unchanged to the case system.

## Why

A handler who needs a project for a case (a reconstruction, a permit that
needs works) has to start it in planninq by hand and cannot see it from the
case. The planninq leaf and its case scope are planninq's to build; showing it
on the case page is dossiq's.

Decision `build`: a half that a merged planninq change depends on, on rows
that carry tender demand.

## What changes

- `case.linkedTypes` gains `planninq-projects`.
- The case page shows a "Projects" panel: planninq's leaf, scoped to the case,
  with its "New project" button.
- On an instance without planninq the panel is absent, not empty.

## Capabilities

- New: `case-linked-projects`.

## Out of scope

- The leaf, its case scope, the New project dialog and the handover of
  files: planninq's.
- A project field on the case. The link lives on planninq's
  `project.caseReference`.
