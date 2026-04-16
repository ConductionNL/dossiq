# Proposal: inspection-checklists

## Summary

Implement configurable inspection checklists and inspection reports for VTH (Vergunningverlening, Toezicht, Handhaving) cases in Procest. Inspectors conduct on-site inspections against structured checklist templates, record findings with photos and measurements, and trigger acceptance/rejection workflows. Supports statistical sampling for quality inspection rounds and supervisor approval gates before results take effect.

## Motivation

Inspection checklists are the #1 and #2 most-demanded VTH features in the market (combined demand score 444 from 144 tender mentions). Procest already defines `inspectieChecklist` and `inspectieRapport` schemas in the data model (ADR-000 Group 7: VTH/Enforcement), but there is currently no UI or business logic to manage checklists, conduct inspections, or process outcomes. This change implements the full inspection lifecycle: checklist template management (with versioning), mobile-friendly inspection execution, statistical sampling for quality rounds, acceptance/rejection workflows tied to case status transitions, and supervisor approval gates.

## Affected Projects

- [ ] Project: `procest` — Add inspection checklist UI, inspection report form, InspectieService, approval workflow integration

## Scope

### In Scope (V1)

- **REQ-ICL-001**: Inspection checklist template management — create/edit checklist templates linked to a case type, with ordered items of types ja_nee_nvt, tekst, getal, foto, and meerkeuze; versioning (edit creates new version); lifecycle status draft/active/archived
- **REQ-ICL-002**: Inspection execution — fill out an `inspectieRapport` from a checklist template on a case, recording per-item results, photo attachments, GPS location, remarks, and automatic conform/niet_conform/deels_conform result calculation
- **REQ-ICL-003**: Statistical sampling and quality hold/release workflow — conduct sample-based inspection rounds where a configured subset of checklist items is randomly selected, with a hold placed on the case until the sample passes and a release action available to authorized inspectors
- **REQ-ICL-004**: Approval workflows in checklists — require supervisor sign-off on completed inspection reports (especially niet_conform results) before the report triggers a case status transition, implemented via the existing task system

### Out of Scope

- Offline/PWA mobile inspection support — deferred (requires separate PWA architecture)
- Integration with external inspection registries (BAG, DSO, WABO) — separate change
- Automated inspection scheduling via workflow triggers — deferred
- Bulk photo import from external devices — deferred

## Approach

1. **Backend**: New `InspectieService.php` handling checklist versioning, result auto-calculation (conform when all required items pass, niet_conform when any required item fails, deels_conform otherwise), sampling logic, and approval task creation. New `InspectieChecklistController` and `InspectieRapportController` for REST endpoints.
2. **Frontend**: `InspectieChecklistList.vue` and `InspectieChecklistDetail.vue` for admin checklist template management. `InspectieRapportForm.vue` for guided inspection execution (item-by-item with photo upload). `InspectieRapportDetail.vue` for reviewing completed reports.
3. **Schemas**: `inspectieChecklist` and `inspectieRapport` are already defined in `procest_register.json` per ADR-000. This change adds seed data and wires the service layer.
4. **Workflow integration**: Completed inspection reports trigger case status transitions via the existing `WorkflowEngineController`. Supervisor approval gates reuse the `task` entity — the supervisor completes the task to release the hold.
