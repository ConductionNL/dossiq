# ADR-004: The tenant cluster is an ADR-022 documented exception, until the migration lands

- **Status:** Accepted
- **Date:** 2026-09-11
- **Sunset:** 2027-03-31
- **Gate 23 rules:** 4
- **Deciders:** Ruben van der Linde, dossiq architecture
- **Scope:** dossiq, tenancy and the OpenRegister organisation boundary
- **References:** hydra ADR-022 (apps consume OpenRegister abstractions), exception clause. Hydra gate 23, `or-abstraction-anti-patterns`. Change `tenancy-onto-openregister-organisation`. Issue dossiq#2460. Issue dossiq#2470.

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
reason (ConductionNL/.github). This ADR is the app-local half of it. The same day
the gate made that path per rule: an exception ADR now names the rules it
covers, and a path it lists stays counted under every other rule.

## Decision

Per the ADR-022 exception clause, the gate suppresses the following paths
under gate 23 rule 4, the `Tenant*.php` name rule, and under no other rule. The
`Gate 23 rules: 4` line in the header is what tells the gate so. Every
suppression is printed on every gate run, with this ADR named beside it.

### Already consuming OpenRegister, flagged on name only

- `lib/Service/TenantService.php`
- `lib/Service/TenantAuditTrailService.php` (gate 23 rules: 4)
- `lib/Service/TenantOrganisationResolver.php` (gate 23 rules: 4)
- `lib/Service/TenantOnboardingService.php` (gate 23 rules: 4)
- `lib/Controller/TenantOnboardingController.php` (gate 23 rules: 4)

These are not a deferral. They are the target state. They appear here because
the rule matches their **name**, not their behaviour, and renaming a correct
consumer to satisfy a grep is not a fix. `TenantOrganisationResolver` reads
only OpenRegister's `OrganisationMapper` since its fallback onto the local
tenant store was removed (`tenancy-onto-openregister-organisation` task 6.6),
the same case as `TenantService`. The onboarding steps are tasks in
OpenRegister's task engine since `remove-casetask` task 7.1, so
`TenantOnboardingService` writes and reads them through the engine, and its
controller is the admin route in front of it. What the service still reads in
dossiq is the decision 2b satellites, for the go-live check.

### Kept in dossiq by decision 2b

- `lib/Service/TenantConfigurationService.php`
- `lib/Service/Tenant/TenantBrandingSanitiser.php`
- `lib/Service/TenantQuotaService.php`
- `lib/Service/TenantBillingService.php`
- `lib/Controller/TenantBillingController.php` (gate 23 rules: 4)

Branding, domain, locale and feature flags have no column on the organisation.
Billing is shillinq's domain, not OpenRegister's. `TenantBillingController`
carries the billing summary and invoicing endpoints, at the URLs they had on
the retired tenant admin controller. Two of the four quota types
map onto `storageQuota` and `requestQuota`; two do not.

### Tenant binding onto OpenRegister's active organisation, by decision 3 and Q3

- `lib/Service/TenantContext.php`
- `lib/Service/TenantSessionService.php`

The active tenant is OpenRegister's active organisation, read through
`OrganisationService::getActiveOrganisation()`, and only when the user's
`tenantUser` memberships list it (Q3). `TenantSessionService` answers it and
keeps no session key of its own. `TenantContext` carries it for the request.
Neither chooses a tenant; both read OpenRegister's choice.

### The tenant migration, for one release only (Q1, Q7)

- `lib/Service/TenantMigrationService.php` (gate 23 rules: 4)

It moves the legacy tenant rows onto Organisations, from `occ dossiq:migrate-tenants`
and from the `MigrateTenantsToOrganisations` repair step on every upgrade, and
reports how many tenants are left unmigrated. Its own sunset is the next dossiq
release: task 6.4 of `tenancy-onto-openregister-organisation` deletes the class
and this entry once a person records that the repair step reports zero. The gate
reads only the ADR-wide `Sunset:` line, so 2027-03-31 stays the backstop.

### Membership, role and mandate lookups, kept until they move to OpenRegister (Q2)

- `lib/Service/TenantAuthenticationService.php` (gate 23 rules: 4)

`listTenantsForUser()`, `isMemberOf()`, `resolveUserRole()`, `loadActiveMatrix()` and
`validateMandateMatrix()` read the `tenantUser` and `tenantMandate` satellites that decision 2b
keeps in dossiq. OpenRegister has no per-membership role or mandate matrix, so these stay until it
has one. Ruben decided this on 2026-10-08 (Q2).

## What this exception does not cover

Everything else the gate names stays red, and it should. Naming it here would
buy silence for the work, which is the opposite of the point.

Nothing tenant-shaped is still counted under rule 4. The onboarding service and
controller moved onto OpenRegister's task (`remove-casetask` task 7.1, decision
144: a skipped step is `terminated` with the outcome `skipped`).

Deleted, not exempted, and so named here by class only:

- The schema-per-tenant pipeline: the isolation middleware, the schema provisioner,
  the provisioning, seed and welcome mail services. Nothing created the schema it
  switched to, so every request resolved in `public`. dossiq#2470 deleted it.
