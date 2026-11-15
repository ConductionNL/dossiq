# Supplier portal reads its collections

## Why

Row 5.20 (suppliers sign in to see their own tenders, contracts and messages). The supplier audience is declared in `lib/Portal/PortalContributionProvider.php` with collections `tenders` (`supplierTender`), `contracts` (`supplierContract`), invoices and a message inbox, each scoped by `supplierRef`, and portaliq renders them (ADR-046). Supplier accounts are managed on `/settings/supplier-users`. The row was rated building because the `supplier-portal` spec names a `ContractController` and contract endpoints that do not exist. They are not needed: the portal reads the collections straight from OpenRegister. Nobody has signed in as a supplier on a live portal to confirm the three lists.

## What changes

- The `supplier-portal` spec is amended to how it is built: the supplier reads collections, not dossiq contract endpoints.
- A live pass as a supplier on portaliq confirms tenders, contracts and messages show only that supplier's rows.
- A supplier can reply to a message (the inbox's `reply` action, as the citizen inbox has).

## Impact

- `lib/Portal/PortalContributionProvider.php` (reply action on the supplier inbox if missing); no new controller.
- Spec delta on `supplier-portal`.
- Supplier pages are portaliq's screens; dossiq's board is the admin side, DqContracten.
