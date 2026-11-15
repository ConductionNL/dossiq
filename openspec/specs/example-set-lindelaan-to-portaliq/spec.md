# example-set-lindelaan-to-portaliq Specification

## Purpose
What dossiq does for example set lindelaan to portaliq. The requirements were first written by the change `example-set-lindelaan-to-portaliq` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-example-set-lindelaan-to-portaliq/`) and cover: The Lindelaan Woo case is written once, by portaliq.

## Requirements

### Requirement: The Lindelaan Woo case is written once, by portaliq

Dossiq's part of the Zuiddrecht example set SHALL write no "Verlichting fietspad Lindelaan" case and nothing that points
at one; that case and its contact moments, documents, tasks, advice and term SHALL be portaliq's. Dossiq's fragment SHALL
keep the Woo document type, described "Document bij een Woo-verzoek", a description no other dossiq seed uses.

#### Scenario: the installed set
- **GIVEN** the Zuiddrecht example set installed
- **THEN** exactly one case is titled "Verlichting fietspad Lindelaan", with Sanne de Vries as its requester
