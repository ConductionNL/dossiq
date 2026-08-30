# VTH Workflow Configuration

**Issue:** #85
**Branch:** `feature/85/vth-workflow-configuration`
**PR:** #98

## Overview

Configures the Dossiq workflow engine for VTH (Vergunningen, Toezicht, Handhaving: Permits, Supervision, Enforcement), adding domain-specific schemas, seed data, LHS matrix support, and UI panels for Dutch environmental compliance workflows.

## Architecture

### Backend

**New schemas** in `lib/Settings/dossiq_register.json`:
| Schema | Purpose |
|--------|---------|
| `inspectieChecklist` | Template-driven inspection checklist per inspection phase |
| `inspectieRapport` | Completed inspection report linking to a case |
| `handhavingsactie` | Enforcement action record (notice, dwangsom, bestuursdwang) |
| `adviesAanvraag` | Advice request to internal/external advisors |

**New config keys** in `SettingsService`:
- `inspectie_checklist_schema`: UUID of inspectieChecklist schema
- `inspectie_rapport_schema`: UUID of inspectieRapport schema
- `handhavingsactie_schema`: UUID of handhavingsactie schema
- `advies_aanvraag_schema`: UUID of adviesAanvraag schema
- `lhsMatrix`: JSON string of the 4×4 Landelijke Handhavingsstrategie matrix

**Seed data:**
- `lib/Settings/vth_seed_data.json`: 6 VTH case types (Omgevingsvergunning Regulier/Uitgebreid, Sloopmelding, Toezichtzaak Bouw/Milieu, Handhavingszaak)
- `lib/Settings/vth-templates/`: 6 JSON template files, one per case type

### LHS Matrix

The Landelijke Handhavingsstrategie (LHS) provides a standard 4×4 enforcement response matrix based on impact (A–D) and intent (1–4). The matrix is configurable per municipality and stored as a JSON string in app config.

### Frontend (Vue)

- **Pinia stores:** `inspection.js`, `enforcement.js`, `advice.js`
- **Case detail panels:** `InspectionPanel`, `EnforcementPanel`, `AdvicePanel`
- **Enforcement wizard:** 3-step: LHS classification → intervention selection → vooraankondiging
- **Admin components:** `ChecklistAdmin` (drag-and-drop versioned checklists), `LhsMatrixAdmin` (editable 4×4 grid), `VthTemplateLibrary`

## Testing

Unit tests are in:
- `tests/Unit/Service/VthSettingsServiceTest.php`: VTH config keys in getSettings(), updateSettings() persists VTH keys, lhsMatrix readable, core keys not overridden
- `tests/Unit/Settings/VthSchemaTest.php`: all 4 VTH schemas registered, vth-templates dir exists, template files are valid JSON, expected files present, vth_seed_data.json valid
