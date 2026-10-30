# case-search-and-lists

## ADDED Requirements

### Requirement: Assigned to me explains its order under the list

The Assigned to me view MUST say on its footer that it is sorted by urgency, and MUST explain under the list, as an info
notice, what the urgency follows from and that covered work carries the colleague's name.

#### Scenario: A handler opens Assigned to me

- GIVEN a handler with open cases in their name
- WHEN they open Assigned to me
- THEN the footer reads "{shown} of {total} · sorted by urgency"
- AND an info notice under the list explains the urgency and covered work

### Requirement: The team queue's list columns are the board's

The team queue's list view MUST draw the wait 60px, the case type 172px and the deadline 96px wide, leaving the rest to
the title column.

#### Scenario: A long meta line

- GIVEN a queue row whose meta line is wider than the title column
- WHEN the list renders in the board look
- THEN the title column is the row less the three fixed columns, and the meta line wraps in it
