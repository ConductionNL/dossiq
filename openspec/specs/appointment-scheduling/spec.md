# appointment-scheduling Specification

## Purpose
Integrate appointment scheduling (afsprakenbeheer) into Procest case flows for cases that require physical service delivery at a municipal counter (balie). Citizens can book appointments as part of case submission or at any point during case handling. The system integrates with existing municipal appointment backends (Qmatic, JCC Afspraken) via a plugin architecture, and supports self-service cancellation and modification.

Open-formulieren implements appointment scheduling as part of form submissions with integration plugins for JCC and Qmatic. Their approach -- product/location/timeslot selection during intake with configurable contact details -- is the reference model. In Dutch municipalities, balie appointments are standard for services like passport collection, marriage registration, and permit discussions.

## Requirements

### Requirement: Appointments MUST be bookable as part of case flow
Case workers or citizens can create appointments linked to a case.

#### Scenario: Book appointment during case intake
- GIVEN a citizen is submitting a `paspoort_aanvraag` case
- AND the zaaktype is configured to require a balie appointment
- WHEN the citizen reaches the appointment step in the intake flow
- THEN the system MUST show:
  - Available products (e.g., "Paspoort ophalen")
  - Available locations (e.g., "Stadskantoor", "Wijkkantoor Noord")
  - Available dates and timeslots for the selected product/location combination
- AND the citizen MUST select a timeslot to proceed

#### Scenario: Book appointment from case detail
- GIVEN case `zaak-1` is in progress and needs a physical meeting
- WHEN a case worker clicks "Plan afspraak" on the case
- THEN the appointment booking form MUST appear with the case context pre-filled
- AND the appointment MUST be linked to `zaak-1` after booking

### Requirement: Appointment backends MUST be pluggable
Different municipalities use different appointment systems.

#### Scenario: JCC Afspraken integration
- GIVEN the municipality uses JCC Afspraken
- AND the JCC plugin is configured with API URL and credentials
- WHEN a timeslot query is made
- THEN the plugin MUST call the JCC API to retrieve available slots
- AND booking MUST create the appointment in JCC
- AND the JCC appointment ID MUST be stored on the Procest appointment record

#### Scenario: Qmatic integration
- GIVEN the municipality uses Qmatic Orchestra
- AND the Qmatic plugin is configured
- WHEN a timeslot query is made
- THEN the plugin MUST call the Qmatic REST API
- AND booking MUST create the appointment in Qmatic

#### Scenario: Fallback manual scheduling
- GIVEN no appointment backend is configured
- WHEN a case worker creates an appointment
- THEN the appointment MUST be stored locally in OpenRegister
- AND no external system call MUST be made
- AND a calendar event MAY be created in Nextcloud Calendar

### Requirement: Citizens MUST be able to cancel or modify appointments
Self-service appointment management reduces administrative burden.

#### Scenario: Cancel an appointment
- GIVEN citizen has appointment `apt-1` for March 25 at 10:00
- WHEN the citizen accesses their appointment via the confirmation link
- AND clicks "Annuleren"
- THEN the appointment MUST be cancelled in both Procest and the backend system
- AND a cancellation confirmation MUST be sent (email/SMS)
- AND the case MUST be updated to reflect the cancelled appointment

#### Scenario: Reschedule an appointment
- GIVEN citizen has appointment `apt-1` for March 25 at 10:00
- WHEN the citizen accesses their appointment and clicks "Verzetten"
- THEN available alternative timeslots MUST be shown
- AND selecting a new slot MUST cancel the old appointment and book the new one
- AND a new confirmation MUST be sent

### Requirement: Appointments MUST track status and send reminders
Appointments have a lifecycle with automated reminders.

#### Scenario: Appointment confirmation
- GIVEN a citizen books appointment `apt-1`
- THEN a confirmation MUST be sent with:
  - Date, time, and location
  - What to bring (linked to zaaktype requirements)
  - Cancellation/modification link
  - Location directions

#### Scenario: Appointment reminder
- GIVEN appointment `apt-1` is scheduled for tomorrow
- WHEN the reminder job runs
- THEN a reminder MUST be sent to the citizen (configurable: 1 day or 2 days before)

#### Scenario: No-show tracking
- GIVEN appointment `apt-1` was scheduled for 10:00
- AND the citizen did not appear
- WHEN the case worker marks the appointment as no-show
- THEN the appointment status MUST change to `niet_verschenen`
- AND the case MUST be updated accordingly

### Requirement: Appointment data MUST be visible in the case timeline
Appointment events appear in the case's activity feed.

#### Scenario: Appointment events in case timeline
- GIVEN case `zaak-1` has an appointment
- WHEN viewing the case timeline
- THEN the following events MUST appear:
  - "Afspraak gepland: 25 maart 2026, 10:00, Stadskantoor"
  - "Herinnering verzonden" (if reminder was sent)
  - "Afspraak gehouden" or "Niet verschenen" (outcome)

### Requirement: Timeslot availability MUST be real-time
Shown timeslots must reflect current availability to prevent double bookings.

#### Scenario: Concurrent booking prevention
- GIVEN two citizens view the same timeslot as available
- WHEN both attempt to book it simultaneously
- THEN only one booking MUST succeed
- AND the other MUST receive a message to select a different timeslot
