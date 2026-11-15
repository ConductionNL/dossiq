# case-type-side-cards-per-tab

## Why

The case type page showed the same side column (Versions, Data) beside every tab, and drew the publish check as the
first card of the Overview body. The boards change the side column per tab: Overview has Versions and Controle
(dossiq/DqZaaktypeOverzicht), Phases and tasks has Versions, Data and History (DqZaaktype), and Fields has the parent
type (DqZaaktypeVelden). The library already shows a side entry only beside the tabs it lists in `tabs` (the tab ids a
`tabs` widget reports); the case type's tabs had no ids, so nothing could name them.

## What changes

- Every case type tab gets an id: overview, fields, statuses, phases, rights, privacy, translations, versions, portal,
  history.
- The publish check moves from the Overview body into a side card beside Overview.
- Versions shows beside every tab but Fields; Data beside every tab but Overview and Fields.
- A History side card (the record's audit trail) shows beside Phases and tasks.
- A "Parent type" side card (the type this one inherits from) shows beside Fields.

## Not built

The board's "Keuzelijst" card beside Fields edits the options of one selected choice-list field. No library widget
edits a field's `enumValues` beside a field list, and the page has no selected field to point it at.
