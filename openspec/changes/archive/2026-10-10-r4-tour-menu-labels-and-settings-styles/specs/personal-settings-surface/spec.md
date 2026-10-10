# personal-settings-surface Delta: r4-tour-menu-labels-and-settings-styles

## ADDED Requirements

### Requirement: Settings bundles that use the library load its stylesheet

Every webpack entry that imports `@conduction/nextcloud-vue` SHALL import
`@conduction/nextcloud-vue/css/index.css`, so library components render with
their own styles outside the app page too.

#### Scenario: The notification table on Settings > Personal > Dossiq

- **GIVEN** a person on Settings > Personal > Dossiq
- **WHEN** the "Which notices reach me" table renders
- **THEN** the Notifications column header shows "Notifications" with "Bundle these" and its choice on their own lines below it
- **AND** the scope picker shows its whole label and value
