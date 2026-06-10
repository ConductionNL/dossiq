# Tasks: archief-edepot-handover-08-admin-ui-docs

> **Build status (hydra audit).** Greenfield. No archief schemas, services, or UI exist on dev. The 8-member archief-edepot-handover chain implements GiHandover/MDTO compliance from scratch (BewaarTermijnRegel, OverdrachtTrigger, SipBundel, OverdrachtTransactie, ArchiefBewijs, OverdrachtAuditLog schemas + daemon + sip-bundle generator + e-depot submission adapter + audit/admin UI). Tasks remain [ ] as genuine forward work for the next builder. See chain plan in design.md.

Chain member 8 of 8 (`kind: code`, depends_on member 07). Traces to giant Tasks 19–22.

## 1. Retention-rule CRUD + UI

- [ ] Implement `GET /api/archief/rules`, `POST /api/archief/rules`, `PUT /api/archief/rules/{ruleId}`, `DELETE /api/archief/rules/{ruleId}` with admin auth posture
- [ ] Validate `zaaktypeKey` is a known zaaktype and `bewaartermijnJaren ≥ 1` or "permanent"
- [ ] Build the admin UI (list, add/edit form dialog, delete with confirmation); NcSelect inputs carry labels; modals isolated
- [ ] All strings via `t('procest', ...)`; Dutch + English

## 2. Dashboard & monitoring

- [ ] Implement `GET /api/archief/dashboard/stats` → {ready, inProgress, failed, completed, totalTransferred}
- [ ] Build the dashboard view: stat cards, triggers table, batch-jobs table, quick actions (initiate batch, retry failed, view proof)

## 3. Unit & integration tests

- [ ] Unit tests for the services from members 02–07 (trigger daemon, bundler, exporter, BagIt bundler, submitter, retry daemon, proof recorder, rollback manager, batch processor)
- [ ] Integration: end-to-end happy path (trigger → bundle → submit → proof)
- [ ] Integration: failure path (bundling/submission fails → DIV notified → corrected → retry succeeds)
- [ ] Integration: batch of 50 cases with concurrency control and report
- [ ] Mock docudesk, e-Depot endpoints, case/document entities, Nextcloud file storage

## 4. Documentation

- [ ] Author the admin guide (overview, retention-rule setup, batch processing, proof of transfer, troubleshooting)
- [ ] Author the developer guide (architecture, extension points, API reference, schemas, testing)
- [ ] Author the e-Depot integration guide (SIP/BagIt + MDTO format, openconnector config, checksum verification)
- [ ] Include architecture diagrams and code/sample-data examples
