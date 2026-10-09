---
kind: code
depends_on: []
---

# Proposal: one-follow-control

## Summary

Following and favourites become one feature in dossiq: Volgen. The star on the case page, the Favourites chip, the Your favourites tile and the Add to favourites row action go. What stays is one Follow control per case with a bell beside it that turns the notifications of your follow on or off, one Following chip on Cases, the tile Zaken die u volgt and a Follow row action. Being made the assignee of a case follows it with notifications on.

## Why

Ruben, reviewing the board DqMijnWerk on 9 October 2026: "Following cases is complicated; let us merge following and favourites into one feature." A handler met a star and a bell on the same case page, two chips and two tiles for one question: which cases do I keep an eye on. OpenRegister makes the platform side of the decision in `merge-follow-and-favourites` (one table, a `notify` switch per follow, favourites moved in as quiet follows, assignment follows). This change is dossiq's half.

## What changes

- **Case page.** `CaseFollowStrip` is the one per-reader control in the banner row. While you follow, a bell beside it sends `PUT .../watch` with `{"notify": false}` or `{"notify": true}` and keeps the follow. `CaseFavouriteStrip`, `favouriteApi.js` and `caseFavourite.js` are deleted, and so is the `case-favourite` widget type.
- **Cases.** The Favourites chip goes. Followed is renamed Following (Volgend), over `_watching`.
- **Row actions.** Add to or remove from favourites becomes Follow or stop following (`toggleCaseFollow`), on Cases and on the Queue.
- **Dashboard.** The tile Your favourites becomes Cases you follow (Zaken die u volgt) over `_watching`. Its widget id stays `favourite-cases`, because a reader's saved layout stores it.
- **Assignment follows.** The case schema marks `assignee` with `x-openregister-role: assignee`, so OpenRegister's `AssigneeFollowListener` makes the assignee follow the case with notifications on. Register 0.20.21, case schema 1.38.0.
- **Specs.** REQ-CM-40 and REQ-ACC-09 are amended to one control with a notifications switch and to assignment following. REQ-FAV-01 (a private star) and REQ-FAV-02 (Favourites and Recently opened) are removed; REQ-FAV-03 adds Following and Recently opened.

## Out of scope

- Who hears what per event (per-event notification preferences). That is a later OpenRegister change.
- Task's own watchers on the task row.

## Impact

- A reader's existing stars arrive as quiet follows (OpenRegister's migration), so they keep their list and hear nothing new.
- Quiet follows are visible to whoever may update the case, under Followers on the People tab. That is the decision, and the release note says so.
- Depends on OpenRegister `merge-follow-and-favourites` for `@self.watchNotify` and the `notify` body. On an older OpenRegister the bell reads as on and the write is ignored, and nothing breaks.
