---
kind: config
depends_on: [case-header]
---

# Proposal: case-timeline

Round 2 competitor analysis, row A05 of
`concurrentie-analyse/procest/_round2/compare/placement.md`. Rung 2 on the
placement ladder: manifest configuration on the `CaseDetail` sidebar that
already exists, using the `audit` sidebar tab (`CnAuditTrailTab`) that
nextcloud-vue 2.40.0 ships. Extends spec `case-dashboard-view`.

## Why

The case shows its history twice and tells you nothing. The sidebar holds a
History tab (`audit`, the `CnAuditTrailTab` over OpenRegister's audit trail)
and a Version history tab (`VersionHistoryLeafTab`, the same rows as a
field diff); 15 of the 17 rows on the baseline case are `admin read`, the
two Action and User filters sit on Loading, and documents, notes and mail
never appear (`_round2/dossiq-baseline/case-detail-anatomy.md`, Sidebar).
Every competitor keeps one log, newest first, with the actor: zaaksysteem a
Tijdlijn with type filters, a date range and Exporteren, carrying documents
and attribute changes on the same feed (`xxllnc-zaken/round2/pages/Case-Tijdlijn.md`);
opencase a Log tab of writes with actor and time
(`opencase/round2/pages/CaseDetail-Log.md`); gzac a Log of audit events per
dossier (`valtimo/round2/pages/CaseDetail-Log.md`). Findings row A05 scores
the gap 3.

## What changes

- **One History tab.** The `CaseDetail` sidebar keeps the `audit` tab,
  labelled History, as the case timeline, and the `version-history` tab
  goes from `CaseDetail` only. The other 13 detail pages keep theirs; they
  are owned by `ncvue-w2-leaves-adoption`, not by this row. The
  `VersionHistoryLeafTab` registry entry stays because those pages resolve
  it.
- **Writes only.** The tab opens on writes (create, update, delete) with
  reads behind the Action filter. `CnAuditTrailTab` renders the Action and
  User filters and reads `actionFilter` internally, but the `audit`
  sidebar widget declares no prop that presets it (its manifest schema
  carries `register`, `schema`, `objectId`, `title`, `maxDisplay`). The
  preset is a nextcloud-vue need, filed in placement section 3; interim:
  the History tab as it is, with the filters the tab already has.
- **Export.** An Export action on the tab writing the filtered rows as CSV.
  The tab has no export; a nextcloud-vue need, interim none.
- **One feed.** Document, note and mail events on the same list. That is
  the OpenRegister `activity` leaf (placement section 3, A05):
  `[blocked: openregister activity leaf]`, interim: the audit trail over
  writes only.

## Where the timeline lives

The timeline is the sidebar History tab, not a body tab. `case-header`
(REQ-CDV-16) names `case-timeline` sixth in `case-panels.content.tabs`,
which would put the same log in the body and in the sidebar, the very
duplication row A05 retires. This change therefore does not add a body
widget; task 4.1 of this change amends `case-header`'s order so the strip
reads Data, Documents, Parties, Tasks, Communication, then the
conditional four, and its REQ-CDV-16 text follows. Both changes sit
unarchived in this batch, so the amendment is one edit, not a spec sync.

## Not in this change

The instance-wide audit page (B14, openregister), toasts on write (B16),
the Communication tab (`contact-moments`, batch 2), and any PHP: the audit
rows are OpenRegister's, read through the tab.

## Decisions

D13 applies: ids and props are English. The label History stays English in
the manifest; nl.json carries Geschiedenis.
