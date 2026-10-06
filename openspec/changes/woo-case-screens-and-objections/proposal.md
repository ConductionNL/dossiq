---
kind: code
depends_on: [woo-requester-notices-really-go-out, woo-term-is-computed-and-reported-right]
---

# Proposal: woo-case-screens-and-objections

Woo capability programme, round 1, wave 2. Row 7.13.

| row | text | our rating today |
| --- | --- | --- |
| 7.13 | An objection against the decision is handled in the product | partial (build) |

Implements Ruben's decisions D1 (dossiq owns the Woo request, so the work between intake and
publication has to be doable in dossiq) and D13 (7.13 was re-mapped to this change because
objections are API only and the Bezwaar case types are parked).

## Summary

The work between intake and publication of a Woo request can be done on dossiq's case screens, and an objection against the Woo decision is handled in the product.

- Rows: 7.13 (objection handling; Awb art. 6:7 and 7:10).
- Wave 2.
- Dependencies: `dossiq/woo-requester-notices-really-go-out` (https://github.com/ConductionNL/dossiq/issues/3286), `dossiq/woo-term-is-computed-and-reported-right` (https://github.com/ConductionNL/dossiq/issues/3287). Reads `dossiq/woo-refusal-grounds-list` (https://github.com/ConductionNL/dossiq/issues/3288) when present.
- Decisions: D1 (dossiq owns the Woo request) and D13 (7.13 is re-mapped here).
- Build rules: openspec/woo-build-rules.md

## The law

- **Awb art. 6:7**: the objection period is six weeks, starting the day after the decision is
  made known (art. 6:8 lid 1).
- **Awb art. 7:10 lid 1 and lid 3**: the body decides on the objection within six weeks (twelve
  with an advisory committee), and may postpone once by six weeks.
- **Woo art. 4.4**: the four-week decision term on the request itself, with one two-week
  extension. Its arithmetic is `woo-term-is-computed-and-reported-right`.

## Why

What dossiq does today, read on `development` at 55bbc761:

- The Woo endpoints exist: POST `/api/cases/{id}/woo/assessment` (`WOOAssessmentController::bulkAssess`,
  body `{assessments: [...]}`), `/woo/extend-deadline` (body `{reason}`) and `/woo/decision` (body
  `{decision: {...}}`). No file under `src/` calls any of them. The assessment view that did was
  removed (#867).
- The CaseDetail page carries the header actions `woo-publish`, `woo-withdraw` and
  `woo-publication-open`. Publish (Woo) is shown only once a decision exists, and a decision can
  only be made through the API. So a handler cannot get from intake to publication without a REST
  client.
- Objections: `bezwaarObjection#open` (POST `/api/bezwaar/{caseId}/objection`, body
  `{contestedDecision, objection: {...}}`) attaches an objection record to an existing bezwaar case,
  and the detail pages `BezwaarDetail`, `BezwaarDecisionDetail` and `BeroepDetail` are routable. But
  nothing on screen starts an objection: the Bezwaren index was retired in #1682 because it listed
  zero rows, and it listed zero rows because the Bezwaar and Beroep case types sit under
  `_caseTypes_disabled` in `lib/Settings/bezwaar_seed_data.json`. That parking was deliberate: it
  keeps the Dutch demo profile off an English demo install.

## What changes

The Woo screens come first. If this is too large for one PR, the objection half (section 4 of
the tasks onward) is a second PR on the same change.

1. **Assess documents from the case.** A Woo case shows its documents with their assessment state
   (outstanding, openbaar, deels openbaar, niet openbaar). An `Assess documents` dialog sets the
   classification and grounds for one or several documents and posts to `/woo/assessment`. Grounds
   are picked from dossiq's refusal grounds list once `woo-refusal-grounds-list` lands, and from the
   values the endpoint accepts until then.
2. **Extend the term from the case.** An `Extend Woo term` dialog asks for the reason, posts to
   `/woo/extend-deadline`, and shows the new deadline and whether the requester was told (the
   `noticeStatus` that `woo-requester-notices-really-go-out` adds).
3. **Make the decision from the case.** A `Woo decision` dialog shows the assessment summary,
   refuses while documents are outstanding, and posts to `/woo/decision`. After it, Publish (Woo)
   appears as it does today.
4. **Register an objection against a Woo decision.** A header action on a Woo case with a decision
   opens a dialog for the received date, the grounds and the channel. A new endpoint, POST
   `/api/cases/{id}/woo/objection`, opens a case of the Bezwaar case type related to the Woo case,
   attaches the objection through the same code `bezwaarObjection#open` runs, and arms the six-week
   term. The objection case is reachable from the Woo case, and the Woo case from it.
5. **The Bezwaar case type is there when Woo is.** The Bezwaar case type is seeded together with
   the Woo request case type, as a statutory companion, not as part of the Dutch demo profile. The
   Beroep and Subsidie demo types stay parked.
6. **A Bezwaren list.** A `Bezwaren` index lists cases of the Bezwaar case type, so an objection is
   findable without knowing its Woo case.

## What does not change

- The endpoints' business rules: the assessment validation, the decision assembly, publish and
  withdraw.
- The bezwaar lifecycle after it is opened: hearing, committee advice, decision on objection
  (`specs/bezwaar-beroep-workflow`, done).
- The English demo profile. Un-parking `_caseTypes_disabled` as a whole is not done here.

## Dependencies

- Planned, dossiq, wave 1: `woo-requester-notices-really-go-out` (the extend dialog shows the
  notice result), `woo-term-is-computed-and-reported-right` (the extend route goes through
  `termijn#verleng`).
- Reads, when present: `woo-refusal-grounds-list` (wave 1) for the ground picker. Without it the
  picker offers the values `WOODocumentAssessmentService::validate()` accepts and says so.

**App absent.** Without opencatalogi, Publish (Woo) refuses with its existing sentence; the screens
before it work. Without filinq, the redaction step on a partly public document shows that the
redaction service is not installed; assessment still works.

## Wave and done

Wave 2. Done means merged on `development` with CI green. Row 7.13 then reads `yes` (build), and
`production` only once a dossiq store release carries it.
