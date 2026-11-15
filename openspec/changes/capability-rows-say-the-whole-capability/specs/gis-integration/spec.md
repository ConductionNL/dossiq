# gis-integration

## ADDED Requirements

### Requirement: The map can show layers from an ArcGIS service (REQ-CRS-41)

An administrator SHALL be able to add an ArcGIS map service as a layer. The case map SHALL then offer that layer beside the PDOK layers.

Rows: `12.16` (dossiq matrix).

#### Scenario: An ArcGIS layer on the map
- **GIVEN** an administrator who adds an ArcGIS service of protected trees
- **WHEN** a handler opens the case map
- **THEN** the protected trees layer can be switched on
