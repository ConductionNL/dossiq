# Zaaktype Configuratie Specification

## Problem
Zaaktype Configuratie provides a zero-coding admin UI for configuring case types and all their behavioral components: status diagrams, checklists, required documents, deadlines, parafeerroutes, and property definitions. While the Case Types spec (`../case-types/spec.md`) defines the data model and validation rules, this spec covers the configuration UI and workflows that administrators use to set up and maintain case types without developer involvement.
**Tender demand**: 23% of tenders (16/69) explicitly require zero-coding zaaktype configuration. Additionally, 36% of all tenders ask for "zero-coding configuratie" as a general principle. This is a key differentiator -- municipalities want to reduce leveranciersafhankelijkheid.
**Relationship to existing specs**: This spec EXTENDS `case-types` (data model). It does NOT duplicate the data model or validation rules. It adds the admin UI and configuration workflows. Check `case-types` for all entity definitions.
**Standards**: ZGW Catalogi API (ZaakType, StatusType, ResultaatType, InformatieObjectType), CMMN 1.1 (CaseDefinition)
**Feature tier**: V1 (basic CRUD UI, status diagram editor, document type config, property definition config, role type config, result type config), V2 (visual flow designer, import/export, ZTC sync, versioning, test mode)

## Proposed Solution
Implement Zaaktype Configuratie Specification following the detailed specification. Key requirements include:
- See full spec for detailed requirements

## Scope
This change covers all requirements defined in the zaaktype-configuratie specification.

## Success Criteria
#### Scenario ZTC-01a: Create new case type
#### Scenario ZTC-01b: Edit existing case type
#### Scenario ZTC-01c: Publish a draft case type
#### Scenario ZTC-01d: Publish validation fails
#### Scenario ZTC-01e: Delete draft case type
