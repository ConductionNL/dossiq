# personal-settings-surface Specification

## Purpose
The personal settings page and the other settings bundles render outside the app page. This capability makes sure the library components on those pages look the same as inside the app, so a user reads the same layout everywhere.

## Requirements

### Requirement: Settings bundles that use the library load its stylesheet

Every webpack entry that imports `@conduction/nextcloud-vue` SHALL import
`@conduction/nextcloud-vue/css/index.css`, so library components render with
their own styles outside the app page too.

#### Scenario: The notification table on Settings > Personal > Dossiq

- **GIVEN** a person on Settings > Personal > Dossiq
- **WHEN** the "Which notices reach me" table renders
- **THEN** the Notifications column header shows "Notifications" with "Bundle these" and its choice on their own lines below it
- **AND** the scope picker shows its whole label and value

### Requirement: The personal settings open with the views in my menu

The user settings dialog SHALL draw the library's Views in my menu section (`views`) first, before dossiq's own
sections, where the case type picker was.

#### Scenario: DqPersoonlijkeInstellingen

- **GIVEN** a user opens Persoonlijke instellingen
- **THEN** the first section is "Weergaven in mijn menu", listing their pinned views with "Uit menu halen" and a
  "Weergave toevoegen" picker
