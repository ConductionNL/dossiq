# Design — Member 07: Admin UI (code)

## Scope

Vue admin components (ADR-004, ADR-010, ADR-017). Consumes the REST endpoints from members 03–06.
No new backend logic — pure frontend over existing APIs.

## Components

- `MandaatMatrixSettings.vue` — admin settings page, four tabs: Besluiten | Rollen | Toewijzingen
  | Import. Server-provided data via `IInitialState` + `loadState()` (ADR-004), never DOM
  data-attributes.
- Besluiten tab → `MandaatMatrixTable.vue`: table of MandateringsBesluit (#, Naam, Status,
  InWerkingtreding, Vervaldatum); row click → detail of all Mandaat records; Edit → `MandaatEditor`;
  Import → member 04 workflow.
- `MandaatEditor.vue` (its own file under `src/modals/` per modal-isolation): fields mandaatNummer,
  omschrijving, bevoegdheidType, wettelijkeGrondslag; voorwaarden JSON editor (plafond_bedrag,
  subdelegatie_toegestaan); validity date pickers; role selector (NcSelect with `inputLabel`).
- `OrganisatieRolManager.vue`: hierarchical role tree (parent-child), add/edit/delete role (name,
  type, parentRole, afdeling, team, mandaatNiveau) — delete blocked if referenced; Toewijzingen
  sub-view: MedewerkerRolToewijzing table (Person | Role | Type | Vanaf–TotEnMet | Edit/End),
  add-assignment dialog, end-assignment, waarnemer rows visually distinct.

## ADR compliance

- ADR-004: data via `loadState`, no DOM data-attributes; admin settings rendered by NC settings
  framework (not added to the in-app vue-router).
- Modal/dialog isolation: `MandaatEditor` and the assignment dialog live in their own files under
  `src/modals/` / `src/dialogs/`.
- NcSelect usages carry `inputLabel` (accessibility, WCAG 2.1 AA).
- NL Design System theming via CSS variables (ADR-010); nl + en i18n (ADR-007).

## Security (ADR-005)

These are admin-only settings views; all writes go through the already-guarded backend endpoints —
the UI does not bypass server-side authorization. No client-side authority decisions.
