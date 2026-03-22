# Tasks: appointment-scheduling

## Implementation Tasks

### Schema & Configuration

- [x] **T01**: Add `appointment`, `appointmentProduct`, `appointmentLocation` schemas to `procest_register.json`. Add config keys to SettingsService: `appointment_schema`, `appointment_product_schema`, `appointment_location_schema`, `appointment_backend`, `appointment_backend_url`, `appointment_backend_api_key`, `appointment_reminder_days`.

### Backend: Plugin Interface & Backends

- [x] **T02**: Create `lib/Service/AppointmentBackend/AppointmentBackendInterface.php` -- Interface with methods: `getTimeslots(productId, locationId, date): array`, `bookAppointment(data): array`, `cancelAppointment(externalId): bool`, `rescheduleAppointment(externalId, newDateTime): array`.

- [x] **T03**: Create `lib/Service/AppointmentBackend/LocalBackend.php` -- Local fallback implementation that stores everything in OpenRegister. Timeslots are generated from configurable business hours. No external API calls.

- [x] **T04**: Create `lib/Service/AppointmentBackend/JccBackend.php` -- JCC Afspraken API integration. Calls JCC REST API for timeslots, booking, and cancellation.

- [x] **T05**: Create `lib/Service/AppointmentBackend/QmaticBackend.php` -- Qmatic Orchestra REST API integration.

### Backend: Service & Controllers

- [x] **T06**: Create `lib/Service/AppointmentService.php` -- Methods: `getTimeslots(productId, locationId, date)` delegates to configured backend, `bookAppointment(caseId, data)` books via backend + stores in OpenRegister + generates cancel token, `cancelAppointment(appointmentId)` cancels in backend + updates OpenRegister, `rescheduleAppointment(appointmentId, newDateTime)` cancels old + books new, `markNoShow(appointmentId)` updates status, `getAppointmentsForCase(caseId)` queries OpenRegister. Injects configured backend via factory pattern.

- [x] **T07**: Create `lib/Controller/AppointmentController.php` -- Authenticated endpoints for all appointment operations.

- [x] **T08**: Create `lib/Controller/PublicAppointmentController.php` -- Public endpoints for citizen self-service (cancel, reschedule via token).

- [x] **T09**: Create `lib/BackgroundJob/AppointmentReminderJob.php` -- Daily TimedJob that finds appointments scheduled for tomorrow and sends reminder notifications.

### Routes

- [x] **T10**: Add appointment routes to `appinfo/routes.php`.

### Frontend

- [x] **T11**: Create `src/services/appointmentApi.js` -- Frontend API service for all appointment endpoints.

- [x] **T12**: Create `src/views/cases/components/AppointmentSection.vue` -- List appointments for the case, "Plan afspraak" button, appointment status badges, no-show marking.

- [x] **T13**: Create `src/views/cases/components/AppointmentBookingDialog.vue` -- Dialog with product selector, location selector, date picker, available timeslots grid, citizen details form. On submit: books appointment and shows confirmation.

- [x] **T14**: Create `src/views/settings/tabs/AppointmentSettingsTab.vue` -- Backend configuration (type selector, URL, API key), products management, locations management, reminder settings.

- [x] **T15**: Create `src/views/public/PublicAppointmentPage.vue` -- Public page for citizen appointment management. Shows appointment details, cancel button, reschedule with new timeslot selection.

## Verification Tasks

- [ ] **V01**: Appointment booking creates record in OpenRegister with case linkage
- [ ] **V02**: Timeslot query returns available slots from configured backend
- [ ] **V03**: Cancel token allows citizen to cancel without authentication
- [ ] **V04**: No-show marking updates appointment status
- [ ] **V05**: Reminder job sends notifications for tomorrow's appointments
- [ ] **V06**: Concurrent booking prevention works
