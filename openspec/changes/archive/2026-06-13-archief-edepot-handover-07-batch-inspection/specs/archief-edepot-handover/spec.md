# Spec delta: archief-edepot-handover-07-batch-inspection

## ADDED Requirements

### Requirement: DIV can run concurrent batch transfers with per-case reporting
The system MUST let DIV initiate a batch transfer of many cases with a configurable concurrency limit, track per-case status, and produce a batch report.

#### Scenario: Batch of 250 cases with concurrency 4
- **GIVEN** 250 cases in status `gereed-voor-overdracht` for the same e-Depot
- **WHEN** DIV calls `POST /api/archief/batch/initiate` with the case ids and `rateLimit` 4
- **THEN** a batch job is created and at most 4 cases are processed in parallel
- **AND** `GET /api/archief/batch/{jobId}` reports totalCases, completed, inProgress, pending, succeeded and failed with a failed-cases list

#### Scenario: Batch report after completion
- **GIVEN** a batch that completes with 245 succeeded and 5 failed
- **WHEN** DIV calls `GET /api/archief/batch/{jobId}/report`
- **THEN** a ZIP is returned with a per-case `summary.csv`, a `failed-cases.txt` with corrective actions, and a `batch-stats.txt` with totals and throughput

### Requirement: Annual inspection export produces an audit-grade ZIP
The system MUST generate an audit-grade export for a calendar year containing a CSV summary, per-case `ArchiefBewijs` PDFs, statistics, and a checksum-verification guide.

#### Scenario: Inspector requests the 2026 export
- **GIVEN** transferred cases for calendar year 2026
- **WHEN** an authorised inspector calls `POST /api/archief/inspection-export?year=2026`
- **THEN** a ZIP is returned with `archival-summary-2026.csv`, one `ArchiefBewijs-[zaak-id].pdf` per transferred case, a statistics PDF, and a checksum-verification guide

### Requirement: Archival events are queryable from an append-only audit log
The system MUST record archival milestones to the append-only `OverdrachtAuditLog` and expose them per case.

#### Scenario: Audit trail for a fully archived case
- **GIVEN** a case that went trigger-detected → bundling → submission → proof-captured
- **WHEN** an authorised caller queries `GET /api/archief/audit-log?zaakId={id}`
- **THEN** the response lists all events reverse-chronologically with timestamp, eventType, actor and details
- **AND** the events are immutable (append-only); the endpoint offers no mutation
