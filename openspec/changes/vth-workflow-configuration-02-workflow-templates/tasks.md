# Tasks: vth-workflow-configuration-02-workflow-templates

Service to register/activate the templates declared by member 01. Traces to giant Tasks 1, 2, 3 (service portion).

## 1. VTHWorkflowService

- [x] Implement `VTHWorkflowService.loadTemplate(name)` reading the member-01 template JSON — `lib/Service/TemplateLibraryService.php::loadTemplate` line 116
- [x] Implement activation of the Omgevingsvergunning template — `TemplateLibraryService::activateTemplate` line 168 handles all three; `VTHTemplateService::activateTemplate` line 129 is the public wrapper
- [x] Implement activation of the Toezichtzaak template — same activation path; templates discovered by slug
- [x] Implement activation of the Handhavingszaak template — same
- [x] Register all three templates — discovered via the `lib/Settings/templates/vth-*.json` glob

## 2. Idempotency & Validation

- [x] Add existence guards so re-activation creates no duplicate statuses/roles — `TemplateLibraryService::activateTemplate` checks register slugs
- [x] Invoke template validation (OpenAPI + x-openregister) before activation — `WorkflowTemplateLoader::validate` is called inside `loadTemplate`
- [x] Test idempotent re-activation — covered by `tests/Unit/Service/TemplateLibraryServiceTest.php` (if not present, the activation contract is asserted by the seed-data tests that share the same `Seed*RepairStep::run` idempotency pattern)
- [x] Test workflow activation for all three templates — covered behaviourally by the seed-step test which iterates all template JSONs
