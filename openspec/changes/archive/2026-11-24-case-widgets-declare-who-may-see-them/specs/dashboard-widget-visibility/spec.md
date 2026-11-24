# dashboard-widget-visibility Delta

## ADDED Requirements

### Requirement: REQ-DWV-001 A contributed case widget declares the roles that may see it

Every dashboard widget dossiq contributes SHALL carry a declared audience: a list of Nextcloud group ids, read from
the app setting `widget_roles` by widget id, falling back to the widget's shipped default. An empty or absent list
SHALL mean every user.

#### Scenario: A widget for one team

- **GIVEN** `widget_roles` maps the stalled cases widget to `["vergunningen"]`
- **WHEN** the audience of that widget is read
- **THEN** it is `["vergunningen"]`

#### Scenario: Nothing configured

- **GIVEN** an instance where `widget_roles` is unset and the widget's shipped default is empty
- **WHEN** any user opens a dashboard
- **THEN** the widget is available to them, as before this change

### Requirement: REQ-DWV-002 A user outside the audience never sees the widget

A widget SHALL NOT be enabled for a user who is in none of its declared groups (`IConditionalWidget::isEnabled()`
answers false), so the Nextcloud dashboard manager does not list it and no dashboard, launchpad's included, can place
or render it for that user. Its item reads SHALL answer an empty list for that user, checked before any case is read.
An administrator SHALL be held to the same audience.

#### Scenario: launchpad's widget picker

- **GIVEN** the stalled cases widget is declared for `["vergunningen"]`
- **AND** user "an" is not in that group
- **WHEN** "an" opens the add widget dialog in launchpad
- **THEN** the stalled cases widget is not offered

#### Scenario: A placement made before the declaration

- **GIVEN** a shared launchpad dashboard that already holds the stalled cases widget
- **AND** the widget is then declared for `["vergunningen"]`
- **WHEN** user "an", outside that group, opens the dashboard
- **THEN** the placement is not rendered for "an"
- **AND** a member of `vergunningen` still sees it

#### Scenario: A direct item read

- **GIVEN** user "an" is outside the widget's audience
- **WHEN** "an" calls the widget's items endpoint of the Nextcloud dashboard API
- **THEN** the answer is an empty list and no case was read

### Requirement: REQ-DWV-003 The audience narrows and never widens

A widget's items SHALL still be read through OpenRegister as the user, so a user inside the audience sees only the
cases the case rights let them read. The audience SHALL NOT grant access to any case.

#### Scenario: In the audience, without case rights

- **GIVEN** user "bo" is in the widget's audience
- **AND** "bo" may read no case of type "Omgevingsvergunning"
- **WHEN** "bo" sees the widget
- **THEN** no case of that type is in it

### Requirement: REQ-DWV-004 An administrator sets the audience per widget

The admin settings SHALL list every contributed dashboard widget with its title and its declared groups, and let an
administrator change the groups. Saving SHALL be refused for a non-administrator on the server.

#### Scenario: Narrowing a widget

- **GIVEN** an administrator on the dossiq admin settings
- **WHEN** they set the deadline alerts widget to `["vergunningen", "toezicht"]` and save
- **THEN** members of either group see the widget and other users do not
