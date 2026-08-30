# Design: vth-workflow-configuration

## Architecture

The VTH workflow configuration sits as a configuration layer on top of the generic Procest workflow engine. It does NOT fork or re-implement workflow functionality; instead, it:
1. Defines VTH-specific zaaktype blueprints as OpenRegister schemas/templates
2. Configures process step sequences, status transitions, and guards using the engine's generic abstraction
3. Adds VTH-specific services for cross-cutting concerns (leges calculation, beschikking generation, LHSO classification)
4. Provides mobile-optimized workflow views and DSO integration hooks that plug into the engine

The generic `workflowTemplate` schema (from `workflow-engine-enhancement`) holds all workflow definitions; this change populates it with VTH-specific instances.

```
OpenRegister                  Procest Workflow Config
├─ caseType (zaaktype)        ├─ VTHWorkflowService
│  ├─ Omgevingsvergunning     ├─ LegesCalculationService
│  ├─ Toezichtzaak           ├─ BeschikkingGenerationService
│  └─ Handhavingszaak        ├─ LhsoLookupService
├─ workflowTemplate           └─ MobileInspectionService
│  ├─ vth-omgevingsvergunning
│  ├─ vth-toezichtzaak       API Layer
│  └─ vth-handhavingszaak    ├─ VTHWorkflowController
└─ statusType, roleType, etc. ├─ LegesController
                             ├─ BeschikkingController
                             └─ MobileInspectionController
```

## Service Layout

### VTHWorkflowService
- Loads VTH workflow templates from `lib/Settings/templates/`
- Registers templates with the workflow engine
- Exposes workflow initialization and step management for admin configuration
- Methods: `loadTemplate()`, `registerWorkflow()`, `getActiveWorkflows()`

### LegesCalculationService
- Rule-based fee calculation engine for zaaktype and activiteit
- Evaluates rules by zaaktype, activiteit, and custom case properties
- Calculates verrekening (offset previous fees), teruggaaf (refunds), navordering (additional billing)
- Methods: `calculateFee()`, `applyVerrekening()`, `refund()`, `navordering()`

### BeschikkingGenerationService
- Generates permit/decision documents from templates
- Merges case data (applicant, location, activities, conditions) into template merge fields
- Handles both positive decisions (Verleend) and negative (Geweigerd, Ingetrokken)
- Coordinates with Docudesk for PDF generation if needed
- Methods: `generateBeschikking()`, `attachToBijlagen()`

### LhsoLookupService
- Provides lookup on the Landelijke Handhavingsstrategie 4x4 matrix (Gedrag × Gevolgen)
- Returns suggested interventie trajectory and classification rules
- Used by enforcement workflow to suggest handhavingstaken
- Methods: `lookup()`, `getMatrix()`, `getInterventieSteps()`

### MobileInspectionService
- Provides mobile-optimized workflow views for field inspectors
- Manages GPS location capture, photo upload, and checklist completion
- Syncs completed inspections back to case workflow
- Methods: `getMobileChecklist()`, `submitInspectionResult()`, `attachPhotos()`

### DSO Integration Hooks
- Listens for DSO verzoeken arriving in OpenRegister
- Auto-creates cases with correct zaaktype and pre-filled data from DSO payload
- Emits status change events for DSO-LV pushback
- Methods: `receiveVerzoek()`, `mapToCase()`, `pushStatusUpdate()`

## Data Model

### Core Entities (OpenRegister Schemas)

**caseType** (existing entity, configured with VTH values)
- New required fields for VTH zaaktypen:
  - `legesModule` (string, optional) — Reference to leges calculation rules
  - `inspectionChecklist` (string, optional) — Default checklist template reference
  - `dsoIntegration` (boolean) — Whether this zaaktype receives DSO verzoeken
  - `handhavingLhs` (boolean) — Whether LHSO classification applies

**workflowTemplate** (existing entity, VTH instances)
- JSON-encoded process definitions:
  - `steps` — Array of workflow steps with assigned roles, required documents, guards
  - `transitions` — Status transitions with conditions and automatic actions
  - `guards` — Checklist completion, required fields, document uploads, role approval
  - `actions` — sendEmail, createTask, createSubCase, webhook, setField, notify

**legesRuleSet** (new schema)
- `zaaktype` (reference) — Parent case type
- `activiteit` (string) — VTH activity classification
- `baseFee` (number) — Base fee in EUR
- `modifiers` (array) — Adjustments by case properties (size, complexity, etc.)
- `exemptions` (array) — Property conditions that waive fees
- `verrekenRules` (array) — Rules for offsetting prior fees
- `teruggaafRules` (array) — Refund calculation logic

**beschikkingTemplate** (new schema)
- `zaaktype` (reference) — Parent case type
- `decisionType` (enum) — Verleend, Geweigerd, Ingetrokken, etc.
- `templateContent` (string) — HTML/Markdown template with {{merge}} fields
- `requiredFields` (array) — Case fields that MUST be populated to generate
- `validFrom` / `validUntil` (date) — Template validity period

