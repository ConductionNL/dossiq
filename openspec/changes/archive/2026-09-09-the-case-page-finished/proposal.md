---
kind: config
depends_on: [case-header, documents-on-the-case, parties-on-the-case, contact-moments]
---

# Proposal: the-case-page-finished

Phase 3 change DQ2. Rung 2 on the placement ladder: manifest configuration on
the `CaseDetail` page, plus one small widget component that the shared
dashboard catalog already has a slot for. Extends spec `case-dashboard-view`
and supersedes REQ-CDV-16 from `case-header`.

## Why

The brief for the whole round 2 programme asked for one thing above the
capability matrix:

> Special attention must be given that we keep the complexity of the pages and
> menus in check for the users. Last time we did this we added a large amount
> of pages and menu items that we later had to abstract away because we made
> the functionality way too concrete.

Round 2 honoured that for the menu, which still holds four top-level entries
against a ceiling of six. It did not honour it for the case page. The
`case-panels` strip held ten tabs when the programme started and holds
fourteen now, a 40 percent growth, because the menu had a stated ceiling and
the strip had nothing counting it. The complexity did not go away, it moved
one level down.

Fourteen tabs is not a cosmetic problem. Nine of them are collections that are
usually empty on any given case, so a handler reads a row of labels that
mostly promise content they do not have, and the five they work in every day
are scattered through it.

## What changes

The strip goes from fourteen tabs to six:

```
Data | Documents | People | Work | Related | Objects and locations
```

**Four tabs go because they were already somewhere else on this page.** Notes,
Mail and Decisions each duplicated a sidebar tab, and in the Notes case the
sidebar copy is the better one: `CaseNotesTab` emits `@mention` and the body
widget predates it. That is the rule REQ-CDV-17 already set for the timeline,
applied to the three panels it did not name. Files is the fourth, and it did
not go: see below.

**Four folds put two panels behind one label**, through a new `case-sections`
widget type:

| tab | sections |
|---|---|
| Documents | the dossier list, then the case folder |
| People | the parties, then the contact with them |
| Work | the tasks, then the appointments |
| Related | the related cases, then the sub-cases |
| Objects and locations | the objects, then the locations |

Files is a section of Documents rather than a tab, which is what lets the
count come down without losing the files leaf's share and comment surface.
Design D5 was right that dropping the tab would take that surface with it.

**Data stays a tab of its own.** It is the case's own fields. The raw-data
view plan item 2.22 asks for is every property including the ones the form
hides, and that is a sidebar tab over the same object, not this surface.

**Locations sits with Objects, not with Related.** A `case-location` carries a
nummeraanduiding id and a parcel id: it is a registry object the case is
about, the same kind of thing as a `caseObject`. Related is case to case.

## What this does NOT do

Plan item 3.18 asked for the task pane to move out of the strip into the right
column. It stays. That column already carries the initiator, the hours, the
flow runs and the terms, and a fifth card moves the complexity rather than
removing it, which is the thing this change exists to stop doing.

The four header actions, the raw-data sidebar tab, the dispatch columns and
the delegate window that the DQ2 plan section also lists are not here. This
change is the subtraction, and the subtraction is what phase 3 is judged on.

## The blocker that turned out not to be one

Two round 2 changes recorded the folds as `[blocked: nextcloud-vue]`: a
`visibleIf` condition on a tab entry, and a tab that renders several widgets.
The second is not blocked in 2.41.0. `CnDetailWidgetHost` resolves a renderer
by widget type against the consuming app's registry, and
`registerDashboardWidget` is the documented way an app extends the shared
catalog, `container: true` included. A container gets the sibling context its
children need. So a widget that renders several widgets is an app-level
component, not an upstream feature.

The first blocker is closed rather than waited on. `visibleIf` was wanted so a
collection holding nothing could be absent rather than empty. With six tabs
each holding two collections, an empty section is a line of text inside a tab
the handler opened on purpose, which is not the same failure as a tab that
promises content and has none.
