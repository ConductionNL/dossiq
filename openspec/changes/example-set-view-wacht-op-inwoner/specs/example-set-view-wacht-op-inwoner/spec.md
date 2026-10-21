# example-set-view-wacht-op-inwoner

## ADDED Requirements

### Requirement: Pieter owns the view the filter boards open

The Zuiddrecht example set SHALL give Pieter Jansen a private view "Wacht op inwoner" on the case list, over the running cases of the group `zuiddrecht-woo-en-informatie` that wait on the applicant, sorted by deadline.

#### Scenario: Pieter opens his view
- **GIVEN** the set is installed
- **WHEN** Pieter opens "Wacht op inwoner" from his menu
- **THEN** the list SHALL show the Woo team's running cases that wait on the resident
