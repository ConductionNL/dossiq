# Archief & e-Depot

> **Admin guide:** see [`docs/admin/archief-edepot.md`](../admin/archief-edepot.md) for the Dutch-language administrator runbook.
> **Developer guide:** see [`docs/Technical/archief-edepot-architecture.md`](../Technical/archief-edepot-architecture.md).

Automated overdracht (handover) of closed cases to a municipal or regional e-Depot conform GiHandover (Generieke Handover Specificatie) and MDTO 1.2 (Metadata Toepassingsprofiel voor Overheidsinformatie).

## Specs

- `openspec/changes/archief-edepot-handover-01-schema-config/specs/archief-edepot-handover/spec.md` (chain members 01-08)

## Features

### Retention rule management (V1)
- DIV admins maintain `BewaarTermijnRegel` objects per zaaktype + trigger combination.
- Default VNG rules (omgevingsvergunning 5y, wmo 10y, subsidie permanent) seed on first install.
- Effective dating: rules apply to new triggers; existing closed cases are not retroactively re-triggered.

### Daily retention trigger daemon (V1)
- `OverdrachtTriggerDaemon` (BackgroundJob) wakes daily, evaluates each `BewaarTermijnRegel` against eligible cases, creates `OverdrachtTrigger` objects for the calculated transfer date.
- Idempotent: a (zaakId, regelId) pair never produces a duplicate trigger.

### SIP bundle assembly (V1)
- `MetadataBundlerService` generates MDTO XML validated against XSD 1.2.
- `DocumentExportService` materialises Nextcloud files into a temporary export tree.
- `SipBundleBuilderService` packages MDTO + documents into a GiHandover BagIt bundle with SHA-256 checksums.

### e-Depot submission (V1)
- `EDepotSubmitterService` sends SIPs through a configurable `EDepotAdapter` (default: openconnector-backed).
- Retry with exponential backoff (default 5 attempts, 60s initial backoff).
- Concurrency cap (default 5 parallel SIPs).

### Proof of transfer (V1)
- On acceptance, `ArchiefBewijs` records the e-Depot receipt id, checksum, and acceptance timestamp.
- Bewijs is immutable (write-once); verification recomputes the SHA-256 against the archived bundle.

### Rollback / corrective handover (V1)
- `RollbackManagerService` requests a rollback when the adapter supports it.
- Otherwise it produces a `correctieVan` SIP referring back to the original transaction.

### Batch processing & monitoring (V1)
- Dashboard with stat cards (ready / in-progress / failed / completed / total transferred), triggers table and batch-jobs table.
- Quick actions: initiate batch, retry failed, view proof.

### Audit (V1)
- `OverdrachtAuditLog` append-only log captures every event (rule change, trigger, build, submit, accept, reject, retry, rollback, verify) per BIO 8.3.1.

## Entities

- `BewaarTermijnRegel`
- `OverdrachtTrigger`
- `SipBundel`
- `OverdrachtTransactie`
- `ArchiefBewijs`
- `OverdrachtAuditLog`

See [ADR-000](../../openspec/architecture/adr-000-data-model.md) for field definitions.
