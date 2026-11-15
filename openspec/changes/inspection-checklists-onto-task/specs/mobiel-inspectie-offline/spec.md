## MODIFIED Requirements

### Requirement: Offline Checklist Completion and Storage

The system SHALL allow inspectors to answer checklist questions completely offline, holding the answers on the device, and SHALL sync a finished checklist as one inspection run: one OpenRegister task, created and completed in one request, with the answers as its responses. The device SHALL NOT queue writes against a result schema.

#### Scenario: Answer checklist question offline and store locally

- **GIVEN** an inspector is in the field without network, viewing checklist "Bouwtoezicht Fase 1 - Fundering" for case ZAAK-2026-000147
- **WHEN** they answer question "Funderingskuil geschoord?" with "ja" and tap Next
- **THEN** the system SHALL:
  - Store the answer on the device with: itemId, value, answeredAt timestamp, GPS coordinates at answer time
  - Display a subtle badge "1 wijziging wachten op sync" (1 change waiting for sync)
  - Remain fully responsive (no network roundtrip)
- @e2e exclude offline storage needs a device without network and an IndexedDB queue dossiq does not ship yet; the sync contract is asserted on the run service in PHPUnit

#### Scenario: Required field validation blocks save

- **GIVEN** an item marked `required: true` whose `photoRequired` is `altijd`
- **WHEN** the inspector attempts to mark the question answered without a photo
- **THEN** the system SHALL display validation error: "Foto verplicht voor deze vraag"
- **AND** SHALL NOT store the answer or queue a sync
- **AND** the server SHALL refuse the same run on sync, writing no task
- @e2e exclude the client gate needs a camera upload; the server gate is asserted on ChecklistService in PHPUnit

#### Scenario: A finished checklist syncs as one run

- **GIVEN** an inspector finished a checklist offline
- **WHEN** the device reconnects
- **THEN** the system SHALL submit one run carrying every answer, with `capturedOffline: true`, `capturedAt` and the run's location in the task's metadata
- **AND** the run SHALL appear on the case as one completed inspection task
- @e2e exclude reconnection needs a controllable network; asserted on the run service in PHPUnit

#### Scenario: Sync status badge counts all pending operations

- **GIVEN** an inspector has answered 12 checklist questions, added 3 photos, and recorded 2 voice memos offline
- **WHEN** viewing the case detail
- **THEN** the sync badge SHALL display "17 wijzigingen wachten op sync" (17 changes waiting)
- **AND** each pending operation SHALL be listed in a "Pending Sync" view with operation type and description
- @e2e exclude needs the offline queue dossiq does not ship yet

### Requirement: Automatic GPS Geolocation Tagging on All Fieldwork

The system SHALL automatically capture GPS coordinates (latitude, longitude, accuracy in meters) and timestamp with every field action (photo, checklist answer, voice memo). Per answer they SHALL sit in the answer's `gpsAtAnswer`; for the run as a whole they SHALL sit in the inspection task's `metadata.location`.

#### Scenario: GPS coordinates captured with checklist answer

@e2e exclude Geolocation API requires a device sensor / fake-geo permission grant; not headless-deterministic. GPS classification logic is unit-tested (classifyGps).

- **GIVEN** an inspector is at case address (52.1601°N, 5.3878°E, estimated ±8 meters accuracy)
- **WHEN** they answer a checklist question offline
- **THEN** the system SHALL automatically append GPS coordinates (lat, lon, accuracy, timestamp) to the answer without user interaction
- **AND** the GPS metadata SHALL be stored in the answer's `gpsAtAnswer` field

#### Scenario: GPS accuracy warning when poor signal

@e2e exclude Requires a controllable Geolocation sensor reading; not headless-deterministic. Poor-accuracy (>50m) warning copy is unit-tested (classifyGps).

- **GIVEN** GPS signal provides coordinates with accuracy worse than 50 meters (e.g., ±200 meters in a shielded basement)
- **WHEN** an inspector attempts to answer a checklist question or take a photo
- **THEN** the system SHALL display a warning: "Locatie onnauwkeurig (±200m), wacht op beter signaal of voeg handmatig adres toe"
- **AND** allow proceeding with the action, but flag the record with `gpsAccuracy: "poor"`
- **AND** enable an optional manual address/location correction field

#### Scenario: GPS fallback to case address when signal is lost

@e2e exclude Sensor-failure fallback requires simulating Geolocation API denial; not headless-deterministic. Sensorless fallback is unit-tested (classifyGps) + server-side (EvidenceMetadataService).

- **GIVEN** GPS fails entirely (e.g., indoor in metal-framed building with no Geolocation API signal)
- **WHEN** an inspector captures evidence
- **THEN** the system SHALL silently fall back to the case's registered address from OpenRegister
- **AND** tag the record with `gpsSource: "sensorless"` (on the run, `metadata.location.source: sensorless`) for audit purposes
- **AND** NOT display an error to the inspector (graceful degradation)
