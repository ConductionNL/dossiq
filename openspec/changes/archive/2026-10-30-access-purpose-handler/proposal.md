# Proposal: access-purpose-handler

## Why

Ruben decided (26 October 2026) how the access-purpose dialog "Waarom open je deze zaak?" treats the case's own
handler (DQ-ZERO, "26 Oct: the access-purpose modal"). Asking the handler why they open their own case is noise: the
purpose is always handling. And an employee who opens a sensitive case to handle it should become its handler, so the
case is not handled by someone the case does not name.

## What changes

- `GET /api/cases/{id}/access-purpose` answers whether the reader must state a purpose. A reader who holds one of the
  configured roles on the case (by default the generic role `handler`: the case's assignee, or a role binding whose role
  type has `genericRole: handler`) does not: dossiq records the configured purpose (by default `handling`) itself, with
  the reader and the time, and answers `{ required: false, recorded: true }`. The case page asks this first
  (nextcloud-vue `accessPurpose.checkUrl`) and opens without the dialog.
- The dialog says it is shown because the reader is not the case's handler.
- A purpose can bind the reader to a role on the case. By default `handling` binds the generic role `handler`: after
  the purpose is recorded, dossiq adds a role binding for the reader with a role type that carries that generic role,
  and writes a second audit entry (`dossiq.case.access-role`) next to the purpose. The dialog says so under the option
  (nextcloud-vue `options[].hint`). A reader who already holds the role is not bound twice.
- Both mappings are app configuration (`access_purpose_roles`), not procedure code: which generic roles skip the
  dialog, the purpose recorded for them, and which purpose binds which generic role.
- The user documentation explains when the dialog shows, why, what each purpose does, and that "Behandeling van de
  zaak" makes you the handler.

## Out of scope

- Which role types an instance names: the binding is by ZGW generic role (`genericRole`), as the coordinator seat is.
