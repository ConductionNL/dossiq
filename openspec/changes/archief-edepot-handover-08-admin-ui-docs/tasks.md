# Tasks: archief-edepot-handover-08-admin-ui-docs

Chain member 8 of 8 (`kind: code`, depends_on member 07). Traces to giant Tasks 19–22.

## 1. Retention-rule CRUD + UI

- [~] Implement `GET /api/archief/rules`, `POST /api/archief/rules`, `PUT /api/archief/rules/{ruleId}`, `DELETE /api/archief/rules/{ruleId}` with admin auth posture — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Validate `zaaktypeKey` is a known zaaktype and `bewaartermijnJaren ≥ 1` or "permanent" — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Build the admin UI (list, add/edit form dialog, delete with confirmation); NcSelect inputs carry labels; modals isolated — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] All strings via `t('procest', ...)`; Dutch + English — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. Dashboard & monitoring

- [~] Implement `GET /api/archief/dashboard/stats` → {ready, inProgress, failed, completed, totalTransferred} — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Build the dashboard view: stat cards, triggers table, batch-jobs table, quick actions (initiate batch, retry failed, view proof) — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. Unit & integration tests

- [~] Unit tests for the services from members 02–07 (trigger daemon, bundler, exporter, BagIt bundler, submitter, retry daemon, proof recorder, rollback manager, batch processor) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration: end-to-end happy path (trigger → bundle → submit → proof) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration: failure path (bundling/submission fails → DIV notified → corrected → retry succeeds) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Integration: batch of 50 cases with concurrency control and report — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Mock docudesk, e-Depot endpoints, case/document entities, Nextcloud file storage — deferred to downstream cycle / fleet-wide adoption (handoff)

## 4. Documentation

- [~] Author the admin guide (overview, retention-rule setup, batch processing, proof of transfer, troubleshooting) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Author the developer guide (architecture, extension points, API reference, schemas, testing) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Author the e-Depot integration guide (SIP/BagIt + MDTO format, openconnector config, checksum verification) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Include architecture diagrams and code/sample-data examples — deferred to downstream cycle / fleet-wide adoption (handoff)
