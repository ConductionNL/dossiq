# Proposal: cases are archival, never deleted

## Why

Ruben decided on 17 November 2026 that archival wins: a case is a record
under the Archiefwet, so it is never deleted. A handler who started a case by
mistake, or whose requester gave up, ends it the way the law already
foresees: the case is **withdrawn** (the `abort` ending act, "Break off" in the menu: an intrekking) or **closed** (the `finish` ending act), both of which the
lifecycle menu already offers with their guards, results and journal entry. A
case leaves the register only through an archival destruction list once its
retention has run out.

The recycle window (`case-recycle-window`, REQ-CRW-01 and REQ-CRW-02) was
built for the opposite answer: delete into OpenRegister's trash, recover
within a stated window, destroy as a second act. With no deletes there is no
trash to keep a window over, and the Deleted cases page would be a lens over
something that cannot fill.

## What changes

- `POST /api/case/{caseId}/delete` is refused, always, with **403** and the
  code `cases_are_archival`, for every role including administrators. It
  reaches nothing in OpenRegister. The route stays so an old client is told
  why, rather than meeting a 404. The 403 is the correct behaviour and is
  pinned by unit tests.
- The **Deleted cases** page (`CasesDeleted`, `/cases/deleted`) and its menu
  entry (`CasesDeletedMenu`) are removed from the manifest and both menu
  layouts. The UI offers withdraw and close in the case's lifecycle menu
  instead; there is no delete gesture on a case anywhere.
- The recovery window is dropped from the spec: REQ-CRW-01 is removed and
  REQ-CRW-02 is narrowed to what remains true for cases soft-deleted before
  this change.
- `deleted-cases-read-the-case-table` (the deleted lens reading the case
  table) and the tasks of `case-recycle-window` are superseded by this change.

## Not changed

- `CaseDeleteGuardListener` (REQ-CM-35) keeps refusing a held case on
  OpenRegister's pre-persist delete event. It guards every door into
  OpenRegister, not only dossiq's endpoint.
- The archival destruction list (vernietigingslijst) and its approval keep
  working; that is the one lawful way a case leaves.
- The restore and destroy endpoints of `CaseRecycleController` stay for cases
  soft-deleted before this change. They are reachable by API only and are
  removed in a later change once no instance holds such rows.
- The two retention clocks (REQ-CRW-03) are unchanged.
