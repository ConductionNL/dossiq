---
kind: code
depends_on: [people-tab-access-card]
---

# Proposal: case-access-card-lists-team-and-group-grants

## Why

Board DqZaakBetrokkenen (https://identity.conduction.nl/screens/board?id=dossiq/DqZaakBetrokkenen)
ends the Betrokkenen tab with a Toegang card. Next to the access links, which
`people-tab-access-card` (branch `feat/r43-people-tab-access-card`) already lists, the board
draws the teams and groups that can open the case:

- "Team Woo en informatie · via het zaaktype": the handling team of the case type, which
  reads the case through the rule `CaseTeamAccessProjector` writes on the case schema.
- "Juridische zaken · gedeeld door Pieter Jansen": a group a colleague shared the case with.

That change left task 3.1 open: no endpoint lists a case's grants as rows with their origin
and who shared them. dossiq should not build one. Who can open a case is an OpenRegister
decision (REQ-ACC-01, D22), and a dossiq reader that evaluated the case schema's rules itself
would be a second decision nobody keeps in step.

## What changes

1. OpenRegister adds the read, in its change `an-object-lists-its-grants-with-their-origin`
   (ConductionNL/openregister): `GET /api/objects/dossiq/case/{id}/grants`, one row per
   principal and origin (`owner`, `shared`, `inherited`, `rule`), with `sharedBy` and the
   properties a rule's condition matched on.
2. dossiq wires the Toegang card's grant rows to that read. Configuration only: rows for
   users and groups, a group icon or initials, the display name as the title, and an origin
   line built from `origin`, `matchedOn` and `sharedBy`.
3. dossiq declares who may read a case's grants: a `readGrants` rule on the case schema that
   admits the case's assignee, the same people who see the case's links today.

Nothing in this change names a procedure. "via het zaaktype" is a label on the generic
`matchedOn: caseType`, written in the manifest.

## Not in this change

- Adding or revoking a grant from the card. Sharing stays in the sidebar Delen tab.
- The `/permissions` audit view in the Access sidebar tab (`CaseAccessTab.vue`). It keeps
  reading the rules as written.

## Rollback

Revert the manifest and the register fragment. The card falls back to the link rows.
