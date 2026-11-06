# admin-settings Delta: connection-row-menu

## ADDED Requirements

### Requirement: A connection row has a menu with what the row offers

Each row of the Integraties tab's connections table that has a settings card (`settingsUrl`) SHALL end with a row actions menu holding "Open settings", a same-tab link to that card. A row without `settingsUrl` SHALL have no menu.

#### Scenario: Kadaster
- GIVEN the Kadaster (BRK) en WOZ connection row with settingsUrl `/settings/admin/dossiq?tab=integrations#section-kadaster`
- WHEN the administrator opens the row's menu and chooses "Instellingen openen"
- THEN the Kadaster settings card opens on this page, in the same tab
