# r21-procurement-portal-accounts-tab

## Why

Board dossiq/DqContracten draws the procurement lists as one set of tabs: Contracten, Leveranciers and
Portaalaccounts. The Contracts and Suppliers pages linked each other, but not the supplier portal accounts, and the
accounts page (`SupplierUsers`, admin only) linked neither back.

## What changes

- Contracts and Suppliers get a third page tab, Portal accounts, to `SupplierUsers`, shown to an admin only. It
  carries no count there: the library still requests the count of a hidden tab, and a non-admin may be reading.
- `SupplierUsers` gets the same three tabs, with counts, so the three pages read as one place.
- The accounts page itself stays admin-only; hiding the tab hides a link, the page refuses others as before.
