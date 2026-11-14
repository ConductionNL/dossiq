# admin-detail-folder-tabs Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [admin-detail-folder-tabs](../../)

## Purpose

Record pages with parts draw the same folder tabs as the case page.

## ADDED Requirements

### Requirement: The case type and organisation pages draw folder tabs

The tabs widget of the case type page (`case-type-panels`) and of the organisation page (`organisation-panels`) SHALL
NOT declare `variant: "segmented"`, so the library draws its folder strip.

#### Scenario: Case type Overview

- **GIVEN** the case type page in the board look
- **THEN** "Overzicht", "Velden", "Statussen" and the other parts are folder tabs and the open one joins the first card

#### Scenario: Organisation Overview

- **GIVEN** an organisation on this environment in the board look
- **THEN** "Overzicht", "Gebruikers", "Mandaten" and "Facturatie" are folder tabs, as on board DqTenant