**lhsoMatrixCell** (existing in vth-module, reused here)
- `gedragRow` (string) — Violation behavior classification (A–D)
- `gevolgColumn` (string) — Consequence classification (1–4)
- `interventieStep` (string) — Suggested intervention trajectory
- `description` (string) — Explanation of the classification

**mobileInspectionChecklistItem** (new schema, consumed by mobiel-inspectie)
- `question` (string) — Checklist item text
- `type` (enum) — boolean, enum, text, photo, gps
- `required` (boolean) — Must be answered before submission
- `helpText` (string) — Contextual help for field inspector
- `photos` (array) — Nextcloud file IDs of uploaded photos (populated on completion)
- `gpsCoordinates` (object) — {lat, lng, accuracy, timestamp}

### Relations and References

- `case` → `caseType` (many-to-one)
- `caseType` → `workflowTemplate` (one-to-one, active version)
- `workflowTemplate` → `statusType` (one-to-many, statuses in workflow)
- `caseType` → `legesRuleSet` (one-to-many, multiple rule sets per zaaktype)
- `caseType` → `beschikkingTemplate` (one-to-many, multiple templates per decision type)
- `case` → `inspectionResult` / `beschikking` (one-to-many, multiple completions per case)

## Seed Data

### VTH Case Types (3 instances per type = 9 total seed cases)

**Omgevingsvergunning Cases**
1. `2026-ENV-001` — Uitbreiding kantoor (Small office expansion) — Status: In behandeling — Activiteit: "Verbouwing kantoor" — Locatie: Amsterdam, Oudezijds Voorburgwal 104
2. `2026-ENV-002` — Nieuwbouw woonhuis (Single-family home) — Status: Beschikking opgesteld — Activiteit: "Bouwactiviteit" — Locatie: Utrecht, Zuilen
3. `2026-ENV-003` — Windmotor installatie — Status: Aanvraag ontvangen — Activiteit: "Energieopwekking" — Locatie: Flevoland, polder

**Toezichtzaak Cases**
4. `2026-TOE-001` — Bouwtoezicht fase fundering — Status: In voortgang — Inspecteur: "M. van Dijk" — Locatie: Rotterdam, Spangen
5. `2026-TOE-002` — Milieutoezicht industrieterrein — Status: Afgerond — Inspecteur: "J. Visser" — Locatie: Arnhem, Rijkerswoerd
6. `2026-TOE-003` — Veiligheidscontrole horecagelegenheid — Status: Geplande inspectie — Inspecteur: "K. Jansen" — Locatie: Den Haag, Scheveningen

**Handhavingszaak Cases**
7. `2026-HAN-001` — Illegale bouwactiviteit (Illegal construction) — Status: Onderzoek — Handhaver: "P. Bakker" — LHSO: Gedrag C, Gevolgen 3 — Locatie: Groningen
8. `2026-HAN-002` — Afvalstoffing niet volgens richtlijnen — Status: Waarschuwing verstuurd — Handhaver: "A. Müller" — LHSO: Gedrag B, Gevolgen 2 — Locatie: Haarlem
9. `2026-HAN-003` — Overtreding voorwaarden omgevingsvergunning — Status: Dwangsom toegepast — Handhaver: "S. de Vries" — LHSO: Gedrag D, Gevolgen 4 — Locatie: Eindhoven

### VTH Workflow Template Configurations

**omgevingsvergunning workflow**
- Steps: Intake (Vergunningverlener) → Beoordeling (Vergunningverlener) → Advies extern (Juridisch adviseur) → Beschikking (Vergunningverlener) → Verzending (Administratief medewerker)
- Statuses: Aanvraag ontvangen → In behandeling → Advies aangevraagd → Beschikking opgesteld → Verzonden → Verleend/Geweigerd
- Guards: Completeness check before beoordeling, external advice deadline, beschikking signature before verzending

**toezichtzaak workflow**
- Steps: Planning → Voorbereiding → Inspectie (Inspector) → Rapportage (Inspector) → Afsluiting
- Statuses: Geplande inspectie → In voorbereiding → Inspectie in voortgang → Rapport opstellen → Afgerond
- Guards: GPS location and photo requirements on inspection step, minimum 3 checklist items completed

**handhavingszaak workflow**
- Steps: Registratie overtreding (Handhaver) → LHSO-classificatie (Handhaver) → Interventie (Handhaver) → Afronden
- Statuses: Onderzoek → Geclassificeerd → Bestuurlijke waarschuwing/Aanzegging/Dwangsom → Afgerond
- Guards: LHSO classification required before intervention, documented override reason if intervention differs from suggestion

### Leges Rule Sets (by zaaktype and activiteit)

**omgevingsvergunning-verbouwing (Office renovation)**
- Base fee: € 500
- Size modifier: +€100 per 100 m²
- Complexity modifier: +€200 if "architectuur-aangepast"
- Verrekening: Offset prior "omgevingsvergunning" leges from same location

