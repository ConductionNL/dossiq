---
kind: code
depends_on: [woo-request-corpus-collection]
---

# Proposal: woo-review-reports

Woo capability programme, round 1, wave 3. Rows 16.10 and 16.11.

| row | text | our rating today |
| --- | --- | --- |
| 16.10 | Throughput is reported per reviewer and per day | partial (production) |
| 16.11 | The collection is reported by sender, by recipient and by email domain | partial (production) |

Implements Ruben's decisions D1 (dossiq owns the Woo request, so its review reports are dossiq's)
and **D9** (policy choices an organisation makes: build opt-in per organisation, off by default, and
name who may read 16.10).

## Summary

dossiq reports review throughput per reviewer and per day, and the collection by sender, recipient and mail domain, opt-in per organisation.

- Rows: 16.10 and 16.11.
- Wave 3.
- Dependencies: `dossiq/woo-request-corpus-collection` (https://github.com/ConductionNL/dossiq/issues/3292), `openregister/adhoc-aggregation-suite` (no issue; https://github.com/ConductionNL/openregister/tree/development/openspec/changes/adhoc-aggregation-suite).
- Decisions: D1 (dossiq owns the Woo request) and D9 (policy choices are opt-in per organisation, off by default, and name who may read 16.10).
- Build rules: openspec/woo-build-rules.md

## Why

A per-reviewer, per-day count is a per-person productivity figure. It may need works council
consent, so the organisation switches it on and names who may read it. A report of senders and
recipients over a request's mail is a list of third parties' names. Both are useful in a large
request and both are a choice, not a default.

What dossiq has, read on `development` at 55bbc761: every `wooDocumentAssessment` records
`assessedBy` and `assessedAt` and its `classification`, so the throughput data exists and is not
reported. A collected mail is a document on the case. After `woo-requests-gather-documents-from-sources`
and `woo-request-corpus-collection` its provenance records the source, the custodian and the system,
but not the mail's sender and recipients.

## What changes

1. **Two organisation switches, both off by default**, in dossiq's admin settings:
   `wooReviewerThroughputReport` (16.10) and `wooCollectionPartiesReport` (16.11). The first needs a
   named reader group (`wooReviewerThroughputReaders`) before it can be switched on.
2. **Throughput per reviewer per day (16.10)**: per Woo case and across Woo cases for a period, the
   number of assessments per reviewer per day, split by verdict (`openbaar`, `deels_openbaar`,
   `niet_openbaar`), on a Woo report screen and as CSV. Only members of the named group read it,
   and every read is recorded.
3. **Mail header fields on collected mail (16.11)**: when a collected document is an e-mail, the add
   stores `provenance.mail` with `from`, `to`, `cc`, `date` and `messageId`, read from the message
   headers.
4. **The collection by sender, recipient and domain (16.11)**: per Woo case, counts of collected
   mail per sender address, per recipient address and per mail domain, on the Woo report screen and
   as CSV, readable by anyone with read access to the case while the switch is on.

## What does not change

- The assessment and the add. This change reads what they store, and adds the mail header fields
  at the add.
- Who may assess.

## Dependencies

- Planned, dossiq, wave 2: `woo-request-corpus-collection` (provenance with custodian and system,
  and the add this change extends).
- Open, openregister, outside the plan: `adhoc-aggregation-suite` (0/10), for multi-field grouping
  (reviewer, day, verdict in one call). When it is not merged, the report groups a paged search of
  the case's assessments in dossiq, bounded per case; task 2.1 says how to tell.

**App absent.** Without integriq, no mail source is connected and 16.11 reports the mail that came
in through dossiq's own mail intake only, and says so.

## Wave and done

Wave 3. Done means merged on `development` with CI green. 16.10 and 16.11 then read `yes` (build),
as opt-in features, and `production` only once a dossiq store release carries them.
