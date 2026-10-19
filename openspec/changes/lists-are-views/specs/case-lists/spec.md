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
