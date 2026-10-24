## MODIFIED Requirements

### Requirement: Suspending asks when the case comes back

The Opschorten form SHALL show "De zaak komt terug op" as an optional date, prefilled with today plus the days given.
Changing the date SHALL change the days the suspend act posts.

#### Scenario: a later return day
- **GIVEN** the Opschorten form with 14 days, on 7 Oct
- **WHEN** the handler sets the date to 27 Oct
- **THEN** the act posts 20 days
