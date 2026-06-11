# Tasks — Member 08: User Bevoegdheden UI (code)

Sourced from giant task 11 (User Bevoegdheden Dashboard).

## 1. Bevoegdheden Panel

- [x] Create `BevoegdhedenPanel.vue` (side panel or modal) opened from case detail — `src/views/cases/widgets/BevoegdhedenPanel.vue`
- [x] Load applicable Mandaat records for the case zaaktype, filtered by the user's current roles — calls `/api/procest/api/mandate/cases/{caseId}/applicable` (new route `mandaatMatrix#applicable` at `appinfo/routes.php:497`); backed by `MandaatCheckService::getApplicableForUser`
- [x] Table columns: Mandaat # | Omschrijving | Bevoegdheidtype | Plafond | Subdelegatie | Geldend v/t | Details — implemented in `BevoegdhedenPanel.vue` template
- [x] Implement "What can I do?" filter — decision types the user can unilaterally execute — `onlyUnilateral` checkbox filters rows where `row.unilateral === true`; backend marks unilateral via `getApplicableForUser`

## 2. Detail Widget

- [x] Build `MandaatMatrixWidget.vue` row-detail expansion: description, wettelijke grondslag link, current role holders, waarnemer note, MandateringsBesluit source — `src/views/cases/widgets/MandaatMatrixWidget.vue`; emits `close`; deep-links to wetten.overheid.nl
- [x] nl + en i18n; NL Design System theming; NcSelect with inputLabel — all strings via `t('procest', ...)`; CSS uses `var(--color-*)`; no NcSelect needed (no select-input surface in the detail widget)
- [~] Test on case detail page; with different roles (incl. waarnemer); filter functionality — UI-level e2e DEFERRED to gate-19 follow-up; backend `getApplicableForUser` is unit-testable via the existing `MandaatCheckServiceTest` scaffold (covered for getApplicableMandaten which is invoked by the user-facing variant)
