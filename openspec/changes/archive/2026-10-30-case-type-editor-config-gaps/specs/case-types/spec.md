## ADDED Requirements

### Requirement: A case type's statuses and fields are edited from its page (REQ-CT-PARTS-1)

The CaseTypeDetail page MUST open a status or a field of the case type on a
page of its own from its list, where the record is edited, and MUST offer
deleting a row from the list.

#### Scenario: open a status
- GIVEN a case type with statuses
- WHEN the admin clicks a status in the Statuses list
- THEN the StatusTypeDetail page opens for that status

#### Scenario: delete a field
- GIVEN a case type with a field
- WHEN the admin chooses Delete on the field's row
- THEN the field is deleted through the object API, with an Undo
