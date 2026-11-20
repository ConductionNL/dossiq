# Tasks: case-widgets-declare-who-may-see-them

Handed over from launchpad `competitor-parity-2026-09` 2.1 and `dashboards-and-who-may-see-them` 9.1 (candidate
C-reporting-22, ledger row 10.1).

## 1. The audience

- [ ] 1.1 `WidgetAudience` service: the declared groups of a widget id from the app setting `widget_roles`, falling back to the shipped default, and whether a user is in one of them (REQ-DWV-001). PHPUnit: configured, unset, empty list, unknown widget.
- [ ] 1.2 Shipped defaults per widget in the app's shipped configuration (empty for every widget unless a board or spec says otherwise).

## 2. Enforcement

- [ ] 2.1 `DashboardPageWidget` implements `IConditionalWidget::isEnabled()` through `WidgetAudience` for the current user (REQ-DWV-002). PHPUnit: inside, outside, administrator outside.
- [ ] 2.2 Every item read of a contributed widget answers an empty list for a user outside the audience, before reading any case (REQ-DWV-002). PHPUnit per widget with items.
- [ ] 2.3 PHPUnit that the items of a user inside the audience are still read as that user, so case rights apply (REQ-DWV-003).

## 3. Administration

- [ ] 3.1 Admin settings section listing each contributed widget with its groups, as manifest configuration over an admin-only endpoint; the write is refused for a non-administrator on the server (REQ-DWV-004). PHPUnit on the guard, Vitest on the section.
- [ ] 3.2 `nl` and `en` strings.

## 4. Proof

- [ ] 4.1 Playwright: a widget declared for one group is absent from launchpad's add widget dialog and from a shared dashboard for a user outside it, and present for a member.
