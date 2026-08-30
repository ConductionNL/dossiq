# Design: mijn-overheid-integration

## Architecture Overview

Mijn Overheid integration adds a message sending capability to case detail. Messages are composed in the UI, sent via the BerichtenboxService, and stored as case documents. A background job polls for read status.

```
CaseDetail.vue
├── BerichtenboxTab.vue (sidebar tab for message history)
│   └── BerichtenboxComposeDialog.vue (message composer)
└── ActivityTimeline.vue (message sent/read events)

Settings
└── BerichtenboxSettingsTab.vue (API credentials, bericht types)
```

## File Map

### New Files

| File | Purpose |
|------|---------|
| `lib/Service/BerichtenboxService.php` | Message composition, validation, sending via adapter, read status polling |
| `lib/Service/BerichtenboxAdapter/BerichtenboxAdapterInterface.php` | Adapter interface for Berichtenbox API |
| `lib/Service/BerichtenboxAdapter/MockAdapter.php` | Mock adapter for development/testing |
| `lib/Controller/BerichtenboxController.php` | API for sending messages, listing sent messages, polling read status |
| `lib/BackgroundJob/BerichtenboxReadStatusJob.php` | Daily job for polling read status |
| `src/views/cases/components/BerichtenboxTab.vue` | Sidebar tab showing sent messages and read status |
| `src/views/cases/components/BerichtenboxComposeDialog.vue` | Message composer with BSN validation, subject, body, attachment |
| `src/views/settings/tabs/BerichtenboxSettingsTab.vue` | Admin settings for API credentials and bericht type codes |
| `src/services/berichtenboxApi.js` | Frontend API service |

### Modified Files

| File | Changes |
|------|---------|
| `lib/Settings/procest_register.json` | Add `berichtenboxMessage`, `berichtenboxTypeCode` schemas |
| `lib/Service/SettingsService.php` | Add berichtenbox config keys |
| `appinfo/routes.php` | Add berichtenbox routes |

## Data Model

### berichtenboxMessage Schema
- `caseId` (string, UUID) -- Linked case
- `bsn` (string) -- Citizen BSN
- `subject` (string) -- Message subject
- `body` (string) -- Plain text message body
- `berichtTypeCode` (string) -- Bericht type code
- `attachmentFileId` (string, nullable) -- Nextcloud file ID of PDF attachment
- `externalMessageId` (string, nullable) -- Berichtenbox message reference ID
- `status` (enum: draft/sent/delivered/read/failed)
- `sentAt` (string, ISO 8601, nullable)
- `readAt` (string, ISO 8601, nullable)
- `readPolledAt` (string, ISO 8601, nullable)
- `errorMessage` (string, nullable) -- Error details if send failed

## API Endpoints

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/berichtenbox/messages` | List sent messages (filter by caseId) |
| POST | `/api/berichtenbox/send` | Send a message |
| GET | `/api/berichtenbox/types` | Get configured bericht type codes |
| POST | `/api/berichtenbox/poll/{messageId}` | Poll read status for a message |
