# Legesberekening Specification

## Problem
Legesberekening is the rules engine that calculates municipal fees (leges) on permit cases. It applies the gemeentelijke legesverordening -- typically based on the VNG modellegesverordening -- to case attributes and produces a calculated amount. The module does NOT handle payment or invoicing; it calculates and exports to the financial system.
**Tender demand**: Found as explicit requirement in 16 VTH tenders. Every VTH tender requires financial system export. Legesberekening is the #1 VTH-specific functional requirement after DSO integration.
**Standards**: VNG Modellegesverordening, Unie van Waterschappen modelverordening (for waterschappen), StUF-FIN, GEMMA VTH-referentiecomponenten (VTH055-VTH057, VTH103, VTH117, VTH119)
**Feature tier**: V1 (basic calculation, single verordening, manual export), V2 (multiple verordeningen, automatic DSO import, 4-ogen principe, versioned calculations, financial system connectors)
**Competitive context**: Dimpact ZAC does not include built-in legesberekening -- municipalities typically use their financial system or a separate legesmodule. Flowable can model fee calculations via DMN decision tables, providing a standards-based approach. Procest should implement legesberekening as a PHP calculation service with verordening data stored in OpenRegister, making it fully integrated in the case workflow rather than requiring external tools.

## Proposed Solution
Implement Legesberekening Specification following the detailed specification. Key requirements include:
- See full spec for detailed requirements

## Scope
This change covers all requirements defined in the legesberekening specification.

## Success Criteria
#### Scenario LEGES-01a: Staffel (tiered) calculation
#### Scenario LEGES-01b: Fixed amount calculation
#### Scenario LEGES-01c: Corrected construction costs
#### Scenario LEGES-01d: Percentage calculation
#### Scenario LEGES-01e: Maximum cap
