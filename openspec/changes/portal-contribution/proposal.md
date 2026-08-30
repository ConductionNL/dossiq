# Proposal: portal-contribution

## Summary

Contribute dossiq's supplier surface to the shared **Portaliq** external portal
(hydra ADR-046) instead of dossiq hosting its own supplier portal. dossiq ships
a single, dependency-free `OCA\Dossiq\Portal\PortalContributionProvider` that
declares — for the supplier audience — the OpenRegister collections a supplier may
see (tenders, contracts, invoices) plus their inbox (supplierMessage), all scoped
by `supplierRef`. Portaliq discovers it by convention FQCN, aggregates it into the
supplier's portal, and reads the collections RBAC-scoped to the subject.

## Why

- One shared external portal (ADR-046), not a portal per app.
- dossiq keeps ownership of its data + domain logic; it only *declares* what a
  supplier may see. No portal auth, shell, or inbox logic lives in dossiq.
- Zero coupling: the provider references nothing from Portaliq and is inert when
  Portaliq is absent (only Portaliq's registry ever loads it, duck-typed).

## Scope

- Add the contribution provider (this change).

## Out of scope (later)

- Retire dossiq's in-app supplier views (`src/views/leverancier/*`) once Portaliq
  renders the contribution.
- Client-facing action endpoints bearer-forwarded from Portaliq.
- Wiring `x-openregister-notifications` to email suppliers on new messages.

## Depends on

- Portaliq installed (the portal that discovers + renders the contribution).
- dossiq's existing supplier schemas in OpenRegister (supplierTender/Contract/
  Invoice/Message, scoped by `supplierRef`).
