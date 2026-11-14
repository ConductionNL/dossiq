---
kind: code
---

# Proposal: suspend-picks-a-pause-reason

## Why

Board DqVolgendeStap draws Opschorten with a Reden picked from a list and, under the days, the ground the suspension rests on ("Awb 4:5, hersteltermijn"). A case type already declares its pause reasons, each with a `legalBasis` (pause-reason-with-chasing), and a pause already stores the key of its reason (`pauseReason` on the term). The suspend gesture on the case page ignored both: it took free text and a number of days, so no pause made there carried a reason, and the dialog had nothing true to show under the days.

## What changes

1. `GET /api/case/{id}/lifecycle` answers `pauseReasons`: the case type's declared reasons, as `{ key, name, legalBasis }`.
2. `POST /api/case/{id}/suspend` takes `pauseReason`. A declared key is stored on the pause and its name is the rationale when no text is given. A key the case type does not declare is refused with 422 and code `pause_reason_not_declared`. Without a key, the free text is required, as before.
3. The Opschorten form offers the reasons in a picker with an "Other" entry. Other shows the free-text reason. The days field shows the picked reason's `legalBasis` as its hint.
4. A case type that declares no pause reasons keeps today's form: the free-text reason and the days, unchanged.
5. The shipped `vth` (Omgevingsvergunning Bouwactiviteit) and `subsidie` packages declare the two generic Awb grounds a decision on an application can be suspended on: an aanvulling under the hersteltermijn of Awb 4:5, and uitstel with the applicant's consent under Awb 4:15 lid 1 sub b. Both packages go to 1.1.0 so the next import writes them. Bezwaar and Woo are not given these: a bezwaar is suspended under Awb 7:10 and a Woo request under the Woo itself, so the Awb 4:5 grounds would be wrong there.

Nothing a handler can do today is lost: the free text stays, behind Other or on its own.

## Not in this change

Pause reasons carry no default number of days, so Dagen is not prefilled. A `defaultDays` on a reason would be its own change to the case type schema.

## Rollback

Revert the commit. Pauses registered with a key keep it; the term reads it as before.
