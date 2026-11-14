# procurement-pages Specification

## Purpose
What dossiq does for procurement pages. The requirements were first written by the change `r21-procurement-portal-accounts-tab` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-r21-procurement-portal-accounts-tab/`) and cover: The procurement pages link the supplier portal accounts.

## Requirements

### Requirement: The procurement pages link the supplier portal accounts

The `Contracts`, `Suppliers` and `SupplierUsers` pages SHALL show the page tabs Contracts, Suppliers and Portal
accounts, in that order. The Portal accounts tab SHALL be `adminOnly`, and SHALL ask no count on the pages a
non-admin can open.

#### Scenario: An admin on the contracts list

- **GIVEN** an admin on the contracts list, in Dutch
- **THEN** the tabs read Contracten, Leveranciers and Portaalaccounts, as on board DqContracten

#### Scenario: A buyer on the contracts list

- **GIVEN** a user who is not an admin on the contracts list
- **THEN** the tabs read Contracten and Leveranciers, and no request counts the portal accounts
