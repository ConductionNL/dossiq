---
status: implemented
---
# appointment-scheduling Specification

## Purpose
Integrate appointment scheduling (afsprakenbeheer) into Procest case flows for cases that require physical service delivery at a municipal counter (balie). Citizens can book appointments as part of case submission or at any point during case handling. The system integrates with existing municipal appointment backends (Qmatic, JCC Afspraken) via a plugin architecture, and supports self-service cancellation and modification.

## Context
In Dutch municipalities, balie appointments are standard for services like passport collection, marriage registration, and permit discussions. Open-Formulieren implements appointment scheduling as part of form submissions with integration plugins for JCC and Qmatic -- product/location/timeslot selection during intake with configurable contact details. This is the reference model. Procest extends this by embedding appointments into the case lifecycle, making appointment status visible in case context, and supporting both citizen self-service and case worker-initiated scheduling.

## ADDED Requirements
### Requirement: Appointments bookable as part of case flow
Case workers or citizens MUST be able to create appointments linked to a case at any point during the case lifecycle.

#### Scenario: Book appointment during case intake
- **GIVEN** a citizen is submitting a `paspoort_aanvraag` case
- **AND** the zaaktype is configured with `requiresAppointment: true`
- **WHEN** the citizen reaches the appointment step in the intake flow
- **THEN** the system MUST show:
  - Available products (e.g., "Paspoort ophalen", "Rijbewijs ophalen") filtered by zaaktype configuration
  - Available locations (e.g., "Stadskantoor", "Wijkkantoor Noord") for the selected product
  - Available dates and timeslots for the selected product/location combination
- **AND** the citizen MUST select a timeslot to proceed with case submission
- **AND** the appointment MUST be automatically linked to the created case

#### Scenario: Book appointment from case detail view
- **GIVEN** case `zaak-1` is in progress and needs a physical meeting
- **WHEN** a case worker clicks "Plan afspraak" in the `CaseDetail.vue` header actions
- **THEN** an appointment booking dialog MUST appear with:
  - Product pre-selected based on the zaaktype (editable)
  - Location dropdown with configured municipal locations
  - Date picker showing available dates
  - Timeslot grid for the selected date
- **AND** the appointment MUST be linked to `zaak-1` after booking
- **AND** an activity entry MUST appear in the `ActivityTimeline`

#### Scenario: Multiple appointments per case
- **GIVEN** case `zaak-1` already has an appointment for document submission
- **WHEN** the case worker books a second appointment for document collection
- **THEN** both appointments MUST be listed in the case's appointment section
- **AND** each appointment MUST have its own status and lifecycle

#### Scenario: Appointment as required task
- **GIVEN** a zaaktype configured with an appointment required at status "Ophalen"
- **WHEN** the case reaches the "Ophalen" status
- **THEN** a task MUST be auto-created: "Plan afspraak voor ophalen"
- **AND** the case MUST NOT be advanceable to the next status until the appointment is booked

#### Scenario: Appointment links to case participants
- **GIVEN** a case with a linked citizen (role: initiator, with BSN and contact details)
- **WHEN** booking an appointment
- **THEN** the citizen's name, phone number, and email MUST be pre-filled from the case role data
- **AND** the case worker MUST be able to override the contact details (e.g., if someone else will attend)

### Requirement: Pluggable appointment backend architecture
Different municipalities use different appointment systems; the integration MUST be pluggable.

#### Scenario: JCC Afspraken integration
- **GIVEN** the municipality uses JCC Afspraken
- **AND** the JCC plugin is configured in Procest settings with: API URL, API key, and organization ID
- **WHEN** a timeslot query is made for product "Paspoort ophalen" at location "Stadskantoor"
- **THEN** the plugin MUST call the JCC API endpoint `/openapi/v1/beschikbaarheid` to retrieve available slots
- **AND** booking MUST call JCC's `/openapi/v1/afspraken` to create the appointment
- **AND** the JCC appointment ID MUST be stored on the Procest appointment record for sync
- **AND** cancellation MUST call JCC's delete endpoint to cancel in both systems

#### Scenario: Qmatic Orchestra integration
- **GIVEN** the municipality uses Qmatic Orchestra
- **AND** the Qmatic plugin is configured with: base URL, API key, and branch ID
- **WHEN** a timeslot query is made
- **THEN** the plugin MUST call the Qmatic REST API (`/rest/servicepoint/branches/{id}/dates/{date}/times`)
- **AND** booking MUST create the appointment in Qmatic
- **AND** the Qmatic appointment reference MUST be stored on the Procest record

#### Scenario: Fallback manual scheduling (no backend)
- **GIVEN** no appointment backend is configured
- **WHEN** a case worker creates an appointment
- **THEN** the appointment MUST be stored locally in OpenRegister as an appointment object
- **AND** a Nextcloud Calendar event MUST be created via `OCP\Calendar\IManager`
- **AND** the calendar event MUST include the case reference, citizen name, product, and location

