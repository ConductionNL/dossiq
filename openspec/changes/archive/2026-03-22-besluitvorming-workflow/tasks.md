## 1. Schema Registration & Backend Setup

- [x] 1.1 Add `voorstel` schema to `lib/Settings/procest_register.json` with all properties (case, type, onderwerp, steller, afdeling, portefeuillehouder, status, parafeerroute, currentStep, document, bijlagen, behandeling) [V1]
- [x] 1.2 Add `parafeerroute` schema to `lib/Settings/procest_register.json` with properties (name, caseType, voorstelType, steps array with embedded parafeerstap objects) [V1]
- [x] 1.3 Add `parafeeractie` schema to `lib/Settings/procest_register.json` with properties (voorstel, step, actor, actorType, onBehalfOf, action, comment, advice, timestamp, mandate) [V1]
- [x] 1.4 Update repair step to import the 3 new schemas via ConfigurationService::importFromApp() [V1]
- [x] 1.5 Add config key mappings for voorstel_schema, parafeerroute_schema, parafeeractie_schema in SettingsService [V1]

## 2. Voorstel Management Frontend

- [x] 2.1 Use shared objectStore (createObjectStore from @conduction/nextcloud-vue) for voorstel CRUD — no separate store needed [V1]
- [x] 2.2 Create `src/views/voorstellen/VoorstelList.vue` — list/dashboard view showing all voorstellen with status, current step, waiting actor, days in step [V1]
- [x] 2.3 Create `src/views/voorstellen/VoorstelDetail.vue` — detail view with header metadata, document preview, parafering progress timeline, action history, case link [V1]
- [x] 2.4 Create `src/views/voorstellen/components/VoorstelCreateDialog.vue` — creation dialog with type selector, case pre-fill, document attachment [V1]
- [x] 2.5 Create `src/views/voorstellen/components/ProgressTimeline.vue` — visual step indicator showing completed/current/future steps with actor names and dates [V1]
- [x] 2.6 Add Vue Router routes: `/voorstellen` (list), `/voorstellen/:id` (detail) in `src/router/index.js` [V1]
- [x] 2.7 Add "Voorstellen" navigation item to the Procest sidebar [V1]

## 3. Parafeerroute Engine

- [x] 3.1 Use shared objectStore for parafeerroute CRUD — no separate store needed [V1]
- [x] 3.2 Create utility `src/utils/parafeerEngine.js` — sequential step routing logic: advance step, validate active actor, load default route for case type/voorstel type [V1]
- [x] 3.3 Create admin tab component `src/views/settings/ParafeerRouteAdmin.vue` — CRUD for parafeerroutes with step management (add/remove/reorder), case type linking [V1]
- [x] 3.4 Register the ParafeerRouteAdmin tab in the admin settings root component [V1]
- [x] 3.5 Implement route snapshot on voorstel submission — copy route steps into voorstel at submission time so later route edits don't affect in-progress voorstellen [V1]
- [x] 3.6 Implement skip step and add ad-hoc step functionality with mandatory reason recording [V1]

## 4. Parafering Actions

- [x] 4.1 Use shared objectStore for parafeeractie create + list (no update/delete) — no separate store needed [V1]
- [x] 4.2 Create `src/views/voorstellen/components/ParafeerActionBar.vue` — action buttons (Paraferen/Adviseren/Terugsturen) shown only to active actor at current step [V1]
- [x] 4.3 Implement paraferen action: create parafeeractie, advance voorstel step, send notification to next actor [V1]
- [x] 4.4 Implement terugsturen action: create parafeeractie with mandatory comment, change voorstel status to teruggestuurd, notify steller [V1]
- [x] 4.5 Implement adviseren action: create parafeeractie with advice text, advance voorstel step [V1]
- [x] 4.6 Implement resubmit after return: steller resubmits, resume from returning step, notify returning actor [V1]
- [x] 4.7 Implement paraferen-namens (delegation): option to act on behalf of another user with mandate reference [V1]

## 5. Parafering Dashboard & Inbox

- [x] 5.1 Add overdue detection to VoorstelList.vue — highlight voorstellen waiting above threshold with warning indicator [V1]
- [x] 5.2 Add "Herinnering sturen" button to VoorstelList.vue — send Nextcloud notification to overdue actor, log in audit trail [V1]
- [x] 5.3 Create `src/views/voorstellen/components/ParafeerInbox.vue` — personal inbox showing voorstellen awaiting current user's action with inline action buttons [V1]
- [x] 5.4 Integrate ParafeerInbox into MyWork.vue as "Ter parafering" section [V1]

## 6. Case Detail Integration

- [x] 6.1 Create `src/views/cases/components/VoorstellenPanel.vue` — B&W Voorstellen panel for case detail sidebar showing linked voorstellen with type, status, current step, steller [V1]
- [x] 6.2 Integrate VoorstellenPanel into CaseDetail.vue [V1]
- [x] 6.3 Add "Nieuw voorstel" button in VoorstellenPanel that opens VoorstelCreateDialog pre-filled with case context [V1]

## 7. Besluit Registration Integration

- [x] 7.1 Create `src/views/voorstellen/components/BesluitRegistration.vue` — dialog for registering a besluit from a voorstel (title, ingangsdatum, besluittype selector) using existing decision schema [V1]
- [x] 7.2 Integrate BesluitRegistration into VoorstelDetail.vue — show "Besluit registreren" button when voorstel status is "geaccordeerd" or "aangeboden" [V1]
- [x] 7.3 On besluit registration: create decision object, update voorstel status to "besloten", add case timeline entry [V1]

## 8. Audit Trail & Notifications

- [x] 8.1 Create `src/views/voorstellen/components/AuditTrail.vue` — chronological display of all parafeeracties for a voorstel with actor resolution, delegation display, no edit/delete controls [V1]
- [x] 8.2 Integrate AuditTrail into VoorstelDetail.vue [V1]
- [x] 8.3 Create ParaferingNotificationService.php for Nextcloud notifications: step activated, voorstel returned, reminder sent [V1]
- [x] 8.4 Implement audit trail export functionality (structured data export of all parafeeracties for a voorstel) [V1]

## 9. Testing & Quality

- [x] 9.1 Verify all 3 new schemas register correctly via repair step on fresh install [V1]
- [x] 9.2 Test complete parafering flow: create voorstel -> submit -> paraferen through all steps -> register besluit [V1]
- [x] 9.3 Test terugsturen flow: return voorstel -> steller edits -> resubmit -> resume from correct step [V1]
- [x] 9.4 Run `composer check:strict` and fix any PHPCS/PHPMD/Psalm/PHPStan issues [V1]
- [x] 9.5 Verify Nextcloud notifications are sent correctly for each parafering action [V1]
