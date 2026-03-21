# VTH Module Specification

## Problem
The VTH (Vergunningen, Toezicht, Handhaving) module extends Procest with domain-specific capabilities for permit management, supervision, and enforcement. VTH processes are the most complex case management domain in Dutch municipalities, involving DSO/Omgevingsloket integration, configurable inspection checklists, enforcement strategies (Landelijke Handhavingsstrategie), supervision planning, and mobile inspection support.
**Tender demand**: 29% of tenders (20/69) explicitly require VTH capabilities. VTH tenders are high-value: they represent large municipalities and regional enforcement agencies (omgevingsdiensten) with budgets of EUR 500K-2M+.
**Standards**: Omgevingswet, GEMMA VTH-referentiecomponenten, DSO (Digitaal Stelsel Omgevingswet), StUF-LVO, ZGW APIs, Landelijke Handhavingsstrategie (LHS), IPPC (Integrated Pollution Prevention Control)
**Feature tier**: V1 (DSO intake, permit workflow, basic checklists, advice management), V2 (enforcement strategies, supervision planning, mobile inspection, LHS matrix, risk-based scheduling)
**Competitive context**: Dimpact ZAC handles VTH through its generic case model with zaaktype-specific configuration (zaakafhandelparameters). ZAC does not have built-in inspection checklists or LHS matrix support. Flowable can model VTH processes via CMMN/BPMN with configurable task forms. XXllnc Zaken and Mozard are dedicated VTH systems with deep DSO integration. Procest should implement VTH as case type extensions (not a separate module), leveraging the existing case infrastructure with VTH-specific case types, document types, and property definitions.

## Proposed Solution
Implement VTH Module Specification following the detailed specification. Key requirements include:
- See full spec for detailed requirements

## Scope
This change covers all requirements defined in the vth-module specification.

## Success Criteria
#### Scenario VTH-01a: DSO application creates permit case
#### Scenario VTH-01b: Multiple activities in single application
#### Scenario VTH-01c: DSO status updates
#### Scenario VTH-01d: DSO aanvulverzoek (request for additional information)
#### Scenario VTH-01e: DSO intake validation
