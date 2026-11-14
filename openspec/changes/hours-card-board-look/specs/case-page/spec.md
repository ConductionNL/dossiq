## ADDED Requirements

### Requirement: The case's hours card reads like the board

The case page SHALL place humaniq's hours leaf with the case named in its week
line, a compact header that keeps the timer reachable, and the note that the
hours come through humaniq.

#### Scenario: a case with hours this week
- GIVEN a case with 11.5 hours booked this week
- WHEN the Overview shows "Uren registreren"
- THEN the week line ends "11,5 uur op deze zaak" and "Timer starten" stands under the form
