# getting-started-tour Delta: the-getting-started-tour-starts-on-the-dashboard

**Status**: draft
**Scope**: where dossiq's getting-started tour starts on a user's first visit.

## ADDED Requirements

### Requirement: The getting-started tour MUST start only from the dashboard

The getting-started tour SHALL auto-start only on the Dashboard route. A user whose first visit is a deep link SHALL see the page they opened, and SHALL get the tour when they first open the dashboard.

#### Scenario: First visit through a link to a Woo request

- **GIVEN** a staff user who never saw the tour
- **WHEN** they open a link to a Woo request
- **THEN** the case page shows without the welcome card
- **AND** when they open the dashboard the tour starts at "Welcome to Dossiq"
