# Design: vth-workflow-configuration-02-workflow-templates

## Architecture

`kind: code` member (ADR-032). Centre of mass is `VTHWorkflowService` (PHP). It consumes the declarative template JSON from member 01 — it does not author it (ADR-031: declarative-first; the service is the thin consumer of the declared template).

## Service Layout

- `VTHWorkflowService.loadTemplate(name)` reads the template JSON declared by member 01.
- `activate(name)` materialises the template's statuses and roles via OpenRegister `ObjectService` (ADR-001), guarded for idempotency.
- No custom mappers for domain data (ADR-022).

## Security (ADR-005)

Template activation is an administrative operation; it is invoked from the repair step (admin context) or an admin-gated settings action added in member 09. This member adds no `#[NoAdminRequired]` endpoints.

## Reuse

The service is the imperative glue that turns the declared templates (member 01) into live workflow configuration. Status/role creation reuses the generic workflow engine from `workflow-engine-enhancement`; this member only maps VTH template properties onto it.
