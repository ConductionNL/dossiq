# Tasks: notification-settings-on-the-shared-screen

> dossiq's notification settings consume the shared screen (ADR-032 `kind: code`).
> Checkbox budget: 2 tasks x 2 = 4 unindented `- [ ]` lines (cap 20).

## Implementation tasks

### Task 1: The platform's answer, shaped for the shared screen
- **spec_ref**: `openspec/changes/notification-settings-on-the-shared-screen/specs/case-management/spec.md#requirement-dossiq-s-notification-settings-are-the-shared-screen`
- **files**: `src/services/notificationPreferenceProps.js`, `tests/vitest/notificationPreferenceProps.spec.js`
- **acceptance_criteria**:
  - An overridden value lands on the person's own layer, a team default on the group layer, and a value nobody changed on neither
  - A forced row carries its value, who forced it and why, and a channel forced OFF stays off
  - A refusal carries its reason, separately from a channel simply being absent
  - A layer the platform did not answer for is not guessed: an effective value is never written into the shipped default
  - The channel axis is the platform's; one named column when it has none
- [x] Implement
- [x] Test

### Task 2: The screen itself
- **spec_ref**: `openspec/changes/notification-settings-on-the-shared-screen/specs/case-management/spec.md#requirement-dossiq-s-notification-settings-are-the-shared-screen`
- **files**: `src/views/settings/NotificationRoutingSettings.vue`, `tests/vitest/notificationRoutingSettingsSharedScreen.spec.js`
- **acceptance_criteria**:
  - `CnNotificationPreferences` renders instead of dossiq's own list of switches
  - A forced row and its reason reach the screen, so a handler sees which of their preferences an administrator has overridden and why
  - A change writes the notification the row stands for, and the screen is re-read afterwards so the deciding layer is the platform's answer
  - A refused write says so rather than leaving the switch where the click put it
  - The domain selector and the team default block are kept; the per-row
    "use the setting from my team" button is LOST and named in the proposal,
    because the shared screen has no clear control to hang it on
- [x] Implement
- [x] Test
