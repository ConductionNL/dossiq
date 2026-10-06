---
kind: code
depends_on: [woo-request-corpus-collection, woo-request-scoped-access]
---

# Proposal: woo-review-triage

Woo capability programme, round 1, wave 3. Rows 19.7, 19.8, 19.9, 19.10, 19.11 and 19.17.

| row | text | our rating today |
| --- | --- | --- |
| 19.7 | Each document in a request's collection is marked in scope or out of scope, and the marking is reported | no |
| 19.8 | A rule marks documents in or out of scope without a reviewer opening each one, and the rule is visible | no |
| 19.9 | The corpus is cut into batches, and each batch is assigned to a named reviewer | no |
| 19.10 | Review depth is set per document type, every page or a sample, and the product records which applied | no |
| 19.11 | The product will not produce the publishable set until every page of every document has been seen | partial (production) |
| 19.17 | A request is a container with its own authorisation, separate from every other request in the same organisation | partial (production) |

Implements Ruben's decision D1. Before D1 these rows were planned on opencatalogi, and the gap texts
still name opencatalogi's model (`#wooAssessment`, its `batch` parent, `publishBatch`). This change
translates them onto dossiq's: the Woo case is the container, `wooDocumentAssessment` is the
disclosure verdict, and the publishable set is what `WooPublicationService::publish()` selects.

## Summary

The corpus of a Woo case is marked in or out of scope by hand or by a visible rule, cut into batches assigned to named reviewers, reviewed at a recorded depth, and kept behind its own authorisation; nothing is published until every page was seen.

- Rows: 19.7, 19.8, 19.9, 19.10, 19.11 and 19.17.
- Wave 3.
- Dependencies: `dossiq/woo-request-corpus-collection` (https://github.com/ConductionNL/dossiq/issues/3292), `dossiq/woo-request-scoped-access` (https://github.com/ConductionNL/dossiq/issues/3302), whose per-object grants carry 19.17. No Woo record uses `x-openregister-hierarchy`: OpenRegister refuses a parent that references another schema. Followed by `dossiq/woo-review-recall-and-stopping` (https://github.com/ConductionNL/dossiq/issues/3295).
- Decisions: D1 (the review of a Woo request is dossiq's).
- Build rules: openspec/woo-build-rules.md

## Why

What dossiq has, read on `development` at 55bbc761:

- One record per document, `wooDocumentAssessment`, which is the disclosure verdict
  (`openbaar`, `deels_openbaar`, `niet_openbaar`). Whether a document is about the request at all
  is not recorded apart from that verdict, so an irrelevant document either gets a verdict or sits
  outstanding forever.
- No rules, no batches, no assignment, no review depth, no record of pages seen.
- `allDocumentsAssessed()` gates the decision on every document having a verdict. That is why 19.11
  is partial: a verdict can be set through the API on a document nobody opened.
- `wooDocumentAssessment` declares no authorisation and no parent. Anyone with access to dossiq's
  register reads every request's assessments. That is why 19.17 is partial.

## What changes

1. **Relevance apart from the verdict** (19.7): a `wooDocumentReview` per collected document holds
   `relevance` (`unmarked`, `in-scope`, `out-of-scope`), who or which rule set it, the batch, the
   review depth that applied, and the pages seen. Only in-scope documents need a verdict. The case
   summary reports both counts.
2. **Rules** (19.8): `wooTriageRule` objects on the case, with metadata conditions (custodian,
   source system, mime type, date range, sender domain) and text conditions (contains, does not
   contain), an effect (in or out) and an order. Applying them marks unmarked documents and records
   the deciding rule. A reviewer can overturn a rule's marking, and the overturn is recorded. The
   rules are listed on the case.
3. **Batches** (19.9): `wooReviewBatch` cuts the in-scope documents into named batches, each assigned
   to a named reviewer through a dossiq task, visible before any verdict.
4. **Review depth** (19.10): the request configuration declares per document type `every-page` or
   `sample` with a size. A batch applies it, and each review records which depth applied and which
   pages the sample drew.
5. **Every page seen** (19.11): the review viewer records the pages a reviewer saw. A verdict on a
   document whose required pages are not all seen is refused, and the decision and the publish
   refuse while any in-scope document has unseen required pages.
6. **The request is an authorisation container** (19.17): every Woo child record is private, and
   dossiq grants it to the case assignee and the batch reviewers through the per-object grants of
   `woo-request-scoped-access`, so access follows the case and one request is closed to the people
   of another. The gap text's "the assessment declares its batch as parent" cannot be built:
   OpenRegister's `rbac-inherits-to-children` refuses at schema save any hierarchy parent that
   references a different schema (`hierarchy.foreign-reference`), and the batch, like the case, is
   a different schema.

## What does not change

- The disclosure verdict and its validation.
- The redaction flow.

## Dependencies

- Planned, dossiq, wave 2: `woo-request-corpus-collection` (custodian and system on each document,
  the request configuration this change adds keys to).
- Planned, dossiq, wave 2: `woo-request-scoped-access` (issue #3302). Its
  `WooAssessmentAccess::reconcile()` and its callers are the mechanism REQ-WRT-006 extends to the
  seven other Woo records.
- Built, openregister `development`: `rbac-inherits-to-children`. It is not used here, because its
  validator accepts only a parent of the same schema; and `object-level-sharing-and-private-scope`,
  which is. OpenRegister lets only an object's owner, an administrator or its owning group set a
  grant, so dossiq sets them in the acting user's request and never from a background job.
- Followed by: `woo-review-recall-and-stopping` (wave 4).

**App absent.** The review viewer is dossiq's own document view on the Woo case. When filinq's review
workbench is installed and the reviewer opens a document there, the workbench reports pages seen
through the same route; without filinq nothing changes.

## Wave and done

Wave 3. Done means merged on `development` with CI green. The six rows then read `yes` (build), and
`production` only once a dossiq store release carries them.