#### Scenario: Plugin registration via OpenConnector
- **GIVEN** the plugin architecture uses OpenConnector as the API adapter layer
- **WHEN** an admin configures a new appointment backend
- **THEN** they MUST select the backend type (JCC/Qmatic/Custom) and configure the connection via OpenConnector source settings
- **AND** the system MUST validate the connection with a test call before saving

#### Scenario: Backend failover handling
- **GIVEN** the JCC API returns a 503 Service Unavailable error
- **WHEN** a case worker attempts to book an appointment
- **THEN** the system MUST display: "Afsprakensysteem tijdelijk niet beschikbaar. Probeer het later opnieuw."
- **AND** the error MUST be logged with timestamp and response details
- **AND** the system MUST NOT fall back to manual scheduling unless explicitly configured

### Requirement: Citizen self-service appointment management
Citizens MUST be able to cancel, reschedule, and view their appointments without contacting the municipality.

#### Scenario: Cancel an appointment via confirmation link
- **GIVEN** citizen has appointment `apt-1` for March 25, 2026 at 10:00 at Stadskantoor
- **AND** the citizen received a confirmation email with a unique cancellation link
- **WHEN** the citizen opens the link and clicks "Annuleren"
- **THEN** a confirmation dialog MUST appear: "Weet u zeker dat u uw afspraak op 25 maart om 10:00 wilt annuleren?"
- **AND** upon confirmation, the appointment MUST be cancelled in both Procest and the backend system (JCC/Qmatic)
- **AND** a cancellation confirmation MUST be sent (email and/or SMS based on configuration)
- **AND** the case `ActivityTimeline` MUST record: "Afspraak geannuleerd door burger"

#### Scenario: Reschedule an appointment
- **GIVEN** citizen has appointment `apt-1` for March 25 at 10:00
- **WHEN** the citizen accesses their appointment via the confirmation link and clicks "Verzetten"
- **THEN** available alternative timeslots MUST be shown for the same product and location
- **AND** selecting a new slot MUST atomically cancel the old appointment and book the new one
- **AND** a new confirmation MUST be sent with updated date/time/location

#### Scenario: View appointment details
- **GIVEN** a citizen accesses their appointment link
- **THEN** the page MUST show: date, time, location (with address and map link), product, what to bring, and the case reference number
- **AND** provide buttons for "Annuleren" and "Verzetten"
- **AND** the page MUST NOT require authentication (token-based access)

#### Scenario: Cancellation deadline enforcement
- **GIVEN** the municipality configures a minimum cancellation notice of 24 hours
- **WHEN** a citizen attempts to cancel appointment `apt-1` that starts in 4 hours
- **THEN** the system MUST display: "Annuleren is niet meer mogelijk. Neem contact op met de gemeente."
- **AND** provide a phone number or contact form link

#### Scenario: Self-service link expiration
- **GIVEN** appointment `apt-1` was scheduled for March 25 at 10:00
- **AND** today is March 26 (appointment has passed)
- **WHEN** the citizen accesses the confirmation link
- **THEN** the page MUST show: "Deze afspraak heeft plaatsgevonden op 25 maart 2026"
- **AND** cancellation and rescheduling MUST be disabled

### Requirement: Appointment lifecycle and reminder notifications
Appointments MUST track status through their lifecycle with automated reminders to reduce no-shows.

#### Scenario: Appointment confirmation notification
- **GIVEN** a citizen books appointment `apt-1` for March 25 at 10:00 at Stadskantoor
- **THEN** a confirmation MUST be sent (configurable: email, SMS, or both) containing:
  - Date, time, and location with address
  - Product name (what the appointment is for)
  - What to bring (linked to zaaktype `requiresDocuments` configuration)
  - Cancellation/modification link (unique token-based URL)
  - Case reference number
- **AND** the confirmation MUST be sent via an n8n workflow for template flexibility

#### Scenario: Reminder notification before appointment
- **GIVEN** appointment `apt-1` is scheduled for tomorrow at 10:00
- **WHEN** the Nextcloud cron job runs the reminder check
- **THEN** a reminder MUST be sent to the citizen via the configured channel
- **AND** the reminder interval MUST be configurable per zaaktype (default: 1 day before)
- **AND** the reminder MUST include a "not able to make it" link for easy cancellation

#### Scenario: No-show recording
- **GIVEN** appointment `apt-1` was scheduled for 10:00 and the citizen did not appear
- **WHEN** the case worker marks the appointment as "Niet verschenen" (no-show)
- **THEN** the appointment status MUST change to `niet_verschenen`
- **AND** the case `ActivityTimeline` MUST record: "Burger niet verschenen bij afspraak"
- **AND** a follow-up task MUST be auto-created: "Contact opnemen na niet-verschijnen" if configured

