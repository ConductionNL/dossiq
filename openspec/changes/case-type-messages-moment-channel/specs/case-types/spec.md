# case-types Delta

## ADDED Requirements

### Requirement: A message template says when and how it goes out

A message template SHALL carry an optional `moment` (a generic case event) and `channel`. A new version of a template
SHALL keep the moment, channel and message category its edit does not name, and SHALL clear one the edit names as null or empty.

#### Scenario: Editing the body of the decision message

- **GIVEN** the template "Besluit" with moment decision-made and channel mailbox
- **WHEN** an administrator saves a new body
- **THEN** the new version still goes out on decision-made, by mailbox

#### Scenario: Clearing the moment

- **GIVEN** the template "Besluit" with moment decision-made
- **WHEN** an administrator saves it with the moment set to nothing (an explicit null)
- **THEN** the new version has no moment and is only sent by hand

### Requirement: The case type page lists its messages

The Phases and tasks tab SHALL list the case type's active message templates as a table of moment, channel and
message, with a note that an opted-out applicant only receives what the law requires. The template editor SHALL stay
reachable on a Message templates tab under the tab strip's Actions menu, opened by "Add a message".

#### Scenario: A permit case type

- **WHEN** an administrator opens Phases and tasks
- **THEN** "Berichten aan de aanvrager" reads Zaak ontvangen · Berichtenbox, anders e-mail · Ontvangstbevestiging, and so on
