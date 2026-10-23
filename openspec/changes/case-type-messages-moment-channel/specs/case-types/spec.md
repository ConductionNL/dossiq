# case-types Delta

## ADDED Requirements

### Requirement: A message template says when and how it goes out

A message template SHALL carry an optional `moment` (a generic case event) and `channel`. A new version of a template
SHALL keep the moment, channel and message category its edit does not name.

#### Scenario: Editing the body of the decision message

- **GIVEN** the template "Besluit" with moment decision-made and channel mailbox
- **WHEN** an administrator saves a new body
- **THEN** the new version still goes out on decision-made, by mailbox

### Requirement: The case type page lists its messages

The Phases and tasks tab SHALL list the case type's active message templates as a table of moment, channel and
message, with a note that an opted-out applicant only receives what the law requires. The template editor SHALL stay
reachable on a Message templates tab under the tab strip's Actions menu, opened by "Add a message".

#### Scenario: A permit case type

- **WHEN** an administrator opens Phases and tasks
- **THEN** "Berichten aan de aanvrager" reads Zaak ontvangen · Berichtenbox, anders e-mail · Ontvangstbevestiging, and so on
