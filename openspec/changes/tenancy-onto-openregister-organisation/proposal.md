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

## What changes, in order

1. **Pin.** Tests for the three untested middlewares. No behaviour change.
2. **Map.** For each of the seven schemas, what `Organisation` already covers
   and what it does not. Quotas and lifecycle look close; `tenantMandate` and
   `tenantOnboardingTask` have no obvious counterpart.
3. **Decide the fail-open.** Refuse an absent claim, or document why not.
4. **Move.** Repoint the five middlewares, migrate the data, retire the schemas.
5. **Remove the surface.** The `Organisations` settings entry goes once the
   store it administers is gone — not before, or the page manages a store
   nothing reads.

Steps 4 and 5 do not begin until step 1 is complete and green.

## Out of scope

`partnerOrganization` stays. It models partner organisations in case
collaboration, not tenancy, and shares only the word.
