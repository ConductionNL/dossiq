## Why

VTH (Vergunningen, Toezicht, Handhaving) is the highest-value domain for Dutch municipalities. 29% of tenders require VTH capabilities. This implements V1 tier: DSO intake service stub, configurable inspection checklists, and advice management workflow.

## What Changes

1. VTH case type templates (omgevingsvergunning, toezichtzaak, handhavingszaak)
2. Inspection checklist schemas and configuration/completion UI
3. DSO intake service stub for receiving vergunningaanvragen
4. Advice management service for requesting/tracking specialist advice
5. LHS enforcement matrix data and lookup utility (V2 foundation)
6. New schemas in procest_register.json: inspectionChecklist, checklistItem, inspectionResult, adviceRequest

## Impact

- New service classes, Vue components, register schemas, route additions
- Dependencies: OpenConnector (DSO), OpenRegister
