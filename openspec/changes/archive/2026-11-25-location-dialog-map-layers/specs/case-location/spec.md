## ADDED Requirements

### Requirement: The location dialog draws the configured map layers

The location dialog SHALL list every active map layer the organisation configured, check the default ones, and draw the checked ones over the base map, as WMS or WFS by the layer's type in any letter case. The map policy SHALL allow the PDOK service host as an image source.

#### Scenario: the example municipality's layers
@e2e exclude data and policy pinned by ZuiddrechtMapLayersTest and MapCspRegistrarTest; the dialog is covered by tests/vitest/caseLocationDialog.spec.js

- **GIVEN** the Zuiddrecht example set is installed
- **WHEN** a handler opens Add location on a case
- **THEN** the dialog SHALL list "Luchtfoto (PDOK)" and "Omgevingsplan (WMS)", both checked
- **AND** the browser SHALL be allowed to load their tiles from service.pdok.nl
