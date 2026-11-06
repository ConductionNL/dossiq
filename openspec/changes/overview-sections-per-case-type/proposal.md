---
kind: code
---

# Overview cards as case type configuration

## Why
Boards DqAanbesteding, DqSubsidie and DqKlachten draw their own cards on the case page. Ruben (6 Nov): a complaint is a normal case type plus configuration, not the complaint schema, and the same pattern serves tenders and grants. `overviewCards` could only pick from cards the page already knows.

## What changes
- caseType schema 1.47.0 gains `overviewSections`: cards a case type declares, each with a title, an optional intro and note, columns, a pill field and rows. A row reads a case property or case field, or shows fixed text.
- One widget, `case-overview-sections`, draws them on the Overview tab, gated on that list.
- Klacht behandeling, Aanbesteding and Subsidie declare sections and leave out the generic priority and locations cards.
- The Subsidie package gains the status Voorschotten between In behandeling and Beschikt, with its moves. Beschikt stays closed.
