---
kind: code
depends_on: []
---

# Proposal: woo-delivered-set-is-a-record

Woo capability programme, round 1, wave 2. Rows 19.15 and 19.16.

| row | text | our rating today |
| --- | --- | --- |
| 19.15 | The set delivered to a requester is itself a record, with its own identity, its contents fixed, and a manifest | partial (production) |
| 19.16 | The delivered rendition sits beside the original, so a reader can compare what went out with what came in | partial (production) |

Implements Ruben's decision D1: dossiq owns the Woo request, so the set it delivers is dossiq's
record. The plan had these rows on opencatalogi before D1. The gap texts in `gaps.tsv` still name
opencatalogi's model (`#wooAssessment`, `publishBatch`, `documentReference`, `anonymizedDocument`).
This change translates them onto dossiq's model: the Woo case is the container,
`wooDocumentAssessment` is the per-document verdict, and `redactedDocumentRef` is the delivered
rendition of a partly public document.

## Why

What dossiq does today, read on `development` at 55bbc761:

- `WooPublicationService::publish()` selects the disclosable documents with
  `selectDisclosableDocuments()`: `openbaar` as is, `deels_openbaar` only through
  `redactedDocumentRef`, `niet_openbaar` never. That redacted-only rule (spec
  `woo-publication-via-opencatalogi`, done) is why the rows are partial and not no.
- Nothing records what went out as one thing. After publish, an assessment can still be changed,
  a redacted file can be replaced, and nobody can show which bytes the requester received.
- The original and the redacted file are two documents on the case. No screen puts them side by
  side.

## What changes

1. **The delivered set is an object of its own.** On publish, dossiq writes a `wooDeliveredSet`
   with its own uuid and a manifest: per item the assessment, the delivered file, its original,
   the SHA-256 and the size of the delivered bytes; and a set hash over the items. It is written
   before the publication is created and marked `frozen` once the publication exists, so no
   publication exists without its manifest.
2. **Its contents are fixed.** Once frozen, the case's assessments and the set itself refuse updates
   and deletes. A new delivery on the same case (after a withdrawal, or a supplementary decision) is
   a new set that names the one it supersedes.
3. **It can be re-verified.** A route and an `occ` command recompute every hash and say per item
   whether the bytes still match.
4. **Compare.** For each item where the delivered file is a redaction, the officer opens the
   original and the delivered rendition side by side, in filinq's review workbench viewer. The set's
   page lists every pair, so the record shows what came in beside what went out.

## What does not change

- The redacted-only rule and the publication payload. The original reference never enters the
  payload. The `wooDeliveredSet` is an internal record in dossiq's register, readable with case
  access, never published.
- Who may publish (`Publish action authorization`, done).

## Dependencies

- Planned, openregister, wave 1: `object-archive-state` (extends REQ-OAS-004 so file writes honour
  the frozen marker). Until it lands, a changed file is caught by re-verification, not prevented;
  task 2.2 says how to tell.
- Open, filinq, outside the plan: `anonymization-review-workbench` (0/17) ships the viewer. Without
  filinq, or before that change lands, the compare action says the viewer needs filinq and offers
  both files to open; nothing pretends to compare.
- Builds on dossiq's own `woo-publication-via-opencatalogi` (done).

**App absent.** Without opencatalogi nothing is published, so no set is frozen; a pending set is
removed when the publish fails.

## Wave and done

Wave 2. Done means merged on `development` with CI green. 19.15 and 19.16 then read `yes` (build),
and `production` only once a dossiq store release carries them. 19.16 reads `yes` only with filinq
installed; the report states that condition.
