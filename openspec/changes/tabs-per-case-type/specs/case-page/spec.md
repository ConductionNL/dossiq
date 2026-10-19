## ADDED Requirements

### Requirement: A case type picks the tabs of its cases

A case type MAY list `tabs`, ids of the case page's tabs. A case of a type
that lists tabs SHALL show those tabs, in that order; a case of a type that
lists none SHALL show the declared tabs.

#### Scenario: An objection
- GIVEN an objection case, whose case type lists Overview, Hearing, Advice, Decision, Files and History
- WHEN the case page opens
- THEN the tab strip shows those tabs in that order, Overview first

#### Scenario: A case type without the setting
- GIVEN a case of a case type with no `tabs`
- WHEN the case page opens
- THEN the tab strip shows the declared tabs
