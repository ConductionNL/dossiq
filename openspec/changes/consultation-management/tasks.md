# Tasks

- [ ] TASK-CN-01: Add `consultation`, `adviceResponse`, and `advisoryBody` schemas to `procest_register.json` and register config keys in `SettingsService::SLUG_TO_CONFIG_KEY`.
- [ ] TASK-CN-02: Implement `ConsultationService` with CRUD, status machine, deadline/extension logic, dependency-cycle validation, and `getBlockingConsultations(zaakId)` for milestone gates.
- [ ] TASK-CN-03: Implement `AdvisoryBodyService` with specialization-weighted search and external-body email path including secure-token issuance.
- [ ] TASK-CN-04: Implement `ConsultationController` REST endpoints plus the public `/api/public/consultations/{token}` route with audited access logging (BIO).
- [ ] TASK-CN-05: Create `ConsultationCreateDialog.vue`, `ConsultationPanel.vue` (case-detail "Adviezen" tab), `ConsultationDashboard.vue` (department inbox), and `ConsultationResponseForm.vue`.
- [ ] TASK-CN-06: Create `ExternalConsultationResponsePage.vue` for token-based external responses; register route outside the authenticated app shell.
- [ ] TASK-CN-07: Add caseType admin UI to configure mandatory/optional consultation types per zaaktype with default body, default deadline, and dependencies.
- [ ] TASK-CN-08: Wire ActivityTimeline integration so consultation lifecycle events surface on the parent case, including the overdue warning event.
- [ ] TASK-CN-09: Add n8n deadline-monitor, email-fanout, and bottleneck-detection workflows; document webhook contracts.
- [ ] TASK-CN-10: Add Dutch + English i18n for all consultation UI and notification templates.
