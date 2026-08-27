# Tasks: portal-contribution

- [x] **T1**: Add `OCA\Dossiq\Portal\PortalContributionProvider` — a
  dependency-free class (`getAudience` + `getContribution`) declaring the supplier
  audience's collections (supplierTender/Contract/Invoice) + inbox
  (supplierMessage), scoped by `supplierRef`. Discovered by Portaliq via
  convention FQCN, duck-typed. LIVE-VERIFIED: a supplier logging into the Portaliq
  portal sees dossiq's tenders/contracts/invoices scoped to their `supplierRef`.

- [ ] **T2**: Retire dossiq's in-app supplier views (`src/views/leverancier/*`,
  `manifest.d/60-leverancier.json`) once Portaliq renders the contribution; keep
  the API + facades.

- [ ] **T3**: Wire `x-openregister-notifications` on `supplierMessage` (and the
  contractExpiring / invoiceDue / tenderPublished rules) to email the supplier via
  OpenRegister's notification engine (email channel + `field` recipient kind).
