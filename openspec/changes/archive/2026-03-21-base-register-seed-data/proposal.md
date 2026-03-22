# Base Register Seed Data Specification

## Problem
Define mock/test register JSON files for five Dutch base registrations (BRP, KVK, BAG, DSO, ORI) with realistic seed data that enables full-cycle testing and demos of Procest (case management) and Pipelinq (CRM) features without external API access. These registers supplement the existing `procest_register.json` and `pipelinq_register.json` by providing the government data layer that these apps query during citizen/business identification, case enrichment, address resolution, permit intake, and council information display.
**Relationship to existing specs**: This spec extends `openregister/openspec/specs/mock-registers/spec.md` (which defines BRP and KVK requirements) by adding BAG, DSO, and ORI registers, specifying cross-register relationships, and defining concrete seed data scenarios tied to Procest and Pipelinq test cases.
**Consuming specs**:
- Procest `case-dashboard-view` (REQ-CDV-05b): BRP-persoon and BAG-object as linked objects
- Procest `vth-module` (REQ-VTH-01): DSO vergunningaanvraag intake with BAG locatie
- Procest `zaak-intake-flow`: Betrokkene identification via BRP/KVK
- Procest `legesberekening`: BAG oppervlakte for fee calculation
- Pipelinq `klantbeeld-360`: BRP/KVK enrichment for 360-degree customer view
- Pipelinq `kcc-werkplek`: BSN/KVK citizen/business identification
- Pipelinq `prospect-discovery`: KVK data for prospect search and scoring

## Proposed Solution
Implement Base Register Seed Data Specification following the detailed specification. Key requirements include:
- See full spec for detailed requirements

## Scope
This change covers all requirements defined in the base-register-seed-data specification.

## Success Criteria
#### Scenario SEED-001a: BSN 11-proef validation
#### Scenario SEED-001b: Family unit consistency
#### Scenario SEED-001c: Geographic distribution
#### Scenario SEED-001d: Demographic diversity
#### Scenario SEED-001e: BRP person usable as case initiator
