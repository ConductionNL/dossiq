# Tasks: portal-contribution

- [x] **T1**: Add `OCA\Dossiq\Portal\PortalContributionProvider` — a
  dependency-free class (`getAudience` + `getContribution`) declaring the supplier
  audience's collections (supplierTender/Contract/Invoice) + inbox
  (supplierMessage), scoped by `supplierRef`. Discovered by Portaliq via
  convention FQCN, duck-typed. LIVE-VERIFIED: a supplier logging into the Portaliq
  portal sees dossiq's tenders/contracts/invoices scoped to their `supplierRef`.

- [x] **T2** (done before 2026-10-09: `src/views/leverancier/` and `manifest.d/60-leverancier.json` are gone, see the MOVED note in `src/registry.js`; the API and facades stay): Retire dossiq's in-app supplier views (`src/views/leverancier/*`,
  `manifest.d/60-leverancier.json`) once Portaliq renders the contribution; keep
  the API + facades.

- [ ] **T3** (half built 2026-10-09, decision 128: `supplier.contactEmail` is on the schema
  (supplier 1.2.0, `tests/Unit/Settings/SupplierContactEmailTest.php`). Not run: the four
  rules wait on openregister's `email` recipient kind that reads an address through a
  reference (`supplierRef.contactEmail`), filed as a sibling ask. Declaring that kind today
  would fail OpenRegister's NotificationAnnotationValidator, which refuses an unknown recipient
  kind and so the whole supplierMessage schema at import.) Wire `x-openregister-notifications` on `supplierMessage` (and the
  contractExpiring / invoiceDue / tenderPublished rules) to email the supplier via
  OpenRegister's notification engine (email channel + `field` recipient kind).
