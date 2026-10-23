# tenant-subscription

## Why

Board dossiq/DqTenant names an organisation's package ("Pakket Standaard") and its contract reference ("TEN-0014"). No
record held either: an OpenRegister organisation has no package, and the billing events carry a fee, not a package name.

## What changes

- A generic `tenantSubscription` schema (1.0.0, fragment `93-tenant-subscription.json`): `tenantRef` (the organisation),
  `packageName`, `reference`, `startDate`, `endDate`. Admin data: admin and beheerders read and write it, the background
  service reads it.
- The Zuiddrecht example set gives Gemeente Waterland its subscription: Standaard, TEN-0014.
- The organisation page's Status card reads the subscription for its package and contract reference; the status, start
  and slug stay the organisation's own (needs nc-vue feat/r24-facts-object-fields-without-source so an organisation
  without a subscription keeps them).
