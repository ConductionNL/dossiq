## ADDED Requirements

### Requirement: The share dialog offers every partner organisation

The case share dialog and the sharing tab SHALL offer every organisation of type `partner` on the instance, whether or not the user is a member of it, and SHALL NOT offer the instance's own organisations.

#### Scenario: a partner the user is not a member of
- GIVEN a partner organisation "Omgevingsdienst Zuid-Holland Zuid" and a user who is not its member
- WHEN the user opens "Delen met een andere organisatie"
- THEN the organisation select offers it
