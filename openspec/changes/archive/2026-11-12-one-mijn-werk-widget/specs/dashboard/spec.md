# dashboard Delta: one-mijn-werk-widget

## MODIFIED Requirements

### Requirement: My open work is a start page widget that any dashboard can render (REQ-DASH-023)
dossiq MUST offer one dashboard widget "Mijn werk" (English source "My work") that summarises the signed-in person's open work from the one personal queue, as board DqNextcloudDashboard draws it: a row counting the open cases on their name, with how many they hold as substitute, and a row counting their open tasks, with how many are late and how many are due today. A kind with nothing open MUST be left out. Each row MUST link to the My work page. The widget MUST answer through Nextcloud's widget item API, so a host that renders items shows it without dossiq's JavaScript. It MUST offer a button "Naar Mijn werk" (English source "Go to My work") that leads to the My work page. dossiq MUST NOT offer separate My tasks or Overdue cases widgets beside it.

#### Scenario: The start page shows my cases and leads to my work
@e2e tests/e2e/my-open-work-widget.spec.ts

- **GIVEN** a handler with two open cases assigned to them and one open task
- **WHEN** they open a start page that holds the widget "Mijn werk"
- **THEN** the widget MUST read "2 open zaken op uw naam" and "1 open taak"
- **AND** "Naar Mijn werk" MUST open the My work page

#### Scenario: Items reach a host without dossiq's bundle
@e2e tests/e2e/my-open-work-widget.spec.ts

- **GIVEN** the same handler
- **WHEN** a client calls `/ocs/v2.php/apps/dashboard/api/v2/widget-items` for the widget
- **THEN** the response MUST carry the cases row and the tasks row, each linking to My work

#### Scenario: More work than fits
@e2e exclude The summary has at most two rows; tests/Unit/Dashboard/MyOpenWorkWidgetTest.php counts late, today and substitute work.

- **GIVEN** a handler with twelve open items
- **WHEN** the widget answers
- **THEN** it MUST still answer one row per kind, counting all twelve

## REMOVED Requirements

### Requirement: My tasks renders through the item API as well (REQ-DASH-024)

**Reason**: Board DqNextcloudDashboard draws one Mijn werk widget; My tasks and Overdue cases are folded into it (my-work spec, dossiq #5182).
**Migration**: A reader who had My tasks or Overdue cases on their dashboard adds Mijn werk; the open tasks are counted there, and Taakherinneringen still lists the tasks that are due.
