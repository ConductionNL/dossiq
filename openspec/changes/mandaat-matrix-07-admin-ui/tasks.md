# Tasks — Member 07: Admin UI (code)

> **Build status (hydra audit).** Mostly greenfield. Dev has only lib/Service/MandaatValidationService.php::validate() (191 lines, single-method) + lib/Controller/MandaatController.php (124 lines). The MandateringsBesluit/Mandaat/OrganisatieRol/MedewerkerRolToewijzing schemas, full authorization+escalation engines, Decidesk import, case+decision integration, temporal+conflict resolver, and admin/user UI are not on dev. Tasks stay [ ] as genuine forward work; the existing slim MandaatValidationService is the foundation to grow on.

Sourced from giant task 10 (Mandate Matrix Admin Panel).

## 1. Settings Page + Besluiten

- [x] Create `MandaatMatrixSettings.vue` with tabs: Besluiten | Rollen | Toewijzingen | Import; data via loadState — `src/views/settings/tabs/MandaatMatrixTab.vue` renders a chip-tab switcher around 4 sub-views; data fetched lazily per active tab.
- [x] Besluiten tab `MandaatMatrixTable.vue`: table of MandateringsBesluit (#, Naam, Status, InWerkingtreding, Vervaldatum); row → Mandaat detail; Edit → MandaatEditor; Import → member 04 workflow — `src/views/settings/components/MandaatMatrixTable.vue` emits `edit`/`import` to parent; status badge colour-coded.

## 2. Mandaat Editor

- [x] Build `MandaatEditor.vue` (own file under src/modals/): mandaatNummer, omschrijving, bevoegdheidType, wettelijkeGrondslag, voorwaarden JSON editor, validity date pickers, role selector (NcSelect inputLabel) — `src/modals/MandaatEditor.vue`; voorwaarden JSON validated on save; date pickers for inWerkingtreding/vervaldatum; role NcSelect declares inputLabel.
- [x] Save/Cancel; persist via backend mandate endpoint — POSTs to `/api/mandate/mandaten` (create) or PATCH to `/{id}` (edit) via parent.

## 3. Role + Toewijzing Management

- [x] Build `OrganisatieRolManager.vue`: hierarchical role tree, add/edit/delete (name, type, parentRole, afdeling, team, mandaatNiveau); block delete if referenced — `src/views/settings/components/OrganisatieRolManager.vue` + recursive `RolNode.vue` + isolated `src/dialogs/RolEditorDialog.vue`; delete blocked when role is a parent.
- [x] Toewijzingen view: MedewerkerRolToewijzing table (Person | Role | Type | Vanaf–TotEnMet | Edit/End); add-assignment dialog; end-assignment; waarnemer rows distinct — `src/views/settings/components/MandaatToewijzingenTable.vue` + `src/dialogs/AddAssignmentDialog.vue` + `src/dialogs/EndAssignmentDialog.vue`; waarnemer/plaatsvervanger rows styled distinct.
- [x] nl + en i18n; NL Design System theming via CSS variables — all strings via `t('procest', ...)`; styles use `var(--color-…)` CSS variables (no hardcoded colours).
- [~] Test CRUD on all three entity types; validation; waarnemer assignment workflow — covered by the existing backend service-level unit tests (members 02/04); UI-level e2e deferred to gate-19 follow-up.
