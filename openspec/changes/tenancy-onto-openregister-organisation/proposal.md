# Tenancy moves onto OpenRegister's Organisation

## Why

Dossiq runs a second, parallel tenancy model beside the one OpenRegister
already owns.

OpenRegister ships a native `Organisation` entity (`lib/Db/Organisation.php`)
carrying users, groups, owner, storage / bandwidth / API quotas, authorization
rules, lifecycle status, environment, parent-child hierarchy and role
definitions — plus an `/organisation` surface. Dossiq carries `tenant`,
`tenantConfiguration`, `tenantQuota`, `tenantUser`, `tenantMandate`,
`tenantBillingEvent` and `tenantOnboardingTask`, referenced from **56 PHP
files** including five middlewares.

That is the "second store that drifts" hazard ADR-098 names, applied to the one
subsystem where drift is not a cosmetic problem: tenancy decides who sees whose
data.

## Why this change leads with tests, not code

A scoping regression here does not throw. It returns another tenant's rows,
formatted correctly, with HTTP 200 — this codebase has already had a JOIN stop
scoping and show one tenant another's budget as an entirely plausible number.

Three of the five middlewares had **no tests at all**:

| Middleware | Tests before this change |
|---|---|
| `TenantContextMiddleware` | none |
| `TenantClaimValidationMiddleware` | none |
| `QuotaEnforcementMiddleware` | none |
| `MandateValidationMiddleware` | present |
| `TenantIsolationMiddleware` | present |

So the first deliverable is pinning tests for the three, written against
current behaviour, before anything moves. `TenantClaimValidationMiddlewareTest`
lands with this proposal; the other two follow in the same wave.

Each pinning test is mutation-checked — disabling the scoping comparison in
`TenantClaimValidationMiddleware` makes the suite fail, which is the only
evidence that a pinning test pins anything.

## A fail-open found while pinning

`TenantClaimValidationMiddleware` guards with:

```php
if ($jwtTenantId !== '' && $jwtTenantId !== $requestTenantId) { /* refuse */ }
```

A token carrying **no `tenant_id` claim** therefore satisfies the guard against
**any** bound tenant. The check that exists to stop cross-tenant access is
skipped precisely when the token declines to say which tenant it is for.

This is pinned as-is by `testEmptyClaimIsAllowedThrough()` and named for what it
is, so a green suite cannot be read as "the empty-claim case is handled".
Whether an absent claim should be refused is a decision for this change — not
something to flip silently inside a refactor, because tightening it may break
callers that currently rely on it.

## Decided: the PHP session is the source of truth for tenant

Both fail-opens found while pinning turned out to share one cause, and the
answer is not to tighten either check in place.

Neither the `X-Tenant-Id` header nor the JWT `tenant_id` claim should decide
which tenant a request acts as. The session does. A tenant *switch* is an
explicit operation that verifies membership and then rewrites the session;
everything after that reads the session.

Making the JWT leading would be actively wrong: changing tenant would then
require reminting the token.

This reframes both findings:

- **`testHeaderAloneBindsTheTenant`** — `resolveTenantIdFromRequest()` returns
  the header verbatim, and `TenantClaimValidationMiddleware` only looks at
  requests carrying a Bearer token, so a session-authenticated request with a
  forged header passes both. The fix is not to validate the header; it is that
  a user request must not carry one. The header stops binding.
- **`testEmptyClaimIsAllowedThrough`** — a token with no `tenant_id` satisfies
  the guard against any bound tenant. Once the session is leading, the claim is
  no longer the thing being trusted, so the guard is a consistency check rather
  than the boundary.

The currently-dead fallback branch in `resolveTenantIdFromRequest()` — which
calls `listActive()` and then `unset()`s the result — becomes the real path.

## Measured: what the six uncovered fields actually do

`Organisation` covers slug, name, lifecycle, quotas, hierarchy and comms. Six
`tenant` fields have no counterpart and there is no extension bag on the
entity. Before deciding where they go, each was traced to its readers.

