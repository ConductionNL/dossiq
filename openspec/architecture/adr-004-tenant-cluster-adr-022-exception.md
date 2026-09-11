# ADR-004: The tenant cluster is an ADR-022 documented exception, until the migration lands

- **Status:** Accepted
- **Date:** 2026-09-11
- **Sunset:** 2027-03-31
- **Deciders:** Ruben van der Linde, dossiq architecture
- **Scope:** dossiq, tenancy and the OpenRegister organisation boundary
- **References:** hydra ADR-022 (apps consume OpenRegister abstractions), exception clause. Hydra gate 23, `or-abstraction-anti-patterns`, rules 2, 4 and 7. Change `tenancy-onto-openregister-organisation`. Issue dossiq#2460. Issue dossiq#2470.

## Context

ADR-022 says dossiq consumes OpenRegister's organisation and tenant lifecycle
instead of growing its own. Gate 23 enforces that, and on 2026-10-03 it turns
from a warning into a block.

Two of the gate's rules match on file name alone. Rule 4 fires on any
`Tenant*.php`. Rule 2 fires on any `*AuditTrail*.php`. Neither reads what the
file does.

That produces a result nobody can act on honestly. `TenantService` resolves
OpenRegister's `OrganisationMapper` and calls `TenantLifecycleService::provision()`
at line 175. `TenantAuditTrailService` writes every entry through OpenRegister's
`AuditTrailMapper::createAuditTrailEntry()` at line 149. Both classes are the
consumers ADR-022 asks for. Both were counted as violations of it.

The only way to clear a name rule is to rename the file. A rename changes no
behaviour, so it would buy a green gate and leave the architecture exactly where
it is. That is the outcome this ADR refuses.

The second half of the problem is the decided migration itself. On 2026-09-11
Ruben decided (`tenancy-onto-openregister-organisation`, decision 2b) that
`tenantConfiguration`, `tenantQuota`, `tenantUser`, `tenantMandate` and
`tenantBillingEvent` stay in dossiq and reference the organisation instead of
the local tenant. OpenRegister does not grow billing or configuration stores.
So the services behind those schemas keep their `Tenant` names, on purpose, and
the approved migration cannot clear rule 4 however much of it is built.

Gate 23 grew an exception path for rules 2 to 6 on 2026-09-11 for exactly this
reason (ConductionNL/.github). This ADR is the app-local half of it.

## Decision

Per the ADR-022 exception clause, the gate suppresses the following paths.
Every suppression is printed on every gate run, with this ADR named beside it.

### Already consuming OpenRegister, flagged on name only

- `lib/Service/TenantService.php`
- `lib/Service/TenantAuditTrailService.php`

These are not a deferral. They are the target state. They appear here because
the rule matches their **name**, not their behaviour, and renaming a correct
consumer to satisfy a grep is not a fix.

### Kept in dossiq by decision 2b

- `lib/Service/TenantConfigurationService.php`
- `lib/Service/Tenant/TenantBrandingSanitiser.php`
- `lib/Service/TenantQuotaService.php`
- `lib/Service/TenantBillingService.php`

Branding, domain, locale and feature flags have no column on the organisation.
Billing is shillinq's domain, not OpenRegister's. Two of the four quota types
map onto `storageQuota` and `requestQuota`; two do not.

### Session-led tenant binding, kept by decision 3

- `lib/Service/TenantContext.php`
- `lib/Service/TenantSessionService.php`

The session is the source of truth for the active tenant. These two read it and
hand it to the scoping filters.

## What this exception does not cover

Everything else the gate names stays red, and it should. Naming it here would
buy silence for the work, which is the opposite of the point.

Still counted, by class name so that this paragraph suppresses nothing:

- `TenantIsolationMiddleware`, `TenantSchemaProvisioner`, `TenantProvisioningService`
  and `TenantSeedService`. This is the schema-per-tenant pipeline. Nothing creates
  the schema it switches to, Postgres ignores a missing schema, and every request
  resolves in `public`. It is deleted by dossiq#2470, not exempted.
- `TenantSaasService`, `TenantSaasController`, `TenantLifecycleControlService`,
  `TenantMigrationService` and `TenantController`. These administer the local
  tenant store, which retires once the data has moved.
- `TenantOnboardingService`, `TenantOnboardingController` and `TenantWelcomeMailer`.
  These map field for field onto OpenRegister's task, and move there.
- `TenantJwtService`, `TenantAuthenticationService`, `TenantClaimValidationMiddleware`
  and `TenantClaimMismatchException`. The header and token binding stops leading
  once the session does. Their end state is undecided, so red is the accurate
  signal.
- `TenantMiddleware` and `TenantContextMiddleware`, which gate 23 rule 7 also
  names. They are re-pointed by the move, not kept as they stand.

### One suppression we did not want

Gate 23 suppresses by path, across all of its rules at once. So covering
`lib/Service/TenantAuditTrailService.php` for rule 4 also silences rule 7's
`search_path` hit on that same file. That hit is real: the hardening checklist
in that class cites the inert isolation middleware as isolation evidence, at
lines 276 and 302. Those two strings are deleted by dossiq#2470. This ADR does
not license keeping them, and the reviewer of dossiq#2470 should check they are
gone rather than trusting a quiet gate.

## Sunset

**2027-03-31.** On that date the gate stops honouring this ADR and dossiq goes
red on every path above.

The tenant binding and the two consumers retire through
`tenancy-onto-openregister-organisation`, steps 4 and 5: move the tenant onto
the organisation, then remove the local store and its admin pages. Step 4 is
irreversible, so it runs dry first and a person reads the orphan and collision
report before the real run.

The four satellites kept by decision 2b have **no retiring change and no
upstream home**. That is the part to re-argue at the sunset, not to renew by
habit. Two things have to be settled by then. The hydra tenant spec says apps
shall not keep local quota counters, and `TenantQuotaService` does, so one limit
is currently spent twice. Per-organisation configuration and branding is generic
enough that hermiq, portaliq and launchpad would use it, which makes it a
candidate to move up rather than a permanent local store.

## Status of the work

Nothing here is done. The tenancy change stands at nine of thirteen tasks, and
the four that remain are the whole migration. This ADR records a scheduled move
and buys it room. It does not report progress.

## Next

Run step 4 of `tenancy-onto-openregister-organisation` as a dry run, and read
the report before the real one.
