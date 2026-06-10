# Tasks: vth-workflow-configuration-02-workflow-templates

Service to register/activate the templates declared by member 01. Traces to giant Tasks 1, 2, 3 (service portion).

> **Build status (hydra audit 2026-06-10).** Shipped on dev as
> `lib/Service/VTHTemplateService.php` (`activateTemplate(string $slug)`
> loads a `vth-*.json` from `lib/Settings/templates/` and writes
> caseType + statusTypes + roleTypes + documentTypes + propertyDefs
> into the configured register, idempotent by slug-match). Wired by
> the `SeedVthWorkflowTemplates` repair step (now registered in
> info.xml via vth-workflow-configuration-01).

## 1. VTHWorkflowService

- [x] Implement `VTHTemplateService.activateTemplate(slug)` reading the member-01 template JSON (single generic method handles all three templates by slug)
- [x] Implement activation of the Omgevingsvergunning template (statuses + roles + documentTypes + propertyDefs) — covered by the generic activateTemplate path
- [x] Implement activation of the Toezichtzaak template (statuses + roles + properties) — covered by the generic activateTemplate path
- [x] Implement activation of the Handhavingszaak template (statuses + roles + LHSO properties) — covered by the generic activateTemplate path
- [x] Register all three templates in template listing (`VTHTemplateService::listTemplates()` enumerates `vth-*.json` in the templates directory)

## 2. Idempotency & Validation

- [x] Add existence guards so re-activation creates no duplicate statuses/roles (activateTemplate uses slug-based findObjects lookup before insert)
- [~] Invoke template validation (OpenAPI + x-openregister) before activation — DEFERRED; templates ship with the app and are not user-uploaded
- [~] Test idempotent re-activation — DEFERRED to opsx-verify (needs live OR)
- [~] Test workflow activation for all three templates — DEFERRED to opsx-verify (needs live OR)
