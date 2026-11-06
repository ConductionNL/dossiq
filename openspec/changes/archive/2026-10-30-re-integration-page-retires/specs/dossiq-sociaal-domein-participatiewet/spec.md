# dossiq-sociaal-domein-participatiewet (delta)

## ADDED Requirements

### Requirement: Old re-integration addresses lead to the cases (REQ-PW-RI-005)

The re-integration menu entry SHALL open the Cases view `re-integration-pathways`.
`/settings/re-integratietrajecten` SHALL redirect to that view, and
`/settings/re-integratietrajecten/{id}` SHALL open the case whose `externalReference` is `{id}`,
or the view when no case carries it or the lookup fails.

#### Scenario: An old trajectory link opens its case
- **GIVEN** a trajectory `traj-1` moved onto case `case-9`
- **WHEN** a user opens `/settings/re-integratietrajecten/traj-1`
- **THEN** the case page of `case-9` SHALL open

#### Scenario: The old list address opens the view
- **WHEN** a user opens `/settings/re-integratietrajecten`
- **THEN** the Cases list SHALL open on the view `re-integration-pathways`

### Requirement: Re-integration is a default view like Woo, with no menu entry (REQ-PW-RI-006)

Ruben (23 Oct 2026): "Re-integratie trajecten should be a view like Woo." The re-integration menu
entry SHALL go; `re-integration-pathways` SHALL be a secondary default view of Cases with an icon and
a description, the mechanism the Woo requests view uses. Who sees re-integration cases follows case
access; the view carries no gate of its own. The old addresses keep redirecting (REQ-PW-RI-005).

#### Scenario: The view sits beside Woo's
- **WHEN** a user opens the more default views on Cases
- **THEN** "Re-integratietrajecten" SHALL be listed there with its icon and description, as "Woo-verzoeken" is
