# Proposal: re-integration-is-a-case-type

## Why

Ruben (23 Oct 2026): "re-integratie trajecten should be a case type". A re-integration
trajectory is a procedure a klantmanager runs with somebody: it has stages, a handler, people
involved, documents, deadlines and an outcome. dossiq kept it as a record of its own
(`reIntegratieTraject`) with a settings list, so none of what a case has applied to it.
Procedures are case types, flows and configuration; the code stays generic.

## What changes

1. **Case type package `re-integratietraject`** (`lib/Settings/packages/re-integratietraject/`, install `auto`):
   the case type Re-integratietraject (category Werk en inkomen, confidentiality vertrouwelijk,
   handled by the group `dossiq-sociaal-domein`, special data health, the social-domain side cards),
   statuses Aangemeld, Plan van aanpak, In uitvoering, Evaluatie, Afgerond, Beëindigd,
   results, roles (Deelnemer, Klantmanager, Jobcoach, Werkgever, Teamleider werk), the
   trajectory's fields as propertyDefinitions (trajectSoort and afstandTotArbeidsmarkt filterable,
   the exemption fields hidden on create), and a published flow whose move to In uitvoering
   requires trajectSoort and afstandTotArbeidsmarkt and whose close requires a result.
2. **Migration as configuration**: the case type declares a record import over
   `reIntegratieTraject` (record-import-moves-in-app-records) with `runOnUpgrade` and
   `carriesTerm: false`. Each stored trajectory becomes one case of the type in In uitvoering,
   its klantmanager as assignee, its uuid in `externalReference`, every field answered in
   `case.properties[]`. The trajectory is left as it was and stamped with `migratedTo` and
   `migratedAt` (`reIntegratieTraject` 1.2.0); nothing is deleted.
3. **The list is a view**: `defaultSavedViews` gains `re-integration-pathways` on Cases
   (filter `caseTypeIdentifier: re-integratietraject`).
4. The status names join the board palette.

The page and menu entry `ReIntegratieTrajecten` retire in `re-integration-page-retires`, which
must merge together with this change.

## Access

Ruben's decision (via the coordinator, 23 Oct): the case type's handling team is
`dossiq-sociaal-domein`, confidentiality vertrouwelijk. This settles admin-data-access task 1.2
for `reIntegratieTraject`.
