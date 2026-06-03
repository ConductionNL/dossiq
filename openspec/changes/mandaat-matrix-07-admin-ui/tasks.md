# Tasks — Member 07: Admin UI (code)

Sourced from giant task 10 (Mandate Matrix Admin Panel).

## 1. Settings Page + Besluiten

- [ ] Create `MandaatMatrixSettings.vue` with tabs: Besluiten | Rollen | Toewijzingen | Import; data via loadState
- [ ] Besluiten tab `MandaatMatrixTable.vue`: table of MandateringsBesluit (#, Naam, Status, InWerkingtreding, Vervaldatum); row → Mandaat detail; Edit → MandaatEditor; Import → member 04 workflow

## 2. Mandaat Editor

- [ ] Build `MandaatEditor.vue` (own file under src/modals/): mandaatNummer, omschrijving, bevoegdheidType, wettelijkeGrondslag, voorwaarden JSON editor, validity date pickers, role selector (NcSelect inputLabel)
- [ ] Save/Cancel; persist via backend mandate endpoint

## 3. Role + Toewijzing Management

- [ ] Build `OrganisatieRolManager.vue`: hierarchical role tree, add/edit/delete (name, type, parentRole, afdeling, team, mandaatNiveau); block delete if referenced
- [ ] Toewijzingen view: MedewerkerRolToewijzing table (Person | Role | Type | Vanaf–TotEnMet | Edit/End); add-assignment dialog; end-assignment; waarnemer rows distinct
- [ ] nl + en i18n; NL Design System theming via CSS variables
- [ ] Test CRUD on all three entity types; validation; waarnemer assignment workflow
