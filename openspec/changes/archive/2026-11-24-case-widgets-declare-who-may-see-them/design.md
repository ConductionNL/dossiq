# Design: case-widgets-declare-who-may-see-them

## The role is a Nextcloud group

launchpad scopes dashboards and widget presence by Nextcloud group (REQ-DASH-011, REQ-RFP-001), and dossiq's case
type rights matrix names its roles the same way. Using the group keeps one notion of "role" across both apps, so an
administrator configures it once.

## Declared in configuration, enforced on the server

The roles per widget are an app setting keyed by widget id (`widget_roles`, a JSON object of widget id to a list of
group ids). Each widget ships a default in the app's shipped configuration; an administrator overrides it. A widget
with no entry, or an empty list, is visible to everybody: an unconfigured instance renders exactly as before.

The check lives in the shared widget base (`DashboardPageWidget`), not per widget, and is named for what it does
(`WidgetAudience`): given a widget id and a user, is the user in one of the declared groups. Two places call it:

1. `isEnabled()` (the base implements `IConditionalWidget`), so Nextcloud's dashboard manager, and with it launchpad,
   never lists the widget for that user.
2. The widget's item read (`IAPIWidget::getItems()` / `IAPIWidgetV2::getItemsV2()` where implemented), so a direct
   API call by a user outside the roles answers an empty list. The guard runs before any case is read.

An administrator is not exempt: an administrator who is outside the roles of a widget does not see it either, so
what the settings page says is what everybody gets.

## Why not a launchpad-side list

launchpad's role-feature-permissions can already hide a widget per group, but it is launchpad's own configuration
and only applies inside launchpad. A dossiq widget placed on Nextcloud's own dashboard would bypass it. Declaring the
audience where the widget is defined covers every surface that shows it.
