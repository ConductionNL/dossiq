# case-management Delta: cases-are-archival-no-delete

**Status**: draft

## ADDED Requirements

### Requirement: A case is never deleted; it is withdrawn or closed (REQ-CM-90)

A case SHALL NOT be deleted by any role. `POST /api/case/{caseId}/delete`
SHALL answer **403** with the code `cases_are_archival` and SHALL NOT ask
OpenRegister to delete, soft-delete or move the case. A case SHALL end through
the lifecycle's ending acts, withdraw (`abort`) or close (`finish`), and SHALL
leave the register only through an archival destruction list once its
retention has run out. The case pages SHALL offer withdraw and close and SHALL
NOT offer a delete.

#### Scenario: A handler tries to delete a case

- **GIVEN** a handler who may change the case
- **WHEN** they send a delete for the case
- **THEN** the answer SHALL be 403 with code `cases_are_archival`
- **AND** the case SHALL be unchanged

#### Scenario: An administrator tries to delete a case

- **GIVEN** an administrator
- **WHEN** they send a delete for any case
- **THEN** the answer SHALL be 403 with code `cases_are_archival`

#### Scenario: A case started by mistake is withdrawn

- **GIVEN** an open case the requester no longer wants
- **WHEN** the handler opens the case's lifecycle menu
- **THEN** the menu SHALL offer to withdraw and to close the case
- **AND** SHALL NOT offer to delete it

#### Scenario: There is no deleted cases page

- **GIVEN** the app's manifest and menu layouts
- **WHEN** they are read
- **THEN** no page SHALL read `/api/cases/deleted` and no menu entry SHALL lead to one

## MODIFIED Requirements

### Requirement: Restoring and destroying are separate, recorded acts (REQ-CRW-02)

A case soft-deleted before cases became archival MAY still be restored by
API, and restoring SHALL record who restored it and when. Destroying such a
case SHALL be a second act, SHALL record who decided, SHALL be permitted only
to the role the case type declares, and SHALL also destroy the process data
behind the case. No new case enters this state.

#### Scenario: a case deleted before the decision is got back

- **GIVEN** a case soft-deleted before cases became archival
- **WHEN** a handler restores it through the API
- **THEN** the case SHALL be live again
- **AND** the restore SHALL record who and when

#### Scenario: only the declared role destroys

- **GIVEN** a case type declaring a destroying role
- **WHEN** a handler without that role tries to destroy a soft-deleted case
- **THEN** the act SHALL be refused

## REMOVED Requirements

### Requirement: A deleted case is recoverable for a stated period (REQ-CRW-01)

**Reason**: cases are archival and are never deleted (Ruben, 17 November
2026), so there is no recycle state for a case to enter and no window to
keep over it.

**Migration**: end the case with withdraw (`abort`) or close (`finish`).
A case leaves the register only through an archival destruction list.

### Requirement: The deleted lens MUST list every deleted case the caller may see

**Reason**: superseded with `deleted-cases-read-the-case-table`; the Deleted
cases page is removed.

**Migration**: none; no new case can be deleted.
