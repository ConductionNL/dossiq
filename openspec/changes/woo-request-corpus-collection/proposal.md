---
kind: code
depends_on: [woo-requests-gather-documents-from-sources]
---

# Proposal: woo-request-corpus-collection

Woo capability programme, round 1, wave 2. Rows 19.1, 19.2, 19.3, 19.4 and 19.18.

| row | text | our rating today |
| --- | --- | --- |
| 19.1 | A search plan is recorded before collection begins, and the product holds it with the request | no |
| 19.2 | The collection is reported per person whose files were searched, with how much came back from each | no |
| 19.3 | Every document excluded before review is listed with the reason, so what the reviewer saw reconciles against what arrived | no |
| 19.4 | The query that selected the corpus is saved with the request in a form someone else can re-run | no |
| 19.18 | A new request starts from a template that carries the configuration of the last one | no |

Implements Ruben's decision D1: dossiq owns the Woo request, so the corpus of a request is
recorded on the dossiq Woo case. Before D1 these rows were planned on opencatalogi.

## Why

`woo-requests-gather-documents-from-sources` (dossiq, open, 0/6, built as written in wave 1) lets a
handler search the instance's sources from a Woo case, add picked results as documents, and stores
where each was found (REQ-WOO-012 to 014). It does not make the corpus a record:

- nothing says, before anyone searches, whose files and which systems will be searched for which
  period and terms, so a decision cannot show that the search was planned rather than improvised;
- provenance names the source and location, not the person whose files they were, so nothing
  reports volumes per custodian;
- a duplicate, an out-of-period file or an unreadable file is simply not added, so what arrived and
  what was reviewed cannot be reconciled;
- the search terms are kept per document, not as a query someone else can run again;
- a second request about the same subject starts from nothing.

## What changes

1. **A search plan per Woo case** (`wooSearchPlan`): custodians (named people or functions, with a
   Nextcloud user where there is one), source systems, the period, and the terms. It is recorded
   before collection, every change to it is on OpenRegister's audit trail, and the gather endpoints
   refuse to search or add while the case has none.
2. **Custodian and system on every collected document**: the gather add records which plan
   custodian the file belongs to and which source system it came from, beside the existing
   provenance. A collection report gives counts and volume per custodian and per system.
3. **Exclusions are records** (`wooExclusion`): every candidate set aside before review is kept with
   its reason (`duplicate`, `out-of-period`, `out-of-scope`, `unreadable`). Duplicates are found by
   content hash at the add. A reconciliation answers arrived = assessed + excluded + outstanding.
4. **Queries are saved and re-runnable** (`wooCollectionQuery`): each search the handler runs from
   the case is stored with its source, terms, period and filters, and the ids it returned. Re-running
   it answers what is new since the last run.
5. **A new request starts from a previous one**: the request's configuration (the plan's
   custodians, systems and terms, not its period) is a `wooRequestConfiguration` that a new Woo case
   copies from a named earlier case or from a named template. The triage change adds its own keys
   to the same object.

## What does not change

- The sources and their search (REQ-WOO-012), the add (REQ-WOO-013) and the provenance written at
  the add (REQ-WOO-014). This change adds fields and refusals around them.
- Assessment and redaction.

## Dependencies

- Open, dossiq, outside the plan: `woo-requests-gather-documents-from-sources` (0/6). This change
  amends its endpoints, so it starts after that change is merged.
- Open, openregister, outside the plan: `records-saved-templates` (0/6), for the named template in
  item 5. Without it, a new request can start from a previous case but not from a named template;
  task 5.2 says what to do.
- Followed by: `woo-review-triage` (wave 3), `woo-review-reports` (wave 3).

**App absent.** A source that integriq does not connect is listed as not connected (REQ-WOO-012); the
plan can still name it, and the collection report shows it with zero documents and the reason.

## Wave and done

Wave 2. Done means merged on `development` with CI green. 19.1 to 19.4 and 19.18 then read `yes`
(build), and `production` only once a dossiq store release carries them.
