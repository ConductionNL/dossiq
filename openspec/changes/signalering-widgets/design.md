# Design: signalering-widgets

## Overview

Signalering (alerting/reminders) provides case handlers with visual indicators and notifications when cases approach or pass their deadlines. This includes:
- Dashboard widgets showing upcoming deadlines
- In-app notifications via Nextcloud notification system
- Email notifications via n8n integration
- Werkvoorraad (work queue) deadline indicators with color coding (green/orange/red)
- Case detail timeline showing deadline events

## Architecture

### Data Layer (READ from workflow engine)
- Read `streeftermijn` (target deadline) and `fatale termijn` (hard deadline) from case type definition
- Respect `opschorting` (suspension) records that pause deadline calculations
- Track deadline status: on-track (green), warning (orange ≥7 days before), overdue (red)

### Notification Engine
- Nextcloud INotificationManager for in-app alerts
- n8n integration for email notifications (triggered via webhook)
- Configurable thresholds per zaaktype

### UI Layer
- UpcomingDeadlinesWidget.vue — Dashboard widget showing user's upcoming deadlines
- DeadlineIndicator.vue — Reusable component for case list and detail view
- SignaleringSettingsPage.vue — Admin settings for alert configuration per zaaktype
- DeadlinesOverviewPage.vue — Bulk management view across all zaaktypen

### Services
- SignaleringService — Deadline calculation, threshold checking, event triggering
- NotificationService — Nextcloud and email notification dispatch

## Changes

### New Controllers
**SignaleringConfigController.php**
- GET /api/signalering/config — List alert configurations per zaaktype
- POST /api/signalering/config — Create/update configuration
- DELETE /api/signalering/config/:zaaktypeId — Remove configuration

**DeadlineNotificationController.php**
- POST /api/deadlines/notify — Webhook endpoint for n8n callback (internal use)
- GET /api/cases/:caseId/deadlines — Get deadline status and events for a case

### New Services
**SignaleringService.php**
- `calculateDeadlineStatus(Case $case, CaseType $caseType): array` — returns {status, daysRemaining, events[]}
- `checkThresholds(Case $case, CaseType $caseType): bool` — true if threshold crossed
- `triggerNotifications(Case $case, CaseType $caseType): void` — dispatches in-app + email

**NotificationService.php** (extends existing)
- `notifyDeadlineWarning(Case $case, string $channel): void` — in-app or email

### New Vue Components
**UpcomingDeadlinesWidget.vue**
- Dashboard widget (IDashboardWidget)
- Shows user's cases with upcoming deadlines sorted by urgency
- Filters by zaaktype (optional)
- Color-coded rows (green/orange/red)

**DeadlineIndicator.vue**
- Reusable inline component for case rows
- Shows streeftermijn (light) and fatale termijn (bold) status
- Tooltip with exact dates and days remaining

**SignaleringSettingsPage.vue**
- Admin page: `/admin/signalering`
- Per-zaaktype configuration:
  - Warning threshold (days before deadline, e.g., 7)
  - Enable/disable in-app notifications
  - Enable/disable email notifications
  - Notification channels (or/and logic)

**DeadlinesOverviewPage.vue**
- Management view: `/deadlines/overview`
- Table: case ID, zaaktype, handler, streeftermijn, fatale termijn, status, days remaining
- Bulk filters: zaaktype, team, status (on-track/warning/overdue)
- Export to CSV

### Werkvoorraad Integration
- Add deadline indicator column to case table
- Icon/color badge showing status
- Click-through to case detail with deadline info

### Case Detail Integration
- Add "Termijnbewaking" section to case detail header
- Timeline showing:
  - Streeftermijn (target) date
  - Fatale termijn (hard) date
  - Opschorting (suspension) events with date range
  - Upcoming warnings based on configuration

### Settings Storage
- OpenRegister schema: `signaleringConfig`
  - Fields: zaaktypeId, warningDaysStreef, warningDaysFatale, notificationChannels, enabled
  - Stored in 'settings' register per OpenRegister conventions

## Data Flow

```
Case created/updated
  → SignaleringService.calculateDeadlineStatus()
     → Read caseType.processingDeadline + opschorting records
     → Calculate effective deadline
     → Compare vs. today
  → SignaleringService.checkThresholds()
     → Load signaleringConfig for this zaaktype
     → Check if warning threshold crossed
  → SignaleringService.triggerNotifications()
     → Nextcloud INotificationManager.notify() (in-app)
     → POST to n8n endpoint (email)
  → Dashboard widget queries SignaleringService for user's upcoming
  → UI renders color-coded indicators
```

## API Contract

### GET /api/signalering/config
List all alert configurations.
```json
[
  {
    "id": "uuid",
    "zaaktypeId": "case-type-123",
    "warningDaysStreef": 7,
    "warningDaysFatale": 0,
    "notificationChannels": ["in-app", "email"],
    "enabled": true
  }
]
```

### POST /api/signalering/config
Create or update a configuration.
```json
{
  "zaaktypeId": "case-type-123",
  "warningDaysStreef": 7,
  "warningDaysFatale": 0,
  "notificationChannels": ["in-app", "email"],
  "enabled": true
}
```

### GET /api/cases/:caseId/deadlines
Get deadline status for a case.
```json
{
  "caseId": "case-123",
  "zaaktypeId": "case-type-456",
  "streeftermijn": {
    "date": "2026-05-01T00:00:00Z",
    "daysRemaining": 13,
    "status": "warning"
  },
  "fatalTermijn": {
    "date": "2026-05-15T00:00:00Z",
    "daysRemaining": 27,
    "status": "on-track"
  },
  "opschorting": {
    "active": false,
    "startDate": null,
    "endDate": null
  },
  "notifications": [
    {
      "type": "warning",
      "triggeredAt": "2026-04-17T10:30:00Z",
      "threshold": "7-days-before-streeftermijn"
    }
  ]
}
```

## Testing Strategy

**Unit tests** (SignaleringService):
- Deadline calculation with/without opschorting
- Threshold detection (warning, overdue)
- Notification triggering

**Integration tests**:
- E2E: Case creation → Deadline notification
- N8n webhook callback handling
- Nextcloud notification dispatch

**Vue component tests**:
- UpcomingDeadlinesWidget loads and renders cases
- DeadlineIndicator shows correct color coding
- SignaleringSettingsPage save/load per zaaktype
