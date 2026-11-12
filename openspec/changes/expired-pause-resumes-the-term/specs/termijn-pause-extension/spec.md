# termijn-pause-extension

## ADDED Requirements

### Requirement: An expired pause SHALL resume the term by itself and tell the handler (REQ-TERM-080)

When a paused term reaches the end of its pause days without an answer, the system SHALL set the
term back to `lopend` by itself, record a `TermijnGebeurtenis` of type `hervat` that says it
resumed because the pause ended, and send the case's handler a notification that names the case
and the new end date. A handler who resumes the term earlier SHALL be handled as REQ-TERM-002
describes, and no automatic resume SHALL follow.

Rows: `8.10` (dossiq matrix).

#### Scenario: The pause runs out
- **GIVEN** a term paused for 14 days on 1 July with no answer from the applicant
- **WHEN** 15 July passes
- **THEN** the term's `status` is `lopend` again and a `hervat` event says it resumed because the pause ended
- **AND** the handler gets a notification naming the case and its new end date

#### Scenario: A handler resumed earlier
- **GIVEN** a 14-day pause that the handler resumed after 8 days
- **WHEN** the original pause end passes
- **THEN** no second `hervat` event is recorded and no notification is sent