#### Scenario: Appointment completed
- **GIVEN** appointment `apt-1` took place
- **WHEN** the case worker marks it as "Afgerond" (completed)
- **THEN** the appointment status MUST change to `afgerond`
- **AND** the case timeline MUST record: "Afspraak gehouden: 25 maart 2026, 10:00, Stadskantoor"
- **AND** if the zaaktype has a post-appointment status transition configured, the case MUST auto-advance

#### Scenario: Appointment status lifecycle
- **GIVEN** an appointment object in OpenRegister
- **THEN** it MUST support the following statuses:
  - `gepland` (initial, after booking)
  - `herinnerd` (after reminder sent)
  - `afgerond` (completed successfully)
  - `niet_verschenen` (no-show)
  - `geannuleerd` (cancelled by citizen or case worker)
  - `verzet` (rescheduled -- old appointment gets this status)

### Requirement: Appointment visibility in case context
Appointment data MUST be visible in the case timeline and case detail view.

#### Scenario: Appointment section in case detail
- **GIVEN** case `zaak-1` has one or more appointments
- **THEN** the case detail view MUST show an "Afspraken" section listing all appointments
- **AND** each appointment MUST show: date/time, location, product, status, and citizen name
- **AND** appointments MUST be ordered by date (upcoming first)

#### Scenario: Timeline integration
- **GIVEN** case `zaak-1` has an appointment lifecycle
- **WHEN** viewing the `ActivityTimeline` component
- **THEN** the following events MUST appear chronologically:
  - "Afspraak gepland: 25 maart 2026, 10:00, Stadskantoor"
  - "Herinnering verzonden naar burger"
  - "Afspraak gehouden" or "Burger niet verschenen"
- **AND** each event MUST include an icon appropriate to its type

#### Scenario: Appointment in case list overview
- **GIVEN** the case list view at `CaseList.vue`
- **THEN** cases with upcoming appointments MUST show a calendar icon with the next appointment date
- **AND** cases where the citizen was a no-show MUST show a warning indicator

#### Scenario: Appointment on dashboard
- **GIVEN** the Procest dashboard (`Dashboard.vue`)
- **THEN** a "Komende afspraken" widget MUST list today's and tomorrow's appointments across all cases assigned to the current user
- **AND** each entry MUST link to the case detail

### Requirement: Real-time timeslot availability
Shown timeslots MUST reflect current availability to prevent double bookings and stale data.

#### Scenario: Live availability query
- **GIVEN** a citizen or case worker is browsing available timeslots
- **WHEN** they select a date
- **THEN** the system MUST query the appointment backend in real-time (not cached) for that date
- **AND** display available slots with capacity indicators (if the backend provides capacity data)

#### Scenario: Concurrent booking prevention
- **GIVEN** two citizens view the same timeslot as available
- **WHEN** both attempt to book it simultaneously
- **THEN** only one booking MUST succeed (the backend system handles atomicity)
- **AND** the other MUST receive: "Dit tijdslot is zojuist geboekt. Kies een ander tijdslot."
- **AND** the timeslot grid MUST refresh to show updated availability

#### Scenario: Timeslot expiration during booking
- **GIVEN** a citizen has been on the booking page for 15 minutes without completing
- **THEN** the system MUST display: "Beschikbaarheid kan gewijzigd zijn. Vernieuw de tijdsloten."
- **AND** provide a refresh button to reload current availability

#### Scenario: Availability filtered by capacity
- **GIVEN** a location has 3 service desks (balies) available
- **AND** 2 are already booked for the 10:00-10:15 slot
- **THEN** the slot MUST still show as available (1 remaining)
- **AND** when all 3 are booked, the slot MUST show as unavailable

### Requirement: Product and location configuration
Administrators MUST be able to configure which products and locations are available for appointment booking.

#### Scenario: Configure products per zaaktype
- **GIVEN** the admin is editing a zaaktype in `CaseTypeDetail.vue`
- **THEN** a "Products" tab MUST allow adding appointment products
- **AND** each product MUST have: name, description, estimated duration (minutes), and backend product ID (for JCC/Qmatic mapping)
- **AND** products MUST be linkable to specific zaaktype statuses (e.g., "Paspoort ophalen" only available at status "Ophalen")

#### Scenario: Configure locations
- **GIVEN** the admin navigates to appointment settings
- **THEN** they MUST be able to manage locations with: name, address, phone number, opening hours, and backend location ID
- **AND** locations MUST be filterable by which products they offer

#### Scenario: Location-specific availability rules
- **GIVEN** location "Wijkkantoor Noord" is only open Tuesday through Thursday
- **WHEN** a citizen selects this location
- **THEN** only Tuesday, Wednesday, and Thursday dates MUST be shown in the date picker
- **AND** the opening hours MUST be configured per location in the admin settings

