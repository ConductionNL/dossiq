# B&W Besluitvorming Workflow

**Issue:** #87
**Branch:** `feature/87/besluitvorming-workflow`
**PR:** #95

## Overview

Implements the B&W (Board & Aldermen: College van Burgemeester en Wethouders) decision-making workflow for Dutch municipal case management. Provides a structured parafering (initialling/sign-off) chain for proposals (`voorstellen`) that require formal decision-making.

## Architecture

### Backend

- **New schemas** in `lib/Settings/dossiq_register.json`:
  - `voorstel`: the proposal document awaiting B&W decision
  - `parafeerroute`: a named sign-off chain with ordered steps
  - `parafeeractie`: an individual action taken by a paraferent

- **New service:** `lib/Service/ParaferingNotificationService.php`
  - Sends Nextcloud notifications for workflow events:
    - `notifyStepActivated`: notify the actor when their step becomes active
    - `notifyVoorstelReturned`: notify the steller when a proposal is returned
    - `notifyParaferingReminder`: send overdue reminders

- **Updated:** `lib/Service/SettingsService.php`
  - New config keys: `voorstel_schema`, `parafeerroute_schema`, `parafeeractie_schema`

### Frontend

- **Pinia store:** `src/store/modules/` (parafeerEngine)
- **Views:** VoorstelList, VoorstelDetail, VoorstelCreateDialog
- **Components:** ParafeerActionBar, ParafeerInbox, ProgressTimeline, AuditTrail, BesluitRegistration
- **Router:** `/voorstellen`, `/voorstellen/:id`

## Notification Events

| Method | Subject key | Recipient | Purpose |
|--------|-------------|-----------|---------|
| `notifyStepActivated` | `parafering_step_activated` | Actor | Inform paraferent their step is active |
| `notifyVoorstelReturned` | `voorstel_returned` | Steller | Inform steller proposal was returned |
| `notifyParaferingReminder` | `parafering_reminder` | Actor | Remind actor of overdue step |

## Testing

Unit tests are in `tests/Unit/Service/ParaferingNotificationServiceTest.php`. They verify:
- Notifications are sent to the correct user
- Subject keys and parameters are set correctly
- App ID is set to the Dossiq app constant
- Exceptions from the notification manager are caught and logged (never thrown to callers)
