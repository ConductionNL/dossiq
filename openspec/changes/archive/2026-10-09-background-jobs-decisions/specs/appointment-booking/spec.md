## MODIFIED Requirements

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

## REMOVED Requirements

### Requirement: REQ-005: AppointmentReminderJob SHALL dispatch citizen reminders before scheduled appointments via the Nextcloud TimedJob queue

**Reason**: The job was never scheduled, the `appointment` schema it read does not exist, and it sent nothing: it only set `reminderSent`.
**Migration**: None. `RetireUnscheduledBackgroundJobs` removes it from any job list that still has it. A real reminder belongs with the calendar leaf.
