# Changelog

All notable changes to Procest are documented in this file.

## [Unreleased]

### Documented

- `workflow-engine-enhancement`: backfilled the openspec change to reflect the shipped engine. The visual workflow editor (canvas, step/transition/guard/action panels, version management) is live under
  `src/views/settings/WorkflowEditor.vue` + `src/views/settings/tabs/WorkflowTab.vue`. The runtime engine is wired through `WorkflowEngineService` → `StatusTransitionService` (single deterministic write path) with strategy registries `GuardRegistry` (checklist / requiredField / requiredDocument / roleGuard) and `ActionHandlerRegistry` (sendEmail / createTask / createSubCase / webhook / setField / notify) under `lib/Service/Transitions/`. Lifecycle endpoints (`publish`/`deprecate`/`cloneDefinition`) live on `WorkflowDefinitionController`; CRUD is delegated to OpenRegister auto-routing per ADR-022. The visual-canvas component tests, the per-handler unit tests, and the live-env integration tests stay deferred to the gate-19 follow-up.
- `consultation-management`: backfilled the openspec change to reflect the shipped `ConsultationService` + `ConsultationController` + the three n8n workflows (`n8n/consultation-deadline-monitor.json`, `n8n/consultation-email-fanout.json`, `n8n/consultation-bottleneck-detection.json`).

## [0.2.5] - 2026-06-01

### Changed

- Documentation site conformance to the canonical `@conduction/docusaurus-preset` product-pages structure (`docs-product-pages-conformance`):
  - Renamed `docs/tutorials/` to `docs/user-guide/` (history preserved via `git mv`).
  - Swept all em-dash characters (`—`) from `docs/` so the fleet-wide prose gate passes (`git grep -E '—' docs/` returns no matches).
  - Re-enabled the `nl` locale in `docs/docusaurus.config.js`; the production build succeeds with the Dutch locale active.
  - Verified the Redocusaurus `/api/` route, the canonical `Features/`, `user-guide/`, `Technical/` routes, and the `UseCases/`/`Integrations/` stubs all build cleanly.
