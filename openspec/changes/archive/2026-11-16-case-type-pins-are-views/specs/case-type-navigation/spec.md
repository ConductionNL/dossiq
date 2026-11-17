## ADDED Requirements

### Requirement: A pinned case type is a view of the case list

The manifest delta SHALL add, for each case type a user pinned, a default view
of the Cases page filtering on that case type, and SHALL add no menu entry of
its own. The Woo request case type SHALL use the view the Cases page ships.

#### Scenario: A user pinned two case types
- GIVEN a user who pinned Klacht and Bezwaar
- WHEN the app loads its manifest
- THEN the Cases page has the default views case-type-<Klacht> and case-type-<Bezwaar>
- AND the menu has no My case types heading

### Requirement: Pins are carried over to the menu views once

On upgrade, each user's pinned case types SHALL be added to their menu views,
in their order, after the views they added themselves, once per user, and the
stored pins SHALL be kept.

#### Scenario: A view taken out stays out
- GIVEN a user whose pins were carried over
- AND who took one of those views out of the menu
- WHEN the upgrade runs again
- THEN that view is not added again
