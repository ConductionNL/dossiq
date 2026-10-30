# tenant-subscription

## Why

Board dossiq/DqTenant ends the organisation's "Historie" side card with its contract reference ("Kenmerk TEN-0014"), and
its Quota card changes the package ("Pakket wijzigen"). No record held either: an OpenRegister organisation has no
package or contract reference, and the billing events carry a fee, not a package name.

## What changes

- A generic `tenantSubscription` schema (1.0.0, fragment `93-tenant-subscription.json`): `tenantRef` (the organisation),
  `packageName`, `reference`, `startDate`, `endDate`. Admin data: admin and beheerders read and write it, the background
  service reads it.
- The Zuiddrecht example set gives Gemeente Waterland its subscription: Standaard, TEN-0014.
- The organisation page's "Historie" side card ends with "Kenmerk {reference}" read from the subscription (needs nc-vue
  feat/dq-c10-record-list-footnote-own-source). Without a subscription the line is left out.
- The Quota card says when another package takes effect: "Een ander pakket gaat binnen een kwartier in." OpenRegister
  enforces the request quota on the active organisation, which a signed-in session caches for at most 15 minutes
  (OrganisationService::CACHE_TIMEOUT 900); the card itself reads the quota fresh.
