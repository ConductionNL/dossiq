---
kind: code
---

# Proposal: case-access-by-handling-team

## Why

Found live on the review instance on 2026-10-11. A user who is in no group at
all could read every case, every case type and every case's files, and the
object folder call answered `canChange: true` for them.

The cause is that dossiq shipped no read rule. The `case` schema carried an
`authorization` block with `roles` only (`register.d/66-notification-routing.json`),
written so the notification dispatcher can find who holds a role. The dossiq
register declares no role definitions, so OpenRegister expanded that block to
nothing and evaluated it as "no rule", which is open to every signed in user.
84 of the other 85 dossiq schemas had no block at all, which OpenRegister also
reads as open.

## Decision (Ruben, 2026-10-11)

Ruben chose the handling team model on 2026-10-11:

1. A case is read and changed by the handling teams of its case type
   (`handling.defaultGroup` and `handling.teams`, Nextcloud groups) and by its
   assignee. Delete stays with administrators. OpenRegister's administrator
   and owner bypasses stay.
2. The rest of the register is closed to anybody outside the provisioned group
   `dossiq-medewerkers`. Schemas that already restrict a write keep that
   restriction.
3. A one-off repair step puts every member of the current handling teams and
   every current assignee into `dossiq-medewerkers`, so nobody loses access on
   upgrade.
4. The rights matrix (`caseType.rightsMatrix`, REQ-CGP-03) stays declared for a
   later refinement and is not wired by this change.

This reverses the sentence in REQ-ACC-10 that said being the team grants
nothing: the handling team of a case type now grants read and update on the
cases of that type. `assignedGroup` on the case still grants nothing.

## Amendment (Ruben, 2026-10-16): claim before acting

The handling team reads; the handler edits after claiming. This keeps what
#3966 shipped (a team member opens an unclaimed case, every act is refused
until they claim it) and amends REQ-ACC-10 to say so, so the spec no longer
reads as if a team member may act on any case of the type.

## What changes

- `register.d/91-register-access.json`: a register level `authorization` block
  on the dossiq register. Read, create, update and delete for
  `dossiq-medewerkers` and the background service group. OpenRegister applies
  it to every schema without a block of its own.
- `register.d/66-notification-routing.json` keeps its `roles`, and the case
  schema gains a block of its own: read and update for the assignee and the
  background service, create for `dossiq-medewerkers` and the background
  service, delete for administrators.
- `register.d/90-background-service-account.json` and
  `register.d/84-woo-refusal-grounds.json`: `authenticated` and `public` become
  `dossiq-medewerkers`. Their own write restrictions stay.
- `CaseTeamAccessProjector` writes one conditional rule per handling team onto
  the live case schema, `{group, match: {caseType: {$in: [...]}}}`, for read
  and update. It runs after every register import and after every case type
  save, a publish included.
- `ProvisionAssignedGroups` provisions `dossiq-medewerkers`.
- `SeedStaffGroup`, a one-off repair step, seeds the group.

## Impact

- Merge order: this change first (or together), then openregister's
  `roles-block-that-resolves-to-nothing-grants-nothing`. OpenRegister alone
  would close the case schema to everybody but administrators and owners.
- A user who is not in `dossiq-medewerkers` loses dossiq after the upgrade
  unless they handle a case type or are assigned a case.
- A background job that reads dossiq objects as nobody, outside the
  background service account, is refused on the single object path.
