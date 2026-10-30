## ADDED Requirements

### Requirement: The landing page holds a My work view and a My team view

The landing page (route `/`) MUST declare two views, My work (`mine`) and My
team (`team`), and MUST open on My work when neither the address nor the
reader's stored choice names a view. My work MUST hold the reader's own
widgets. My team MUST hold widgets the dashboard already declares: the shared
queue, the team counts and the team charts. Neither view MAY render empty
where the page showed widgets before. The same My team view MUST be shown in
the simple and the full structure.

#### Scenario: A handler opens the app
@e2e exclude Needs a live instance with both structures; the views, their widgets and their layout are asserted in landingViews.spec.js, and the coordinator checks the page live.
- **GIVEN** a handler who never chose a view
- **WHEN** they open `/apps/dossiq/`
- **THEN** the landing page MUST show the My work view
- **AND** the switch MUST offer My work and My team

#### Scenario: A team lead switches to My team
@e2e exclude Same reason; the library's view switch is tested in nextcloud-vue.
- **GIVEN** the landing page on My work
- **WHEN** the team lead picks My team
- **THEN** the page MUST show the shared queue, the team counts and the team charts below the switch
- **AND** the address MUST carry `?view=team`
- **AND** the page MUST NOT navigate to another page

#### Scenario: Both views place every widget they declare
@e2e exclude A comparison of the built pages with the manifest, asserted in landingViews.spec.js.
- **GIVEN** the simple or the full structure
- **WHEN** the landing page is built
- **THEN** each view MUST place at least one widget
- **AND** every widget a view declares MUST be placed in that view, without overlap

### Requirement: The simple greeting switches views, not pages

In the simple structure the greeting MUST sit on the landing page's own grid
and MUST draw the My work | My team switch. Each option MUST name a view of the
landing page and MUST NOT name a route. The landing page MUST keep its header,
because the header holds the links to Your queue, Assigned to me and Close out
your day.

#### Scenario: The greeting draws the switch
@e2e exclude Asserted on the built simple manifest in simpleListAndDashboard.spec.js.
- **GIVEN** the simple structure
- **WHEN** the landing page is built
- **THEN** the greeting options MUST be `{ label: My work, view: mine }` and `{ label: My team, view: team }`
- **AND** the page header MUST still carry the three links

## MODIFIED Requirements

### Requirement: My Work carries three widgets, the Dashboard carries none of them

The three widgets below SHALL render in the My work view of My Work and SHALL
NOT render on the Dashboard. The Dashboard's KPI cards, status/type charts, and
Stalled Cases panel stay on `/dashboard`. The My team view of My Work MAY show
copies of the team-wide ones, and each copy MUST equal the Dashboard's
definition.

#### Scenario: My Work shows open tasks, deadlines and open cases
@e2e exclude A manifest property, asserted in dashboardWorkTables.spec.js and landingViews.spec.js.
- **WHEN** a handler views the My work view of My Work in the full structure
- **THEN** the view shows a "My work" widget of the current user's open tasks
- **AND** a "Deadlines" widget of cases due today, overdue, or within 3 days
- **AND** an "Open Cases" widget of the most recently started open cases

#### Scenario: The Dashboard no longer carries the personal-workload widgets
@e2e exclude A manifest property, asserted in dashboardWorkTables.spec.js and landingViews.spec.js.
- **WHEN** a handler views `/dashboard`
- **THEN** the page does NOT show the My work, Deadlines or Open Cases widgets
