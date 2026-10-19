## ADDED Requirements

### Requirement: Lists that were pages open as views of Cases

The old addresses of Assigned to me, the team queue and the data subject
requests SHALL redirect to the Cases page with that view applied, and the
views SHALL filter as the pages did.

#### Scenario: A bookmark of the team queue
- GIVEN a bookmark of /queue
- WHEN it is opened
- THEN the Cases page opens with the `team-queue` view, every open case nobody picked up

#### Scenario: Complaints on an instance with the complaint case type
- GIVEN an instance with a case type identified `klacht-behandeling`
- WHEN the app loads its manifest
- THEN the Cases page has a Complaints view filtering on that case type

### Requirement: A pin of a retired list page points at its view

A user's menu pin of the team queue or Assigned to me SHALL become that view
in the user's menu views, once per user, and the retired id SHALL leave their
pins. A user who never set pins SHALL be treated as holding the former default.

#### Scenario: A user pinned Assigned to me
- GIVEN a user whose pins are `MyWork` and `Tasks`
- WHEN the app upgrades
- THEN their menu views end with `default:my-work` on the Cases page, and their pins are `Tasks`

#### Scenario: A view taken out stays out
- GIVEN the upgrade already mapped a user's pins, and the user then removed the view from their menu
- WHEN the app upgrades again
- THEN the view is not added back
