# Tasks: vth-workflow-configuration-02-workflow-templates

Service to register/activate the templates declared by member 01. Traces to giant Tasks 1, 2, 3 (service portion).

## 1. VTHWorkflowService

- [ ] Implement `VTHWorkflowService.loadTemplate(name)` reading the member-01 template JSON
- [ ] Implement activation of the Omgevingsvergunning template (statuses + roles)
- [ ] Implement activation of the Toezichtzaak template (statuses + roles + properties)
- [ ] Implement activation of the Handhavingszaak template (statuses + roles + LHSO properties)
- [ ] Register all three templates in `VTHWorkflowService.loadTemplate()`

## 2. Idempotency & Validation

- [ ] Add existence guards so re-activation creates no duplicate statuses/roles
- [ ] Invoke template validation (OpenAPI + x-openregister) before activation
- [ ] Test idempotent re-activation
- [ ] Test workflow activation for all three templates
