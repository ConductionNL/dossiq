> SUPERSEDED 2026-06-02 (ADR-032): decomposed into archief-edepot-handover-01..08

# Proposal: archief-edepot-handover

## Summary

Automate the audit-grade handover of closed cases to certified e-Depot archives (like RHC or commercial providers) in compliance with Dutch Archiefwet 1995/2024, TMLO 1.2.1, and MDTO 1.0/1.1 metadata standards. This capability detects when cases reach the end of their retention period, builds TMLO/MDTO-compliant metadata bundles with multi-format document exports (PDF/A + originals + checksums), submits to the configured e-Depot via HTTPS/SFTP/S3, captures proof-of-transfer, and executes rollback if ingestion fails—replacing ad-hoc manual export + spreadsheet workflows with deterministic, auditable, standards-compliant archival.

## Why

Dutch municipalities are **legally required** to transfer closed dossiers to certified archives after their retention period (typically 5–10 years per Selectielijst gemeenten 2020) but currently do this manually: DIV staff ZIP each file, track metadata in spreadsheets, upload to SFTP, and keep no proof of transfer. This approach:
- **Scales poorly**: non-scalable for 1000s of dossiers across municipalities
- **Lacks auditability**: no verifiable proof-of-transfer for archival inspections
- **Produces errors**: wrong retention periods, missing metadata, incompleteness
- **Cannot rollback**: if e-Depot rejects the ingestion, the dossier is stranded
- **Defies Common Ground**: data should stay at source (procest) until archival moment; archival should be automated and centralized

This capability automates the entire pipeline, making archival as reliable and auditless-free as case creation itself. It is essential for municipalities that must prove compliance during provincial archival inspections and for meeting the upcoming Archiefwet 2024.

## What Changes

1. **Retention-Trigger Detection** — Automated daemon that runs nightly, identifies cases with `endDate + retention_period_years < today`, and marks them as "ready-for-transfer"
2. **TMLO/MDTO Metadata Bundler** — Builds XML metadata bundles conforming to TMLO 1.2.1 or MDTO 1.0/1.1 (configurable per e-Depot), including all required + optional fields, document metadata, and validation against XSD
3. **Multi-Format Document Export** — For each document: converts to PDF/A-2b or PDF/A-3a for long-term readability, preserves original file for bit-perfect archival, computes SHA-256 checksums for both
4. **SIP (Submission Information Package) Assembly** — Packages metadata + documents into BagIt format (RFC 8493) or proprietary ToPX structure with manifest and integrity checksums
5. **e-Depot Integration** — Submits bundles to configured e-Depot via HTTPS POST, SFTP upload, or S3-compatible storage with exponential backoff retry logic and certificate/API-key authentication
6. **Proof-of-Transfer Capture** — Records archival ID from e-Depot, stores signed receipt as read-only attachment, creates ArchiefBewijs record for audit trail
7. **Rollback on Failure** — If e-Depot rejects ingestion, dossier remains in procest, status marked "failed", DIV staff notified with actionable error details to fix and retry
8. **Bulk Batch Processing** — DIV staff can trigger batch-transfers of 100s of cases grouped by retention-period cohort, with concurrent bundling (configurable rate limit) and per-case reporting
9. **Archival Inspection Export** — On demand, generates audit-grade exports for provincial inspectors: CSV summary, individual ArchiefBewijs PDFs, checksum verification instructions, timeline visualization

## Impact

- **Affected projects**: procest (primary, case/document/audit engine), openregister (archive schema registration), openconnector (e-Depot adapter layer), docudesk (PDF/A conversion service), possibly legesberekening (if refunds apply post-archival)
- **Code surface**: new `procest-archief` register with 6 schemas (BewaarTermijnRegel, OverdrachtTrigger, SipBundel, OverdrachtTransactie, ArchiefBewijs, OverdrachtAuditLog); backend archival-trigger daemon and e-Depot integration service; admin UI for config and batch processing; REST API for proof-of-transfer queries
- **Dependencies**: REQUIRED: docudesk for PDF/A conversion; openconnector for e-Depot endpoints; openregister for schema management. OPTIONAL: virus scanning (antivirus app or openconnector adapter), digital signatures (Collabora/PKIoverheid), reporting dashboard (launchpad, opencatalogi)
- **Standards**: Archiefwet 1995 (operative), Archiefwet 2024 (incoming 2026), Selectielijst gemeenten 2020, TMLO 1.2.1 (legacy), MDTO 1.1 (current), BagIt RFC 8493, ISO 14721 OAIS, ISO 19005 PDF/A, ToPX standard, Common Ground principles

## Scope

### In Scope