#### Scenario: Seasonal closures and holidays
- **GIVEN** the municipality configures holidays and closure dates
- **THEN** those dates MUST be excluded from appointment availability
- **AND** existing appointments on newly added closure dates MUST be flagged for rescheduling

### Requirement: Appointment data model in OpenRegister
Appointments MUST be stored as OpenRegister objects with a defined schema.

#### Scenario: Appointment schema definition
- **GIVEN** the Procest register configuration
- **THEN** an `appointment` schema MUST be defined with fields:
  - `id` (UUID, auto-generated)
  - `caseId` (reference to case)
  - `citizenName` (string)
  - `citizenEmail` (string)
  - `citizenPhone` (string)
  - `product` (string, from configured products)
  - `location` (string, from configured locations)
  - `dateTime` (ISO 8601 datetime)
  - `duration` (integer, minutes)
  - `status` (enum: gepland/herinnerd/afgerond/niet_verschenen/geannuleerd/verzet)
  - `externalId` (string, JCC/Qmatic reference)
  - `selfServiceToken` (string, unique token for citizen access)
  - `notes` (text, case worker notes)
  - `bookedBy` (string, user who created the booking)

#### Scenario: Appointment linked to case via caseObject
- **GIVEN** an appointment is created for case `zaak-1`
- **THEN** a `caseObject` record MUST link the appointment to the case
- **AND** querying the case's objects MUST include the appointment

#### Scenario: Appointment history preserved
- **GIVEN** appointment `apt-1` is rescheduled from March 25 to March 28
- **THEN** the original appointment MUST be preserved with status `verzet`
- **AND** a new appointment MUST be created with the new date and status `gepland`
- **AND** both MUST be linked to the same case

### Requirement: Notification channel configuration
Appointment notifications MUST support multiple channels with per-municipality configuration.

#### Scenario: Email notifications via n8n
- **GIVEN** the municipality has email notifications configured
- **WHEN** an appointment is booked
- **THEN** the confirmation email MUST be sent via an n8n workflow
- **AND** the email template MUST be customizable by the municipality (HTML template in n8n)

#### Scenario: SMS notifications
- **GIVEN** the municipality has SMS notifications enabled (via a configured SMS gateway in OpenConnector)
- **WHEN** an appointment reminder is triggered
- **THEN** an SMS MUST be sent with a short message: "Herinnering: uw afspraak morgen om 10:00 bij Stadskantoor. Niet kunnen komen? [link]"

#### Scenario: Notification preferences per citizen
- **GIVEN** a citizen has specified their notification preference during booking (email, SMS, or both)
- **THEN** notifications MUST only be sent via the selected channel(s)
- **AND** the preference MUST be stored on the appointment record

## Dependencies
- OpenRegister (for appointment data storage)
- OpenConnector (for JCC/Qmatic API adapters and SMS gateway)
- Nextcloud Calendar (`OCP\Calendar\IManager`) for fallback calendar events
- n8n (for notification workflow orchestration)
- Pipelinq (sister app -- appointments booked during CRM interactions may be linked to cases)
- Mijn Overheid integration (appointment status as case status update)

---

### Current Implementation Status

**Not yet implemented.** No appointment-related schemas, controllers, services, or Vue components exist in the Procest codebase. The `procest_register.json` configuration does not include an appointment schema.

**Foundation available:**
- Case detail view (`src/views/cases/CaseDetail.vue`) provides the integration point where a "Plan afspraak" button would be added in the header actions.
- Activity timeline component (`src/views/cases/components/ActivityTimeline.vue`) could display appointment events.
- `DeadlinePanel.vue` shows that date-based tracking UI patterns are established.
- OpenConnector (external dependency) could host JCC/Qmatic API adapters.
- The task management infrastructure (`src/views/tasks/`) could model appointment scheduling as a task type.
- `NotificatieService.php` provides notification infrastructure.
- n8n MCP tools can orchestrate notification workflows.

**Partial implementations:** None.

### Standards & References

- **VNG GEMMA Referentiearchitectuur**: Afsprakenbeheer is a recognized component in the GEMMA zaakgericht werken reference architecture.
- **JCC Afspraken API**: Proprietary API for municipal appointment scheduling (widely used in Dutch municipalities). OpenAPI v1 specification.
- **Qmatic Orchestra REST API**: Standard integration for queue management and appointment booking.
- **Open-Formulieren Appointment Plugin Architecture**: Reference implementation for pluggable appointment backends (JCC, Qmatic) with product/location/timeslot selection model.
- **WCAG AA**: Appointment booking UI must be accessible, including date/time pickers that work with keyboard and screen readers.
- **BRP (Basisregistratie Personen)**: Citizen identification for appointment linking via BSN.
- **Nextcloud Calendar IManager**: OCP interface for creating calendar events as fallback appointment tracking.
