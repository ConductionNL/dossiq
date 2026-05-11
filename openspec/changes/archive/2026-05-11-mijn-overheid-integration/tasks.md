# Tasks: mijn-overheid-integration

## Implementation Tasks

### Schema & Configuration

- [x] **T01**: Add `berichtenboxMessage`, `berichtenboxTypeCode` schemas to `procest_register.json`. Add config keys: `berichtenbox_message_schema`, `berichtenbox_type_code_schema`, `berichtenbox_enabled`, `berichtenbox_api_url`, `berichtenbox_oin`, `berichtenbox_certificate_path`, `berichtenbox_default_type_code`.

### Backend: Adapter Interface

- [x] **T02**: Create `lib/Service/BerichtenboxAdapter/BerichtenboxAdapterInterface.php` -- Interface with: `sendMessage(bsn, subject, body, typeCode, attachment): array`, `getReadStatus(messageId): array`.

- [x] **T03**: Create `lib/Service/BerichtenboxAdapter/MockAdapter.php` -- Mock implementation for development. Returns simulated success with generated message ID. Simulates read status as "read" after a configurable delay.

### Backend: Service & Controller

- [x] **T04**: Create `lib/Service/BerichtenboxService.php` -- Methods: `sendMessage(caseId, bsn, subject, body, typeCode, attachmentFileId)` validates input (BSN required, subject required, body required, attachment <= 10 MB), delegates to adapter, stores message record in OpenRegister, returns result; `getMessagesForCase(caseId)` queries messages; `pollReadStatus(messageId)` queries adapter and updates message; `validateBsn(bsn)` checks 11-proef; `getTypeCodesForZaaktype(zaaktypeId)` returns configured codes.

- [x] **T05**: Create `lib/Controller/BerichtenboxController.php` -- Endpoints for send, list, types, poll.

- [x] **T06**: Create `lib/BackgroundJob/BerichtenboxReadStatusJob.php` -- Daily TimedJob that polls read status for all sent messages that haven't been read yet. Flags messages unread for > 7 days.

### Routes

- [x] **T07**: Add berichtenbox routes to `appinfo/routes.php`.

### Frontend

- [x] **T08**: Create `src/services/berichtenboxApi.js`.

- [x] **T09**: Create `src/views/cases/components/BerichtenboxTab.vue` -- Sidebar tab showing sent messages with status badges, read timestamps, and "Nieuw bericht" button.

- [x] **T10**: Create `src/views/cases/components/BerichtenboxComposeDialog.vue` -- Message composer with BSN (pre-filled from case), subject, plain text body (with character count), bericht type selector, PDF attachment upload, validation errors.

- [x] **T11**: Create `src/views/settings/tabs/BerichtenboxSettingsTab.vue` -- Enable toggle, API URL, OIN, certificate path, bericht type code management, test connection button.

## Verification Tasks

- [ ] **V01**: Message send validates BSN is present
- [ ] **V02**: Message body is plain text only (no HTML)
- [ ] **V03**: Attachment rejects files > 10 MB
- [ ] **V04**: Sent message stored as case document
- [ ] **V05**: Read status polling updates message record
- [ ] **V06**: Unread messages flagged after 7 days
- [ ] **V07**: BSN validation uses 11-proef
