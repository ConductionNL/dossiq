## ADDED Requirements

### Requirement: The priority card explains the priority

The Overview's priority card SHALL show the case's priority as a pill in its
title row and, as label-left rows, the derived priority with the impact and
urgency it came from, whether it was overridden ("No" when it was not) and the
floor a rule raised it to. It SHALL end with the line on how the priority is set.

#### Scenario: a case nobody overrode
- GIVEN a case with priority `high`, derived `high` from impact `medium` and urgency `high`, and no override
- WHEN the Overview tab shows
- THEN the Priority card's title row carries the pill "High"
- AND its rows read "Derived: High, from impact medium and urgency high" and "Overridden: No"

### Requirement: The priority can be overridden from its card

The priority card SHALL carry an "Override priority" action on a case that is
not archived. It SHALL open a form asking the override and its reason and SHALL
save only those two fields on the case.

#### Scenario: a handler overrides the priority
- GIVEN an open case
- WHEN the handler chooses "Override priority", picks Urgent and gives a reason
- THEN the case is PATCHed with `priorityOverride` and `priorityOverrideReason` only
