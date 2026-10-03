## ADDED Requirements

### Requirement: My open work is a start page widget that any dashboard can render (REQ-DASH-023)
dossiq MUST offer a dashboard widget "My open work" that lists the signed-in
person's open cases and work items from the one personal queue, soonest due
first, each linking to its case or task. The widget MUST answer through
Nextcloud's widget item API, so a host that renders items shows it without
dossiq's JavaScript. It MUST offer a button "Open my work" that leads to the My
work page.

#### Scenario: The start page shows my cases and leads to my work
- **GIVEN** a handler with two open cases assigned to them and one open task
- **WHEN** they open a start page that holds the widget "My open work"
- **THEN** the widget MUST list the two cases and the task, soonest due first
- **AND** "Open my work" MUST open the My work page

#### Scenario: Items reach a host without dossiq's bundle
- **GIVEN** the same handler
- **WHEN** a client calls `/ocs/v2.php/apps/dashboard/api/v2/widget-items` for
  the widget
- **THEN** the response MUST carry the three items with their titles and links

#### Scenario: More work than fits
- **GIVEN** a handler with twelve open items and a host that asks for seven
- **WHEN** the widget answers
- **THEN** it MUST list six items and a seventh reading "6 more in My work"
  that links to the My work page

### Requirement: My tasks renders through the item API as well (REQ-DASH-024)
The widget "My tasks" MUST keep its id and its dashboard rendering and MUST also
answer its open tasks through Nextcloud's widget item API.

#### Scenario: A start page that reads items shows my tasks
- **GIVEN** a handler with two open tasks
- **WHEN** a client asks the widget item API for "My tasks"
- **THEN** it MUST answer both tasks with a link to each
