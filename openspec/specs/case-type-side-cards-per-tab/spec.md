# case-type-side-cards-per-tab Specification

## Purpose
The case type page's side column follows the open tab, as the boards draw it.

## Requirements

### Requirement: The case type side column follows the open tab

Every tab of `case-type-panels` SHALL have an id. The side column SHALL show the publish check beside Overview only,
Versions beside every tab but Fields, Data beside every tab but Overview and Fields, History beside Phases and tasks,
and the parent type beside Fields. The Overview body SHALL NOT show the publish check.

#### Scenario: Overview

- **GIVEN** a case type page on Overview
- **THEN** the side column shows Versions and Check, as on board DqZaaktypeOverzicht

#### Scenario: Fields

- **GIVEN** a case type page on Fields
- **THEN** the side column shows the parent type only
