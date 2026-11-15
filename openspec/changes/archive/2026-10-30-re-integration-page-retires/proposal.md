# Proposal: re-integration-page-retires

## Why

Re-integration trajectories are cases of the Re-integratietraject case type
(`re-integration-is-a-case-type`). The settings list over `reIntegratieTraject` would show records
nobody writes any more. Lists are views of the case list (Ruben, 19 and 20 October 2026).

**Merge together with** `record-import-moves-in-app-records` and `re-integration-is-a-case-type`:
without them the list goes before the records are cases.

## What changes

- The page `ReIntegratieTrajecten` retires. The menu entry `ReIntegratieTrajectenMenu` stays where
  it was and opens the Cases view `re-integration-pathways`. It carries no permission, like the view
  it opens: who reads a re-integration case is decided by the case's own access (handling group
  `dossiq-sociaal-domein`, confidential), not by hiding a link.
- `/settings/re-integratietrajecten` redirects to the view; `/settings/re-integratietrajecten/:id`
  opens the case whose `externalReference` is that id (the trajectory it moved from), or the view
  when there is none. The redirect is generic (`movedRecordRoutes`, `caseIdByReference`).
- The `reIntegratieTraject` schema stays declared with its authorization, so the stored rows stay
  readable in OpenRegister; removing it is a later decision.
- admin-data-access 1.2 no longer needs a decision for `reIntegratieTraject`: its records are cases
  handled by `dossiq-sociaal-domein`.

## What the old page offered, and where it is now

| Old page | Now |
|---|---|
| List, columns, sort by start date | View `re-integration-pathways`, same columns via `caseAnswer` |
| Sidebar with metadata, audit | Case page, its sidebar and audit trail |
| Create | New case of the type; the instruments list edits row by row with nextcloud-vue `fix/r25-dynamic-field-items` |
| Edit a trajectory's fields | Case-type fields on the case; editing existing answers depends on the DQ-EDIT lane's prefill fix |
| Delete | Case delete, under case rights |
| Filter by distance | Not on the old page either; the filter bar over `properties[]` is the DQ-L6 lane's |
