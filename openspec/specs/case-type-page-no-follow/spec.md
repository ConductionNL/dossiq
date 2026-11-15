# case-type-page-no-follow Specification

## Purpose
A case type page carries the header the boards draw.

## Requirements

### Requirement: The case type page has no follow control

The `CaseTypeDetail` page SHALL set `follow: false`, and no menu structure profile SHALL set it otherwise.

#### Scenario: Case type header

- **GIVEN** a case type page in any menu structure profile
- **THEN** the header shows its actions without a Follow toggle or follower count, as on board DqZaaktypeOverzicht
