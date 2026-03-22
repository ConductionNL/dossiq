# Proposal: legesberekening

## Summary
Implement a rules engine for calculating municipal fees (leges) on permit cases. The module applies the gemeentelijke legesverordening to case attributes and produces a calculated amount with audit trail. It does NOT handle payment -- it calculates and exports to the financial system.

## Motivation
Legesberekening is the #1 VTH-specific functional requirement after DSO integration, found in 16 VTH tenders. Every VTH tender requires financial system export.

## Affected Projects
- [x] Project: `procest` -- Calculation engine, verordening admin, financial export

## Scope

### In Scope
- Leges calculation engine supporting vast bedrag, percentage, staffel, maximum, combinatie
- Verordening administration (titel/hoofdstuk/artikel hierarchy)
- Calculation version history with audit trail
- Verrekening (deduction) and teruggaaf (refund) support
- Financial system export (CSV, ASCII, XML formats)
- LegesberekeningService, LegesVerordeningService, LegesExportService
- LegesController API endpoints

### Out of Scope
- Payment processing and invoicing
- Direct financial system connectors (Key2Financien, Civision) -- via OpenConnector
- 4-ogen principe (V2 feature)
- Excel import of verordeningen (V2 feature)