- The token path: the JWT service, the claim middleware and its exception (Q2).
- The two tenant middlewares, the switch route and the tenant controller (Q3, Q5).
- The tenant admin store: its service and controller and their CRUD routes
  (`tenancy-onto-openregister-organisation` task 6.9). A status change reaches
  the tenant audit trail from OpenRegister's `OrganisationUpdatedEvent` instead.

### Rule 7 on `TenantAuditTrailService` is not covered

Gate 23 suppresses per rule, and this ADR covers rule 4 only. The file carries
its own `(gate 23 rules: 4)` list as well, so widening the header line later
cannot reach it. Rule 7 used to fire on the file because its hardening
checklist cited the inert search_path middleware as isolation evidence. That
was dossiq#2470, and `tenant-isolation-names-the-control-that-runs` removed
both strings and the code they pointed at. If rule 7 fires on the file again,
this ADR does not license it.

Rule 2 is not covered here either. The gate clears `TenantAuditTrailService`
under rule 2 because it writes through OpenRegister's `AuditTrailMapper`. If it
ever stops doing that, rule 2 should fire, and this ADR will not stop it.

## Decided by Ruben on 2026-10-08

Ruben answered the four questions the gate 23 amend of `tenancy-onto-openregister-organisation`
left open. This section records the decisions. It names classes, not paths, on purpose: it covers
nothing yet. Each build change adds its own path lines when the code it describes is merged, so the
exception never runs ahead of the work.

- **Q1, the migration.** `TenantMigrationService` also runs as a repair step for one release and
  reports how many tenants are left unmigrated. It is covered for rule 4 for that release only,
  added by task 6.11 of `tenancy-onto-openregister-organisation`, and deleted in the release after,
  once the count is zero (task 6.4). Same pattern as decision D12 for Woo requests.
- **Q2, the token path.** The JWT service, the tenant claim middleware and its mismatch
  exception are deleted, not exempted
  (`tenancy-onto-openregister-organisation-active-organisation`). The membership, role and mandate
  lookups in `TenantAuthenticationService` stay in dossiq, covered for rule 4 until they move to
  OpenRegister. That change adds its path line.
- **Q3, the active tenant.** It is OpenRegister's active organisation, read through
  `OrganisationService::getActiveOrganisation()`. The two tenant middlewares, the tenant switch
  route and the session store of `TenantSessionService` are deleted, not exempted.
  `TenantSessionService` stays in the tenant binding section above with what is left of it. The refusal of a non-active organisation moves into `MandateValidationMiddleware`.
- **Q4, the audit anchor.** Tenant objects are never deleted. They stay read-only as the anchor of
  every tenant audit entry `TenantAuditTrailService` writes, so existing and new entries for those
  tenants keep resolving. The `tenant` schema stays declared for that reason.

Ruben answered the three points left after that, later the same day:

- **Q5, the tenant controller.** Its `current`, `memberships`, `provision` and `usage` endpoints move
  to OpenRegister's organisation endpoints (`getActive`, `index`, `activate`, and `usage` with
  `show`), and the controller is deleted, not exempted
  (`tenancy-onto-openregister-organisation-active-organisation`).
- **Q6, the anchor of a new tenant.** When dossiq onboarding is initialised for an Organisation,
  dossiq creates a read-only tenant object for it, once, so every tenant, old and new, has one place
  its audit history lives. Creating that anchor is the only write left on the `tenant` schema;
  nothing updates or deletes one. `TenantAuditTrailService` still writes no row when an anchor is
  missing.
- **Q7, the migration cover.** The one-release cover of `TenantMigrationService` under Q1 stays,
  with its reason and a sunset of the next dossiq release, stated beside its path line when task
  6.11 adds it. The gate reads only the ADR-wide date, so 2027-03-31 remains the backstop. Task 6.4
  deletes the class and the line.

The last open point, the onboarding `skipped` status mapping in `remove-casetask` task 7.1, was
decided on 2026-10-10 (decision 144) and built. The paragraph "What this exception does not cover" above
predates these decisions; where it and this section differ, this section is the newer one.

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

Done, on the branches named in the changes:

- `tenant-isolation-names-the-control-that-runs` (dossiq#3465): the schema-per-tenant pipeline is
  deleted and the hardening checklist names OpenRegister's organisation row filter.
- `tenancy-onto-openregister-organisation-active-organisation` (dossiq#3469): the token path, the
  two tenant middlewares and the tenant controller are deleted, the active tenant is OpenRegister's
  active organisation, and the refusal of an organisation that is not active lives in
  `MandateValidationMiddleware`.
- `bezwaar-audit-onto-openregister-trail` (dossiq#3467): the Awb entries are rows on
  OpenRegister's audit trail.
- `tenancy-onto-openregister-organisation` (dossiq#3466), up to task 6.11: the dry run (posted on
  #3466: 3 tenants, no collision), the migration as a one-release repair step that also gives every
  member organisation its audit anchor, the tenant schema kept as the read-only anchor (Q4, Q6),
  the resolver without its fallback, and the tenant admin store removed.
- `remove-casetask` task 7.1: the onboarding steps are engine tasks; a tenant's legacy
  `tenantOnboardingTask` rows move onto the engine the first time an admin opens its onboarding.

## Next

Task 6.4 in the release after: delete `TenantMigrationService` and its entry here once the repair
step reports zero unmigrated tenants.
