# Start Case Widget

## Summary

Dashboard widget for starting new cases directly from the Nextcloud dashboard or MyDash, without navigating into the Procest app first.

## Overview

Case workers frequently need to start new cases throughout their day. The Start Case widget eliminates friction by providing a one-click case creation flow directly from the Nextcloud home screen. It shows available case types (zaaktypen) as clickable cards. When a user clicks a case type, a new case is created and they are navigated to the case detail page.

In government case management (zaakgericht werken), fast intake is critical. Citizens call or walk in, and the case worker needs to register a new case immediately. This widget reduces the number of clicks from 3-4 to 1-2.

## Key Capabilities

- **Case type quick-start cards**: Shows configured case types as clickable cards with titles
- **Inline case creation**: Creates a case via OpenRegister API and navigates to the new case detail page
- **Empty state**: When no case types are configured, shows a helpful message directing admins to Procest settings
- **Loading state**: Shows loading indicator while fetching case types
- **i18n support**: All widget text available in Dutch and English
- **MyDash compatible**: Widget appears automatically when MyDash discovers registered Nextcloud widgets

## Technical Details

| Component | File |
|-----------|------|
| PHP Widget Class | `lib/Dashboard/StartCaseWidget.php` |
| Vue Component | `src/views/widgets/StartCaseWidget.vue` |
| Webpack Entry | `src/startCaseWidget.js` |
| Registration | `lib/AppInfo/Application.php` |

## Related

- Original issue: [#105](https://github.com/ConductionNL/procest/issues/105)
- Tracking issue: [#107](https://github.com/ConductionNL/procest/issues/107)
- OpenSpec change: `openspec/changes/archive/2026-03-24-start-case-widget/`
