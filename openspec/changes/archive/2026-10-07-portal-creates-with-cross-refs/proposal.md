---
kind: code
depends_on: [portal-contribution]
---

# Proposal: portal-creates-with-cross-refs

Competitor gap register, row 6.7 "Messaging with citizens through a portal"
(`procest/_gaps/gap-register.md` in ConductionNL/market-intelligence). Closes
the one task `move-portals-to-portaliq` was archived with open, T7.

## Why

dossiq moved its portals into Portaliq on 2026-09-09 and left three writes
behind. A citizen could read their cases, their berichtenbox and their
requests, and file a standalone complaint, but could not object to a decision,
reply to a message, or (as an external inspector) submit the checklist run
they had been assigned.

All three were deferred for one reason. A portal write is a flat map: the
action whitelists which fields the client may send and the server stamps
ownership onto one of them. A bezwaar names the case it objects to and a reply
names the case it is about, and nothing checked that either was the sender's.
A create that let a citizen name any case uuid is a write-IDOR, so the creates
were not shipped rather than shipped unguarded.

## What changes

- Portaliq now resolves a declared cross reference against the subject's own
  scope before writing (portaliq `portal-create-cross-refs`). The citizen
  audience declares two creates that use it: `createBezwaar` guards
  `againstCaseId`, and `replyToMessage` guards `caseId`. Both references are
  required, and both resolve to the citizen's own cases.
- Neither create lets the sender say what the write is: the `kind` of a
  bezwaar and the `direction` of a reply come from `defaults`, stamped
  server-side over the whitelisted body.
- The inspector's `submitChecklistRun` ships as an UPDATE on a run they are
  already assigned, so the client sends no `case` and no `template` at all.
  That removes the reference rather than guarding it, which is the better
  answer where it is available.

## Ownership

dossiq declares what may be written and what has to be checked. Portaliq
performs the check, because it is the only party holding the portal subject
at write time.

## Capabilities

- Modified: `portal-contribution`: the citizen can object and reply, and the
  inspector can submit, without any of the three being able to name another
  party's case.

## Impact

`lib/Portal/PortalContributionProvider.php`; provider unit tests.
