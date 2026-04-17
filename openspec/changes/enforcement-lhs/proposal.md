<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: vth-workflow-templates (Vth Workflow Templates)
     This spec extends the existing `vth-workflow-templates` capability. Do NOT define new entities or build new CRUD — reuse what `vth-workflow-templates` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

# Proposal: Enforcement Lhs

## Summary

Add Landelijke Handhavingsstrategie (LHS) matrix support to Procest VTH (Vergunning, Toezicht, Handhaving) case flows. Inspectors classify violations by ernst (severity) and gedrag (violator behaviour), the system suggests the LHS-appropriate intervention, and the suggested or overridden action is recorded as a `handhavingsactie` object linked to the enforcement case. An enforcement overview dashboard, full-text search, CSV/Excel export, and deadline notifications complete the feature set.

## Problem

Dutch municipalities performing toezicht en handhaving are required by law to follow the Landelijke Handhavingsstrategie (LHS). Today inspectors must look up the two-axis LHS matrix manually and record the outcome in free-text notes, resulting in:

- Inconsistent intervention choices across inspectors and teams.
- No audit trail connecting ernst/gedrag classification to the chosen intervention.
- No system-level enforcement that deviations from the LHS suggestion are documented with a reason.
- No deadline monitoring for the begunstigingstermijn (grace period) before a dwangsom takes effect.
- No management overview of open enforcement requests by type, severity, or deadline.

## Affected Projects

- [ ] Project: `procest` — Add LHS matrix service, handhavingsactie management UI, overview view, deadline background job, and notification integration

## Scope

### In Scope (V1)

- **LHS Matrix Engine** (REQ-ENF-001, REQ-ENF-002): Pure-function `LhsMatrixService` encodes the full LHS matrix; given ernst + gedrag it returns the standard intervention. If the inspector overrides the suggestion, `overrideReason` is mandatory before save.
- **Enforcement Action Management** (REQ-ENF-001): Create, view, edit, and close `handhavingsactie` objects on a case, with begunstigingstermijn countdown, dwangsomBedrag/dwangsomMaximaal configuration, and effectueringsDatum.
- **Case Detail Integration**: `HandhavingsactieSection.vue` embedded in case detail showing all enforcement actions for the case with status badges and deadline indicators.
- **Enforcement Overview** (REQ-ENF-003): Dedicated `HandhavingView.vue` listing all enforcement actions across all cases with filter sidebar (type, ernst, status, deadline).
- **Full-Text Search** (REQ-ENF-004): Search enforcement actions by case identifier, overreder, violation type, and overrideReason — delegated to OpenRegister `IndexService`.
- **Export** (REQ-ENF-005): CSV and Excel export of filtered enforcement data via OpenRegister `ExportService` + `CnMassExportDialog`.
- **Deadline Notifications** (REQ-ENF-006): `HandhavingsactieDeadlineJob` runs daily, sends Nextcloud notifications when begunstigingstermijn expires within the configured warning window.
- **Workflow-Embedded Compliance Checks** (REQ-ENF-007): `workflowTemplate` transition guards that require a linked `handhavingsactie` before a case can advance beyond the toezicht phase.
- **Override Audit** (REQ-ENF-008): Every LHS deviation (override) is recorded on the `handhavingsactie` with actor, timestamp, and reason — surfaced in the standard OpenRegister audit trail.

### Out of Scope

- Strafrechtelijke handhaving routing to the OM/politie (no external API integration in V1).
- Automatic dwangsom invoicing (requires external financial system integration).
- Cross-municipality benchmarking dashboard.
- Mobile inspector app for offline LHS classification.

## Approach

1. **LhsMatrixService** (PHP): Encodes the 4×4 LHS matrix as a lookup array (ernst × gedrag → interventie string). Stateless pure function, no DB access.
2. **HandhavingsactieService** (PHP): Wraps OpenRegister `ObjectService` for handhavingsactie CRUD. Calls `LhsMatrixService` to populate the `interventie` field on creation. Validates that `overrideReason` is set whenever the suggested interventie is changed.
3. **HandhavingsactieController** (PHP): Thin REST controller exposing create, read, update, delete, and `suggest` (POST with ernst + gedrag, returns suggested interventie without saving).
4. **HandhavingsactieDeadlineJob** (PHP): Daily `TimedJob` querying handhavingsacties with status `actief` and `effectueringsDatum` within the configured warning window. Sends Nextcloud notifications via `NotificationService`.
5. **Frontend**: `HandhavingView.vue` (overview + filter sidebar), `HandhavingsactieSection.vue` (case detail embed), `LhsMatrixDialog.vue` (ernst/gedrag selection with live intervention preview). Export and search delegated to `CnMassExportDialog` and `CnFilterBar`.

## Cross-Project Dependencies

- **OpenRegister**: Storage for `handhavingsactie` objects; `IndexService` for search; `ExportService` for export.
- **@conduction/nextcloud-vue**: `CnFilterBar`, `CnMassExportDialog`, `CnDataTable`, `CnDetailPage`, `CnFormDialog` — no custom UI primitives needed.
- **Nextcloud NotificationService** (`OCP\Notification\IManager`): Deadline notifications.
- **workflowTemplate** / workflow engine: Guard integration for compliance checks.
- **inspectieRapport**: Enforcement actions may be initiated from an existing inspection report linked to the same case.
