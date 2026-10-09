---
status: done
retrofit: true
---

# Appointment Booking Specification

## Purpose

@e2e exclude Appointment booking is V1; public token pages + backend integrations are not Playwright-testable in the current build.

Enable Dossiq cases to schedule, manage, and cancel citizen appointments through pluggable backend integrations with external municipal appointment systems (JCC Afspraken, Qmatic Orchestra) or a local-storage fallback. Citizens receive a token-protected URL for viewing and cancelling their appointment without authentication; case handlers manage appointments through authenticated endpoints; a background job dispatches reminders before each appointment.

**Standards**: JCC Afspraken API, Qmatic Orchestra REST API
**Feature tier**: V1

## Requirements

### Requirement: REQ-001: AppointmentBackendInterface SHALL define a 4-method contract for pluggable appointment-scheduling backends

`OCA\Dossiq\Service\AppointmentBackend\AppointmentBackendInterface` SHALL define exactly four methods that every backend implementation SHALL implement:
- `getTimeslots(string $productId, string $locationId, string $date): array` — return the list of available timeslots `[{time, duration, available}]` for a (product, location, date) triple.
- `bookAppointment(array $data): array` — book an appointment in the external system and return a result containing `externalId`.
- `cancelAppointment(string $externalId): bool` — cancel by external ID; return `true` on success.
- `rescheduleAppointment(string $externalId, string $newDateTime): array` — reschedule and return the updated booking.

The interface SHALL be the only point of coupling between `AppointmentService` and external systems. Three implementations SHALL ship with Dossiq: `JccBackend` (JCC Afspraken), `QmaticBackend` (Qmatic Orchestra), and `LocalBackend` (no external system — appointments stored only in OpenRegister).

#### Scenario: Every shipped backend implements the contract
- **GIVEN** the three shipped backends `JccBackend`, `QmaticBackend`, `LocalBackend`
- **WHEN** the autoloader resolves each class
- **THEN** each SHALL be `instanceof AppointmentBackendInterface`
- **AND** each SHALL expose the four contract methods with matching signatures

#### Scenario: LocalBackend short-circuits external calls
- **GIVEN** the active backend is `LocalBackend`
- **WHEN** `bookAppointment($data)` is invoked
- **THEN** no outbound HTTP call SHALL be issued — the result SHALL be generated locally and returned

#### Notes
- Adding a new municipal-system backend requires only a new class implementing the interface plus a config entry — no `AppointmentService` changes.

### Requirement: REQ-002: AppointmentService SHALL persist every booked appointment to OpenRegister and SHALL generate per-appointment cancel tokens

`OCA\Dossiq\Service\AppointmentService` SHALL be the single orchestrator that ties backends to OpenRegister persistence. The service SHALL expose six public methods:
- `getTimeslots(string $productId, string $locationId, string $date): array`, delegating to the active backend.
- `bookAppointment(string $caseId, array $data): array`, booking via the backend and then persisting to OpenRegister with `status: 'scheduled'`, `externalId: <backend result>`, `cancelToken: bin2hex(random_bytes(16))` (32-char hex) and the `caseId`. It returns `['error' => 'OpenRegister is not available']` when `ObjectService` resolves to null.
- `cancelAppointment(string $appointmentId): array`, loading, cancelling via the backend and flipping the persisted status.
- `markNoShow(string $appointmentId): array`, flipping the persisted status to no-show (no backend call).
- `getAppointmentsForCase(string $caseId): array`, listing persisted appointments by case.
- `getAppointmentByToken(string $token): ?array`, looking up by the `cancelToken` for the public controller.

The persisted appointment SHALL live in the configured `register` + `appointment_schema` (read from `SettingsService::getConfigValue`). It SHALL NOT carry a `reminderSent` flag: no reminder job exists to read it.

#### Scenario: Booking generates a 32-char hex cancel token
- **WHEN** `bookAppointment('uuid-case', $data)` is invoked
- **THEN** the persisted record SHALL contain `cancelToken` matching `^[0-9a-f]{32}$`
- **AND** SHALL contain `status: 'scheduled'`

#### Scenario: OpenRegister unavailable returns structured error
- **GIVEN** `SettingsService::getObjectService()` returns null
- **WHEN** `bookAppointment('uuid-case', $data)` is invoked
- **THEN** the result SHALL be `['error' => 'OpenRegister is not available']`
- **AND** the backend's `bookAppointment` SHALL NOT have been called

#### Scenario: Token lookup returns null when token unknown
- **WHEN** `getAppointmentByToken('not-a-real-token')` is invoked
- **THEN** the method SHALL return `null` (NOT throw)

#### Notes
- The cancel token's entropy (128 bits) is sufficient to prevent brute-forcing; rotation on cancel/reschedule is a future TODO.
- The "book in backend first, then persist" order means a successful backend booking with a failed OpenRegister persist leaves an orphan in the external system. A future requirement may codify the compensating cancel.

### Requirement: REQ-003: AppointmentController SHALL expose the internal handler-facing CRUD endpoints

`OCA\Dossiq\Controller\AppointmentController` SHALL expose five authenticated action methods (default `#[NoAdminRequired]`):
- `index()` — list appointments for the current handler / filter context.
- `create()` — book a new appointment for a case (delegates to `AppointmentService::bookAppointment`).
- `cancel(string $appointmentId)` — cancel an existing appointment.
- `noShow(string $appointmentId)` — mark as no-show.
- `timeslots()` — proxy to `AppointmentService::getTimeslots` so the UI can render the picker.

#### Scenario: Cancel returns the updated appointment record
- **GIVEN** a scheduled appointment `appt-uuid-1`
- **WHEN** an authenticated handler calls `cancel('appt-uuid-1')`
- **THEN** the response SHALL be a JSONResponse containing the appointment record with `status: 'cancelled'`

#### Scenario: noShow does not touch the external backend
- **WHEN** `noShow('appt-uuid-1')` is invoked
- **THEN** the persisted status SHALL flip to `noShow` (or equivalent)
- **AND** no outbound backend cancel/reschedule call SHALL be issued

### Requirement: REQ-004: PublicAppointmentController SHALL serve token-gated view + cancel for citizens

`OCA\Dossiq\Controller\PublicAppointmentController` SHALL expose two unauthenticated (`#[PublicPage] #[NoCSRFRequired]`) action methods keyed by the `cancelToken` from REQ-002:
- `view(string $token)` — return the appointment record (date, time, location, status) for the matching token, or `404` when the token does not match.
- `cancel(string $token)` — cancel the matched appointment via `AppointmentService::cancelAppointment`, or `404` when the token does not match.

The controller SHALL NEVER expose the persisted appointment UUID, caseId, or backend externalId in either response — only the citizen-facing fields.

#### Scenario: Unknown token returns 404
- **WHEN** `view('not-a-real-token')` is invoked
- **THEN** the response SHALL have status 404 (or an equivalent error envelope)
- **AND** SHALL NOT leak appointment data

#### Scenario: Valid token returns citizen-safe fields
- **GIVEN** an appointment with `cancelToken = 'abc...32hex'` and `caseId = 'uuid-1'`
- **WHEN** `view('abc...32hex')` is invoked
- **THEN** the response SHALL contain the appointment date, time, location, and status
- **AND** SHALL NOT contain the appointment UUID, `caseId`, or `externalId`

#### Notes
- Rate-limiting the token-validation endpoint is a security TODO; today the controller relies on token entropy alone (128 bits — well above brute-force territory but worth pairing with rate-limit per IP).
