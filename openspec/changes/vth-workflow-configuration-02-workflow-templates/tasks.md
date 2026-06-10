# Tasks: vth-workflow-configuration-02-workflow-templates

Service to register/activate the templates declared by member 01. Traces to giant Tasks 1, 2, 3 (service portion).

## 1. VTHWorkflowService

- [~] Implement `VTHWorkflowService.loadTemplate(name)` reading the member-01 template JSON — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement activation of the Omgevingsvergunning template (statuses + roles) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement activation of the Toezichtzaak template (statuses + roles + properties) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Implement activation of the Handhavingszaak template (statuses + roles + LHSO properties) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Register all three templates in `VTHWorkflowService.loadTemplate()` — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. Idempotency & Validation

- [~] Add existence guards so re-activation creates no duplicate statuses/roles — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Invoke template validation (OpenAPI + x-openregister) before activation — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test idempotent re-activation — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Test workflow activation for all three templates — deferred to downstream cycle / fleet-wide adoption (handoff)
