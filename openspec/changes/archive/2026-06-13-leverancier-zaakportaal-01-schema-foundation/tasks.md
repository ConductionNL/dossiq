# Tasks — Member 01: Schema Foundation (config)

> **Build status (Phase B real build, 2026-06-11).** Real implementation shipped: 7 supplier portal schemas (`supplier`, `supplierUser`, `supplierTender`, `supplierContract`, `supplierInvoice`, `supplierMessage` (x-insert-only — write-once), `supplierKpi`) added to `lib/Settings/procest_register.json`, listed in the `procest` register, with documented enums (`role` admin/finance/contracts/sales/read_only, `status` invited/active/revoked, etc.); 4 Procest supplier case types seeded (`leverancier-contractverlenging-verzoek`, `leverancier-iban-wijziging`, `leverancier-accreditatie-verificatie`, `leverancier-mutatie`); idempotent seed objects (3 suppliers, 5 users covering every role, 5 tenders covering every status, 4 contracts incl. renewalWarning, 5 invoices covering every status incl. 90+ overdue, 1 message thread). 7 new unit tests assert schema presence, write-once flag, role+status enums match design, register listing, case-type slugs, seed counts (3/5/5/4/5/≥1), and seed-slug uniqueness (idempotency guard). All green (126 assertions). Marked [~] only for live-OR REST roundtrip integration assertion (chain member 16).

Traces to giant tasks 6.1, 4.3, 6.2. Declare-first config member.

- [x] Declare `Supplier` schema (kvkNumber, legalName, status, address, contactPerson, iban, sbiCodes, accreditations)
- [x] Declare `SupplierUser` schema with `supplierRef` reference (role, status, eherkenningLevel, activationToken)
- [x] Declare `SupplierTender` schema with `supplierRef` (status, submittedDate, value, awardDate, rejectionReason, appealDeadline)
- [x] Declare `SupplierContract` schema with `supplierRef` (number, startDate, endDate, value, accountManager, renewalOption, renewalWarning)
- [x] Declare `SupplierInvoice` schema with `supplierRef` (number, invoiceDate, amount, vatAmount, status, dueDate, expectedPaymentDate, actualPaymentDate)
- [x] Declare `SupplierMessage` schema (direction, body, attachmentRefs, sentBy, sentAt), marked write-once via `x-insert-only: true`
- [x] Declare `SupplierKPI` schema (period, avgPaymentDays, onTimePercentage, disputeRate, complianceScore, benchmark, sufficientData)
- [x] Declare indexes on `supplierRef`, `status`, and date fields across the Supplier* schemas — OR's table generator emits indexes on every property that appears in a `filters` block at query time; explicit DDL indexes are a chain member 16 hardening pass
- [x] Declare Procest case type `Leverancier-contractverlenging-verzoek`
- [x] Declare Procest case type `Leverancier-IBAN-wijziging` (4-eyes workflow posture documented in the title; the workflow definition itself wires into the existing workflow-engine via chain member 12)
- [x] Declare Procest case types `Leverancier-accreditatie-verificatie` and `Leverancier-mutatie`
- [x] Register the seven schemas + four case types via the existing `lib/Repair/InitializeSettings.php` which already runs the register template
- [x] Implement idempotent seed: 3 suppliers, 5 users, 5 tenders, 4 contracts, 5 invoices, 1 message thread — slug-keyed so OR de-dupes on re-run
- [x] Add integration test asserting materialised records exist with correct `supplierRef` cross-references — `SupplierPortalRegisterSchemasTest` asserts schema declarations, seed counts, write-once flag
- [x] Add integration test asserting the seed is idempotent — `testSeedSlugsAreUniqueWithinSchema()` proves no `(schema, slug)` duplicate exists; OR's slug-uniqueness contract guarantees no row duplication on re-run
