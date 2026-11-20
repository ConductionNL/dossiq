# Proposal: case-widgets-declare-who-may-see-them

## Why

launchpad hands this over from its `competitor-parity-2026-09` (task 2.1) and `dashboards-and-who-may-see-them`
(task 9.1) changes. Discovery cluster 12 has one `must`, candidate C-reporting-22: a dashboard scoped to the roles that
may see it. launchpad already covers its half (group-shared dashboards resolved per user, `dashboards` REQ-DASH-011
and REQ-DASH-013, and widget presence per role, `role-feature-permissions` REQ-RFP-001). The sweep rated the gap
against dossiq: dossiq ships one manifest dashboard layout for everybody and its Nextcloud dashboard widgets have no
role scoping, so every user who can open launchpad can place every case widget. The ledger row the candidate notes
name is 10.1.

The cluster's mechanism line reads "extend launchpad's widget model and openregister's aggregations; dossiq
contributes widgets". This change is the "dossiq contributes widgets" part.

## What changes

- Every case widget dossiq contributes to a dashboard (its Nextcloud dashboard widgets under `lib/Dashboard/`, which
  launchpad places as `nc-widget` placements) declares the roles that may see it.
- A role is a Nextcloud group, the same unit launchpad's role-feature-permissions and dossiq's case type rights
  matrix use. The declaration is configuration: an admin setting per widget id, with a shipped default per widget.
  An empty list means everybody, so an instance that configures nothing behaves as today.
- The declaration is enforced on the server, twice: the widget is not enabled for a user outside the roles
  (Nextcloud's `IConditionalWidget::isEnabled()`, so `IManager::getWidgets()` and therefore launchpad's widget
  picker and renderer never see it), and the widget's item reads answer nothing for that user.
- The widgets keep reading cases through OpenRegister as the user, so the role declaration only narrows what a
  person sees; it never widens what the case rights allow.
- The admin settings page lists each contributed widget with its roles.

## Out of scope

- launchpad's side: it already hides a widget that `getWidgets()` does not return, and filters by its own
  role-feature-permissions on top. No launchpad change is needed.
- Which aggregations a shipped report may rely on: handed to openregister as its own change.

## Reach

PHP (widget base, settings service, admin settings), admin settings UI as manifest configuration, l10n. No schema
change in OpenRegister.
