# case-appointment-via-calendar-leaf Specification

## Purpose
TBD - created by archiving change migrate-appointments-to-calendar-leaf. Update Purpose after archive.
## Requirements
### Requirement: Local Case Appointments Are Scheduled Through The OR Calendar Leaf

Scheduling a moment against a case (the former `LocalBackend` path) SHALL be performed through
OpenRegister's `calendar` integration leaf (ADR-019). Procest SHALL NOT persist or render local
appointment events through its own `AppointmentService`/`LocalBackend` after this migration.

#### Scenario: Scheduling a moment creates a calendar-leaf event on the case

- **GIVEN** a `case` object and the OR calendar leaf enabled + whitelisted on the `case` schema
- **WHEN** a handler schedules an appointment moment on the case
- **THEN** a calendar event SHALL be created via the calendar leaf, linked to the case
- **AND** the event SHALL appear in the calendar leaf tab/widget on the case detail page
- **AND** no `LocalBackend` HTTP/persistence path SHALL be invoked

#### Scenario: LocalBackend is removed

- **GIVEN** the procest codebase after this migration
- **WHEN** `lib/Service/AppointmentBackend/` is inspected
- **THEN** `LocalBackend.php` SHALL NOT be present
- **AND** the local scheduling path SHALL NOT exist in `AppointmentService`

---

### Requirement: Zaak-Specific Appointment Metadata Is Retained As Case Fields

Procest SHALL retain appointment metadata that the calendar leaf does not model (product,
location, citizen cancel token, reminder-sent flag, no-show status) as fields on a
case-appointment object in its register. The calendar leaf SHALL own the event; procest SHALL
own the zaak-domain metadata.

#### Scenario: Zaak metadata survives the migration

- **GIVEN** a case-appointment after this migration
- **WHEN** the appointment record is inspected
- **THEN** `productId`, `locationId`, `cancelToken`, `reminderSent`, and no-show status SHALL be
  retained as case-appointment fields
- **AND** the reminder background job SHALL continue to read these fields

---

### Requirement: External Qmatic/JCC Scheduling Is An ADR-022 Exception Not Served By The Leaf

Procest SHALL NOT migrate external municipal-system scheduling via `QmaticBackend` (Qmatic
Orchestra) and `JccBackend` (JCC Afspraken) to the calendar leaf, because the leaf does not
model external-system timeslot booking. This divergence SHALL be documented in an app-local ADR
referencing ADR-022 exception clause 1.

#### Scenario: Qmatic/JCC remain a documented exception

- **GIVEN** the migration is applied
- **WHEN** the external scheduling path is reviewed
- **THEN** Qmatic/JCC SHALL either remain in-app behind an app-local ADR (resolution a) OR be moved
  to an openconnector source (resolution b)
- **AND** the calendar leaf SHALL NOT be used for external timeslot booking
- **AND** a GH issue SHALL record the chosen resolution