**omgevingsvergunning-bouw (Building)**
- Base fee: € 750
- Size modifier: +€50 per 100 m² (building volume)
- Exemptions: Public housing (social rent) waives 50%

**toezichtzaak-standaard (Standard inspection)**
- Base fee: € 250
- Type modifier: +€100 if "complex" property set
- Verrekening: Not applicable

### Beschikking Templates

**Omgevingsvergunning - Verleend**
- Template: "Hierbij vergunnen wij u de volgende activiteit(en) op {{locatie}}: {{activiteiten}}. Deze vergunning is onderworpen aan de volgende voorwaarden: {{voorwaarden}}."
- Required fields: locatie, activiteiten, voorwaarden (at least 1), applicant name

**Omgevingsvergunning - Geweigerd**
- Template: "Uw aanvraag voor {{activiteiten}} op {{locatie}} is afgewezen op grond van: {{motivering}}"
- Required fields: locatie, activiteiten, motivering

## API Design

### Workflow Configuration Endpoints

- `GET /api/vth/workflows` — List all VTH workflow templates (admin)
- `GET /api/vth/workflows/{id}` — Retrieve workflow definition
- `POST /api/vth/workflows/{id}/activate` — Activate workflow version
- `GET /api/vth/zaaktypen` — List configured VTH case types (with current workflow binding)

### Leges Calculation Endpoints

- `POST /api/vth/leges/calculate` — Calculate fee for case (payload: caseId or {zaaktype, activiteit, properties})
- `GET /api/vth/leges/rules/{zaaktype}` — List leges rules for a case type
- `POST /api/vth/cases/{id}/leges` — Set/update leges on case after calculation

### Mobile Inspection Endpoints

- `GET /api/vth/cases/{id}/mobile/checklist` — Get inspection checklist for mobile view
- `POST /api/vth/cases/{id}/mobile/inspection-result` — Submit completed inspection (with photos/GPS)
- `GET /api/vth/cases/{id}/mobile/photos` — List uploaded inspection photos

### Beschikking Generation Endpoints

- `POST /api/vth/cases/{id}/beschikking/generate` — Generate beschikking document (payload: {templateId, decisionType})
- `GET /api/vth/beschikking-templates/{zaaktype}` — List available templates for case type

### LHSO Classification Endpoints

- `GET /api/vth/lhso/matrix` — Retrieve full 4x4 LHSO matrix
- `GET /api/vth/lhso/lookup?gedrag={A-D}&gevolgen={1-4}` — Lookup suggested intervention
- `POST /api/vth/cases/{id}/lhso-classify` — Classify case with LHSO and record suggestion

## Reuse Analysis

This change leverages existing OpenRegister and Procest infrastructure extensively:

| Service/Component | Used For | Notes |
|---|---|---|
| `workflowTemplate` (from engine) | VTH workflow definitions | No duplication; populating existing schema |
| `statusType`, `roleType`, `propertyDefinition` | VTH case type configuration | Standard case type setup, no custom logic |
| `ObjectService`, `RelationService` | CRUD on all entities | Standard OpenRegister usage |
| `CnFormDialog`, `CnDetailPage` | Admin UI for workflows and templates | No custom form builders |
| `InspectionChecklist` (from vth-module) | Checklist template base | Reused; mobile layer is consumer, not duplication |
| `adviceRequest` (from vth-module) | External advisor coordination | Reused in workflow steps |
| `lhsMatrixCell` (from vth-module) | LHSO classification data | Reused; lookup service is thin wrapper |

No deduplication issues found; all custom services are domain-specific orchestration or calculation logic not provided by the engine.

## Integration Boundaries

- **Procest ↔ OpenRegister** — All workflow, case type, and configuration data flows through OpenRegister REST API; no direct DB access
- **Procest ↔ OpenConnector** — DSO verzoeken arrive as `ObjectCreatedEvent` on `vergunningaanvraag` schema; status changes dispatched via typed event
- **Procest ↔ mobiel-inspectie** — Mobile views consume `GET /api/vth/cases/{id}/mobile/checklist`; submission via `POST .../mobile/inspection-result`
- **Procest ↔ legesberekening** — Leges calculation exposed via REST; legesberekening app may consume via webhook or polling
- **Procest ↔ Docudesk (optional)** — Beschikking generation may delegate to Docudesk template engine if configured; falls back to HTML template if not

## Standards Alignment

- **GEMMA VTH** — Case type structure, status enums, role definitions aligned to VTH-referentiecomponenten
- **DSO/STAM 2.0** — Verzoek payload mapping to case properties validated against STAM 2.0 schema
- **Landelijke Handhavingsstrategie (LHS) 1.7** — Enforcement matrix and intervention classification per LHS axis definitions
- **Omgevingswet** — Deadline calculation (8-week reguliere, 26-week uitgebreide procedure) per OW rules
- **ZGW Zaken API** — Status transitions and case management follow ZGW patterns (where applicable to internal API)
