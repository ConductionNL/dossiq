## MODIFIED Requirements

### Requirement: The case's incidents are rows on the Taken tab

The Taken tab's Incidents card SHALL list a case's incidents as rows ordered by `eventDate` ascending. Each row SHALL show
the description, the event date, the reporter, the owner and the recording date, plus the state as a toned pill. Add
SHALL open the incident form for this case.

#### Scenario: an incident reported late
- **GIVEN** an incident on 5 Oct, recorded on 15 Oct
- **WHEN** the Taken tab opens
- **THEN** its row reads the description, then "5 okt · gemeld door … · vastgelegd 15 okt", with an amber "Open" pill
