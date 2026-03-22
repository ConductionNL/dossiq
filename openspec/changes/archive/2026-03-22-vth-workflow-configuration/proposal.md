## Why

VTH (Vergunningen, Toezicht, Handhaving) is the highest-demand domain for municipal case management — 848 requirements across 236 tenders explicitly require VTH capabilities. The workflow engine from PR #93 provides configurable process steps, transitions, guards, and actions, but contains no domain-specific VTH configuration. Municipalities need ready-to-use VTH workflow templates, inspection checklists, enforcement strategies (LHS matrix), and advice management to handle permit applications, supervision inspections, and enforcement actions without building workflows from scratch. This change configures the workflow engine for VTH and adds domain-specific schemas, UI panels, and seed data.

## What Changes

- **VTH workflow templates**: Pre-built importable workflow definitions for Omgevingsvergunning (regulier 8 wk / uitgebreid 26 wk), Toezichtzaak Bouw (fundering/ruwbouw/oplevering phases), and Handhavingszaak (vooraankondiging through hercontrole). These use the `workflowTemplate` schema from the workflow engine.
- **VTH case type seed data**: Register seed data (`procest_register.json`) for 6 VTH case types with pre-configured status types, role types, document types, and property definitions per the vth-module spec.
- **Inspection checklist schemas**: New `inspectieChecklist`, `checklistItem`, and `inspectieRapport` schemas in OpenRegister for configurable inspection checklists with versioning, photo capture, and multi-type fields (ja/nee/nvt, tekst, getal, foto, meerkeuze).
- **Enforcement schemas**: New `handhavingsactie` schema supporting LHS matrix classifications (ernst x gedrag), dwangsom amounts, begunstigingstermijn tracking, and enforcement status lifecycle.
- **Advice management schemas**: New `adviesAanvraag` schema for internal/external advice requests with deadline tracking and escalation.
- **LHS matrix configuration panel**: Admin UI for configuring the 4x4 Landelijke Handhavingsstrategie matrix (ernst x gedrag = interventie) per municipality.
- **Inspection panel on case dashboard**: UI component showing inspection checklists, completed reports, and inspection progress per case.
- **Advice panel on case dashboard**: UI component showing advice requests with status badges, deadlines, and overdue alerts.
- **Enforcement wizard**: Guided workflow for creating enforcement actions based on LHS matrix classification.
- **VTH workflow import/export**: JSON templates for all VTH workflow configurations, importable via the workflow engine's import functionality.

## Capabilities

### New Capabilities
- `vth-workflow-templates`: Pre-built workflow definitions (Omgevingsvergunning, Toezichtzaak, Handhavingszaak) with VTH-specific guards, actions, and transition rules
- `vth-case-type-seed`: Register seed data for 6 VTH case types with status types, role types, document types, property definitions
- `inspection-checklists`: Configurable inspection checklists with versioning, multi-type fields, photo requirements, and rapport generation
- `enforcement-lhs`: LHS matrix configuration and enforcement action wizard with dwangsom/bestuursdwang tracking
- `advice-management`: Internal/external advice request lifecycle with deadline tracking and escalation

### Modified Capabilities
- `vth-module`: Updating implementation status section to reflect newly implemented V1 capabilities

## Impact

- **Register schema** (`lib/Settings/procest_register.json`): New schemas for inspectieChecklist, checklistItem, inspectieRapport, handhavingsactie, adviesAanvraag; new VTH case type seed objects
- **Frontend store** (`src/store/modules/`): New stores for inspection, enforcement, advice management; extends existing workflow store for VTH template loading
- **Frontend views** (`src/views/cases/components/`): New InspectionPanel, AdvicePanel, EnforcementWizard components on case dashboard
- **Frontend admin** (`src/views/settings/`): LHS matrix configuration panel; VTH template import UI
- **Workflow engine dependency**: Requires workflow-engine-enhancement (PR #93) for workflowTemplate schema, visual editor, guard evaluation, and action dispatch
- **Pipelinq**: No impact — VTH cases use standard case creation, Pipelinq request-to-case bridge works unchanged
