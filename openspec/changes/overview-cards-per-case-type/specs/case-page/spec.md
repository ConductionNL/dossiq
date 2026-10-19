## ADDED Requirements

### Requirement: A case type can replace the generic Overview cards

A case type MAY list `overviewCards`. A case of a type that lists cards SHALL
show no generic Overview card (request, case data, priority, locations, plan,
term and payment, flows, hours); a case of a type that lists none SHALL show
them as before. A domain card SHALL follow its own condition either way.

#### Scenario: A case type without the setting
- GIVEN a case of a case type with no `overviewCards`
- WHEN the Overview tab opens
- THEN it shows the request and case data cards as before

#### Scenario: A case type with the setting
- GIVEN a Melding Jeugdwet case, whose case type lists `case-summary`
- WHEN the Overview tab opens
- THEN it shows the summary card and no generic card
