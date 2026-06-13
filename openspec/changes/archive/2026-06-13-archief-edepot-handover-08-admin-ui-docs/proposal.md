---
kind: code
depends_on:
  - archief-edepot-handover-07-batch-inspection
chain:
  - archief-edepot-handover-01-schema-config
  - archief-edepot-handover-02-retention-trigger
  - archief-edepot-handover-03-metadata-bundling
  - archief-edepot-handover-04-document-export
  - archief-edepot-handover-05-sip-submission
  - archief-edepot-handover-06-proof-rollback
  - archief-edepot-handover-07-batch-inspection
  - archief-edepot-handover-08-admin-ui-docs
---

# Proposal: archief-edepot-handover-08-admin-ui-docs

## Summary

This is **spec 8 of 8** (final) in the `archief-edepot-handover` chain. It puts the DIV-facing surface and assurance layer over the backend built by members 01–07: the retention-rule configuration UI + CRUD, the archival monitoring dashboard, the cross-service test suite (unit + end-to-end), and the admin/developer/e-Depot documentation. `kind: code`; `depends_on` member 07.

## Why

The pipeline is only usable by DIV with a configuration UI and a status dashboard, and is only shippable with end-to-end test coverage and operator documentation. This member closes the chain by making the capability operable and verifiable.

## What Changes

1. **Retention-rule CRUD + UI** — `GET/POST/PUT/DELETE /api/archief/rules` and an admin UI for `BewaarTermijnRegel` with validation.
2. **Archival dashboard** — `GET /api/archief/dashboard/stats` + a dashboard view (ready/in-progress/failed/completed, batch jobs, quick actions).
3. **Unit + integration tests** — coverage across the services from members 02–07 plus end-to-end happy-path and failure-path workflows.
4. **Documentation** — admin guide, developer guide, e-Depot integration guide.

## Impact

- **Affected**: procest (rule controller, dashboard controller, Vue views, tests, docs).
- **Consumes**: the full pipeline (members 01–07).
- **Downstream**: none — this is the chain tail.

## Traceability

Covers giant tasks **19** (retention-rule UI), **20** (dashboard & monitoring), **21** (unit & integration tests), and **22** (documentation). No new scope.
