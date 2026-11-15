---
kind: code
depends_on: [portal-case-list-declarations]
---

# Proposal: portal-change-proposals-on-the-case

Owner-moves pass of 2026-09-28. The dossiq half of portaliq's open change
`change-proposal-queue`, handed to dossiq after dossiq's own lane had
finished. It belongs to dossiq matrix row 2.21, "Change proposals from
colleagues or citizens queued for acceptance", rated `no`, `built.state`
`built`, `built.owner` `ConductionNL/portaliq`, whose note reads: "portaliq
lib/Controller/ProposalController.php and appinfo/routes.php:242-251
implement the change-proposal queue, but nothing calls it: no portal-SPA
propose button, no admin page, no accept or reject UI".

portaliq's `change-proposal-queue` tasks.md, verbatim: "For dossiq: the leaf
ids above, the action type `propose-change` with its `proposable` list, and
the staff endpoints for accept and reject." dossiq's umbrella
`competitor-parity-2026-09` lists the same half against 2.21 ("the accept and
reject actions and the field write") as a task with no change, and
`case-sharing-mints-access-links` leaves it out ("The portal queue for 2.21
belongs to portaliq"). No dossiq change specifies it.

## Why

A resident whose case lists the wrong address, or a colleague who spots a
wrong subject line, has no way to ask for a correction that the handler then
accepts or rejects with one click. portaliq holds the queue, the diff, the
drift check and the write as the reviewer. What is missing is dossiq saying
which case fields may be proposed, and dossiq showing the queue on its case
page.

Decision `build`: a half that a merged portaliq change depends on.

## What changes

- The citizen contribution declares a `propose-change` action on `case`
  with the fields a resident may propose to change, and offers it on the case
  detail in the portal.
- The case page in dossiq shows portaliq's proposal queue for that case, where
  the handler accepts or rejects, once portaliq registers the queue leaf.

## Capabilities

- Modified: `portal-contribution`.

## Out of scope

- The queue, accept and reject, the diff and the write: portaliq's
  (`ProposalService`, `ReviewerObjectWriter`).
- A resident's direct correction of their own answers: that is a citizen
  write (`portal-citizen-writes-on-the-case`), not a proposal.
