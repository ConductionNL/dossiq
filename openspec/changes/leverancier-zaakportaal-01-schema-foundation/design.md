# Design — Member 01: Schema Foundation (config)

## Scope

Declare seven OpenRegister schemas, four Procest supplier case types, and seed reference data for
the supplier portal. No behaviour ships in this member; consumers land in 02–16.

## Declarative-vs-imperative decision

Per ADR-031 (declarative-first) and ADR-001 (OpenRegister ObjectService), the supplier domain
model is expressed as OpenRegister schema metadata rather than bespoke PHP entity classes.
Schemas are registered through the procest register on install/upgrade via a repair step (per the
fleet `lib/Repair/InitializeRegister.php` + `<repair-steps>` pattern), NOT in a database
migration — migrations run before peer-app autoloaders load, so OpenRegister registration in a
postSchemaChange step silently skips. The four supplier case types are declared as Procest
zaaktype definitions, also declaratively.

## Data Model (seven schemas)

- **Supplier** — `kvkNumber`, `legalName`, `status` (active/inactive/blacklisted), `address`,
  `contactPerson`, `iban` (masked at API layer), `sbiCodes` (JSON), `accreditations` (JSON).
- **SupplierUser** — `supplierRef` → Supplier, `userRef` (eHerkenning id), `email`, `role`
  (admin/finance/contracts/sales/read_only), `status` (invited/active/revoked),
  `eherkenningLevel`, `activationToken`, `addedBy`, `addedAt`, `lastLoginAt`.
- **SupplierTender** — `supplierRef`, `caseRef`, `title`, `status`
  (submitted/evaluating/awarded/rejected/withdrawn), `submittedDate`, `value`, `awardDate`,
  `rejectionReason`, `appealDeadline`, `evaluationReportRef`.
- **SupplierContract** — `supplierRef`, `caseRef`, `number`, `subject`, `startDate`, `endDate`,
  `value`, `accountManager`, `renewalOption` (auto/manual_request/none), `renewalWarning`.
- **SupplierInvoice** — `supplierRef`, `caseRef`, `number`, `invoiceDate`, `amount`, `vatAmount`,
  `status` (received/under_review/approved/disputed/rejected/paid), `dueDate`,
  `expectedPaymentDate`, `actualPaymentDate`, `disputeReason`.
- **SupplierMessage** — `caseRef`, `supplierRef`, `direction` (inbound/outbound), `subject`,
  `body`, `attachmentRefs` (JSON), `sentBy`, `sentAt`; declared write-once (immutable audit
  trail), enforced at the API layer by member 11.
- **SupplierKPI** — `supplierRef`, `period` (YYYY-MM), `avgPaymentDays`, `onTimePercentage`,
  `disputeRate`, `complianceScore`, `benchmark` (JSON), `sufficientData` (bool).

Cross-references: every Supplier* schema declares a `supplierRef` reference to `Supplier`;
`SupplierUser` also references `Supplier`. Indexes on `supplierRef`, `status`, and date fields.

## Procest case types

`Leverancier-contractverlenging-verzoek` (renewal), `Leverancier-IBAN-wijziging` (4-eyes),
`Leverancier-accreditatie-verificatie`, `Leverancier-mutatie` (generic master-data change).

## Seed-data section

Idempotent repair step creates: 3 Supplier, 5 SupplierUser (covering admin/finance/contracts/
sales/read_only), 5 SupplierTender (one per status), 4 SupplierContract (one inside 90-day
window), 5 SupplierInvoice (covering each status incl. a 90+ overdue), 1 SupplierMessage thread.
Re-running creates no duplicates (lookup-before-create on natural keys).

## Security (ADR-005)

No endpoints in this member. The schema declares `iban` as a field the API layer (member 04/12)
masks; `SupplierMessage` is marked write-once so member 11 can enforce immutability. Supplier
scoping is enforced by member 04 at every API layer.
