---
kind: code
depends_on: []
---

# Proposal: a-handling-team-opens-its-unclaimed-cases

## Why

Rehearsal on a fresh instance, 15 October: Thomas, a member of `behandelaars`, opened a Woo request from his team's queue before anyone had claimed it. The case page answered 403 "You do not work on this case." from the acts read, and the review and assessment reads refused him too, until he claimed the case. After the claim the queue he came from kept showing the case.

Two causes:

1. `CaseAccessGuard::hasCaseReadAccess()` lets in the case's assignee, its `assignees` and a platform grant, but not the handling teams of the case type. REQ-ACC-10 (Ruben, 11 October) says those teams read the cases of their case type, and OpenRegister already lets them read the case itself, so the page loaded and every dossiq read on it refused. The acts read (`GET /api/case/{id}/acts`) was behind the mutation guard, so even a reader got 403 there.
2. The claim row action, the bulk reassign and transition dialogs, mark read/unread, follow and the attention panel announced a change only as `dossiq:cases-changed`, which nothing listens to. The index page listens for `cn:objects-changed` and a dashboard list widget for `cn:page:refresh`; neither was sent.

## What changes

1. A member of one of the case type's handling teams (`CaseTypeHandling::teams()`) reads the case. Acting on it stays with its handler: the mutation guard is unchanged, so a team member claims the case before acting.
2. The acts read is behind the read guard. A reader who is not the handler gets every act listed and refused, with the sentence "Claim this case first: only its handler acts on it."
3. `announceCasesChanged()` sends all three signals, and every place that sent the bare event uses it.

## Not changed

- The case's own `assignedGroup` still grants nothing.
- Writes: REQ-ACC-10 also lets handling teams update through OpenRegister; dossiq's own acts still need the handler. Widening those is a separate decision.

## Rollback

Revert the commit.
