# Tasks — Member 07: Admin UI (code)

Sourced from giant task 10 (Mandate Matrix Admin Panel).

## 1. Settings Page + Besluiten

- [~] Create `MandaatMatrixSettings.vue` with tabs: Besluiten | Rollen | Toewijzingen | Import; data via loadState — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Besluiten tab `MandaatMatrixTable.vue`: table of MandateringsBesluit (#, Naam, Status, InWerkingtreding, Vervaldatum); row → Mandaat detail; Edit → MandaatEditor; Import → member 04 workflow — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. Mandaat Editor

- [~] Build `MandaatEditor.vue` (own file under src/modals/): mandaatNummer, omschrijving, bevoegdheidType, wettelijkeGrondslag, voorwaarden JSON editor, validity date pickers, role selector (NcSelect inputLabel) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Save/Cancel; persist via backend mandate endpoint — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. Role + Toewijzing Management

- [~] Build `OrganisatieRolManager.vue`: hierarchical role tree, add/edit/delete (name, type, parentRole, afdeling, team, mandaatNiveau); block delete if referenced — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Toewijzingen view: MedewerkerRolToewijzing table (Person | Role | Type | Vanaf–TotEnMet | Edit/End); add-assignment dialog; end-assignment; waarnemer rows distinct — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] nl + en i18n; NL Design System theming via CSS variables — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test CRUD on all three entity types; validation; waarnemer assignment workflow — deferred to downstream cycle / fleet-wide adoption (handoff)