- **Retention-rule management**: BewaarTermijnRegel schema; per-zaaktype configuration of retention years or "permanent"/"destroy" disposition; per-organization defaults from VNG selectielijst
- **Transfer-trigger detection**: Nightly job identifying cases with `endDate + retention_years < today`; status transition to "ready-for-transfer"; blocking on missing BewaarTermijnRegel (with DIV warning)
- **TMLO/MDTO metadata bundling**: Complete XML serialization per TMLO 1.2.1 or MDTO 1.1; XSD validation; all required + present optional fields; document metadata per attachment
- **Multi-format document export**: PDF/A conversion via docudesk; original file preservation; checksum computation (SHA-256); handling of digitally signed documents (signature metadata preserved)
- **SIP assembly**: BagIt or ToPX packaging; manifest generation; integrity checksums
- **e-Depot submission**: HTTPS POST, SFTP, S3 support; auth (API-key, cert, OAuth2); exponential backoff (1min → 5min → 30min → 2hr → 8hr); 5-retry limit with escalation
- **Proof-of-transfer**: ArchiefBewijs record; read-only attachment to case; signed receipt capture and storage
- **Rollback on failure**: Dossier remains in procest on ingestion rejection; status "failed"; error details logged; DIV notification with corrective action
- **Bulk batch processing**: DIV staff can initiate batch-transfers of 100s of cases; concurrent bundling with configurable rate-limit; per-case status reporting
- **Archival inspection export**: On-demand audit export for provincial inspectors; CSV + PDFs + checksum verification guide; timeline visualization

### Out of Scope

- Generic document conversion (use docudesk)
- Generic e-Depot endpoint definition (use openconnector)
- Case deletion/expiration lifecycle (orthogonal to archival; may follow in future)
- Digital signature + PKIoverheid integration (reference architecture provided; implementation in e-Depot-specific adapter)
- Virus scanning (can be plugged via openconnector adapter)
- Advanced archival search/discovery (that is e-Depot responsibility)
- Multi-tenant archival governance (single org per procest instance assumed)

## Dependencies

- **docudesk** (REQUIRED) — PDF/A conversion service for document rendering
- **openconnector** (REQUIRED) — Adapter layer for e-Depot endpoints (HTTPS, SFTP, S3 endpoints configured as openconnector sources)
- **openregister** (REQUIRED) — Schemas for BewaarTermijnRegel, OverdrachtTrigger, etc.; REST API for CRUD + relations
- **procest base** (REQUIRED) — Case, document, auditTrail entities; user/org context
- **Antivirus app / openconnector adapter** (OPTIONAL) — Virus scanning before submission
- **Collabora / PKIoverheid** (OPTIONAL) — Digital signature for ArchiefBewijs receipt
- **launchpad / opencatalogi** (OPTIONAL) — Reporting dashboard for archival batches
- **External e-Depots** — RHC, gemeentearchief, De Ree, Picturae, Digital Taties, Devoteam, etc. (via openconnector)

## Acceptance Criteria

1. GIVEN a municipality with zaaktypen "omgevingsvergunning" (retention 5yr), "wmo-aanvraag" (retention 10yr), "subsidie-vastgesteld" (permanent), WHEN DIV loads retention rules, THEN all zaaktypen show configured rules; missing zaaktype shows warning "Geen retentiebesluit voor [zaaktype]"
2. GIVEN 200 cases of type "omgevingsvergunning" closed on 2021-05-15, WHEN nightly trigger runs on 2026-05-22 (5yr+ elapsed), THEN all 200 are marked status "ready-for-transfer" with OverdrachtTrigger records
3. GIVEN a case marked "ready-for-transfer", WHEN metadata bundling runs, THEN SipBundel is created with MDTO XML (valid against XSD), document PDFs (PDF/A-2b or 3a), original files, SHA-256 manifest
4. GIVEN a SipBundel ready for transfer, WHEN submitted via configured e-Depot (HTTPS/SFTP), THEN OverdrachtTransactie records send timestamp + auth; on 200 OK response THEN ArchiefBewijs is created with archief-id + receipt
5. GIVEN an e-Depot returns 400 "MDTO_VALIDATION_FAILED: missing field X", WHEN failure handler runs, THEN OverdrachtTransactie status = "failed"; SipBundel preserved; DIV notified "Bundel afgewezen: veld X ontbreekt; corrigeer zaak en retry"
6. GIVEN DIV corrects the missing field and retries, WHEN new bundeling runs, THEN new SipBundel is built; new OverdrachtTransactie is sent; both transactions logged in OverdrachtAuditLog
7. GIVEN archival inspector queries an archief-id, WHEN export endpoint is called, THEN response includes CSV summary, ArchiefBewijs PDF, original checksums, and checksum-verification instruction document
8. GIVEN DIV initiates batch-transfer of 250 cases, WHEN batch completes with 245 success + 5 failed, THEN batch report shows per-case status; failed cases linked to error details; batch-job stats in OverdrachtAuditLog

## Open Questions / Future Scope

- **Selective retentionperiod override**: Can DIV delay archival for specific high-value cases? (Future: add "hold" status)
- **Multi-format document versioning**: Should SipBundel track document version history, or only final version? (Scope: final version only; history in case auditTrail)
- **Reporting to national level**: Should municipality publish archival stats to Nationaal Archief dashboard? (Future: opendata/reporting module)
