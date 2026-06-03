---
kind: code
depends_on:
  - archief-edepot-handover-02-retention-trigger
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

# Proposal: archief-edepot-handover-03-metadata-bundling

## Summary

This is **spec 3 of 8** in the `archief-edepot-handover` chain. It implements the TMLO/MDTO metadata bundler that turns a ready-for-transfer case into a `SipBundel` with standards-compliant XML, validated against the official XSD, and blocks bundling when a document lacks a document-type. `kind: code`; `depends_on` member 02 (it bundles cases that member 02 marked ready).

## Why

The e-Depot can only ingest a case that arrives as a validated MDTO 1.1 (or TMLO 1.2.1) metadata bundle. This member produces that XML and the initial `SipBundel` record. It deliberately stops short of document export (PDF/A) and packaging — those are members 04 and 05.

## What Changes

1. **MetadataBundler** — `buildBundle(caseId, mdtoVersion)` loads case + rule + documents and emits MDTO/TMLO XML with all required and present-optional fields.
2. **MDTO/TMLO XML builder** — maps procest case fields → MDTO elements, including per-document metadata and digital-signature metadata.
3. **XSD validation** — `validateXsd()` validates against the official MDTO 1.1 / TMLO 1.2.1 schema.
4. **SipBundel creation** — `createSipBundel()` persists the bundle record with `metadataXml`, `metadataXsdValid`, status `prepared`.
5. **Document-type gate** — `SchemaValidator.validateDocumentTypes()` blocks bundling when any document lacks a document-type and raises an actionable DIV task.

## Impact

- **Affected**: procest (bundler, XML builder, schema validator).
- **Consumes**: `OverdrachtTrigger`, `SipBundel`, `OverdrachtAuditLog` (members 01–02).
- **Downstream**: member 04 attaches exported documents; member 05 packages the bundle.

## Traceability

Covers giant tasks **5** (MetadataBundler) and **6** (XSD validation + error handling); requirement REQ-ARCH-002 (A/B/C). No new scope.