| Field | Written by | Read by | Verdict |
|---|---|---|---|
| `tier` | SaaS create API | `TenantProvisioningService` (selects zaaktype templates), `TenantQuotaService::initialize` (`TIER_DEFAULTS` → the four canonical quotas) | **load-bearing** |
| `kvkNumber` | SaaS create API (required) | nothing keys off it | identity |
| `legalName` | — | `TenantWelcomeMailer`, as a display fallback | display only |
| `contractRef` | — | **nothing** | dead |
| `isolationMode` | create, as `TIER_ISOLATION[$tier]` | **nothing** | dead, derived |
| `dataResidency` | create, as the constant `'nl'` | **nothing** | dead, constant |

The intuition that `isolationMode` and `dataResidency` are "technical, so they
belong in OpenRegister and should be governed there" is the natural reading of
the names — but neither governs anything today. `isolationMode` is a pure
function of `tier`, and `dataResidency` is a hardcoded `'nl'`. Moving them to
the foundation repo would move dead weight into every app that inherits it, and
would make two fields look authoritative that no code consults.

Conversely `tier` — the one that reads as commercial, and the one least
obviously OpenRegister's business — is the only one of the six that drives
behaviour, and what it drives is *technical*: schema seeding and quota
defaults. The identity/technical split does not cleave where the names suggest.

So the disposition follows the measurement, not the vocabulary:

1. **Drop** `contractRef`, `isolationMode`, `dataResidency`. No readers. If
   isolation mode is ever needed it is `TIER_ISOLATION[$tier]`, computable at
   the point of use; data residency is deployment config, not per-tenant data.
2. **Move** `kvkNumber` and `legalName` onto `Organisation`. These are generic
   Dutch organisation identity, they are exactly the "extend the active tenant
   with organisation data" case, and other fleet apps want them — putting them
   in the foundation is what makes them reusable rather than re-modelled.
3. **Keep** `tier` in dossiq. Its quota role transfers to `Organisation`'s
   existing `storageQuota` / `bandwidthQuota` / `requestQuota`; its remaining
   role — which zaaktype templates to seed — is a dossiq concept.

Dropping a field is the one step here that is not reversible from code, so each
of the three is verified against stored data before removal, not only against
readers.

## Found while mapping: the Organisations index is keyed to the wrong schema

`Tenants` lists columns `name`, `slug`, `oin`, `domain`, `groupId`,
`isActive`. The `tenant` schema has `slug`, `displayName`, `legalName`,
`kvkNumber`, `contractRef`, `status`, `tier`, `isolationMode`,
`dataResidency`, `createdAt`, `activatedAt`, `terminatedAt`.

Five of the six columns name properties the schema does not have. They are the
`partnerOrganization` column set — the index was copied from `Partners` and
never re-keyed.

This is a static finding. The live instance holds no tenant records, so the
page shows "No items found" and the drift is not observable there; it was not
confirmed against rendered rows. Removing the surface in step 5 deletes it
either way, so it is recorded rather than separately fixed.

## What changes, in order

1. **Pin.** ✅ Done. All three middlewares now have mutation-checked tests.
2. **Map.** For each of the seven schemas, what `Organisation` already covers
   and what it does not. Quotas and lifecycle look close; `tenantMandate` and
   `tenantOnboardingTask` have no obvious counterpart.
3. **Decide the fail-open.** ✅ Decided: the session leads; neither the
   header nor the claim binds the tenant. See above.
4. **Move.** Repoint the five middlewares, migrate the data, retire the schemas.
5. **Remove the surface.** The `Organisations` settings entry goes once the
   store it administers is gone — not before, or the page manages a store
   nothing reads.

Steps 4 and 5 do not begin until step 1 is complete and green.

## Out of scope

`partnerOrganization` stays. It models partner organisations in case
collaboration, not tenancy, and shares only the word.
