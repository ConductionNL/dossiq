# Proposal: Advice Management

## Summary

Add a complete advice request management system (adviezen) to Procest case flows. Behandelaars can request internal and external advice on cases, track deadlines, receive notifications, and guard workflow transitions until all advice is received.

## Problem

Cases often require advice from internal colleagues (welstandscommissie, juridische dienst) or external parties (Veiligheidsregio, RUD) before a decision can be made. Currently there is no structured way to track these advice requests within a case — deadlines are managed manually in email, adviseurs are not notified systematically, and there is no workflow guard to prevent premature case closure. Advice requests that expire without response block the case but are invisible to the teamleider.

## Affected Projects

- [ ] Project: `procest` — Add adviesAanvraag schema, AdviceService, AdviceController, deadline job, and advice panel components on case detail view

## Scope

### In Scope (V1)

- **Advice Request Schema** (REQ-ADV-001): `adviesAanvraag` OpenRegister entity with case linkage, adviseur, type (intern/extern), onderwerp, deadline, status lifecycle (aangevraagd → ontvangen / verlopen), document reference, and specific questions field
- **Advice Panel on Case Dashboard** (REQ-ADV-002): "Adviezen" panel on case detail showing all advice requests with type badges (intern/extern), status badges (aangevraagd=blue, ontvangen=green, verlopen=red), deadline dates, overdue highlighting, and quick actions per request
- **Advice Request Form** (REQ-ADV-003): Dialog for creating advice requests — adviseur selector (user picker for intern, text for extern), type toggle, onderwerp, deadline date picker (default 2 weeks), and specific questions textarea
- **Workflow Guard**: Workflow transition guard blocks case progression when any `adviesAanvraag` has status `aangevraagd`; violation message lists pending advice with deadlines
- **Deadline Tracking & Notifications**: Nextcloud reminder notification 3 days before deadline, escalation notification to behandelaar and teamleider on overdue, automatic status change to `verlopen` past deadline
- **Task Automation**: Task created for adviseur on request ("Advies uitbrengen voor [zaak]"), task created for behandelaar on timeout ("Advies verlopen: beoordeel of procedure kan doorgaan")
- **Case Timeline**: Activity log entries on request, receipt, and expiry of advice

### Out of Scope

- External email dispatch for external adviseurs (V2 — requires n8n workflow)
- Advice analytics dashboard and SLA reporting (V2)
- PDF template generation for formal advice letters (V2)

## Approach

1. **Backend**: New `AdviceService.php` handling CRUD, notification dispatch, and timeline recording. New `AdviceController.php` for authenticated REST endpoints. `AdviceDeadlineJob.php` background job (daily) for deadline monitoring and status transitions.
2. **Frontend**: `AdviesPanel.vue` component embedded in case detail, `AdviesAanvraagDialog.vue` for creating requests, workflow guard integration checking open advice before transition.
3. **Schema**: `adviesAanvraag` is already defined in ADR-000 — add to `procest_register.json` with Dutch seed data.
4. **Workflow Integration**: Reuse existing `workflowTemplate.transitions[].guards` array — add `adviesGuard` type to the platform's guard evaluation.

## Cross-Project Dependencies

- **OpenRegister**: `adviesAanvraag` object storage and relation management to `case`
- **NotificatieService** (platform): Nextcloud in-app notifications for adviseur, behandelaar, and teamleider
- **TasksController** (platform): Auto-create tasks linked to the case for adviseur and behandelaar
- **WorkflowEngine** (platform): Guard hook point on `workflowTemplate` transitions
