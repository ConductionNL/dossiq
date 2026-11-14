## ADDED Requirements

### Requirement: A package shows its configuration on its own admin tab (REQ-VAS-PKG-01)

An installed package that declares an admin tab SHALL get a tab in dossiq's Nextcloud admin settings with the title it declares, listing the configuration it ships: its case types, flows (published and draft), fee rules, decision templates, connections and checklists, each opening the generic editor for that item. The VTH package SHALL declare the tab board DqVthInstellingen draws.

#### Scenario: The VTH tab lists what the package installed

- **GIVEN** the VTH package is installed
- **WHEN** an administrator opens dossiq's admin settings
- **THEN** a tab "VTH" SHALL list the six case types, the flows with their draft or published state, the fee rules, the decision templates, the DSO connection state and the three checklists
- @e2e tests/e2e/package-admin-tab.spec.ts

#### Scenario: An uninstalled package has no tab

- **GIVEN** the subsidie package is not installed
- **WHEN** an administrator opens dossiq's admin settings
- **THEN** no subsidie tab SHALL be shown
- @e2e tests/e2e/package-admin-tab.spec.ts

### Requirement: A case that came in through DSO can be forwarded or shared from the case (REQ-VAS-DSO-01)

A case whose source is the DSO SHALL offer "Doorsturen naar ander bevoegd gezag" and "Samenwerking starten" on the case page, calling the DSO routes, and SHALL show the outcome on the case history.

#### Scenario: Forwarding a DSO permit

- **GIVEN** a permit case that came in through the DSO
- **WHEN** the handler forwards it to another authority with a reason
- **THEN** the forward SHALL be sent through the DSO route and the case history SHALL record it
- @e2e exclude the DSO is an external system; asserted with the DSO mock in Newman (`tests/integration/packages/dso-intake.postman_collection.json`)
