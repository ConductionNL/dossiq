---
kind: config
depends_on: []
---

# Proposal: timeline-entries-default-internal

Competitor gap register, row 6.15 "Internal or public visibility per
timeline entry" (`procest/_gaps/gap-register.md` in
ConductionNL/market-intelligence, 2026-09-13). Rated no, owner openregister,
slug `timeline-entry-visibility`, size S. The register's third pick to
build first: "seven of eight pass, dossiq fails, and the citizen portal
cannot show a case timeline until every entry says which side of the
counter it belongs on". This change is dossiq's half; the flag on the feed
entry is openregister's `timeline-entry-visibility`, to be specified there.

## Why

Every note and every logged call on a case is written for the handler and
readable by nobody else, because nothing says which entries an applicant
may see. `#PublicStatus` shows a status and no history; the portal
contribution hands portaliq no timeline.

The best competitors in the register: Zammad 7
`ticket_articles.internal`; osTicket thread type N; seven of eight systems
(`_round4/compare/promoted-rows-batch3.md`).

## What changes

- Every entry dossiq writes on the case feed defaults to internal: notes
  (`case-notes-panel`), logged contact moments (`case-communication-panel`),
  status changes. The note and contact forms offer "Visible to the
  applicant".
- Entries that leave the counter are public by construction: a
  Berichtenbox message, a portal message, a delivered beschikking, a
  status change of a status with a public label.
- The portal contribution hands portaliq the public entries as the case
  timeline; `#PublicStatus` shows the same list.

## Ownership

dossiq builds the defaults, the toggle and the two readers. It consumes
openregister `timeline-entry-visibility` (to be specified in openregister,
row 6.15) for the flag on the activity and notes leaves, and portaliq's
contribution contract (ADR-046) for the portal side.

## ADRs

- Company ADR-046: dossiq contributes; portaliq renders.
- Company ADR-054: a public surface shows only what is declared public.
- Company ADR-031: the default is declared per writer.

## Capabilities

- Modified: `portal-contribution`: the contribution carries a timeline of
  public entries.

## Impact

Note and contact forms (default and toggle); the writers for messages and
beschikkingen; `lib/Portal/PortalContributionProvider.php`;
`PublicStatusPage`; one e2e spec.
