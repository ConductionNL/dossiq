# Proposal: the admin data carries its own access rules

## Why

An access audit (18 Oct 2026) found five schemas behind admin-only pages with no authorization block of their own: avgIncident, supplierUser, tenantUser, tenantMandate and tenantBillingEvent. Each took the register block (`register.d/91-register-access.json`), so every member of `dossiq-medewerkers` could read and change data breaches, supplier accounts, tenant memberships, mandates and billing events through OpenRegister's object API, whatever the page said.

## What changes

- `register.d/92-admin-data-access.json` gives the five schemas a block of their own. A schema block replaces the register block for that schema.
- Supplier accounts and billing events: admin and beheerders.
- Tenant memberships and mandates: readable by staff, because `MandateValidationMiddleware` reads them as the signed-in user; changed only by admin and beheerders.
- Data breaches: any member of staff may report one; reading and handling one is for admin and beheerders.
- The background service account keeps the access it had where a job may read or write.
- The register version moves to 0.20.54 so existing instances re-import.

## Open

- indicatiestelling, mdoOverleg and reIntegratieTraject are read on social-domain case pages by the staff who handle them. dossiq has no social-domain group, so they keep the register block until one is decided.
- dossiq has no privacy-officer group; breaches are read by beheerders until one exists.
