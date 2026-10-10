## ADDED Requirements

### Requirement: The admin settings carry the queue urgency section

The dossiq admin settings page MUST carry a section "Queue urgency" ("Urgentie
in de werkvoorraad") with four fields: "Critical from, working days left"
(default 3), "Almost due from, working days left" (default 7), "Weight of the
priority" (default 10) and "Weight of the time lying still" (default 0.5). The
section MUST say in one line that a case type can override the two
thresholds. It MUST save through the app's own admin-guarded settings write
(`POST /api/settings`) to the IAppConfig keys `queue_critical_days`,
`queue_warning_days`, `queue_priority_weight` and `queue_idle_weight`, and MUST
read its values from initial state. A value out of bounds MUST be refused in
the form with a message naming the bounds, before anything is sent. The
section is part of the Nextcloud admin settings page, not a route of the app.

#### Scenario: The section shows the defaults
- GIVEN no queue setting was ever saved
- WHEN an administrator opens the dossiq admin settings
- THEN the Queue urgency section MUST show 3, 7, 10 and 0.5

#### Scenario: A saved threshold comes back after a reload
- GIVEN an administrator sets "Critical from, working days left" to 5 and saves
- WHEN the page is reloaded
- THEN the field MUST show 5

#### Scenario: An out-of-bounds weight is refused in the form
@e2e exclude Asserted by tests/vitest/queueUrgencySettings.spec.js over the form validator; the browser path adds nothing the validator does not decide.

- GIVEN an administrator enters 80 as the priority weight
- WHEN they save
- THEN the form MUST say the weight runs from 0 to 50
- AND nothing MUST be sent
