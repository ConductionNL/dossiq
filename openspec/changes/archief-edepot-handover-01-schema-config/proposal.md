---
kind: config
depends_on: []
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

# Proposal: archief-edepot-handover-01-schema-config

## Summary

This is **spec 1 of 8** in the `archief-edepot-handover` chain (ADR-032 decomposition of the original `archief-edepot-handover` giant). It declares the entire `procest-archief` data model as OpenRegister schemas, registers the register + manifest wiring, seeds the VNG-default retention rules, and ships the integration test that proves the materialised schemas + seed are correct. It is `kind: config` — only declarative JSON (schema definitions + register template + seed) plus the integration test that verifies them. No imperative service code lives here; that is the job of chain members 02–08, each of which `depends_on` this member.

## Why

The e-Depot handover pipeline (retention detection, metadata bundling, document export, SIP submission, proof capture, rollback, batch processing, inspection export) all reads and writes a shared set of six archival schemas. ADR-031 (declarative-first) and ADR-032 (chain `declare → consume → delete`) require that the schema metadata land **first**, in isolation, so that:

- Every downstream consumer (members 02–08) can read the fields without re-declaring them.
- The schema surface is reviewed against the schema/manifest validation gate only — not the full 18-gate code review.
- A mid-chain merge is safe: the schemas become read-only-available on every object before any consumer changes.

## What Changes

1. **Register the `procest-archief` register** with six OpenRegister schemas: `BewaarTermijnRegel`, `OverdrachtTrigger`, `SipBundel`, `OverdrachtTransactie`, `ArchiefBewijs`, `OverdrachtAuditLog`.
2. **Wire the register/schema import** via the repair step pattern (ADR-001 OR ObjectService; import on install, idempotent).
3. **Seed the VNG-default `BewaarTermijnRegel` rules** (omgevingsvergunning 5yr, wmo-aanvraag 10yr, subsidie-verlening permanent) idempotently on first install.
4. **Integration test** that registers the schemas on a fresh instance, asserts all six exist with the documented fields/relations, asserts the REST endpoints return an empty list, and asserts the seed produced exactly the expected rules (and is idempotent on re-run).

## Impact

- **Affected**: procest (schema + register + seed), openregister (schema host).
- **Code surface**: six schema JSON files under the register; one repair/seed step; one integration test. No business-logic services.
- **Downstream**: members 02–08 consume these schemas.

## Traceability

Covers giant tasks **1** (Register procest-archief Schemas) and **2** (Seed Default Retention Rules). No new scope is introduced.
