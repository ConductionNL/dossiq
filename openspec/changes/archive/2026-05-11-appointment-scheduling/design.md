# Design: appointment-scheduling

## Architecture Overview

Appointments are OpenRegister objects linked to cases. A plugin interface abstracts the appointment backend. The frontend provides booking UI in case detail and a public booking page.

```
CaseDetail.vue
├── AppointmentSection.vue (list appointments, book new)
│   └── AppointmentBookingDialog.vue (product/location/timeslot selection)
└── ActivityTimeline.vue (appointment events)

Settings
└── AppointmentSettingsTab.vue (backend config, products, locations)

Public
└── PublicAppointmentPage.vue (citizen cancel/reschedule)
```

## File Map

### New Files

| File | Purpose |
|------|---------|
| `lib/Service/AppointmentService.php` | Appointment CRUD, backend plugin dispatch, reminder scheduling |
| `lib/Service/AppointmentBackend/AppointmentBackendInterface.php` | Plugin interface for appointment backends |
| `lib/Service/AppointmentBackend/LocalBackend.php` | Local fallback (OpenRegister storage only) |
| `lib/Service/AppointmentBackend/JccBackend.php` | JCC Afspraken API integration |
| `lib/Service/AppointmentBackend/QmaticBackend.php` | Qmatic Orchestra REST API integration |
| `lib/Controller/AppointmentController.php` | Authenticated API for appointment management |
| `lib/Controller/PublicAppointmentController.php` | Public endpoints for citizen self-service |
| `lib/BackgroundJob/AppointmentReminderJob.php` | Daily job for sending appointment reminders |
| `src/views/cases/components/AppointmentSection.vue` | Case detail appointment list and booking trigger |
| `src/views/cases/components/AppointmentBookingDialog.vue` | Product/location/timeslot selection dialog |
| `src/views/settings/tabs/AppointmentSettingsTab.vue` | Admin settings for backend, products, locations |
| `src/views/public/PublicAppointmentPage.vue` | Citizen appointment management page |
| `src/services/appointmentApi.js` | Frontend API service |

### Modified Files

| File | Changes |
|------|---------|
| `lib/Settings/procest_register.json` | Add `appointment`, `appointmentProduct`, `appointmentLocation` schemas |
| `lib/Service/SettingsService.php` | Add appointment config keys |
| `appinfo/routes.php` | Add appointment routes |

## Data Model

### appointment Schema
- `caseId` (string, UUID) -- Linked case
- `productId` (string) -- Appointment product
- `locationId` (string) -- Physical location
- `dateTime` (string, ISO 8601) -- Appointment datetime
- `duration` (integer, minutes) -- Duration
- `status` (enum: scheduled/confirmed/cancelled/completed/no_show)
- `citizenName` (string) -- Citizen name
- `citizenEmail` (string) -- Citizen email
- `citizenPhone` (string, nullable) -- Citizen phone
- `externalId` (string, nullable) -- External system appointment ID
- `cancelToken` (string) -- Token for self-service cancellation
- `reminderSent` (boolean, default false)
- `notes` (string, nullable) -- Case worker notes

## API Endpoints

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/appointments` | List appointments (filtered by caseId) |
| POST | `/api/appointments` | Book an appointment |
| GET | `/api/appointments/{id}` | Get appointment details |
| PUT | `/api/appointments/{id}` | Update appointment |
| DELETE | `/api/appointments/{id}` | Cancel appointment |
| POST | `/api/appointments/{id}/no-show` | Mark as no-show |
| GET | `/api/appointments/timeslots` | Get available timeslots (product + location + date) |
| GET | `/api/public/appointment/{token}` | View appointment (citizen) |
| POST | `/api/public/appointment/{token}/cancel` | Cancel (citizen) |
| POST | `/api/public/appointment/{token}/reschedule` | Reschedule (citizen) |
