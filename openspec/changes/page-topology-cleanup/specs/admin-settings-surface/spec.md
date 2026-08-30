# admin-settings-surface

## ADDED Requirements

### Requirement: Administration is reachable only through the Nextcloud settings framework

Procest's administration surface SHALL be served exclusively by
`OCA\Procest\Settings\AdminSettings` at `/settings/admin/procest`. The app SHALL
NOT declare an in-app manifest page that renders the same administration
component, because a component reached through the in-app router bypasses the
server-side access checks the settings framework applies (ADR-004).

#### Scenario: The in-app settings page is gone

- **GIVEN** the manifest
- **THEN** no page declares route `/settings` with the administration component in a slot
- **AND** `AdminRootView` is not registered in `src/registry.js` or `src/customComponents.js`

#### Scenario: The admin-router gate is green

- **GIVEN** the procest repository
- **WHEN** `hydra-gate-admin-router` runs
- **THEN** it reports no findings

### Requirement: Administration has exactly one navigation entry

The app navigation SHALL contain at most one entry pointing at the
administration surface, presented through the ADR-044 settings foldout rather
than as a top-level operational item.

#### Scenario: No duplicate configuration entries

- **GIVEN** the effective menu
- **THEN** exactly one entry links to the administration surface
- **AND** the former duplicate pair (`Case types` at order 95 and `Configuration` at order 99) is absent

### Requirement: Tenant onboarding is an administration section, not a page

The tenant-onboarding wizard SHALL be rendered as a section within the
administration surface. It SHALL NOT be declared as a top-level manifest page or
carry its own navigation entry.

#### Scenario: Onboarding is reached from administration

- **GIVEN** an administrator opens `/settings/admin/procest`
- **THEN** a tenant-onboarding section is available there
- **AND** no `/tenant-onboarding` page or menu entry exists in the manifest
