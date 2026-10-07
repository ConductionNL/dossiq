## MODIFIED Requirements

### Requirement: Zaak-Specific Appointment Metadata Is Retained As Case Fields

Dossiq SHALL retain appointment metadata that the calendar leaf does not model (product, location, citizen cancel token, no-show status) as fields on a case-appointment object in its register. The calendar leaf SHALL own the event; dossiq SHALL own the zaak-domain metadata.

#### Scenario: Zaak metadata survives the migration

- **GIVEN** a case-appointment after this migration
- **WHEN** the appointment record is inspected
- **THEN** `productId`, `locationId`, `cancelToken`, and no-show status SHALL be retained as case-appointment fields
- **AND** there SHALL be no `reminderSent` flag, because no reminder job reads one
