# mobiel-inspectie-offline Delta: inspection-conflict-card

## MODIFIED Requirements

### Requirement: Conflict Detection and Resolution for Concurrent Edits

The system SHALL detect when a colleague has edited the same case while the inspector was offline, surface the conflict clearly, and allow the inspector to keep one of the two versions: the one on the device or the one at the office.

#### Scenario: Detect conflict on sync replay

@e2e exclude requires an offline-created result and a concurrent server edit to
trigger a 409, which is not headless-deterministic. The conflict classification
backend was removed by 062d9dede, so the two PHPUnit classes this reason used
to name no longer exist here and this scenario has no coverage.

- **GIVEN** inspector Anja completed a checklist offline with answer "goedgekeurd" for "Keuring afgewerkt?"
- **AND** while she was offline, her colleague Piet (back at the office) changed the same case's inspection status to "afgekeurd"
- **WHEN** Anja's sync queue replays her ChecklistResult update
- **THEN** the system SHALL receive a 409 Conflict response from OpenRegister (ETag mismatch)
- **AND** create a ConflictRecord with:
  - `syncQueueRef`: reference to the failed operation
  - `clientVersion`: Anja's ChecklistResult record (including her answer)
  - `serverVersion`: Piet's updated record (including his status change)
  - `conflictType: "concurrent_edit"`
  - `targetRegister`, `targetSchema` and `targetId`: the object the update was written to
  - `conflictFields`: the dot paths of the fields both sides changed

#### Scenario: Show conflict merge UI to inspector

@e2e exclude a conflict record with both versions is example-set data on the measured instance; the card is covered by nextcloud-vue's CnConflictResolveCard suite and tests/vitest/inspectionConflictCard.spec.js reads its configuration.

- **GIVEN** a ConflictRecord without a resolution, whose `conflictFields` names the finding text Pieter changed on his phone and the office changed meanwhile
- **WHEN** Pieter opens the sync frame of Inspectie in het veld
- **THEN** the system SHALL show, under the outbox, a card "Conflict: welke tekst houdt u?"
- **AND** the line `Op dit toestel: "Bijgebouw 4 bij 6 meter, in de groenstrook."`
- **AND** the line `Op kantoor: "Bijgebouw circa 24 m², zonder vergunning."`
- **AND** two buttons, "Deze houden" and "Die van kantoor"
- **AND** when more than one field differs, each pair of lines under the field's name

#### Scenario: Inspector resolves conflict

@e2e exclude the resolve endpoint is SIG's (see FOR-SIG in this change) and has not shipped; the card's request is covered by nextcloud-vue's CnConflictResolveCard suite.

- **GIVEN** the conflict card is shown and dossiq announces the capability `conflictResolution`
- **WHEN** the inspector taps "Deze houden"
- **THEN** the system SHALL post `{ "resolution": "client_wins" }` to `/apps/dossiq/api/conflict-records/{id}/resolve`
- **AND** the server SHALL write the device's value at each of `conflictFields` onto the target object, as the inspector, when the office value is still the one in `serverVersion`
- **AND** mark the ConflictRecord with `resolution: "client_wins"`, `resolvedBy` the inspector's uid from the session and `resolvedAt` now
- **AND** mark the linked syncQueue row `synced`
- **AND** the card SHALL leave the frame
- **WHEN** the inspector taps "Die van kantoor" instead
- **THEN** the same request carries `server_wins`, nothing is written to the target object, and the record and the outbox row are marked the same way

#### Scenario: The endpoint has not shipped

- **GIVEN** dossiq does not announce the capability `conflictResolution`
- **THEN** the card SHALL still show both texts
- **AND** both buttons SHALL be disabled and described as "Een tekst kiezen kan nog niet. Uw wijziging blijft in de wachtrij."
- **AND** no request SHALL be sent

#### Scenario: Permission lost during offline work

@e2e exclude requires revoking case permission while offline to force a 403 on
replay, which is not headless-deterministic. The terminal permission_lost
handling went with the sync backend in 062d9dede, so the PHPUnit class this
reason used to name no longer exists here.

- **GIVEN** an inspector worked offline on a sensitive case (e.g., social-welfare home visit)
- **AND** while she was offline, a manager revoked her read permission on that case
- **WHEN** sync replays her ChecklistResult update
- **THEN** the system SHALL receive a 403 Forbidden response
- **AND** create a ConflictRecord with `conflictType: "permission_lost"`
- **AND** display a warning to the inspector: "Geen toestemming meer voor deze zaak. Neem contact op met uw supervisor."
- **AND** move the operation to `failed` status (not retryable)

## ADDED Requirements

### Requirement: Resolving a conflict record writes the chosen value and marks the record resolved

The system SHALL offer `POST /apps/dossiq/api/conflict-records/{id}/resolve` with a body `{ "resolution": "client_wins" | "server_wins" }`. It SHALL check access before it writes anything: the caller SHALL be able to read the conflict record, and for `client_wins` SHALL be allowed to update the target object. A record that already has a resolution SHALL be refused with 409. For `client_wins` the system SHALL compare the target's current values at `conflictFields` with `serverVersion`; when the office changed the text again, it SHALL refuse with 409, store the new office values as `serverVersion` and leave the record open. Otherwise it SHALL write the device's values at `conflictFields` onto the target as the caller. `resolvedBy` SHALL be the session user, never a value from the body. The system SHALL announce the endpoint as the capability `dossiq.conflictResolution`.

#### Scenario: The office changed the text again

@e2e exclude needs a second concurrent office edit between showing the card and choosing; SIG covers it with a PHPUnit test of the service.

- **GIVEN** an open conflict whose `serverVersion` holds "Bijgebouw circa 24 m², zonder vergunning."
- **AND** a colleague has since changed the finding to "Bijgebouw 24 m², vergunning aangevraagd."
- **WHEN** the inspector chooses "Deze houden"
- **THEN** the system SHALL answer 409 with "Op kantoor is de tekst intussen opnieuw gewijzigd. Kies opnieuw."
- **AND** store the new office text as `serverVersion`, so the card shows it on the next read
- **AND** write nothing to the target object

#### Scenario: Someone without access to the case

@e2e exclude access is decided by OpenRegister's RBAC on the target schema; SIG covers it with a PHPUnit test of the controller.

- **GIVEN** a user who can read the conflict record but may not update the target object
- **WHEN** they choose "Deze houden"
- **THEN** the system SHALL answer 403 and write nothing
