---
kind: config
depends_on: [documents-on-the-case, parties-on-the-case]
---

# Proposal: case-header

Round 2 competitor analysis, rows A01 and A33 of
`concurrentie-analyse/procest/_round2/compare/placement.md`. Rung 2 on the
placement ladder: manifest configuration on the `CaseDetail` page that
already exists, using the `subtitleField`, `header` widget, `CnStatusBadge`
and `CnBreadcrumbs` pieces that nextcloud-vue 2.40.0 ships. Extends spec
`case-dashboard-view`.

## Why

The case page does not say which case you are on. The header renders the
eyebrow CASE and the title, nothing else; identifier, case type, status,
assignee and deadline sit inside the Core case data widget, and Identifier
hides behind Show all 12 fields
(`_round2/dossiq-baseline/case-detail-anatomy.md`, Header and Core case
data). There is no breadcrumb and no way back to the list except the menu.
Every competitor puts the identity in the header: opencase shows chips for
number, type, status and organisation under the title with a breadcrumb
(`opencase/round2/case-detail-anatomy.md`, Header), gzac a status pill, the
assignee as a link and a breadcrumb (`valtimo/round2/case-detail-anatomy.md`,
Header), zaaksysteem an info card and the top-bar title Zaak 2
(`xxllnc-zaken/round2/case-detail-anatomy.md`).

The tab strip (A33) holds ten tabs that wrap onto three lines at 1440 and
fall below the fold at 1024; two are dead, one is blank, one stuck loading
(baseline, Case panels). opencase keeps its conditional tabs absent rather
than empty; gzac shows four tabs; zaaksysteem a side menu of five with Kaart
and Samenwerkingen conditional.

## What changes

- **A01, the subtitle.** `CaseDetail.config.subtitleField` names
  `identifier`, so the case number reads under the title. The full line
  from identifier, case type and assignee needs a templated `subtitle`,
  which the 2.40.0 detail page does not have (placement section 3, field
  chips in a detail header): interim `subtitleField: identifier`, the rest
  `[blocked: nextcloud-vue]`.
- **A01, the header row.** A `header`-row widget `case-header` on the top
  layout row renders `CnStatusBadge` with the status type's name and the
  deadline countdown that `case-kpi-time-left` shows today, so the two KPI
  tiles fold into one row under the title. Case type stays as the
  `case-kpi-casetype` tile until the subtitle can carry it.
- **A01, the breadcrumb.** `breadcrumbs` on `CaseDetail`: Cases (route
  `Cases`) then the case title, rendered by `CnBreadcrumbs`.
- **A33, the tab order.** `case-panels.content.tabs` in the order Data,
  Documents (`case-documents`, from `documents-on-the-case`), Parties
  (`case-roles`, from `parties-on-the-case`), Tasks (`case-tasks`),
  Communication (`case-communication`, from `contact-moments`), Timeline
  (`case-timeline`, from `case-timeline`; the History sidebar tab until it
  lands). Sub-cases, Locations, Appointments and Decisions follow, and show
  only when they hold something. That needs `visibleIf` on a tab entry,
  which `CnTabsWidget` 2.40.0 does not read: `[blocked: nextcloud-vue]`,
  interim: the four stay last in the strip. Files, Notes, Mail, Related
  cases and Contacts leave the strip once their sibling changes fold them
  (Files into Documents, Notes and Mail into Communication, Contacts into
  Parties); until then they sit between Timeline and the conditional four.

## Not in this change

The stepper (`case-lifecycle-on-the-page`, REQ-CDV-13), the Documents,
Parties and Communication tabs themselves, the Timeline widget, and the
state controls (A02). This change only orders what those deliver.

## Decisions

D13 applies: widget ids, field names and route names are English. Labels
follow the existing English manifest; nl.json carries the Dutch.
