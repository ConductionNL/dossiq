## ADDED Requirements

### Requirement: A reader arranges each landing page view for themselves

The landing page MUST declare `userLayout` together with its `appId`, its
`pageId` and its views, so a reader can arrange the widgets inside My work and
inside My team. Each view MUST keep its own arrangement for that reader. The
manifest MUST still decide which widgets a view holds.

#### Scenario: A reader arranges My team
@e2e exclude The per-view storage is tested in nextcloud-vue (CnPageViewsUserLayout.spec.js); dossiq asserts the declaration in dashboardUserLayout.spec.js, and the coordinator checks it live.
- **GIVEN** a reader on the My team view
- **WHEN** they move a widget in edit mode and reload the page
- **THEN** My team MUST show the widget where they put it
- **AND** My work MUST keep its own arrangement
