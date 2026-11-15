## ADDED Requirements

### Requirement: A case type is deleted only while it is an unused draft (REQ-CT-DEL-1)

Deleting a case type through any path MUST be refused when it is published or
when a case uses it, and an allowed delete MUST remove the case type's status
types.

#### Scenario: delete a published case type
- GIVEN a published case type
- WHEN it is deleted through the object API
- THEN the delete is refused with "A published case type cannot be deleted"

#### Scenario: delete an unused draft
- GIVEN a draft case type no case uses, with two status types
- WHEN it is deleted
- THEN the delete goes ahead and both status types are removed

### Requirement: An email template keeps its earlier wording on every write path (REQ-CT-TPL-1)

A change to an email template's name, subject or body MUST keep the earlier
wording as an inactive copy and raise the template's version, whichever API
made the change.

#### Scenario: edit a message from the case type page
- GIVEN an active template at version 2
- WHEN its body is changed through the object API
- THEN an inactive copy at version 2 holds the earlier body
- AND the template is at version 3 with previousVersion pointing at the copy
